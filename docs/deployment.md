# Dokploy deployment

## Current initial deployment

The published container is deployed on Levante, in **Pixelforge → production → app**:

<https://px.bit-monkey.io>

The original temporary hostname, <https://pixelforge-app-iu9qbn-59b3e5-167-235-70-59.sslip.io>, still routes to the same app as a fallback.

This is an **infrastructure-first deployment**, not full product acceptance. It uses committed revision `aa314c44b775adb9ee0135506b25031e0ac8693a`; the newer uncommitted local application features are not in this image.

| Setting               | Value                                                                                                                                                                              |
| --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Dokploy               | `https://levante.bit-monkey.io`                                                                                                                                                    |
| Project               | `-ykvdDra_iMUOhDSx6pIV`                                                                                                                                                            |
| Environment           | `nO5EfwUJOF6uSbsCrBg19`                                                                                                                                                            |
| Application           | `tgu1YlcKPzwZFLXXJMbg-` (`pixelforge-app-iu9qbn`)                                                                                                                                  |
| Source                | Docker, not a Git build                                                                                                                                                            |
| Image                 | `ghcr.io/andycowan/pixelforge@sha256:7924f6b25b6c461c53b51744cbf71fef3feacbf7551d16dcd953e844fe5a7fdd`                                                                             |
| Platform / replicas   | `linux/amd64` / one                                                                                                                                                                |
| Internal HTTP port    | `80`                                                                                                                                                                               |
| Domains               | `px.bit-monkey.io` (primary) and the temporary `sslip.io` hostname; both HTTPS / Let's Encrypt to port 80                                                                          |
| DNS                   | Cloudflare zone `bit-monkey.io`: proxied CNAME `px` → `levante.bit-monkey.io` (record `ff130cc7b27ca320f7b71a18008807cb`). Created via the Cloudflare API, not managed by OpenTofu |
| `APP_URL`             | Still the temporary `sslip.io` URL; see below                                                                                                                                      |
| Command               | Image default: Supervisor manages web, worker and scheduler                                                                                                                        |
| Stop grace period     | 70 seconds (`70000000000` nanoseconds in Swarm settings)                                                                                                                           |
| App storage           | Named volume `pixelforge-app-iu9qbn-storage` at `/app/storage`                                                                                                                     |
| Database              | Dokploy Postgres `f8po30xQyp7WTalVHVx5n` (`pixelforge-db-bb1mag`), Postgres 18                                                                                                     |
| Database connectivity | Private `dokploy-network`; no public database port                                                                                                                                 |
| Database storage      | Named volume `pixelforge-db-bb1mag-data` contains the actual PostgreSQL data directory                                                                                             |
| Deployment trigger    | Manual; CI publishes images but does not deploy them                                                                                                                               |

### Configuration and credentials

Use the application's **Docker provider** settings for its image and registry authentication. This deployment uses an app-specific GHCR credential; it does not change Levante's shared registry login. The current supplied token is broader than a pull requires: replace it with a dedicated classic PAT with `read:packages` and package access. Registry credentials must not be added to the application environment or build arguments.

Runtime configuration lives in Dokploy's application environment:

- `APP_ENV=production`, `APP_DEBUG=false`, the exact HTTPS `APP_URL`, and secure session cookies.
- Fresh stable `APP_KEY` and distinct Mercure publisher/subscriber keys, not development keys.
- The private production `DATABASE_URL`; database-backed queue, cache and sessions.
- No `TEST_DATABASE_URL`, Dokploy API token or GitHub token in the app container.
- No custom startup command or automatic startup migrations.

Local operator credentials and the initial production environment snapshot are under Git-/Docker-ignored `.private/`, with owner-only permissions. Never commit them. The initial snapshot is not automatically kept in sync with later Dokploy UI edits.

**Important current configuration distinction:** the running container has image generation disabled and `MAIL_MAILER=log`. Two provider credentials were subsequently added to Dokploy, but they match the local environment's credentials and have **not** been applied to the running service. They were preserved, not overwritten. Replace them with a separate production API key/signing secret before clicking Deploy/Redeploy; otherwise callbacks and per-key usage would mix environments. Configure actual mail delivery before relying on registration verification or password resets.

### Custom domain and Cloudflare

`px.bit-monkey.io` follows the existing Levante convention: a proxied CNAME to `levante.bit-monkey.io`. The zone uses Cloudflare **Full** SSL with Always Use HTTPS off, so Traefik's Let's Encrypt HTTP challenge works through the proxy. The origin has its own Let's Encrypt certificate for `px.bit-monkey.io`; changing the zone to Full (strict) would not break this route.

Create the DNS record before adding the Dokploy domain, so certificate issuance can succeed immediately. Adding an application domain writes only that app's Traefik route; it does not redeploy the app.

The app builds page URLs from the request host, so it works on `px.bit-monkey.io` while `APP_URL` still names the temporary host. Mercure tokens and the hub both derive from `APP_URL`, so they remain consistent. Switch `APP_URL` to `https://px.bit-monkey.io` when the app is next redeployed, so out-of-request URLs (queued mail and similar) and the provider webhook URL use the permanent host. Don't redeploy until the saved provider credentials are replaced with production-only values. Remove the `sslip.io` domain only after that switch is verified.

