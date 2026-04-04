# Johnny

**Johnny** packages [Garage](https://garagehq.deuxfleurs.fr/) for **Ubuntu 24.04 LTS** with:

- One-shot **autoinstall** (Garage layout, default S3 keys, **Caddy + Let’s Encrypt**, nightly cron).
- A **`johnny` CLI** that wraps Garage and adds **`johnny backup`** to manage **SFTP backup targets** (IP, port, user, password).
- A **nightly job** (primary VPS only) that, for **each** configured target, syncs **every Garage bucket** into a dated tree:

  `remote_base_path/YYYY-MM-DD/<bucket-name>/…`

  then **deletes** dated folders older than **`retention_days`** (default **90**).

The storage engine is still upstream **Garage** (`garage` binary). Johnny adds paths under `/etc/johnny` and `/var/lib/johnny`, region **`johnny`**, and automation around backups and TLS.

## How it fits together

```mermaid
flowchart TB
  subgraph primary [Primary VPS]
    G[Garage S3 API]
    C[Caddy TLS]
    CRON[Cron nightly]
    G --> C
    CRON --> RCLONE[rclone sync]
  end
  subgraph targets [Backup servers SFTP :22]
    T1[vps-us]
    T2[vps-eu]
  end
  RCLONE --> T1
  RCLONE --> T2
```

- **Orchestration runs only on the primary VPS** (push). Backup servers only need SSH/SFTP and disk; they do **not** need Garage unless you also use them for something else.
- **Default S3 credentials** for applications are written to `/etc/johnny/credentials/default-s3.env` during autoinstall.
- **Internal** credentials for the nightly job (`johnny-backup` key) live in `/etc/johnny/credentials/backup-internal-s3.env` (local `http://127.0.0.1:3900` only).

## Requirements

- Ubuntu **24.04** on the primary VPS.
- **DNS** pointing your chosen hostname to this server **before** autoinstall (Let’s Encrypt must validate the domain).
- For each backup host: **SSH/SFTP** reachable from the primary (port configurable, default **22**), with a user that can write under your chosen remote path (default base folder: `johnny-backups` on the SFTP home or as resolved by the server).

## One-shot autoinstall (recommended)

On a fresh VPS:

```bash
git clone git@github.com:andreapollastri/johnny.git
cd johnny
sudo bash scripts/autoinstall.sh
```

You will be prompted to confirm, then for the **public domain** used for the S3 HTTPS endpoint (e.g. `storage.example.com`). The script:

1. Installs dependencies (**python3**, **caddy**, **rclone**, …) and runs `scripts/install.sh`.
2. Starts Garage, runs **single-node layout** (`bootstrap-single-node.sh`).
3. Creates bucket **`default`**, API keys **`johnny-default`** (for apps) and **`johnny-backup`** (for nightly sync), and writes env files under `/etc/johnny/credentials/`.
4. Writes **`/etc/caddy/johnny.caddy`** and imports it from `/etc/caddy/Caddyfile` (existing file is backed up).
5. Creates **`/etc/johnny/backup.json`** with **`retention_days`: 90** and empty **`targets`**.
6. Installs **`/etc/cron.d/johnny-nightly`** (default **03:00** server time).

After install, use your app credentials from:

`source /etc/johnny/credentials/default-s3.env`

Then e.g. `aws s3 ls` with `AWS_ENDPOINT_URL` set to `https://your-domain`.

## Manual install (without autoinstall)

```bash
sudo bash scripts/install.sh
sudo systemctl start johnny-garage
sudo bash scripts/bootstrap-single-node.sh
```

Configure TLS yourself (see `config/nginx-johnny-s3.conf.example` or `config/caddy-johnny.caddy.example`). Create `/etc/johnny/credentials/*.env` and keys with `sudo -u johnny johnny key create …` as needed.

## `johnny` CLI

Garage commands pass through:

```bash
sudo johnny status
sudo johnny bucket list
sudo -u johnny johnny bucket create my-bucket
```

Backup targets (SFTP) — **run as root**:

| Command                              | Description                                                                                            |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------ |
| `sudo johnny backup list`            | List targets and show retention / remote base path                                                     |
| `sudo johnny backup create NAME`     | Interactive prompts for host, port, user, password (or use `--host`, `--port`, `--user`, `--password`) |
| `sudo johnny backup delete NAME`     | Remove a target                                                                                        |
| `sudo johnny backup update NAME`     | Change fields; use `-p` to prompt for a new password                                                   |
| `sudo johnny backup set-retention N` | Keep dated folders not older than **N** days (default **90**)                                          |
| `sudo johnny backup run`             | Run the same job as cron immediately                                                                   |

Configuration file: **`/etc/johnny/backup.json`** (mode `600`). Passwords are stored **in plain text** — protect this file. You can edit **`remote_base_path`** here (default **`johnny-backups`**, created under the SFTP user’s home unless the server chroots elsewhere).

### Remote layout after backups

On each SFTP target you should see folders like:

```text
johnny-backups/
  2026-01-23/
    default/
    my-bucket/
  2026-01-24/
    default/
    my-bucket/
```

Older date folders are removed when **`date < today - retention_days`**.

## Nightly job details

- Implemented in **`scripts/johnny-nightly-backup.py`** (installed under `/usr/local/share/johnny/scripts/`).
- Uses **rclone** to sync `johnny_local:<bucket>` → `sftp:<remote>:<base>/<date>/<bucket>/`.
- Ensures key **`johnny-backup`** has **read** permission on every bucket before syncing (best-effort `bucket allow`).
- **Retention** uses the dates in folder names (`YYYY-MM-DD`) under `remote_base_path`.

Logs: **`/var/log/johnny-nightly.log`**.

## Security notes

- Restrict **`/etc/johnny`** (especially `backup.json` and `credentials/`).
- Prefer **SSH keys** on backup servers in the long term; Johnny currently documents **password** auth for simplicity.
- Firewall: expose **443** (and **80** if needed for ACME), **22** only from trusted IPs where possible.
- `rclone sync` can **delete** extra files on the destination under each dated prefix; read the [rclone sync](https://rclone.org/commands/rclone_sync/) docs.

## Optional: S3-to-S3 replication

Older scripts **`backup-replicate.sh`** and **`replicate-run.sh`** remain for Garage-to-Garage replication over **S3**, if you still want that in addition to SFTP backups.

## License

MIT — see [LICENSE](LICENSE).
