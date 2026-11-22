#!/usr/bin/env bash
# shellcheck disable=SC2016
set -Eeuo pipefail
INSTALL_DIR=/opt/clean-link; SERVICE_NAME=clean-link
SERVICE_FILE=/etc/systemd/system/clean-link.service
ARCHIVE_URL="${CLEAN_LINK_ARCHIVE_URL:-https://github.com/keepraw/clean-link/archive/refs/heads/${CLEAN_LINK_BRANCH:-main}.tar.gz}"
DEFAULT_PORT=3000; STAGING_DIR=""; BACKUP_DIR=""
UNIT_BACKUP=""; UNIT_TEMP=""; CADDY_TEMP=""
SWAPPED=false; FRESH_INSTALL=false; SUCCESS=false; SUDO=""
say() { printf '%s\n' "$*"; }; fail() { say "ERROR: $*" >&2; exit 1; }
if [ "${EUID:-$(id -u)}" -ne 0 ]; then command -v sudo >/dev/null 2>&1 || fail "Run as root or install sudo first."; SUDO=sudo; sudo -v || fail "sudo access is required."; fi
run_root() { if [ -n "$SUDO" ]; then sudo "$@"; else "$@"; fi; }
read_setting() {
    local key="$1" file="$2" value
    value="$(sed -n "s/^${key}=//p" "$file" | tail -n 1)"
    value="${value#\'}"; value="${value%\'}"; value="${value#\"}"; value="${value%\"}"; printf '%s' "$value"
}
validate_port() { case "$1" in ''|*[!0-9]*) fail "The listening port must be a number from 1024 to 65535." ;; esac; if [ "$1" -lt 1024 ] || [ "$1" -gt 65535 ]; then fail "The listening port must be a number from 1024 to 65535."; fi; }
validate_password() { [ "${#1}" -ge 20 ] || fail "The password must contain at least 20 characters."; case "$1" in *$'\n'*|*$'\r'*) fail "The password cannot contain a line break." ;; esac; }
cleanup() {
    local status=$?
    if [ "$status" -ne 0 ] && [ "$SWAPPED" = true ] && [ -d "$BACKUP_DIR" ]; then
        say "Update failed; restoring the previous installation." >&2
        run_root systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
        run_root rm -rf -- "$INSTALL_DIR"; run_root mv "$BACKUP_DIR" "$INSTALL_DIR"
        run_root cp "$UNIT_BACKUP" "$SERVICE_FILE"
        run_root systemctl daemon-reload >/dev/null 2>&1 || true
        run_root systemctl restart "$SERVICE_NAME" >/dev/null 2>&1 || true
    elif [ "$status" -ne 0 ] && [ "$FRESH_INSTALL" = true ]; then
        run_root systemctl disable --now "$SERVICE_NAME" >/dev/null 2>&1 || true
        run_root rm -f -- "$SERVICE_FILE"; run_root rm -rf -- "$INSTALL_DIR"
        run_root systemctl daemon-reload >/dev/null 2>&1 || true
    fi
    [ -z "$STAGING_DIR" ] || [ ! -d "$STAGING_DIR" ] || run_root rm -rf -- "$STAGING_DIR"
    [ -z "$UNIT_TEMP" ] || [ ! -f "$UNIT_TEMP" ] || rm -f -- "$UNIT_TEMP"
    [ -z "$UNIT_BACKUP" ] || [ ! -f "$UNIT_BACKUP" ] || rm -f -- "$UNIT_BACKUP"
    [ -z "$CADDY_TEMP" ] || [ ! -f "$CADDY_TEMP" ] || rm -f -- "$CADDY_TEMP"
    if [ "$SUCCESS" = true ] && [ -n "$BACKUP_DIR" ] && [ -d "$BACKUP_DIR" ]; then run_root rm -rf -- "$BACKUP_DIR"; fi
}
trap cleanup EXIT
[ "$(uname -s)" = Linux ] || fail "This installer only supports Linux."
[ -r /etc/os-release ] || fail "Cannot identify this Linux distribution."
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in debian|ubuntu) ;; *) fail "Automatic installation supports Debian and Ubuntu." ;; esac
[ "$(ps -p 1 -o comm= | tr -d ' ')" = systemd ] || fail "systemd is required."
UPGRADE=false
if [ -f "$INSTALL_DIR/.env" ]; then
    UPGRADE=true
    [ -f "$SERVICE_FILE" ] || fail "The Clean Link systemd service is missing."
elif [ -e "$INSTALL_DIR" ]; then
    fail "$INSTALL_DIR exists without a Clean Link configuration."
fi
php_bin="$(command -v php || true)"
if [ "$UPGRADE" = false ] || [ -z "$php_bin" ] || ! "$php_bin" -r 'exit(extension_loaded("curl") ? 0 : 1);'; then
    say "Installing PHP and required system packages..."
    run_root apt-get update && run_root apt-get install -y ca-certificates curl tar php-cli php-curl
    php_bin="$(command -v php || true)"
