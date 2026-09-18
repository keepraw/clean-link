#!/usr/bin/env bash
set -Eeuo pipefail

INSTALL_DIR="/opt/clean-link"
SERVICE_NAME="clean-link"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
DATA_DIR="/var/lib/clean-link"
REPO_BRANCH="${CLEAN_LINK_BRANCH:-main}"
ARCHIVE_URL="${CLEAN_LINK_ARCHIVE_URL:-https://github.com/keepraw/clean-link/archive/refs/heads/${REPO_BRANCH}.tar.gz}"
STAGING_DIR=""
BACKUP_DIR=""
SWAPPED="false"
SERVICE_BACKUP=""

say() { printf '%s\n' "$*"; }
fail() { say "ERROR: $*" >&2; exit 1; }

if [ "${EUID:-$(id -u)}" -eq 0 ]; then
    SUDO=""
elif command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
    sudo -v || fail "sudo access is required."
else
    fail "Run as root or install sudo first."
fi
run_root() { if [ -n "$SUDO" ]; then sudo "$@"; else "$@"; fi; }

cleanup() {
    local status=$?
    if [ "$status" -ne 0 ]; then
        say "Update failed." >&2
        if [ "$SWAPPED" = "true" ] && [ -d "$BACKUP_DIR" ]; then
            run_root systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
            if [ -d "$INSTALL_DIR" ]; then run_root rm -rf -- "$INSTALL_DIR"; fi
            run_root mv "$BACKUP_DIR" "$INSTALL_DIR"
            if [ -n "$SERVICE_BACKUP" ] && [ -f "$SERVICE_BACKUP" ]; then
                run_root cp "$SERVICE_BACKUP" "$SERVICE_FILE"
                run_root systemctl daemon-reload >/dev/null 2>&1 || true
            fi
            run_root systemctl start "$SERVICE_NAME" >/dev/null 2>&1 || true
            say "The previous version was restored." >&2
        fi
        if [ -n "$STAGING_DIR" ] && [ -d "$STAGING_DIR" ]; then
            run_root rm -rf -- "$STAGING_DIR"
        fi
        if [ -n "$SERVICE_BACKUP" ] && [ -f "$SERVICE_BACKUP" ]; then
            rm -f -- "$SERVICE_BACKUP"
        fi
    fi
}
trap cleanup EXIT

[ -f "$INSTALL_DIR/.env" ] || fail "No Clean Link configuration found at $INSTALL_DIR/.env."
[ -f "$SERVICE_FILE" ] || fail "The Clean Link systemd service is missing."
command -v curl >/dev/null 2>&1 || fail "curl is required."
command -v tar >/dev/null 2>&1 || fail "tar is required."
php_bin="$(command -v php || true)"
[ -n "$php_bin" ] || fail "php is required."

STAGING_DIR="$(run_root mktemp -d "${INSTALL_DIR}.update.XXXXXX")"
say "Downloading the latest Clean Link version..."
run_root bash -c 'set -o pipefail; curl -fsSL "$1" | tar -xz --strip-components=1 -C "$2"' bash "$ARCHIVE_URL" "$STAGING_DIR"
run_root cp "$INSTALL_DIR/.env" "$STAGING_DIR/.env"
run_root "$php_bin" "$STAGING_DIR/scripts/migrate-env.php" "$STAGING_DIR/.env"
say "Validating the update..."
run_root find "$STAGING_DIR/app" "$STAGING_DIR/public" "$STAGING_DIR/tests" "$STAGING_DIR/scripts" \
    -type f -name '*.php' -exec "$php_bin" -l '{}' ';' >/dev/null
run_root "$php_bin" "$STAGING_DIR/tests/run.php"
run_root chown -R root:root "$STAGING_DIR"
# mktemp creates the staging directory with mode 0700. The service runs as the
# unprivileged clean-link user, so it must be able to enter the directory after
# it is renamed to INSTALL_DIR.
run_root chmod 0755 "$STAGING_DIR"
run_root chown root:"$SERVICE_NAME" "$STAGING_DIR/.env"
run_root chmod 640 "$STAGING_DIR/.env"
run_root chmod 755 "$STAGING_DIR/install.sh" "$STAGING_DIR/update.sh"

