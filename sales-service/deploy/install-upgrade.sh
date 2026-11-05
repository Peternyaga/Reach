#!/usr/bin/env bash
set -Eeuo pipefail

# Run from an extracted Fieldwork Reach release on the HostAfrica account.
domain=/home/supashop/domains/reach.vumbualabs.com
package=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
private="$domain/fieldwork"
public="$domain/public_html"
config="$private/config.local.php"

if [[ ! -f "$config" || ! -d "$public" || ! -d "$private/backend" ]]; then
  echo 'Existing Fieldwork installation or private configuration was not found; no files changed.' >&2
  exit 1
fi
if [[ ! -f "$package/public_html/.htaccess" || ! -f "$package/public_html/index.html" || ! -f "$package/fieldwork/backend/migrate.php" ]]; then
  echo 'Release archive is incomplete; no files changed.' >&2
  exit 1
fi
for command in php cp mkdir date grep cat; do command -v "$command" >/dev/null || { echo "Missing $command" >&2; exit 1; }; done

stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup="$domain/fieldwork-backups/$stamp"
mkdir -p -- "$backup"
chmod 700 "$backup"
echo "Backing up production to $backup"
php "$package/backup-database.php" "$config" "$backup/database.sql"
cp -a -- "$public" "$backup/public_html"
cp -a -- "$private/backend" "$backup/backend"
if [[ -f "$private/var/mail.key" ]]; then cp -a -- "$private/var/mail.key" "$backup/mail.key"; fi

# Gate the entire website/MCP while files and membership schema are changing.
# This flag is intentionally retained on failure, including a restored older backend.
maintenance="$public/.reach-maintenance"
maintenance_gate() {
  if ! grep -q 'reach-maintenance' "$public/.htaccess"; then
    { printf '%s\n' 'RewriteEngine On' 'RewriteCond %{DOCUMENT_ROOT}/.reach-maintenance -f' 'RewriteRule ^ - [R=503,L]'; cat "$public/.htaccess"; } > "$backup/maintenance.htaccess"
    cp -- "$backup/maintenance.htaccess" "$public/.htaccess"
  fi
}
changed=0
restore_files() {
  status=$?
  if (( status != 0 && changed == 1 )); then
    echo 'Install failed; restoring the previous site and backend files.' >&2
    cp -a -- "$backup/public_html/." "$public/" || true
    cp -a -- "$backup/backend/." "$private/backend/" || true
    touch "$maintenance"
    maintenance_gate || true
    echo 'Maintenance remains enabled. An older backend must not serve restricted memberships.' >&2
    echo "Database backup: $backup/database.sql (schema changes are not automatically rolled back)." >&2
  fi
  exit "$status"
}
trap restore_files EXIT

echo 'Updating backend and running repeatable migration...'
changed=1
touch "$maintenance"
maintenance_gate
cp -a -- "$package/fieldwork/backend/." "$private/backend/"
mkdir -p -- "$private/var"
chmod 700 "$private/var"
if [[ ! -f "$private/var/mail.key" ]]; then
  umask 077
  php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;' > "$private/var/mail.key"
fi
chmod 600 "$private/var/mail.key"
php "$private/backend/migrate.php"
echo 'Updating website...'
cp -a -- "$package/public_html/." "$public/"
php -l "$private/backend/hosted-router.php" >/dev/null
php -l "$private/backend/router.php" >/dev/null
rm -f -- "$maintenance"
echo "Release installed. Backup: $backup"
echo 'Check https://reach.vumbualabs.com/ and the Codex MCP connection.'