Because of the Cloudflare proxy, and because Levante's Traefik does not trust Cloudflare's forwarding headers, the app sees Cloudflare edge IPs rather than real client IPs. Login throttling keys on email plus that IP, and generation throttles key on the user, so this is acceptable for now. Revisit it before relying on per-IP limits.

## First deployment on an empty database

1. Configure the app's Docker source, a tested image digest, fresh production environment and storage mount. Leave the image's default command intact.
2. Deploy the existing managed Postgres service. Verify the database/role and private connectivity; inspect existing tables and take an appropriate backup before changing schema.
3. Pull the selected private image using its app-specific credentials. Do not overwrite a shared registry login.
4. Run migrations explicitly using a one-off container of the **new image before starting the supervised app**. A fresh database has no `cache` table: starting the worker first makes Supervisor's fail-fast protection restart the app before an interactive migration can reliably attach.
5. Deploy the application, verify its actual Docker health and supervised processes, then add/enable the HTTPS route to port 80.

For this server, the explicit migration command was:

```bash
IMAGE='ghcr.io/andycowan/pixelforge@sha256:7924f6b25b6c461c53b51744cbf71fef3feacbf7551d16dcd953e844fe5a7fdd'
ssh -p 2222 -o BatchMode=yes -o StrictHostKeyChecking=yes andy@levante \
  "sudo -n docker run --rm --pull=never --network dokploy-network \
    --env-file /dev/stdin --entrypoint php '$IMAGE' \
    artisan migrate --force --no-interaction" < .private/production.env
```

Use a verified, current production environment snapshot for that command. `--pull=never` requires that exact image to have been pulled already. The environment is streamed over SSH stdin, not printed or placed in shell arguments. The temporary container is removed after the command. Do not use `migrate:fresh`, reset the database, copy the development database or run the test suite against production.

The first deployment exposed the empty-schema ordering issue above; a one-off migration corrected it, and the existing Swarm service recovered without another deployment request. No permanent migration-at-startup behavior was added.

## Updating the image

Wait for the desired commit's **Build** workflow to pass. In the app's Docker source settings, select its tested commit tag or, preferably, its verified digest. Keep existing registry credentials, environment, domain, storage and one-replica settings unless deliberately changing them. Back up the database, apply the new image's pending migrations explicitly, deploy, then verify the actual running digest and public endpoints. A green build or Dokploy deployment record alone does not prove the app or worker is healthy.

Do not deploy `latest` blindly: it may differ from the last verified artifact. Do not run the broken parameterised CLI update commands against this server; CLI 0.30.5 detail requests returned HTTP 400. Supported REST endpoints worked on Dokploy 0.30.10. Prefer dedicated provider/environment operations; narrow direct updates must preserve unrelated fields and be read back.

## Verified on the initial deployment

- Exact selected digest, one running app replica and one Postgres replica.
- All ten migrations applied to the initially empty production database; correct database/role verified.
- Persistent database data directory and app storage volume; database port not publicly published.
- Docker health plus web, worker, scheduler and fail-fast listener running; zero failed jobs.
- Production mode, debug off, secure sessions, correct application URL and no management/test tokens in app env.
- Public CA-verified HTTPS: `/up`, `/login` and `/register` return 200; `/` reaches login; HTTP redirects to HTTPS. Verified on both the temporary host and on `px.bit-monkey.io` through Cloudflare.
- The origin presents a valid Let's Encrypt certificate for `px.bit-monkey.io` (checked directly against Levante with normal validation).
- Chromium renders the login page without console errors or disabled certificate validation.
- Private Mercure streaming through the public proxy: no-cookie request gets 401; an operator-created synthetic test topic receives a private update without compression. This created no user, generation or financial records. It is a transport/authentication check, not an authenticated product journey. Repeated through Cloudflare on `px.bit-monkey.io`: no-cookie 401; private update delivered unbuffered in about 0.7 s, the same as the direct route.
- Unconfigured webhook returns 503 rather than accepting unsigned evidence.

## Before full product use

- Replace the unapplied shared provider credentials with a **production-only** Pixelforge API key and signing secret, and configure its webhook URL as this deployment's `/webhooks/pixelforge` endpoint. Verify a genuine signed callback after redeployment.
- Configure real mail delivery, then test registration, verification, sign-in and password reset.
- Intentionally commit/publish the remaining local application features if they should be included; this deployment does not contain them.
- Replace the broad registry PAT with a read-only pull credential.
- Configure scheduled Postgres backups to an approved destination and prove a restore. The small private baseline dump taken before initial migration is not a backup schedule or restore proof.
- Verify a real generation, exact settlement, live history and per-environment provider reconciliation; retain reviewer-accessible sample generations.
- Switch `APP_URL` to `https://px.bit-monkey.io` at the next redeploy, configure the production provider webhook as `https://px.bit-monkey.io/webhooks/pixelforge`, verify, then remove the temporary `sslip.io` domain.