fi
[ -n "$php_bin" ] || fail "PHP is required."
"$php_bin" -r 'exit(version_compare(PHP_VERSION, "7.2.0", ">=") ? 0 : 1);' || fail "PHP 7.2 or newer is required."
"$php_bin" -r 'exit(extension_loaded("curl") ? 0 : 1);' || fail "The PHP cURL extension is required."
command -v curl >/dev/null 2>&1 || fail "curl is required."
command -v tar >/dev/null 2>&1 || fail "tar is required."
if ! id -u "$SERVICE_NAME" >/dev/null 2>&1; then run_root useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin "$SERVICE_NAME"; fi
run_root install -d -m 0755 /opt
STAGING_DIR="$(run_root mktemp -d "${INSTALL_DIR}.new.XXXXXX")"
say "Downloading Clean Link..."
run_root bash -o pipefail -c 'curl -fsSL "$1" | tar -xz --strip-components=1 -C "$2"' _ "$ARCHIVE_URL" "$STAGING_DIR"
if [ "$UPGRADE" = true ]; then
    run_root cp "$INSTALL_DIR/.env" "$STAGING_DIR/.env"
    app_port="$(read_setting APP_PORT "$STAGING_DIR/.env")"; app_port="${app_port:-$DEFAULT_PORT}"
    app_domain="$(read_setting APP_DOMAIN "$STAGING_DIR/.env")"
    validate_port "$app_port"
    password_hash="$(read_setting APP_PASSWORD_HASH "$STAGING_DIR/.env")"
    if [ "${APP_PASSWORD+x}" = x ]; then
        validate_password "$APP_PASSWORD"
        password_hash="$(printf '%s' "$APP_PASSWORD" | "$php_bin" -r '$hash = password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT); if (!is_string($hash)) exit(1); echo $hash;')"
        session_secret="$("$php_bin" -r 'echo bin2hex(random_bytes(32));')"
        "$php_bin" -r '
            $path = $argv[1]; $hash = $argv[2]; $secret = $argv[3];
            $contents = file_get_contents($path); if (!is_string($contents)) exit(1);
            foreach (["APP_PASSWORD_HASH" => $hash, "SESSION_SECRET" => $secret] as $key => $value) {
                $pattern = "/^" . preg_quote($key, "/") . "=.*$/m";
                $contents = preg_replace_callback($pattern, function () use ($key, $value) { return $key . "=\x27" . $value . "\x27"; }, $contents, 1, $count);
                if ($count !== 1) exit(1);
            }
            if (file_put_contents($path, $contents) === false) exit(1);
        ' "$STAGING_DIR/.env" "$password_hash" "$session_secret" || fail "Could not update password configuration."
        unset APP_PASSWORD
    elif [ -z "$password_hash" ]; then
        fail "Rerun with APP_PASSWORD=... to set a password"
    fi
else
    if [ "${APP_PASSWORD+x}" = x ]; then app_password="$APP_PASSWORD"; elif [ -r /dev/tty ]; then IFS= read -r -s -p "Choose an app password: " app_password </dev/tty; printf '\n' >/dev/tty; else fail "Set APP_PASSWORD to a random password of at least 20 characters."; fi
    validate_password "$app_password"
    password_hash="$(printf '%s' "$app_password" | "$php_bin" -r '$hash = password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT); if (!is_string($hash)) exit(1); echo $hash;')"
    unset app_password APP_PASSWORD
    if [ "${APP_PORT+x}" = x ]; then app_port="$APP_PORT"; elif [ -r /dev/tty ]; then IFS= read -r -p "Listening port [${DEFAULT_PORT}]: " app_port </dev/tty; app_port="${app_port:-$DEFAULT_PORT}"; else app_port="$DEFAULT_PORT"; fi
    validate_port "$app_port"
    if [ "${APP_DOMAIN+x}" = x ]; then app_domain="$APP_DOMAIN"; elif [ -r /dev/tty ]; then IFS= read -r -p "Domain for HTTPS (press Enter for localhost-only access): " app_domain </dev/tty; else app_domain=""; fi
    app_domain="${app_domain%.}"; app_domain="${app_domain,,}"
    if [ -n "$app_domain" ]; then
        if [ ${#app_domain} -gt 253 ] || [[ ! "$app_domain" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]]; then
            fail "Enter a valid hostname without a scheme, path, or port."
        fi
        say "DNS records for $app_domain:"
        records="$("$php_bin" -r '$records = @dns_get_record($argv[1], DNS_A | DNS_AAAA); if (is_array($records)) foreach ($records as $record) { if (isset($record["ip"])) echo $record["ip"], PHP_EOL; if (isset($record["ipv6"])) echo $record["ipv6"], PHP_EOL; }' "$app_domain" | sort -u)"
        if [ -n "$records" ]; then say "$records"; else say "(no A/AAAA records returned)"; fi
        if [ -r /dev/tty ] && [ "${APP_DOMAIN+x}" != x ]; then
            IFS= read -r -p "Continue with this domain? [y/N]: " confirmed </dev/tty
            case "$confirmed" in y|Y|yes|YES) ;; *) fail "Domain setup cancelled." ;; esac
        fi
        if command -v caddy >/dev/null 2>&1; then fail "Caddy is already installed; configure the existing proxy manually."; fi
    fi
    session_secret="$("$php_bin" -r 'echo bin2hex(random_bytes(32));')"
    printf "APP_PASSWORD_HASH='%s'\nSESSION_SECRET='%s'\nAPP_PORT=%s\nAPP_DOMAIN='%s'\nSESSION_SECONDS=86400\nCOOKIE_SECURE=auto\n" \
        "$password_hash" "$session_secret" "$app_port" "$app_domain" >"$STAGING_DIR/.env"
