# Deployment

Production runs at <https://px.bit-monkey.io> on a self-hosted [Dokploy](https://dokploy.com) server (Docker Swarm), behind Cloudflare.

| Setting      | Value                                                                                                         |
| ------------ | ------------------------------------------------------------------------------------------------------------- |
| Source       | Docker image `ghcr.io/andycowan/pixelforge:latest`, built and tested by CI (not built on the server)          |
| Replicas     | One app container, `linux/amd64`, internal HTTP port 80                                                       |
| Processes    | Entrypoint migrates (`MIGRATE_ON_START=true`), then Supervisor runs web, queue worker and scheduler           |
| Stop grace   | 70 seconds, so in-flight jobs can finish                                                                      |
| App storage  | Named volume at `/app/storage`                                                                                |
| Database     | Separate Dokploy-managed Postgres 18 service on the private Docker network; no public port; named data volume |
| Domain / TLS | Proxied Cloudflare CNAME to the server; Traefik serves a Let's Encrypt certificate for `px.bit-monkey.io`     |
| Deploys      | Automatic after every successful `main` build (below)                                                         |

## Configuration

Runtime configuration lives only in Dokploy's application environment, never in Git, the image or build arguments:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://px.bit-monkey.io`, secure session cookies.
- A production-only `APP_KEY` and distinct Mercure publisher/subscriber keys.
- The private `DATABASE_URL`; database-backed queue, cache and sessions.
- `MIGRATE_ON_START=true`.
- A **production-only** Pixelforge API key and signing secret. Its webhook URL in Pixelforge is `https://px.bit-monkey.io/webhooks/pixelforge`. Each environment has its own key, so `GET /v1/usage` for this key matches the deployed app's records exactly.
- `MAIL_MAILER=resend`, `RESEND_API_KEY`, and a sender on a Resend-verified domain.
- No test database URL, Dokploy API key or GitHub token.

Registry pull credentials are set on the app's Docker source in Dokploy, not in its environment. Use a pull-only (`read:packages`) token.

Cloudflare proxies the domain, and the server's Traefik does not trust Cloudflare's forwarding headers, so the app sees Cloudflare edge IPs. Login throttling keys on email plus that IP and generation throttles key on the user, so this is acceptable for now; revisit before relying on per-IP limits.

## Automatic deployment

Every successful `main` build deploys itself:

1. CI publishes `:latest` and `:sha-<commit>`, with the build identity `<commit>.<run id>.<attempt>` baked in as `APP_REVISION`.
2. The **Deploy to production** job POSTs the app's Dokploy deploy webhook (`DOKPLOY_DEPLOY_WEBHOOK_URL` in the GitHub `production` environment). That URL can only redeploy this app.
3. Swarm starts the new container before stopping the old one, rolling back on failure. The new container runs `php artisan migrate --force`, then starts Supervisor. A failed migration or health check leaves the previous container serving.
4. CI polls `https://px.bit-monkey.io/up` until its `X-App-Revision` header equals that build identity, and fails after 10 minutes. Including the run makes a same-commit rebuild (for example a base-image security update via a manual run) wait for its own container rather than passing against the old one. Dokploy reports webhook deployments as done even when the new container never starts, so this check is the real signal.

Migrations must be additive: during the overlap the previous release runs against the migrated schema. A destructive change needs a separately planned release. Production prohibits `migrate:fresh`, `db:wipe` and similar destructive commands.

**Rollback:** set the app's Docker image to a known-good `ghcr.io/andycowan/pixelforge:sha-<commit>` and deploy. Pushes to `main` will not override it until the image is set back to `:latest`. Migrations are not rolled back automatically; restore from a backup if a schema change must be undone.

**Backups:** scheduled Postgres backups are not configured yet. Take a manual `pg_dump` before any release with risky migrations.

## Remaining before real use

- Scheduled Postgres backups to an approved destination, with a tested restore.
- Remove the temporary `sslip.io` fallback domain.
- Revisit client-IP handling behind Cloudflare.
