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
- Uses a signed `HttpOnly`, `SameSite=Strict` login cookie.
- Rechecks every redirect target against private, loopback, link-local, reserved, and metadata IP ranges.
- Processes links in memory without storing URLs or history.

## Installation

Run the command at the top of this README on a Debian or Ubuntu VPS with systemd. The application code supports PHP 7.2 and newer; a currently maintained OS and PHP release are still strongly recommended for Internet-facing use. The installer:

1. Installs PHP CLI, PHP cURL, curl, tar, and CA certificates through `apt`.
2. Downloads the repository to `/opt/clean-link`.
3. Prompts for an app password without displaying it.
4. Prompts for a port from `1024` to `65535` (default `3000`).
5. Optionally accepts a domain, displays its DNS records, and asks you to confirm ports `80` and `443` are open.
6. When a domain is supplied, installs Caddy and obtains an automatically renewed Let's Encrypt certificate.
7. Creates a restricted `clean-link` system user and systemd service.
8. Starts the service and verifies `/health` before reporting success.

Without a domain, no Node.js, npm, Composer, Git, or separate web server is required. HTTPS mode adds only Caddy. Updates download a small GitHub source archive directly with curl.

For unattended installation without a domain:

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/install.sh \
  | APP_PASSWORD='your-strong-password' APP_PORT=3000 bash
```

For unattended HTTPS installation, point the domain's A/AAAA record to the VPS first and open inbound TCP ports `80` and `443`:

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/install.sh \
  | APP_PASSWORD='your-strong-password' APP_DOMAIN='clean.example.com' bash
```

`APP_DOMAIN` mode verifies that DNS resolves to a detected public address on the VPS before installing Caddy. The PHP backend then listens only on `127.0.0.1`; Caddy redirects HTTP to HTTPS and renews the certificate automatically.

The password and generated session secret are stored in `/opt/clean-link/.env`, readable only by root and the service account.

## Update

Both methods preserve `.env`, the password, secret, and port. A failed health check automatically restores the previous version.

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/update.sh | bash
```

```bash
/opt/clean-link/update.sh
```

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
    }
}
```

When HTTPS terminates at a reverse proxy, `COOKIE_SECURE=auto` detects `X-Forwarded-Proto: https` and adds the Secure cookie flag.

## Security

Redirects are followed manually. Before every request, all DNS answers are checked; a target is rejected if any answer is non-public. cURL is pinned to the validated address, proxy environment variables are disabled, response bodies are aborted after headers, and request timeouts are enforced.

The systemd service runs as the dedicated `clean-link` user with filesystem and process hardening. Submitted links are request bodies rather than access-log URLs and are not persisted.

## Development

Requirements: PHP 7.2+ with the cURL extension.

```bash
php tests/run.php
find app public tests -name '*.php' -type f -exec php -l '{}' \;
```

Local server:

```bash
APP_PASSWORD='development-password' \
SESSION_SECRET='development-session-secret' \
php -S 127.0.0.1:3000 -t public public/index.php
```

## Uninstall

```bash
sudo systemctl disable --now clean-link
sudo rm -f /etc/systemd/system/clean-link.service
sudo systemctl daemon-reload
sudo userdel clean-link
sudo rm -rf /opt/clean-link
```

If the installer added Caddy exclusively for this tool, you may also remove it with `sudo apt purge caddy`. The final command permanently removes the application configuration and password.