app_port="$(run_root sed -n 's/^APP_PORT=//p' "$STAGING_DIR/.env" | tail -n 1)"
app_port="${app_port:-3000}"
app_port="${app_port#\'}"; app_port="${app_port%\'}"
app_port="${app_port#\"}"; app_port="${app_port%\"}"
case "$app_port" in ''|*[!0-9]*) fail "APP_PORT in .env is invalid." ;; esac
if [ "$app_port" -lt 1024 ] || [ "$app_port" -gt 65535 ]; then
    fail "APP_PORT in .env must be from 1024 to 65535."
fi
app_domain="$(run_root sed -n 's/^APP_DOMAIN=//p' "$STAGING_DIR/.env" | tail -n 1)"
app_domain="${app_domain#\'}"; app_domain="${app_domain%\'}"
app_domain="${app_domain#\"}"; app_domain="${app_domain%\"}"

say "Applying the update..."
SERVICE_BACKUP="$(mktemp)"
run_root cp "$SERVICE_FILE" "$SERVICE_BACKUP"
run_root systemctl stop "$SERVICE_NAME"
BACKUP_DIR="${INSTALL_DIR}.backup.$(date +%s)"
run_root mv "$INSTALL_DIR" "$BACKUP_DIR"
SWAPPED="true"
run_root mv "$STAGING_DIR" "$INSTALL_DIR"
STAGING_DIR=""
run_root install -d -o "$SERVICE_NAME" -g "$SERVICE_NAME" -m 0700 "$DATA_DIR"
run_root sed -i 's/-S 0\.0\.0\.0:/-S 127.0.0.1:/' "$SERVICE_FILE"
run_root grep -q -- '-S 127\.0\.0\.1:' "$SERVICE_FILE" \
    || fail "Unable to restrict the service listener to 127.0.0.1."
if ! run_root grep -q '^ReadWritePaths=/var/lib/clean-link$' "$SERVICE_FILE"; then
    run_root sed -i '/^ProtectSystem=strict$/a ReadWritePaths=/var/lib/clean-link' "$SERVICE_FILE"
fi
run_root grep -q '^ReadWritePaths=/var/lib/clean-link$' "$SERVICE_FILE" \
    || fail "Unable to grant the service access to its rate-limit state directory."
run_root systemctl daemon-reload
run_root systemctl start "$SERVICE_NAME"

healthy="false"
for _ in $(seq 1 30); do
    if curl -fsS "http://127.0.0.1:${app_port}/health" >/dev/null 2>&1; then
        healthy="true"
        break
    fi
    if ! run_root systemctl is-active --quiet "$SERVICE_NAME"; then break; fi
    sleep 1
done
if [ "$healthy" != "true" ]; then
    run_root journalctl -u "$SERVICE_NAME" -n 60 --no-pager >&2 || true
    fail "The updated service failed its health check."
fi
response_headers="$(curl -fsS -D - -o /dev/null "http://127.0.0.1:${app_port}/")"
printf '%s\n' "$response_headers" | grep -qi '^Content-Security-Policy:' \
    || fail "The updated service did not apply Content-Security-Policy to the main page."
printf '%s\n' "$response_headers" | grep -qi '^X-Frame-Options: DENY' \
    || fail "The updated service did not apply frame protection to the main page."

run_root rm -rf -- "$BACKUP_DIR"
BACKUP_DIR=""
SWAPPED="false"
rm -f -- "$SERVICE_BACKUP"
SERVICE_BACKUP=""

say "Clean Link updated successfully."
say "Configuration preserved: $INSTALL_DIR/.env"
if [ -n "$app_domain" ]; then
    say "URL: https://${app_domain}"
else
    say "URL: http://127.0.0.1:${app_port} (localhost only)"
    say "SSH tunnel: ssh -L ${app_port}:127.0.0.1:${app_port} USER@SERVER_IP"
fi
