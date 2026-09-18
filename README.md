# Clean Link

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/install.sh | bash
```

A tiny private PHP tool that follows redirect links and removes affiliate and tracking parameters. It has no framework, package manager, database, or external API.

## Features

- Follows up to 10 HTTP `301`, `302`, `303`, `307`, and `308` redirects.
- Unwraps known client-side affiliate redirectors from FatCoupon, BigOffers, and clcktrck.
- Shows the original, resolved, and cleaned URLs.
- Canonicalizes Amazon product links to `/dp/ASIN` when an ASIN is present.
- Removes common tracking parameters while preserving unknown functional parameters.
- Stores the login password as a one-way PHP password hash and rate-limits failed logins.
- Uses a signed `HttpOnly`, `SameSite=Strict` login cookie with a configurable lifetime (24 hours by default).
- Rechecks every redirect target against private, loopback, link-local, reserved, and metadata IP ranges.
- Processes links in memory without storing URLs or history.

## Installation

Run the command at the top of this README on a Debian or Ubuntu VPS with systemd. The application code supports PHP 7.2 and newer; a currently maintained OS and PHP release are still strongly recommended for Internet-facing use. The installer:

1. Installs PHP CLI, PHP cURL, curl, tar, and CA certificates through `apt`.
2. Downloads the repository to `/opt/clean-link`.
3. Prompts for an app password of at least 20 characters without displaying it, then stores only its password hash.
4. Prompts for a port from `1024` to `65535` (default `3000`).
5. Optionally accepts a domain, displays its DNS records, and asks you to confirm ports `80` and `443` are open.
6. When a domain is supplied, installs Caddy and obtains an automatically renewed Let's Encrypt certificate.
7. Creates a restricted `clean-link` system user and systemd service.
8. Starts the service and verifies `/health` before reporting success.

Without a domain, the service listens only on `127.0.0.1` and is not exposed on the public network. Access it through an SSH tunnel, Tailscale Serve, or another trusted VPN. HTTPS mode adds Caddy. No Node.js, npm, Composer, Git, or database is required. Updates download a small GitHub source archive directly with curl.

### Access modes

| Installation choice | Listener | Intended access |
| --- | --- | --- |
| No domain | `127.0.0.1:APP_PORT` | SSH tunnel, Tailscale Serve, or trusted VPN proxy only |
| Domain | Caddy on public HTTPS; PHP on `127.0.0.1:APP_PORT` | `https://APP_DOMAIN` |

There is deliberately no supported public plain-HTTP mode. Supplying no domain never binds the application to `0.0.0.0`.

For unattended installation without a domain:

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/install.sh \
  | APP_PASSWORD='use-a-random-password-at-least-20-characters' APP_PORT=3000 bash
```

After a no-domain installation, create a tunnel from your computer:

```bash
ssh -L 3000:127.0.0.1:3000 USER@SERVER_IP
```

Then open `http://127.0.0.1:3000` locally. Do not expose the application port directly through a cloud firewall or router. For Tailscale or another VPN, proxy the encrypted private connection to the same loopback URL rather than changing the service to `0.0.0.0`.

For unattended HTTPS installation, point the domain's A/AAAA record to the VPS first and open inbound TCP ports `80` and `443`:

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/install.sh \
  | APP_PASSWORD='use-a-random-password-at-least-20-characters' APP_DOMAIN='clean.example.com' bash
```

`APP_DOMAIN` mode verifies that DNS resolves to a detected public address on the VPS before installing Caddy. The PHP backend then listens only on `127.0.0.1`; Caddy redirects HTTP to HTTPS and renews the certificate automatically.

The password hash and generated session secret are stored in `/opt/clean-link/.env`, readable only by root and the service account. Existing plaintext password configurations are migrated to password hashes during an update.

## Update

Both methods preserve the effective login password, domain, and port. A failed validation or health check automatically restores the previous version. The one-time plaintext-password migration intentionally rotates the session secret, as described below.

Before stopping the running service, the updater checks PHP syntax, runs the test suite, migrates legacy configuration in staging, and keeps the existing installation untouched if validation fails. The first update from a plaintext-password version converts `APP_PASSWORD` to `APP_PASSWORD_HASH` and rotates `SESSION_SECRET`, so existing login cookies are intentionally invalidated once.

The migration preserves the existing password so that an update cannot lock you out. If that password is shorter than 20 characters or reused elsewhere, replace it with a unique random password after updating; the 20-character minimum is enforced for all new installations.

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/update.sh | bash
```

```bash
/opt/clean-link/update.sh
```

### Configuration

The installer manages `/opt/clean-link/.env`. Its supported settings are:

