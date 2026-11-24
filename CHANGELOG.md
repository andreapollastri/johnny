# Changelog

All notable changes to Johnny. Versions follow the release tags; the updater installs them on the stable channel.

## 2.0.2 — 2026-11-24

- **Updates started from the panel.** The *Update* button starts the update with `systemd-run`, where root has no `HOME`: the install steps stopped at once with `fatal: $HOME not set` (`git config --global`) and the previous build was put back. `scripts/update.sh` and `scripts/johnny-update` now take `HOME` from the user's entry in `/etc/passwd` when it is missing. The nightly update (cron) and `sudo johnny update apply` from a shell already had one.

## 2.0.1 — 2026-11-24

- No changes to Johnny itself: `VERSION`, changelog and documentation only. Published right after 2.0.0, so that servers have a newer release to update to.
- The `1.3.0` tag pointed at the code of 2.0.0, whose `VERSION` says `2.0.0`: the updater refused it (`the VERSION file of 1.3.0 says 2.0.0`). `1.3.0` is not a Johnny version; 2.0.0 and 2.0.1 install normally.

## 2.0.0 — 2026-11-24

A new panel: a new look, data that moves in and out of Johnny, keys with scopes, and an API that covers all of it.

### Panel

- **New design.** Neutral and minimal: white surfaces, hairline borders, one typeface (Inter), colour only for state. The sidebar is replaced by a light bar on top, with the pages, *Jump to…*, updates, theme and an account menu. Light and dark themes.
- **Jump to…** (`Ctrl`/`⌘` + `K`, or `/`): any page or bucket by name.
- **Dashboard:** stored data, disk space, largest buckets, latest transfers.
- **File browser:** drag and drop of files and whole folders; resumable uploads in chunks, with no `php.ini` limits to raise; previews of images, video, audio, PDF and text; rename and move; bulk delete; share links (presigned URLs, up to 7 days); filter by name prefix.
- **Bucket settings:** quotas (size, number of objects), expiry rules (delete after N days, optionally under one folder; clean up unfinished multipart uploads), CORS.
- **Connect tab:** ready-to-paste configuration for Laravel, the AWS CLI, rclone, Python and Node.js.
- Confirmations, toasts and dialogs of the panel's own instead of the browser's.

### Access keys and scopes

- Every key gets one **scope** per bucket: *read only*, *read and write*, *write only*, *full control*. Garage enforces read, write and owner and nothing finer: a key that can write can also overwrite and delete.
- Keys for **chosen buckets**, or for **all buckets**, including the ones created later (from the panel, the API, or within five minutes when created elsewhere: `php artisan johnny:keys:apply`).
- A page per key: its buckets and scopes, revoke, or one scope everywhere.
- From a bucket: create a key that works only there, or give an existing key a scope.

### Transfers and scheduled syncs

- **Connections** to other S3 providers (AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Wasabi, Hetzner, Scaleway, MinIO, another Johnny, any S3 endpoint) and to **SFTP servers** (password or private key; the host key is pinned the first time the server answers). Secrets are stored encrypted.
- **Import** into Johnny and **export** out of it: a whole bucket or one folder, copy or mirror, dry run, several buckets at once. Run by rclone in the background, with live progress, speed, log, cancel and retry.
- **Scheduled syncs**, in or out: every minute, 5/15/30 minutes, hour, 6/12 hours, day, week, or any number of minutes, hours or days. Runs never overlap; pause, run now, change, delete. The latest 30 runs are kept.

### API

- From 11 to 38 endpoints, documented in the OpenAPI spec (`/api/openapi.yaml`, Swagger UI at `/api/docs`):
  - bucket settings: `GET /api/buckets/{bucket}/settings`, `PUT …/quotas`, `…/cors`, `…/lifecycle`; `GET /api/buckets/{bucket}` adds size, objects, quotas and the scope of each key;
  - scopes and global keys: `GET /api/scopes`, `POST /api/keys` with `access`, `scope` and `buckets`, `PUT|DELETE /api/keys/{keyId}/all-buckets`, `scope` on `POST /api/buckets/{bucket}/keys`, and the buckets of a key on `GET /api/keys/{keyId}`;
  - `GET /api/providers`, `/api/connections` (CRUD, `check`, `buckets`);
  - `/api/transfers` (list, start, progress and log, `cancel`, `retry`, remove);
  - `/api/syncs` (CRUD, pause and resume, `run`).
- `POST /api/buckets/provision` takes an optional `scope` for the dedicated key (default `full`, as before).

### Server

- Migration `009` installs `/etc/cron.d/johnny-panel`, which runs the panel's scheduler every minute (scheduled syncs, keys for all buckets). `scripts/install-panel.sh` installs it on new servers.
- New `panel/.env` settings: `GARAGE_INTERNAL_ENDPOINT`, `JOHNNY_RCLONE_BINARY`, `JOHNNY_PHP_BINARY`, `JOHNNY_TRANSFER_CONCURRENCY`.

- **PHP 8.6.** New servers get PHP 8.6. Migration `010` moves existing servers from 8.5 with `scripts/switch-php.sh`: same extensions, FPM pools carried over, the panel checked on 8.6 before Caddy and `php` are switched; the 8.5 packages stay. On any problem the server stays on PHP 8.5, still supported. CI runs on 8.5 and 8.6.

- **PHP from packages.sury.org.** The ondrej/php PPA is being retired: it has no packages for Ubuntu 26.04, and on 22.04/24.04 its new Label stops `apt-get update`. `install-panel.sh` and `switch-php.sh` take PHP from [packages.sury.org/php](https://packages.sury.org/php/README.txt) (same maintainer, same packages) and remove the PPA from the apt sources (`scripts/lib/johnny-php-repo.sh`). Migration `011` moves existing servers over and tries the PHP 8.6 switch again where it could not run.

- **Dependencies** refreshed within their constraints: Laravel 13.43, Symfony 8.1.11 (security fixes in http-foundation, http-kernel, routing, serializer, mime, filesystem), nette for PHP 8.6. `composer audit` is clean.

### Upgrading from 1.2.x

Nothing to do: the nightly update (or *Update* on the Updates page) migrates the panel's database and runs migrations `009` (scheduler), `010` (PHP 8.6) and `011` (PHP from packages.sury.org). Existing buckets, keys and permissions are not touched.

## 1.2.1

- Composer dependency updates.

## 1.2.0

- Updater with release channels (stable, beta), nightly updates of Johnny and of the server, manual and forced updates, automatic rollback; the panel's Updates page.
