#!/usr/bin/env bash
# Fill the demo cluster with realistic buckets, keys, permissions and ~1 GB of objects.
# Called by demo/up.sh on the first run; needs Garage and the panel to be running.
# Objects are uploaded over the S3 API with curl's SigV4 support (no AWS CLI needed).
set -euo pipefail

DEMO_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$DEMO_DIR/.." && pwd)"
# shellcheck source=demo/lib.sh
source "$DEMO_DIR/lib.sh"
# shellcheck source=/dev/null
source "$STATE/credentials/default-s3.env"

TMP="$(mktemp -d "${TMPDIR:-/tmp}/johnny-seed.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
RANDOM=2026 # same sizes on every machine → comparable screenshots

KB=1024
MB=$((1024 * 1024))

# put BUCKET KEY SIZE [CONTENT_TYPE] [ACCESS_KEY SECRET_KEY]
# SIZE is a byte count or "MIN:MAX" for a random size in that range. Sizes are drawn here,
# not in $(…), so $RANDOM advances in this shell and the sequence stays reproducible.
put() {
  local bucket="$1" key="$2" bytes="$3" type="${4:-application/octet-stream}"
  local ak="${5:-$AWS_ACCESS_KEY_ID}" sk="${6:-$AWS_SECRET_ACCESS_KEY}"
  if [[ "$bytes" == *:* ]]; then
    local min="${bytes%%:*}" max="${bytes##*:}"
    bytes=$(( min + (RANDOM * 32768 + RANDOM) % (max - min) ))
  fi
  head -c "$bytes" /dev/urandom >"$TMP/obj"
  curl -fsS -o /dev/null --aws-sigv4 "aws:amz:johnny:s3" --user "$ak:$sk" \
    -H "Content-Type: $type" -T "$TMP/obj" "$S3_URL/$bucket/$key"
}

bucket() {
  "$JOHNNY" bucket info "$1" >/dev/null 2>&1 || "$JOHNNY" bucket create "$1" >/dev/null
  # Same defaults the panel applies to new buckets: app key rw+owner, backup key read.
  "$JOHNNY" bucket allow --read --write --owner "$1" --key johnny-default >/dev/null
  "$JOHNNY" bucket allow --read "$1" --key johnny-backup >/dev/null
}

key() {
  "$JOHNNY" key info "$1" >/dev/null 2>&1 || "$JOHNNY" key create "$1" >/dev/null
}

# allow BUCKET KEY FLAGS...
allow() {
  local b="$1" k="$2"; shift 2
  "$JOHNNY" bucket allow "$@" "$b" --key "$k" >/dev/null
}

log "Creating buckets"
for b in website-assets media-library db-backups invoices-2026 app-logs customer-exports; do
  bucket "$b"
done

log "Uploading objects to default"
for i in $(seq 1001 1024); do
  put default "uploads/avatars/user-$i.jpg" "$((40 * KB)):$((180 * KB))" image/jpeg
done
for name in master-services-agreement nda-acme-corp dpa-2026 sla-premium onboarding-checklist; do
  put default "uploads/documents/$name.pdf" "$((300 * KB)):$((1200 * KB))" application/pdf
done
put default "exports/report-2026-Q3.xlsx" $((2400 * KB)) application/vnd.openxmlformats-officedocument.spreadsheetml.sheet

log "Uploading objects to website-assets"
put website-assets index.html $((24 * KB)) text/html
put website-assets robots.txt 68 text/plain
put website-assets sitemap.xml $((18 * KB)) application/xml
put website-assets css/app.4f2a91.css $((182 * KB)) text/css
put website-assets css/vendor.b81c2e.css $((318 * KB)) text/css
put website-assets js/app.9c1d77.js $((644 * KB)) text/javascript
put website-assets js/vendor.a1b2c3.js $((1430 * KB)) text/javascript
put website-assets js/charts.e5f6a7.js $((412 * KB)) text/javascript
for name in hero-home hero-pricing hero-docs feature-backups feature-panel feature-api; do
  put website-assets "img/$name.webp" "$((180 * KB)):$((900 * KB))" image/webp
done
put website-assets img/og-cover.png $((1100 * KB)) image/png
put website-assets img/logo.svg $((6 * KB)) image/svg+xml
put website-assets fonts/inter-var.woff2 $((322 * KB)) font/woff2
put website-assets fonts/jetbrains-mono.woff2 $((112 * KB)) font/woff2

