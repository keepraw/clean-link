#!/usr/bin/env bash
set -Eeuo pipefail

INSTALL_DIR="/opt/clean-link"
SERVICE_NAME="clean-link"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
CADDY_FILE="/etc/caddy/Caddyfile"
REPO_BRANCH="${CLEAN_LINK_BRANCH:-main}"
ARCHIVE_URL="${CLEAN_LINK_ARCHIVE_URL:-https://github.com/keepraw/clean-link/archive/refs/heads/${REPO_BRANCH}.tar.gz}"
DEFAULT_PORT="3000"
STAGING_DIR=""
NEW_INSTALL="false"
UNIT_CREATED="false"
USER_CREATED="false"
ENV_TEMP=""
UNIT_TEMP=""
CADDY_TEMP=""
CADDY_INSTALLED="false"
CADDY_CONFIGURED="false"

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
        say "Installation failed. Cleaning up the partial installation." >&2
        if [ "$UNIT_CREATED" = "true" ]; then
            run_root systemctl disable --now "$SERVICE_NAME" >/dev/null 2>&1 || true
            run_root rm -f -- "$SERVICE_FILE"
            run_root systemctl daemon-reload >/dev/null 2>&1 || true
        fi
        if [ "$NEW_INSTALL" = "true" ] && [ -d "$INSTALL_DIR" ]; then
            run_root rm -rf -- "$INSTALL_DIR"
        fi
        if [ -n "$STAGING_DIR" ] && [ -d "$STAGING_DIR" ]; then
            run_root rm -rf -- "$STAGING_DIR"
        fi
        if [ -n "$ENV_TEMP" ] && [ -f "$ENV_TEMP" ]; then rm -f -- "$ENV_TEMP"; fi
        if [ -n "$UNIT_TEMP" ] && [ -f "$UNIT_TEMP" ]; then rm -f -- "$UNIT_TEMP"; fi
        if [ -n "$CADDY_TEMP" ] && [ -f "$CADDY_TEMP" ]; then rm -f -- "$CADDY_TEMP"; fi
        if [ "$CADDY_CONFIGURED" = "true" ]; then
            run_root systemctl disable --now caddy >/dev/null 2>&1 || true
            run_root rm -f -- "$CADDY_FILE"
        fi
        if [ "$CADDY_INSTALLED" = "true" ]; then
            run_root apt-get remove -y caddy >/dev/null 2>&1 || true
        fi
        if [ "$USER_CREATED" = "true" ]; then
            run_root userdel "$SERVICE_NAME" >/dev/null 2>&1 || true
        fi
    fi
}
trap cleanup EXIT

[ "$(uname -s)" = "Linux" ] || fail "This installer only supports Linux."
[ -r /etc/os-release ] || fail "Cannot identify this Linux distribution."
# shellcheck disable=SC1091
. /etc/os-release
case "${ID:-}" in
    debian|ubuntu) ;;
    *) fail "Automatic installation currently supports Debian and Ubuntu." ;;
esac

[ "$(ps -p 1 -o comm= | tr -d ' ')" = "systemd" ] || fail "systemd is required."

if [ -f "$INSTALL_DIR/.env" ] && [ -f "$SERVICE_FILE" ]; then
    say "An existing Clean Link installation was found. Running its updater instead."
    run_root bash "$INSTALL_DIR/update.sh"
    exit 0
fi
if [ -e "$INSTALL_DIR" ]; then
    fail "$INSTALL_DIR already exists but is not a valid Clean Link installation. Move it aside and retry."
fi

say "Installing the small PHP runtime and required system packages..."
run_root apt-get update
run_root apt-get install -y ca-certificates curl tar php-cli php-curl

php_bin="$(command -v php || true)"
[ -n "$php_bin" ] || fail "PHP was not installed successfully."
"$php_bin" -r 'exit(version_compare(PHP_VERSION, "7.2.0", ">=") ? 0 : 1);' \
    || fail "PHP 7.2 or newer is required."
"$php_bin" -r 'exit(extension_loaded("curl") ? 0 : 1);' \
    || fail "The PHP cURL extension is not available."

if ! id -u "$SERVICE_NAME" >/dev/null 2>&1; then
    run_root useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin "$SERVICE_NAME"
    USER_CREATED="true"
