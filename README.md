# Pixelforge

Laravel 13 + Vue 3/Inertia application using the official Laravel Vue starter kit, with Laravel Fortify authentication.

The whole app runs in one container: FrankenPHP serves PHP and Caddy, and the Vue assets are compiled into the same image by a multi-stage build. Fairlead provides the HTTPS route.

## Architecture

See [the agreed architecture](docs/architecture.md) for layer ownership, Saloon integration, lifecycle and accounting invariants, durable webhook processing, private SSE, reporting and implementation order. It describes the target design. The app now has authentication, exact quote calculations, durable generation submission/reconciliation, verified webhook receipt, monotonic lifecycle application, exactly-once settlement, owned generation history and an append-only customer credit ledger with demo top-ups. Supervisor runs the web server, queue worker and scheduler in the same container. The Generations page updates live over a private Mercure event stream. Operators get a daily/per-user report reconciled against Pixelforge usage. Production runs at https://px.bit-monkey.io and deploys automatically from `main`; it has its own Pixelforge key and sends mail through Resend.

## First run

```bash
cp .env.example .env
printf 'base64:%s' "$(openssl rand -base64 32)" > /tmp/pixelforge-key
sed -i '' "s|^APP_KEY=.*|APP_KEY=$(cat /tmp/pixelforge-key)|" .env
rm /tmp/pixelforge-key
for key in MERCURE_PUBLISHER_JWT_KEY MERCURE_SUBSCRIBER_JWT_KEY; do
    sed -i '' "s|^$key=.*|$key=$(openssl rand -base64 32)|" .env
done

fl db create pixelforge
fl project sync
fl project up
docker exec pixelforge-app-1 php artisan migrate --force
```

Fairlead provisions the app database/role and keeps its generated `DATABASE_URL` outside the checkout. Laravel reads that URL for its Postgres connection. If an existing image predates the Postgres setup, run `fl project rebuild app` before migrating.

Open <https://pixelforge.fl.wb>.

The app key lives only in the ignored, host-owned `.env`. It is bind-mounted read-only at runtime and is never added to the image or repository.

## Email

Email verification and password resets use Laravel's mail system. Local Fairlead mail stays on SMTP/Mailpit; adding a Resend key alone does not enable external delivery.

For real delivery, verify your sending domain in Resend and set these in the deployment's private runtime environment (never Git or build arguments):

```dotenv
MAIL_MAILER=resend
RESEND_API_KEY=your-resend-sending-key
MAIL_FROM_ADDRESS=hello@your-verified-domain.example
MAIL_FROM_NAME=Pixelforge
```