| Setting | Purpose | Default |
| --- | --- | --- |
| `APP_PASSWORD_HASH` | PHP `password_hash()` output; never put the plaintext password here | Generated during installation or migration |
| `SESSION_SECRET` | Signs login cookies | Random 32-byte value |
| `SESSION_SECONDS` | Login-cookie lifetime, from 300 to 2,592,000 seconds | `86400` (24 hours) |
| `APP_PORT` | Loopback backend port | `3000` |
| `APP_DOMAIN` | Public HTTPS hostname; empty means localhost-only | Empty |
| `RATE_LIMIT_FILE` | Login rate-limit state; contains only HMAC-pseudonymized client identifiers | `/var/lib/clean-link/login-attempts.json` |
| `COOKIE_SECURE` | `auto`, `true`, or `false`; public HTTPS installations should use `auto` | `auto` |

### Change the password

Run the following on the VPS. The password is read from standard input rather than a command-line argument, and the plaintext value is not written to disk:

```bash
read -r -s -p "New Clean Link password: " CLEAN_LINK_NEW_PASSWORD
printf '\n'
printf '%s' "$CLEAN_LINK_NEW_PASSWORD" \
  | sudo php /opt/clean-link/scripts/change-password.php /opt/clean-link/.env
unset CLEAN_LINK_NEW_PASSWORD
sudo systemctl restart clean-link
```

The new password must contain at least 20 characters. Changing it also rotates the session secret, immediately invalidating existing login cookies.

## Service commands

```bash
sudo systemctl status clean-link
sudo systemctl restart clean-link
sudo journalctl -u clean-link -f
```

The unauthenticated health endpoint is `http://127.0.0.1:3000/health` when using the default port.

## HTTPS and existing reverse proxies

The interactive installer can configure a fresh Caddy installation automatically. It deliberately refuses to overwrite an existing Caddy setup. If the VPS already has Caddy or Nginx, leave the domain prompt empty and add the following reverse-proxy configuration yourself.

### Caddy

```caddyfile
clean.example.com {
    header {
        Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"
        Referrer-Policy "no-referrer"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        Permissions-Policy "camera=(), microphone=(), geolocation=()"
        Strict-Transport-Security "max-age=31536000"
        -Server
    }
    reverse_proxy 127.0.0.1:3000
}
```

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name clean.example.com;

    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-For $remote_addr;
        add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'" always;
        add_header Referrer-Policy "no-referrer" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-Frame-Options "DENY" always;
        add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
        add_header Strict-Transport-Security "max-age=31536000" always;
    }
}
```

When HTTPS terminates at a reverse proxy, `COOKIE_SECURE=auto` detects `X-Forwarded-Proto: https` and adds the Secure cookie flag.

## Security

Redirects are followed manually. Before every request, all DNS answers are checked; a target is rejected if any answer is non-public. cURL is pinned to the validated address, proxy environment variables are disabled, response bodies are aborted after headers, and request timeouts are enforced.

No-domain installations bind only to loopback. Public installations require a domain and HTTPS. Security headers are applied by the application to HTML, static assets, and JSON responses, and are also applied by the generated Caddy configuration. Failed logins are rate-limited using HMAC-pseudonymized client identifiers in `/var/lib/clean-link`; submitted URLs are never written there.

Five failed password attempts from one client within five minutes trigger a 15-minute client lock. A separate global limit slows distributed guessing. A successful login clears the client-specific failure state. The rate-limit directory is the service's only writable persistent application directory.

The systemd service runs as the dedicated `clean-link` user with filesystem and process hardening. Submitted links are request bodies rather than access-log URLs and are not persisted.

## Development

Requirements: PHP 7.2+ with the cURL extension.

```bash
php tests/run.php
find app public tests -name '*.php' -type f -exec php -l '{}' \;
```

Local server:

```bash
export APP_PASSWORD_HASH="$(printf '%s' 'development-password-at-least-20-chars' | php -r '$password = stream_get_contents(STDIN); echo password_hash($password, PASSWORD_DEFAULT);')"
APP_PASSWORD_HASH="$APP_PASSWORD_HASH" \
SESSION_SECRET='development-session-secret' \
SESSION_SECONDS=86400 \
RATE_LIMIT_FILE="$(php -r 'echo sys_get_temp_dir();')/clean-link-login-attempts.json" \
php -S 127.0.0.1:3000 -t public public/index.php
```

## Uninstall

```bash
sudo systemctl disable --now clean-link
sudo rm -f /etc/systemd/system/clean-link.service
sudo systemctl daemon-reload
sudo userdel clean-link
sudo rm -rf /opt/clean-link
sudo rm -rf /var/lib/clean-link
```

If the installer added Caddy exclusively for this tool, you may also remove it with `sudo apt purge caddy`. The final command permanently removes the application configuration and password.
