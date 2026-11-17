# Pixelforge

Laravel 13 + Vue 3/Inertia application using the official Laravel Vue starter kit, with Laravel Fortify authentication.

The whole app runs in one container: FrankenPHP serves PHP and Caddy, and the Vue assets are compiled into the same image by a multi-stage build. Fairlead provides the HTTPS route.

## Architecture

See [the agreed architecture](docs/architecture.md) for layer ownership, Saloon integration, lifecycle and accounting invariants, durable webhook processing, private SSE, reporting and implementation order. It describes the target design; the current app is the authentication/runtime scaffold with a branded empty workspace; provider features are not implemented yet.

## First run

```bash
cp .env.example .env
printf 'base64:%s' "$(openssl rand -base64 32)" > /tmp/pixelforge-key
sed -i '' "s|^APP_KEY=.*|APP_KEY=$(cat /tmp/pixelforge-key)|" .env
rm /tmp/pixelforge-key

fl db create pixelforge
fl project sync
fl project up
docker exec pixelforge-app-1 php artisan migrate --force
```

Fairlead provisions the app database/role and keeps its generated `DATABASE_URL` outside the checkout. Laravel reads that URL for its Postgres connection. If an existing image predates the Postgres setup, run `fl project rebuild app` before migrating.

Open <https://pixelforge.fl.wb>.

The app key lives only in the ignored, host-owned `.env`. It is bind-mounted read-only at runtime and is never added to the image or repository.

## Migrations

Migrations are deliberately never run by the container entrypoint or image build. Run them explicitly:

```bash
docker exec pixelforge-app-1 php artisan migrate --force
```

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

Frontend dependency installation, build, and type checking run inside Docker during the image build; no host PHP or Node installation is needed.

There is no HMR/dev server or SSR. After changing Vue, CSS, or TypeScript, rebuild:

```bash
fl project rebuild app
```

Then run migrations again only if migrations changed.

## Fairlead

- `fl.yml` is the committed project definition. Generated env/Compose config stays in `~/.config/fl/projects/pixelforge/`.
- The single `app` service is routed catch-all on port 80 at `pixelforge.fl.wb`.
- Postgres is enabled; S3 is disabled. The app database/role is `pixelforge`; tests use `pixelforge_test` with separate credentials.
- Fairlead injects `DATABASE_URL`, `APP_URL`, `EMAIL_ADAPTER`, and the `SMTP_*` values. `config/mail.php` maps `EMAIL_ADAPTER=smtp` and the `SMTP_*` values onto Laravel's mail config.
- `REDIS_URL` is present but unused: cache, sessions, and queues use the database.
- Trusted proxies are limited to loopback and private Docker networks so HTTPS URLs and secure cookies work behind Fairlead's Traefik.

## Dokploy deployment target

Deploy Postgres as a separate container/service alongside the app, with a persistent database volume, backups and a tested restore process. Use Postgres 18 to match Fairlead locally. Connect over Dokploy's private network using `DB_CONNECTION=pgsql` and `DATABASE_URL`; do not publish Postgres publicly or reuse local credentials. Set `APP_ENV=production` and `APP_DEBUG=false`, provide a stable `APP_KEY`, and run migrations explicitly. Do not provide local `TEST_DATABASE_URL` credentials to production.

The app image includes the Postgres PDO driver. No Dokploy database/service has been provisioned yet.

## Operational notes

- The container runs as root, which is FrankenPHP's default. It does not need host port bindings; Traefik reaches port 80 on the `fl` network.
- No queue worker, scheduler, SSR process, or CI workflow is configured.
- DNS resolves correctly to this Mac's Tailscale IP. If HTTPS reports an untrusted issuer, install Fairlead's existing mkcert CA into the system trust store: `CAROOT="$HOME/Library/Application Support/mkcert" mkcert -install`. This changes system trust and may require administrator approval. Explicit verification without changing trust: `curl --cacert "$HOME/Library/Application Support/mkcert/rootCA.pem" https://pixelforge.fl.wb/up`. With `XDG_DATA_HOME` set, plain `mkcert -install` may use a different CA directory.