fi

run_root mkdir -p "$(dirname "$INSTALL_DIR")"
STAGING_DIR="$(run_root mktemp -d "${INSTALL_DIR}.install.XXXXXX")"
say "Downloading Clean Link..."
run_root bash -c 'set -o pipefail; curl -fsSL "$1" | tar -xz --strip-components=1 -C "$2"' bash "$ARCHIVE_URL" "$STAGING_DIR"

if [ -n "${APP_PASSWORD:-}" ]; then
    app_password="$APP_PASSWORD"
else
    [ -r /dev/tty ] || fail "No interactive terminal found. Set APP_PASSWORD before piping this installer to bash."
    while true; do
        IFS= read -r -s -p "Choose an app password: " app_password </dev/tty
        printf '\n' >/dev/tty
        [ ${#app_password} -ge 8 ] && break
        say "Password must contain at least 8 characters." >/dev/tty
    done
fi
case "$app_password" in
    *"'"*) fail "The password cannot contain a single quote. Choose a different password." ;;
    *$'\n'*|*$'\r'*) fail "The password cannot contain a line break." ;;
esac

if [ -n "${APP_PORT:-}" ]; then
    app_port="$APP_PORT"
else
    if [ -r /dev/tty ]; then
        IFS= read -r -p "Listening port [$DEFAULT_PORT]: " app_port </dev/tty
    else
        app_port="$DEFAULT_PORT"
    fi
    app_port="${app_port:-$DEFAULT_PORT}"
fi
case "$app_port" in
    ''|*[!0-9]*) fail "The listening port must be a number from 1024 to 65535." ;;
esac
if [ "$app_port" -lt 1024 ] || [ "$app_port" -gt 65535 ]; then
    fail "The listening port must be a number from 1024 to 65535."
fi

domain_from_env="false"
if [ -n "${APP_DOMAIN:-}" ]; then
    app_domain="$APP_DOMAIN"
    domain_from_env="true"
elif [ -r /dev/tty ]; then
    IFS= read -r -p "Domain for automatic HTTPS (press Enter to use IP and port): " app_domain </dev/tty
else
    app_domain=""
fi
app_domain="${app_domain%.}"
app_domain="${app_domain,,}"
if [ -n "$app_domain" ]; then
    if [ ${#app_domain} -gt 253 ] || [[ ! "$app_domain" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]]; then
        fail "Enter a hostname such as clean.example.com, without http://, a path, or a port."
    fi

    dns_ips="$("$php_bin" -r '
        $records = dns_get_record($argv[1], DNS_A | DNS_AAAA);
        if (!is_array($records)) exit(1);
        foreach ($records as $record) {
            if (isset($record["ip"])) echo $record["ip"], PHP_EOL;
            if (isset($record["ipv6"])) echo $record["ipv6"], PHP_EOL;
        }
    ' "$app_domain" | sort -u)"
    [ -n "$dns_ips" ] || fail "$app_domain has no A or AAAA record yet. Point it to this server, wait for DNS propagation, and retry."

    public_v4="$(curl -4 -fsS --max-time 8 https://api.ipify.org 2>/dev/null || true)"
    public_v6="$(curl -6 -fsS --max-time 8 https://api64.ipify.org 2>/dev/null || true)"
    public_ips="$(printf '%s\n%s\n' "$public_v4" "$public_v6" | sed '/^$/d' | sort -u)"
    dns_matches="false"
    while IFS= read -r address; do
        if printf '%s\n' "$public_ips" | grep -Fxq -- "$address"; then dns_matches="true"; fi
    done <<<"$dns_ips"

    say "DNS records for $app_domain:"
    say "$dns_ips"
    if [ -n "$public_ips" ]; then
        say "Detected public address(es) for this server:"
        say "$public_ips"
    fi
    if [ "$domain_from_env" = "true" ]; then
        [ "$dns_matches" = "true" ] || fail "APP_DOMAIN does not resolve to a detected public address of this server."
    else
        IFS= read -r -p "Confirm the domain points here and inbound ports 80/443 are open [y/N]: " dns_confirmed </dev/tty
        case "$dns_confirmed" in y|Y|yes|YES) ;; *) fail "HTTPS setup cancelled." ;; esac
    fi

    if command -v caddy >/dev/null 2>&1; then
        fail "Caddy is already installed. To avoid overwriting an existing proxy, add this app to its configuration manually."
    fi
    say "Installing Caddy for automatic Let's Encrypt HTTPS..."
    CADDY_INSTALLED="true"
    run_root apt-get install -y caddy || fail "Caddy could not be installed or started. Check package sources and make sure ports 80/443 are free."
    listen_host="127.0.0.1"