Use a sending-only API key restricted to your sending domain. Set `APP_URL` to the application's canonical HTTPS URL so authentication links point at the right environment. The native Laravel Resend transport and its PHP SDK are installed; no extra service provider or webhook endpoint is needed. See [Laravel's Resend setup](https://laravel.com/framework/docs/13.x/mail#resend-driver).

Deploy an image containing this dependency before enabling `MAIL_MAILER=resend`. After changing settings, clear/rebuild any Laravel configuration cache and recreate/redeploy the app so web and background processes inherit the new environment. Locally, if deliberately testing real delivery, use `fl project restart`, then `docker exec pixelforge-app-1 php artisan config:clear`; leave the Resend overrides unset for normal Mailpit use.

Verify with a new test registration and the forgot-password flow, checking both the Resend dashboard and recipient inbox. Authentication mail remains synchronous; delivery errors are surfaced, not silently redirected to logs, and API acceptance does not guarantee inbox delivery. Automated tests use dummy credentials and mocked transport responses; they send no real mail.

## Pricing preview

Sign in to the workspace to price a prompt, size, quality and up to four reference-image URLs. Estimates, conditional maximum prices and actual charges include a 25% markup and are rounded up once to the next whole cent; provider costs stay exact. The workspace shows customers only the estimated and maximum prices; the quote response carries no provider costs, token counts, rates or markup (those stay in the stored quote snapshot). USD amounts remain exact decimal strings in the browser.

The authenticated, CSRF-protected `POST /generations/quote` endpoint validates inputs and fetches the public Pixelforge pricing catalogue through Saloon. No provider API key is needed yet. Validated rates/rules are cached for five minutes; failures are not cached and expired prices are not silently reused when the provider is unavailable. The maximum is conditional on the quoted rates, not a guaranteed cap if rates change before provider acceptance.

`PIXELFORGE_BASE_URL` defaults to `https://pixelforge.promptedit.dev/v1`. Restart the app after changing environment settings. If changing the provider URL, also discard the old catalogue:

```bash
docker exec pixelforge-app-1 php artisan cache:forget pixelforge.pricing.v1
```

A quote never creates a generation, enqueues work, reserves credit or charges anything; it only reports whether the current available balance covers the maximum. Generate is enabled only when a provider API key is configured and the balance covers the quoted maximum; submission re-quotes server-side, re-checks credit and records the immutable request and its credit hold before background work starts.

## Credits

Each user has a credit balance derived from an append-only ledger (`credit_ledger_entries`): purchases and releases add credit, holds and debits remove it. The balance appears in the sidebar **Credits** item, on the Credits page and next to Generate.

- **Buy credits** (`/credits`) is a demo: fixed $5/$10/$25 packages, no payment taken. Each purchase carries a client UUID, so a double-click or retry credits once. Purchases are rate-limited to 10 per minute per user.
- Submitting a generation holds its quoted **maximum** price. If the available balance is lower, the request is rejected with a 422 and nothing is created, held or queued.
- On success the whole hold is released and the actual charge (the same amount as `generation_charges.customer_cost_nanodollars`) is debited. Provider failure or definitive rejection releases the hold. Uncertain or paused submissions keep their hold until the outcome is known.
- If provider rates rise between quote and acceptance, the full actual charge is still debited and the balance can go negative; new generations are then blocked until credit is added.
- Existing users start at $0.00. Generations settled before the ledger existed have no hold and receive no retroactive debit. Ledger entries are retained financial evidence: the database rejects edits and deletes, and accounts with entries cannot be deleted.

## Generation configuration

Create one Pixelforge API key per environment and set these values in the ignored `.env` (never in Git or browser code):

```dotenv
PIXELFORGE_API_KEY=your-provider-key
PIXELFORGE_WEBHOOK_SECRET=your-key-specific-signing-secret
```

Configure that key's webhook URL in the provider dashboard as `https://your-public-host/webhooks/pixelforge`. The local Fairlead hostname is private and cannot receive real provider callbacks without a public tunnel; background retrieval still reconciles accepted generations. No tunnel is provisioned automatically. For local callbacks, use the webhook-only Tailscale Funnel setup below. Restart the app after environment changes.

Submission and webhook work are durable database records. Retries keep the same idempotency key/options; duplicates and late observations cannot regress terminal state or create a second charge. Success settles against the provider's accepted rate snapshot and actual usage, with the 25% markup, rounded up once to the next whole cent. Provider failure/known rejection is uncharged; an uncertain outcome remains unknown, not free or failed. Never start a replacement just because a submission response was lost—retry unchanged options or inspect Generations.

The inbox verifies raw-body signatures before JSON decoding, enforces a five-minute timestamp tolerance and records one immutable row per event ID. Receipt returns success only after persistence; queue-dispatch failure is recovered by the scheduler. Unmatched/conflicting/invalid signed evidence stays inspectable in `webhook_events` rather than being assigned to another user.

Initial limits: five submission network attempts, a conservative shared 45/minute job budget, 60-second work leases, roughly 15-second accepted-generation retrieval and a 30-minute automatic recovery window. Provider `Retry-After` is respected. The recovery sweep considers at most 20 items per minute. Exhaustion/configuration problems pause work for operator attention without fabricating provider failure.

Images retain the provider's fixed 24-hour expiry. Accounts with generation or credit evidence cannot currently be deleted through the app; explicit anonymisation/retention remains to be implemented. Financial records are retained, never cascaded away.

## Local webhooks through Tailscale Funnel

Requires Tailscale connected on the host, host Caddy installed, and the Fairlead app running. The dedicated gateway permits only `POST /webhooks/pixelforge`; all other methods/routes return 404. Upstream error bodies are sanitised without changing their status codes, preventing local Laravel debug pages from becoming public. Raw webhook bodies/signature headers pass through unchanged; Laravel still verifies signatures before recording events.

Funnel uses port 8443, not 443: a Tailscale Serve/Funnel handler on 443 intercepts every tailnet connection to this Mac's port 443, breaking Fairlead's `*.fl.wb` HTTPS for other tailnet devices (`ERR_SSL_PROTOCOL_ERROR`). Check for existing Serve/Funnel routes first; do not overwrite another service:

```bash
tailscale serve status
tailscale funnel status
export FAIRLEAD_CA="$HOME/Library/Application Support/mkcert/rootCA.pem"
caddy validate --config docker/webhook.Caddyfile --adapter caddyfile
caddy start --config docker/webhook.Caddyfile --adapter caddyfile \
    --pidfile /tmp/pixelforge-webhook-proxy.pid
bash tests/webhook-proxy.sh
tailscale funnel --bg --https=8443 http://127.0.0.1:18081
tailscale funnel status
```

If Funnel is disabled, the CLI prints a Tailscale approval link. Approve it and rerun the Funnel command. Use the resulting hostname to configure the provider callback as `https://your-machine.your-tailnet.ts.net:8443/webhooks/pixelforge`. Keep `APP_URL` pointing to Fairlead; browser navigation does not need the tunnel. The gateway listens only on `127.0.0.1:18081` and verifies Fairlead's HTTPS certificate using the existing CA (no insecure TLS bypass).

Run `bash tests/webhook-proxy.sh https://your-machine.your-tailnet.ts.net:8443` to check routing and unsigned rejection through the hostname too. A 503 means provider credentials are missing; 400 means the unsigned request was rejected. Neither proves a genuine signed callback has arrived. Verify public reachability separately from outside the tailnet: MagicDNS can send local tests directly to the node instead of the public Funnel relay. On a Mac where Fairlead owns port 443, that local route can instead reach Traefik and produce a certificate error. Test from outside Tailscale, or use `curl --resolve your-machine.your-tailnet.ts.net:8443:PUBLIC_RELAY_IP` with a relay IP obtained from public DNS; do not disable TLS verification.

Funnel runs in the background, but the host Caddy process must be restarted after reboot. The app, gateway, Tailscale and awake/online Mac must all be available. Reconciliation remains necessary for missed deliveries. Stop this gateway/tunnel without resetting unrelated Tailscale configuration:

```bash
tailscale funnel --https=8443 off
kill -TERM "$(cat /tmp/pixelforge-webhook-proxy.pid)"
rm /tmp/pixelforge-webhook-proxy.pid
```

## Live updates

The bundled FrankenPHP Mercure hub (Mercure 1.x) streams private generation changes to the Generations page. Generate two separate keys in the ignored `.env`, then recreate the app (`fl project restart`); the entrypoint refuses to start without them:

```dotenv
MERCURE_PUBLISHER_JWT_KEY=   # openssl rand -base64 32
MERCURE_SUBSCRIBER_JWT_KEY=  # openssl rand -base64 32, different from the publisher key
```

- Each state change commits a durable outbox row with the generation change. The queue worker publishes it as a **private** update carrying only `{generation_id, revision}`; the page then reloads its owner-scoped props. The minute recovery sweep republishes anything a crash or hub outage left unpublished.
- The page receives an `HttpOnly`, `Secure`, `SameSite=Strict` cookie (`__Secure-mercure_access_token`, path `/.well-known/mercure`) granting only that user's topic for one hour. Every page load or live refresh renews it; logout expires it. A guessed topic gets nothing, and no-cookie connections are rejected with 401.
- Token issuer/audience derive from `APP_URL`, which must match the public origin exactly. The hub keeps no history (`transport local`) because the page refetches on every (re)connect. Streams close after 10 minutes and reconnect automatically; a tab left open after logout in another tab can see invalidation IDs (never prompts or prices) until then.
- Unchanged since the earlier slices: browsers never compute prices or apply state; the stream only says "something changed".

## Migrations

Migrations are deliberately never run by the container entrypoint or image build. Run them explicitly:

```bash
docker exec pixelforge-app-1 php artisan migrate --force
```

The credit-ledger migration (`2026_11_17_160000_create_credit_ledger_entries_table`) is additive: it creates the ledger table, its constraints and an append-only trigger, and changes no existing rows. Everyone starts with a zero balance.

Application data lives in Fairlead's Postgres database `pixelforge`, not the app container. App rebuilds and `fl project down` do not remove it. The `pixelforge_storage` volume still contains Laravel runtime files; the old SQLite file is retained there as an unused backup, not copied into Postgres.

## Checks

Tests use a separate Postgres database and role, `pixelforge_test`. Provision it once:

```bash
fl db create pixelforge_test
```

Set `TEST_DATABASE_URL` in the ignored `.env` to that database's reported connection string, changing the host from `localhost` to `db` for container access. Fairlead keeps the credentials in its state; do not commit them. Recreate the app to load the changed environment:

```bash
fl project restart
docker exec pixelforge-app-1 composer test
```

That runs Pint in check mode, PHPStan, and the full PHPUnit suite against Postgres. Test bootstrap fails closed if the test URL does not use the dedicated database/role, or cached application configuration exists. It never falls back to the application database. The local test URL accepts no query parameters except `sslmode=disable`. Individually:

```bash
docker exec pixelforge-app-1 php artisan test
docker exec pixelforge-app-1 ./vendor/bin/pint --test
docker exec pixelforge-app-1 ./vendor/bin/phpstan analyse
```

Frontend dependency installation, build, type checking and the pure frontend helper tests run inside Docker during the image build; no host PHP or Node installation is needed. To run frontend formatting/lint and helper checks separately:

```bash
docker build --target assets -t pixelforge-assets .
docker run --rm --entrypoint npm pixelforge-assets run check
docker run --rm --entrypoint sh pixelforge-assets -c 'node --test tests/frontend/*.test.mjs'
```

With Node 26 locally, run `node --test tests/frontend/*.test.mjs`. Runtime health/fatal-process checks use Python's standard-library runner: `python3 -m unittest discover -s tests/runtime`. Integration suites serialize themselves with a dedicated-test-database advisory lock before migration traits run; genuine concurrency tests use independent application processes and still contend on real generation rows.

There is no HMR/dev server or SSR. After changing Vue, CSS, or TypeScript, rebuild:

```bash
fl project rebuild app
```

Then run migrations again only if migrations changed.

## CI and container images

[`.github/workflows/build.yml`](.github/workflows/build.yml) runs on pull requests to `main`, pushes to `main`, and manual dispatches:

- **Test and build:** builds the application image with frontend formatting/lint, TypeScript, frontend helper tests and Python runtime tests; then runs Pint, PHPStan and the full PHP suite inside that image. CI supplies a disposable Postgres 18 service with only the `pixelforge_test` database/role, a generated test app key and fake Mercure signing keys. It uses no application database, provider credentials or local `.env`.
- **Publish image:** only after successful checks on a `main` push or manual `main` run. Publishes `ghcr.io/andycowan/pixelforge:latest` and `ghcr.io/andycowan/pixelforge:sha-<full-commit-sha>`, with source/revision labels. Pull requests and manual runs on other branches never publish.
- **Deploy to production:** after publishing, calls the Dokploy app's deploy webhook (GitHub `production` environment secret `DOKPLOY_DEPLOY_WEBHOOK_URL`, which can only redeploy this app). Dokploy redeploys `:latest`. The job then polls `https://px.bit-monkey.io/up` until its `X-App-Revision` header equals this build's identity (`<commit>.<run id>.<attempt>`), and fails after 10 minutes. Same-commit rebuilds, such as base-image security updates, therefore still have to prove their new container is live; Dokploy's own "done" status is not proof the new task is running.

Images initially target **linux/amd64**. Publishing uses the automatic `GITHUB_TOKEN`; deployment needs only the webhook secret above. The publish job requires package-write access allowed by repository/organisation policy. GHCR package visibility and deployment-host pull access must be configured separately if the image is private. See [GitHub's container publication guide](https://docs.github.com/en/actions/tutorials/publish-packages/publish-docker-images).

Actions are pinned to release commit SHAs. BuildKit caches speed up subsequent builds; main publication and deployment runs are serialised, while superseded PR checks are cancelled. Production secrets stay in Dokploy; migrations run in the new container before it starts (see below).

## Fairlead

- `fl.yml` is the committed project definition. Generated env/Compose config stays in `~/.config/fl/projects/pixelforge/`.
- The single `app` service is routed catch-all on port 80 at `pixelforge.fl.wb`.
- Postgres is enabled; S3 is disabled. The app database/role is `pixelforge`; tests use `pixelforge_test` with separate credentials.
- Fairlead injects `DATABASE_URL`, `APP_URL`, `EMAIL_ADAPTER`, and the `SMTP_*` values. `config/mail.php` maps `EMAIL_ADAPTER=smtp` and the `SMTP_*` values onto Laravel's mail config.
- `REDIS_URL` is present but unused: cache, sessions, and queues use the database.
- Trusted proxies are limited to loopback and private Docker networks so HTTPS URLs and secure cookies work behind Fairlead's Traefik.

## Dokploy deployment

Production runs `ghcr.io/andycowan/pixelforge:latest` at https://px.bit-monkey.io with a separate, private Postgres 18 service, and every successful `main` build deploys automatically. See the [deployment runbook](docs/deployment.md) for settings, rollback and remaining production checks.

## Operator report

Operators see **Operator report** in the sidebar (`/admin/report`): credit spend (customer charges), provider cost and profit for each UTC day and each user, with range totals and a per-user summary. Each day is compared with Pixelforge's `GET /v1/usage` for the configured API key: provider cost, generation count and every token category must match, and differences are shown rather than adjusted. Generations count on the UTC day they completed, as Pixelforge bills them, and only charges made with the current API key are included (others are counted separately). Ranges are inclusive UTC dates, up to 92 days, defaulting to the last 30.

Grant or revoke operator access (it is not self-service or mass-assignable):

```bash
docker exec pixelforge-app-1 php artisan users:admin you@example.com
docker exec pixelforge-app-1 php artisan users:admin you@example.com --revoke
```

## Operational notes

- The container runs as root, which is FrankenPHP's default. It does not need host port bindings; Traefik reaches port 80 on the `fl` network.
- Supervisor manages `web`, `worker`, `scheduler` and a fatal-process listener. Inspect them with `docker exec pixelforge-app-1 supervisorctl -c /etc/supervisor/pixelforge.conf status`.
- Queue jobs time out after 30 seconds, below the database reservation timeout of 90 seconds. Container shutdown allows 70 seconds for ordered graceful process-group stopping. Health requires all supervised processes plus HTTP; exhausted startup failures terminate Supervisor so Docker can restart the container.
- The scheduler runs `generations:recover` every minute. A manual sweep is `docker exec pixelforge-app-1 php artisan generations:recover`. Inspect paused requests/inbox outcomes and application/failed-job logs before resuming uncertain work; do not rewrite request keys or settled evidence.
- Credit balances are append-only. To change one, record an operator adjustment that sets the user's available balance: `docker exec pixelforge-app-1 php artisan credits:adjust user@example.com --to=0.40 --reason="Why"`. It records one credit or debit adjustment with the reason (shown in the user's activity as "Adjustment"), or nothing if already at the target. A single adjustment is limited to $1,000.
- With `MIGRATE_ON_START=true` (set in production only) the entrypoint runs `php artisan migrate --force` before Supervisor starts; a failed migration stops the container rather than running new code on the old schema. Locally the flag is unset, so run migrations yourself after pulling. Keep migrations additive: during a start-first rollout the previous release briefly runs against the new schema.
- No SSR process is configured. GitHub Actions builds/tests the image, publishes successful `main` builds to GHCR and deploys them to production.
- DNS resolves correctly to this Mac's Tailscale IP. If HTTPS reports an untrusted issuer, install Fairlead's existing mkcert CA into the system trust store: `CAROOT="$HOME/Library/Application Support/mkcert" mkcert -install`. This changes system trust and may require administrator approval. Explicit verification without changing trust: `curl --cacert "$HOME/Library/Application Support/mkcert/rootCA.pem" https://pixelforge.fl.wb/up`. With `XDG_DATA_HOME` set, plain `mkcert -install` may use a different CA directory.