fi
chmod 0640 "$STAGING_DIR/.env"
say "Validating staged application..."
run_root find "$STAGING_DIR/app" "$STAGING_DIR/public" "$STAGING_DIR/tests" -name '*.php' -type f -exec "$php_bin" -l '{}' \;
run_root "$php_bin" "$STAGING_DIR/tests/run.php"
UNIT_TEMP="$(mktemp)"
cat >"$UNIT_TEMP" <<EOF
[Unit]
Description=Clean Link private URL sanitizer
Wants=network-online.target
After=network-online.target
[Service]
Type=simple
User=$SERVICE_NAME
Group=$SERVICE_NAME
WorkingDirectory=$INSTALL_DIR
EnvironmentFile=$INSTALL_DIR/.env
ExecStart=$php_bin -d expose_php=0 -S 127.0.0.1:$app_port -t $INSTALL_DIR/public $INSTALL_DIR/public/index.php
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
PrivateDevices=true
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
RestrictSUIDSGID=true
LockPersonality=true
[Install]
WantedBy=multi-user.target
EOF
run_root chown -R root:root "$STAGING_DIR"
# mktemp creates the staging directory with mode 0700; the service needs 0755 after rename to avoid status 200/CHDIR.
run_root chmod 0755 "$STAGING_DIR"
run_root chown root:"$SERVICE_NAME" "$STAGING_DIR/.env"; run_root chmod 0640 "$STAGING_DIR/.env"
run_root chmod 0755 "$STAGING_DIR/install.sh" "$STAGING_DIR/update.sh"
if [ "$UPGRADE" = true ]; then
    UNIT_BACKUP="$(mktemp)"; run_root cp "$SERVICE_FILE" "$UNIT_BACKUP"
    run_root systemctl stop "$SERVICE_NAME"
    BACKUP_DIR="${INSTALL_DIR}.backup.$(date +%s)"
    [ ! -e "$BACKUP_DIR" ] || fail "Backup path already exists: $BACKUP_DIR"
    run_root mv "$INSTALL_DIR" "$BACKUP_DIR"; SWAPPED=true
else
    FRESH_INSTALL=true
fi
run_root mv "$STAGING_DIR" "$INSTALL_DIR"; STAGING_DIR=""
run_root install -o root -g root -m 0644 "$UNIT_TEMP" "$SERVICE_FILE"
rm -f -- "$UNIT_TEMP"; UNIT_TEMP=""
run_root systemctl daemon-reload
run_root systemctl enable "$SERVICE_NAME"
run_root systemctl restart "$SERVICE_NAME" 2>/dev/null || run_root systemctl start "$SERVICE_NAME"
healthy=false
for _ in $(seq 1 30); do
    if curl -fsS --max-time 2 "http://127.0.0.1:${app_port}/health" >/dev/null 2>&1; then healthy=true; break; fi
    if ! run_root systemctl is-active --quiet "$SERVICE_NAME"; then break; fi
    sleep 1
done
[ "$healthy" = true ] || fail "Clean Link failed its /health check."
if [ "$UPGRADE" = false ] && [ -n "$app_domain" ]; then
    run_root apt-get install -y caddy
    CADDY_TEMP="$(mktemp)"
    printf '%s {\n    reverse_proxy 127.0.0.1:%s\n}\n' "$app_domain" "$app_port" >"$CADDY_TEMP"
    run_root install -o root -g root -m 0644 "$CADDY_TEMP" /etc/caddy/Caddyfile
    rm -f -- "$CADDY_TEMP"; CADDY_TEMP=""
    run_root systemctl enable caddy; run_root systemctl restart caddy
fi
if [ "$UPGRADE" = true ]; then run_root rm -rf -- /var/lib/clean-link; fi
SUCCESS=true; FRESH_INSTALL=false; SWAPPED=false
say "Clean Link installed or upgraded successfully."
if [ -n "$app_domain" ]; then say "URL: https://${app_domain}"; else
    say "URL: http://127.0.0.1:${app_port} (localhost only)"
    say "SSH tunnel: ssh -L ${app_port}:127.0.0.1:${app_port} USER@SERVER_IP"
fi
say "Service: systemctl status clean-link"
