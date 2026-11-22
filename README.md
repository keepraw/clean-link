# Clean Link

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/install.sh | bash
```

Clean Link is a private PHP tool that opens the merchant link behind affiliate and buying-group redirects, then removes tracking parameters. It has no framework, database, or build step.

## What it does

Static unwrapping recognizes cross-site URL parameters such as Awin `ued`, Rakuten `murl`, Walmart `u`, and Partnerize `/destination:` paths on any host. It follows HTTP redirects with a browser user agent and cookies shared across hops, and recognizes HTML meta refresh and small-page JavaScript redirects. Once a chain leaves its input host, it stops at a merchant's same-site redirect (often a bot-check, locale, or login page). If a request or redirect cannot safely be followed, it returns the best known URL with a warning.

The cleaner canonicalizes Amazon product URLs to `/dp/ASIN`, removes common affiliate and analytics parameters including `utm_`, `mtm_`, and `pk_` keys, and preserves unknown functional parameters and fragments.

## Access modes

Without a domain, the PHP service listens only on `127.0.0.1`. Use an SSH tunnel:

```bash
ssh -L 3000:127.0.0.1:3000 USER@SERVER
```

Or publish it only to your private tailnet with Tailscale Serve:

```bash
tailscale serve --bg http://127.0.0.1:3000
```

Unattended localhost install:

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/install.sh \
  | APP_PASSWORD='random-password-of-at-least-20-characters' APP_PORT=3000 bash
```

On a fresh install, if the default or prompted port is busy, the installer selects the next free port within 100 ports. An explicitly set busy `APP_PORT` fails with an error; upgrades keep their existing port.

To use a domain, point its A/AAAA records to the server and allow inbound ports 80 and 443. The installer selects a proxy mode:

- Existing Caddy: adds `/etc/caddy/clean-link.caddy` and imports it from the existing Caddyfile.
- Active Nginx: adds `/etc/nginx/conf.d/clean-link.conf` and requests a certificate with Certbot.
- Another server using port 80 or 443: installs for localhost only; add your own proxy to `127.0.0.1:<port>`.
- No existing proxy: installs Caddy and configures HTTPS.

For example:

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/install.sh \
  | APP_PASSWORD='random-password-of-at-least-20-characters' APP_DOMAIN='clean.example.com' bash
```

## Upgrade

```bash
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/install.sh | bash
```

New installations also provide `/opt/clean-link/update.sh`. Installations from before this release must use the curl command once; their old local updater calls a removed migration script and will fail safely.

## Change the password

The install command updates the password hash and rotates the session secret when `APP_PASSWORD` is set:

```bash
read -r -s -p "New password: " APP_PASSWORD; printf '\n'
export APP_PASSWORD
curl -fsSL https://raw.githubusercontent.com/keepraw/clean-link/main/install.sh | bash
unset APP_PASSWORD
```

Use a unique random password of at least 20 characters.

## Configuration

Settings are stored in `/opt/clean-link/.env`.

| Setting | Purpose | Default |
| --- | --- | --- |
| `APP_PASSWORD_HASH` | PHP password hash; do not store a plaintext password | Created by installer |
| `SESSION_SECRET` | Signs session cookies | Random 32-byte value |
| `APP_PORT` | Loopback service port, 1024–65535 | `3000` |
| `APP_DOMAIN` | HTTPS hostname, or empty for localhost-only access | Empty |
| `SESSION_SECONDS` | Signed-cookie lifetime, 300–2,592,000 seconds | `86400` |
| `COOKIE_SECURE` | `auto`, `true`, or `false` | `auto` |

## Existing reverse proxy

Leave `APP_DOMAIN` empty when using an existing proxy. For Caddy:

```caddyfile
clean.example.com {
    reverse_proxy 127.0.0.1:3000
}
```

For Nginx, proxy the application and pass the HTTPS scheme:

```nginx
location / {
    proxy_pass http://127.0.0.1:3000;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Host $host;
}
```

## Security

Every requested hostname is resolved and pinned to a validated public address; private, loopback, link-local, reserved, shared-address, benchmark, multicast, NAT64, and 6to4 ranges are blocked. Redirects are validated independently, proxies are disabled, and TLS verification remains enabled. The service listens on loopback, runs as a dedicated system user under systemd hardening, and does not save submitted URLs.

Use a random password with at least 20 characters. Failed login responses are delayed by two seconds; the PHP development server and installer service handle requests serially. Sessions use signed, `HttpOnly`, `SameSite=Strict` cookies with a configurable lifetime.

## Development

Requirements: PHP 7.2+ with cURL.

```bash
php tests/run.php
find app public tests -name '*.php' -type f -exec php -l '{}' \;
```

Run the local server:

```bash
export APP_PASSWORD_HASH="$(printf '%s' 'development-password-at-least-20-chars' | php -r '$password = stream_get_contents(STDIN); echo password_hash($password, PASSWORD_DEFAULT);')"
APP_PASSWORD_HASH="$APP_PASSWORD_HASH" SESSION_SECRET='development-session-secret' \
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

If Clean Link added a Caddy site, remove `/etc/caddy/clean-link.caddy` and its `import /etc/caddy/clean-link.caddy` line from `/etc/caddy/Caddyfile`, then reload Caddy. If it added an Nginx site, remove `/etc/nginx/conf.d/clean-link.conf` and reload Nginx. Purge Caddy only if it was installed exclusively for Clean Link.
