<?php

declare(strict_types=1);

use CleanLink\AppError;
use CleanLink\Auth;
use CleanLink\LoginRateLimiter;
use CleanLink\NetSafety;
use CleanLink\Resolver;
use CleanLink\Sanitizer;

require_once dirname(__DIR__) . '/app/AppError.php';
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/LoginRateLimiter.php';
require_once dirname(__DIR__) . '/app/NetSafety.php';
require_once dirname(__DIR__) . '/app/Resolver.php';
require_once dirname(__DIR__) . '/app/Sanitizer.php';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

function securityHeaders(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    if (requestIsSecure()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** @param array<string, array{0: string, 1: string}> $files */
function serveStaticFile(string $path, array $files): void
{
    if (!isset($files[$path])) return;
    securityHeaders();
    header('Cache-Control: no-cache');
    header('Content-Type: ' . $files[$path][1]);
    readfile(__DIR__ . '/' . $files[$path][0]);
    exit;
}

/** @param array<string, mixed> $body */
function jsonResponse(int $status, array $body): void
{
    http_response_code($status);
    securityHeaders();
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** @return array<string, mixed> */
function jsonBody(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 16384) {
        throw new AppError('INVALID_REQUEST', 'Request is too large.', 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if ($raw === false || strlen($raw) > 16384) {
        throw new AppError('INVALID_REQUEST', 'Request is too large.', 413);
    }
    $decoded = json_decode($raw === '' ? '{}' : $raw, true);
    if (!is_array($decoded)) {
        throw new AppError('INVALID_REQUEST', 'Invalid request.');
    }
    return $decoded;
}

function requestIsSecure(): bool
{
    $setting = strtolower((string) (getenv('COOKIE_SECURE') ?: 'auto'));
    if ($setting === 'true') return true;
    if ($setting === 'false') return false;
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function clientAddress(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (in_array($remote, ['127.0.0.1', '::1'], true)) {
        $forwarded = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0];
        $forwarded = trim($forwarded);
        if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }
    }
    return $remote;
}

$staticFiles = [
    '/index.html' => ['index.html', 'text/html; charset=utf-8'],
    '/styles.css' => ['styles.css', 'text/css; charset=utf-8'],
    '/app.js' => ['app.js', 'application/javascript; charset=utf-8'],
    '/favicon.svg' => ['favicon.svg', 'image/svg+xml'],
];
if ($requestMethod === 'GET') {
    serveStaticFile($requestPath, $staticFiles);
    if ($requestPath === '/') {
        securityHeaders();
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/index.html');
        exit;
    }
}

$passwordHash = getenv('APP_PASSWORD_HASH') ?: '';
$secret = getenv('SESSION_SECRET') ?: '';
$sessionSecondsValue = getenv('SESSION_SECONDS') ?: '86400';
if ($passwordHash === '' || $secret === '') {
    error_log('APP_PASSWORD_HASH and SESSION_SECRET are required.');
    jsonResponse(503, ['error' => 'NOT_CONFIGURED', 'message' => 'Application is not configured.']);
}
$passwordInfo = password_get_info($passwordHash);
if (empty($passwordInfo['algo'])) {
    jsonResponse(503, ['error' => 'NOT_CONFIGURED', 'message' => 'Application is not configured.']);
}
if (!ctype_digit($sessionSecondsValue)) {
    jsonResponse(503, ['error' => 'NOT_CONFIGURED', 'message' => 'Application is not configured.']);
}
$sessionSeconds = (int) $sessionSecondsValue;
if ($sessionSeconds < 300 || $sessionSeconds > 2592000) {
    jsonResponse(503, ['error' => 'NOT_CONFIGURED', 'message' => 'Application is not configured.']);
}
$auth = new Auth($passwordHash, $secret, $sessionSeconds);
$rateLimitPath = getenv('RATE_LIMIT_FILE') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clean-link-login-attempts.json';
$rateLimiter = new LoginRateLimiter($rateLimitPath, $secret);
$method = $requestMethod;
$path = $requestPath;

try {
    if ($method === 'GET' && $path === '/health') {
        $rateLimitDirectory = dirname($rateLimitPath);
        if (
            !is_dir($rateLimitDirectory)
            || !is_writable($rateLimitDirectory)
            || (file_exists($rateLimitPath) && !is_writable($rateLimitPath))
        ) {
            throw new AppError('NOT_CONFIGURED', 'Application storage is not writable.', 503);
        }
        jsonResponse(200, ['status' => 'ok']);
    }
    if ($method === 'GET' && $path === '/api/session') {
        jsonResponse(200, ['authenticated' => $auth->isAuthenticated()]);
    }
    if ($method === 'POST' && $path === '/api/login') {
        $client = clientAddress();
        $retryAfter = $rateLimiter->retryAfter($client);
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
            throw new AppError('RATE_LIMITED', 'Too many login attempts. Try again later.', 429);
        }
        $body = jsonBody();
        if (!$auth->passwordMatches($body['password'] ?? null)) {
            $retryAfter = $rateLimiter->recordFailure($client);
            if ($retryAfter > 0) header('Retry-After: ' . $retryAfter);
            throw new AppError('INVALID_PASSWORD', 'Incorrect password.', 401);
        }
        $rateLimiter->clearClient($client);
        $auth->setSessionCookie(requestIsSecure());
        jsonResponse(200, ['authenticated' => true]);
    }
    if ($method === 'POST' && $path === '/api/logout') {
        $auth->clearSessionCookie(requestIsSecure());
        jsonResponse(200, ['authenticated' => false]);
    }
    if ($method === 'POST' && $path === '/api/clean') {
        if (!$auth->isAuthenticated()) {
            throw new AppError('UNAUTHORIZED', 'Please sign in.', 401);
        }
        $body = jsonBody();
        $original = is_string($body['url'] ?? null) ? trim($body['url']) : '';
        if ($original === '') {
            throw new AppError('INVALID_URL', 'Invalid URL.');
        }

        $resolved = (new Resolver(new NetSafety()))->resolveWithHttp($original, 10, 8000);
        $clean = (new Sanitizer())->sanitize($resolved['finalUrl']);
        jsonResponse(200, [
            'originalUrl' => $original,
            'finalUrl' => $resolved['finalUrl'],
            'cleanUrl' => $clean,
            'redirectCount' => max(0, count($resolved['hops']) - 1),
        ]);
    }

    throw new AppError('NOT_FOUND', 'Not found.', 404);
} catch (AppError $error) {
    jsonResponse($error->httpStatus, ['error' => $error->errorCode, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    jsonResponse(502, ['error' => 'RESOLUTION_FAILED', 'message' => 'Unable to resolve link.']);
}