log "Uploading objects to media-library (≈500 MB)"
put media-library videos/product-tour-2026.mp4 $((148 * MB)) video/mp4
put media-library videos/onboarding-webinar.mp4 $((96 * MB)) video/mp4
put media-library videos/teaser-15s.mov $((38 * MB)) video/quicktime
for i in $(seq 4101 4130); do
  put media-library "photos/2026/09/IMG_$i.jpg" "$((2 * MB)):$((6 * MB))" image/jpeg
done
put media-library podcasts/episode-41.mp3 $((54 * MB)) audio/mpeg
put media-library podcasts/episode-42.mp3 $((61 * MB)) audio/mpeg

log "Uploading objects to db-backups (≈430 MB)"
for d in 01 02 03 04 05 06 07; do
  put db-backups "postgres/app-2026-10-$d.sql.gz" "$((58 * MB)):$((64 * MB))" application/gzip
done
put db-backups redis/dump-2026-10-07.rdb.gz $((12 * MB)) application/gzip

log "Uploading objects to invoices-2026"
n=1
for m in 01 02 03 04 05 06 07 08 09 10; do
  for _ in $(seq 1 14); do
    put invoices-2026 "2026/$m/INV-2026-$(printf '%04d' "$n").pdf" "$((60 * KB)):$((220 * KB))" application/pdf
    n=$((n + 1))
  done
done

log "Uploading objects to app-logs"
for d in 01 02 03 04 05 06 07; do
  put app-logs "nginx/access-2026-10-$d.log.gz" "$((4 * MB)):$((9 * MB))" application/gzip
  put app-logs "laravel/laravel-2026-10-$d.log" "$((1 * MB)):$((3 * MB))" text/plain
  put app-logs "worker/queue-2026-10-$d.log" "$((200 * KB)):$((900 * KB))" text/plain
done

log "Uploading objects to customer-exports"
for name in customers orders subscriptions refunds churn-cohorts mrr-by-plan usage-by-tenant nps-responses; do
  put customer-exports "2026-10/$name.csv" "$((1 * MB)):$((6 * MB))" text/csv
done

log "Creating application keys and permissions"
for k in web-frontend billing-service backup-agent ci-pipeline log-shipper analytics-etl legacy-cms; do
  key "$k"
done
allow website-assets web-frontend --read
allow media-library web-frontend --read
allow invoices-2026 billing-service --read --write
allow customer-exports billing-service --read --write
allow db-backups backup-agent --read --write
allow website-assets ci-pipeline --read --write --owner
allow app-logs log-shipper --write
allow app-logs analytics-etl --read
allow customer-exports analytics-etl --read

log "Issuing panel API tokens"
token() { artisan johnny:api-token "$DEMO_EMAIL" --name="$1" | head -n 1; }
ONBOARDING_TOKEN="$(token 'Tenant onboarding')"
CI_TOKEN="$(token 'CI pipeline')"
token 'Terraform' >/dev/null

log "Provisioning tenant-acme through the panel API"
curl -fsS -X POST "$PANEL_URL/api/buckets/provision" \
  -H "Authorization: Bearer $ONBOARDING_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" -d '{"bucket":"tenant-acme"}' >"$STATE/credentials/tenant-acme.json"

TENANT_AK="$(php -r '$j = json_decode(file_get_contents($argv[1]), true); echo $j["credentials"]["access_key_id"];' "$STATE/credentials/tenant-acme.json")"
TENANT_SK="$(php -r '$j = json_decode(file_get_contents($argv[1]), true); echo $j["credentials"]["secret_access_key"];' "$STATE/credentials/tenant-acme.json")"

# Upload with the tenant's own key: proves the provisioned credentials work end to end.
for name in logo.png brand-guidelines.pdf price-list-2026.xlsx; do
  put tenant-acme "assets/$name" "$((200 * KB)):$((3 * MB))" application/octet-stream "$TENANT_AK" "$TENANT_SK"
done

# Exercise the CI token so "Last used" is populated in Settings.
curl -fsS -o /dev/null "$PANEL_URL/api/buckets" -H "Authorization: Bearer $CI_TOKEN" -H "Accept: application/json"

log "Seed complete"
"$JOHNNY" bucket list
