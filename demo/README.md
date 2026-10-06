# Johnny local demo

A throwaway, fully local Johnny stack for trying the panel, developing it, and regenerating the
screenshots used in the README and on the website. Everything is real: a single-node **Garage**
cluster, about 1 GB of sample objects uploaded over the S3 API, keys and permissions created with the
Garage CLI, and a tenant provisioned through the panel's own REST API.

Nothing is installed system-wide. Garage data, credentials and the panel database live in
`demo/.state/`; the panel reads its settings from `panel/.env.demo` (both are git-ignored).

## Requirements

- [Garage](https://garagehq.deuxfleurs.fr/) 2.x in `PATH` (macOS: `brew install garage`; elsewhere use the
  release binary and set `GARAGE_BIN`)
- PHP 8.5 and Composer
- `curl` (7.75+, for `--aws-sigv4`) and `openssl`
- For screenshots only: Node.js 20+ and Google Chrome

## Start and stop

```bash
bash demo/up.sh            # first run: boots Garage, seeds data, starts the panel
bash demo/down.sh          # stop Garage and the panel, keep the data
bash demo/down.sh --wipe   # stop and delete everything (next up.sh starts from scratch)
```

`up.sh` is idempotent: run it again after `down.sh` and the same data comes back.

| What | Where |
|---|---|
| Panel | <http://127.0.0.1:8099> — `demo@johnny.test` / `johnny-demo` |
| S3 endpoint | `http://127.0.0.1:13900`, region `johnny`, path-style |
| App credentials | `demo/.state/credentials/default-s3.env` |
| Tenant credentials (from the API) | `demo/.state/credentials/tenant-acme.json` |
| Garage CLI | `demo/.state/bin/johnny bucket list` |
| Logs | `demo/.state/logs/` |

Ports avoid a real install (3900–3903) and can be changed with `JOHNNY_DEMO_S3_PORT`,
`JOHNNY_DEMO_RPC_PORT`, `JOHNNY_DEMO_ADMIN_PORT` and `JOHNNY_DEMO_PANEL_PORT`.

## What gets seeded

| Bucket | Content | Keys with access |
|---|---|---|
| `default` | avatars, contracts, a quarterly report | system keys |
| `website-assets` | HTML, CSS/JS bundles, images, fonts | `web-frontend` (R), `ci-pipeline` (RWO) |
| `media-library` | product videos, 30 photos, podcast episodes (~500 MB) | `web-frontend` (R) |
| `db-backups` | a week of Postgres dumps + a Redis snapshot (~430 MB) | `backup-agent` (RW) |
| `invoices-2026` | 140 PDF invoices in monthly folders | `billing-service` (RW) |
| `app-logs` | nginx, Laravel and worker logs | `log-shipper` (W), `analytics-etl` (R) |
| `customer-exports` | CSV exports | `billing-service` (RW), `analytics-etl` (R) |
| `tenant-acme` | created with `POST /api/buckets/provision`, files uploaded with the returned key | its own key (RWO) |

Object contents are random bytes; names, folders and sizes are realistic and deterministic, so
screenshots look the same on every machine.

## How the panel talks to Garage here

On a server the panel runs `sudo -n -u johnny /usr/local/bin/johnny …`. The demo points
`JOHNNY_CLI_BINARY` at `demo/.state/bin/johnny` (a two-line wrapper around `garage -c …`) and leaves
`JOHNNY_CLI_SUDO_USER` empty, so the exact same code paths run without root.

## Regenerate the screenshots

```bash
bash demo/up.sh
npm --prefix demo install
node demo/screenshots.mjs            # writes docs/img/*.jpg
```

The script signs in, captures every page in light mode (plus dashboard and file browser in dark
mode), creates and deletes a temporary key to show the one-time credentials card, and starts and
cancels a 2FA enrolment to show the QR code. Set `CHROME_PATH` to use a Chromium build instead of
Google Chrome.