else
    listen_host="0.0.0.0"
fi

session_secret="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')"
ENV_TEMP="$(mktemp)"
UNIT_TEMP="$(mktemp)"
chmod 600 "$ENV_TEMP"
printf "APP_PASSWORD='%s'\nSESSION_SECRET='%s'\nAPP_PORT=%s\nAPP_DOMAIN='%s'\nCOOKIE_SECURE=auto\n" \
    "$app_password" "$session_secret" "$app_port" "$app_domain" >"$ENV_TEMP"

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
ExecStart=$php_bin -d expose_php=0 -S $listen_host:$app_port -t $INSTALL_DIR/public $INSTALL_DIR/public/index.php
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

if [ -n "$app_domain" ]; then
    CADDY_TEMP="$(mktemp)"
    cat >"$CADDY_TEMP" <<EOF
{
    acme_ca https://acme-v02.api.letsencrypt.org/directory
}

$app_domain {
    reverse_proxy 127.0.0.1:$app_port
}
EOF
    run_root caddy validate --config "$CADDY_TEMP" --adapter caddyfile
fi

run_root mv "$ENV_TEMP" "$STAGING_DIR/.env"
ENV_TEMP=""
run_root chown -R root:root "$STAGING_DIR"
run_root chown root:"$SERVICE_NAME" "$STAGING_DIR/.env"
run_root chmod 640 "$STAGING_DIR/.env"
run_root chmod 755 "$STAGING_DIR/install.sh" "$STAGING_DIR/update.sh"
run_root mv "$STAGING_DIR" "$INSTALL_DIR"
STAGING_DIR=""
NEW_INSTALL="true"
run_root install -m 0644 "$UNIT_TEMP" "$SERVICE_FILE"
rm -f -- "$UNIT_TEMP"
UNIT_TEMP=""
UNIT_CREATED="true"

if [ -n "$app_domain" ]; then
    run_root install -o root -g root -m 0644 "$CADDY_TEMP" "$CADDY_FILE"
    CADDY_CONFIGURED="true"
    rm -f -- "$CADDY_TEMP"
    CADDY_TEMP=""
fi

say "Starting Clean Link..."
run_root systemctl daemon-reload
run_root systemctl enable --now "$SERVICE_NAME"
if [ -n "$app_domain" ]; then
    run_root systemctl enable caddy
    run_root systemctl restart caddy
fi

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
    fail "Clean Link did not become healthy. Check whether port $app_port is already in use."
fi

NEW_INSTALL="false"
UNIT_CREATED="false"
USER_CREATED="false"
server_ip="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
server_ip="${server_ip:-SERVER_IP}"

if [ -n "$app_domain" ]; then
    https_ready="false"
    for _ in $(seq 1 45); do
        if curl -fsS --noproxy '*' --resolve "${app_domain}:443:127.0.0.1" "https://${app_domain}/health" >/dev/null 2>&1; then
            https_ready="true"
            break
        fi
        sleep 1
    done
    if [ "$https_ready" != "true" ]; then
        say "WARNING: Caddy is running but the certificate is not ready yet. It will keep retrying." >&2
        say "Check DNS, ports 80/443, and: journalctl -u caddy -f" >&2
    fi
    public_url="https://${app_domain}"
else
    public_url="http://${server_ip}:${app_port}"
fi

say ""
say "Clean Link installed successfully."
say "URL: $public_url"
say "Install directory: $INSTALL_DIR"
say "Service status: active"
say "Update: $INSTALL_DIR/update.sh"
say "Logs: journalctl -u $SERVICE_NAME -f"
say "Uninstall instructions: $INSTALL_DIR/README.md"
