<?php

declare(strict_types=1);

namespace CleanLink;

final class Resolver
{
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    private const DESTINATION_PARAMETERS = [
        'url', 'u', 'murl', 'ued', 'dest', 'destination', 'dl', 'deeplink',
        'redirect', 'redirect_url', 'target', 'to', 'goto', 'out', 'link',
        'r', 'd', 'store_url', 'urllink', 'q',
    ];

    /** @var NetSafety */
    private $netSafety;

    /** @var callable|null */
    private $fetch;

    /** @var resource */
    private $curlShare;

    public function __construct(NetSafety $netSafety, ?callable $fetch = null)
    {
        $this->netSafety = $netSafety;
        $this->fetch = $fetch;
        $this->curlShare = curl_share_init();
        curl_share_setopt($this->curlShare, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
    }

    /** @return array{finalUrl: string, hops: list<array{url: string, status: int}>, warning: ?string} */
    public function resolveWithHttp(string $input, int $maxRedirects = 10, int $timeoutMs = 8000): array
    {
        $initial = $this->netSafety->validateUrl($input);
        $current = $initial['url'];
        $inputHost = $this->normalizedHost($initial['host']);
        $hops = [];
        $redirects = 0;
        $hasLeftInputHost = false;

        while (true) {
            try {
                $target = $this->netSafety->validateUrl($current);
            } catch (AppError $error) {
                if ($hops === []) {
                    throw $error;
                }
                return $this->stopped($current, $hops, 'Stopped at a blocked or invalid redirect.');
            }

            $current = $target['url'];
            if ($this->normalizedHost($target['host']) !== $inputHost) {
                $hasLeftInputHost = true;
            }
            $embeddedDestination = $this->embeddedDestination($current);
            if ($embeddedDestination !== null) {
                $hops[] = ['url' => $current, 'status' => 200];
                try {
                    $destination = $this->netSafety->validateUrl($embeddedDestination);
                } catch (AppError $error) {
                    return $this->stopped($current, $hops, 'Stopped at a blocked or invalid redirect.');
                }
                if ($redirects >= $maxRedirects) {
                    return $this->stopped($current, $hops, sprintf('Stopped after %d redirects.', $maxRedirects));
                }
                $redirects++;
                $current = $destination['url'];
                continue;
            }

            $timeout = $hasLeftInputHost ? min($timeoutMs, 4000) : $timeoutMs;
            try {
                $response = $this->fetch === null
                    ? $this->fetchDefault($target, $timeout)
                    : call_user_func($this->fetch, $target, $timeout);
            } catch (AppError $error) {
                if ($hops === [] && $error->errorCode === 'UNSAFE_ADDRESS') {
                    throw $error;
                }
                $hops[] = ['url' => $current, 'status' => 0];
                $message = $error->errorCode === 'REQUEST_TIMEOUT'
                    ? 'Stopped early: request timed out.'
                    : 'Stopped early: unable to resolve link.';
                return $this->stopped($current, $hops, $message);
            }

            $hops[] = ['url' => $current, 'status' => $response['status']];
            $next = null;
            if (in_array($response['status'], self::REDIRECT_CODES, true) && $response['location'] !== null) {
                try {
                    $next = $this->resolveLocation($current, $response['location']);
                } catch (AppError $error) {
                    return $this->stopped($current, $hops, 'Stopped at a blocked or invalid redirect.');
                }
            } elseif (
                $response['status'] === 200
                && stripos($response['contentType'], 'text/html') !== false
            ) {
                $next = $this->htmlRedirect(
                    $response['body'],
                    $response['bodyComplete'],
                    $current
                );
            }

            if ($next === null) {
                return ['finalUrl' => $current, 'hops' => $hops, 'warning' => null];
            }

            try {
                $nextTarget = $this->netSafety->validateUrl($next);
            } catch (AppError $error) {
                return $this->stopped($current, $hops, 'Stopped at a blocked or invalid redirect.');
            }
            if ($hasLeftInputHost && $this->sameSite($target['host'], $nextTarget['host'])) {
                return ['finalUrl' => $current, 'hops' => $hops, 'warning' => null];
            }
            if ($redirects >= $maxRedirects) {
                return $this->stopped($current, $hops, sprintf('Stopped after %d redirects.', $maxRedirects));
            }

            $redirects++;
            $current = $nextTarget['url'];
        }
    }

    public function embeddedDestination(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (
            in_array($host, ['samsclub.com', 'www.samsclub.com'], true)
            && ($parts['path'] ?? '') === '/are-you-human'
        ) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $encodedPath = $query['url'] ?? null;
            if (!is_string($encodedPath) || $encodedPath === '') {
                return null;
            }

            $decodedPath = base64_decode(str_replace(' ', '+', $encodedPath), true);
            if (!is_string($decodedPath) || strpos($decodedPath, '/') !== 0 || strpos($decodedPath, '//') === 0) {
                return null;
            }

            return strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . $host . $decodedPath;
        }

        $outerHost = $this->normalizedHost($host);
        if (isset($parts['path']) && preg_match('#(?:^|/)destination:(.+)$#i', (string) $parts['path'], $match)) {
            $rawCandidate = $match[1];
            if (isset($parts['query'])) {
                $rawCandidate .= '?' . $parts['query'];
            }
            if (isset($parts['fragment'])) {
                $rawCandidate .= '#' . $parts['fragment'];
            }
            $candidate = $this->decodeDestination($rawCandidate);
            if ($candidate !== null && $this->hasDifferentHost($candidate, $outerHost)) {
                return $candidate;
            }
        }

        $preferred = [];
        $other = [];
        foreach (explode('&', (string) ($parts['query'] ?? '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            $pieces = explode('=', $pair, 2);
            $key = strtolower(urldecode($pieces[0]));
            $candidate = $this->decodeDestination($pieces[1] ?? '');
            if ($candidate === null || !$this->hasDifferentHost($candidate, $outerHost)) {
                continue;
            }
            if (in_array($key, self::DESTINATION_PARAMETERS, true)) {
                $preferred[$key] = $preferred[$key] ?? $candidate;
            } else {
                $other[] = $candidate;
            }
        }

        foreach (self::DESTINATION_PARAMETERS as $key) {
            if (isset($preferred[$key])) {
                return $preferred[$key];
            }
        }
        return $other[0] ?? null;
    }

    /** @param array{scheme: string, host: string, port: int, url: string} $target
     *  @return array{status: int, location: ?string, contentType: string, body: string, bodyComplete: bool}
     */
    private function fetchDefault(array $target, int $timeoutMs): array
    {
        $address = $this->netSafety->resolvePublicAddress($target['host']);
        return $this->request($target, $address['address'], $timeoutMs);
    }

    /** @param array{scheme: string, host: string, port: int, url: string} $target
     *  @return array{status: int, location: ?string, contentType: string, body: string, bodyComplete: bool}
     */
    private function request(array $target, string $address, int $timeoutMs): array
    {
        $headers = [];
        $body = '';
        $status = 0;
        $contentType = '';
        $bodyComplete = false;
        $writeAborted = false;
        $curl = curl_init($target['url']);
        $pinnedAddress = strpos($address, ':') !== false ? '[' . $address . ']' : $address;

        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: identity',
            ],
            CURLOPT_SHARE => $this->curlShare,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], $pinnedAddress)],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, &$status, &$contentType): int {
                $length = strlen($line);
                if (preg_match('#^HTTP/\S+\s+(\d+)#i', $line, $match)) {
                    $status = (int) $match[1];
                    $headers = [];
                    $contentType = '';
                    return $length;
                }
                $position = strpos($line, ':');
                if ($position !== false) {
                    $name = strtolower(trim(substr($line, 0, $position)));
                    $headers[$name] = trim(substr($line, $position + 1));
                    if ($name === 'content-type') {
                        $contentType = $headers[$name];
                    }
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$body, &$writeAborted, &$status, &$contentType): int {
                if ($status !== 200 || stripos($contentType, 'text/html') === false) {
                    $writeAborted = true;
                    return 0;
                }
                $remaining = 65536 - strlen($body);
                if ($remaining <= 0) {
                    $writeAborted = true;
                    return 0;
                }
                $body .= substr($data, 0, $remaining);
                if (strlen($data) > $remaining) {
                    $writeAborted = true;
                    return 0;
                }
                return strlen($data);
            },
        ]);

        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errorNumber = curl_errno($curl);
        curl_close($curl);
        $expectedAbort = $writeAborted && $errorNumber === CURLE_WRITE_ERROR && $status > 0;
        if ($success === false && !$expectedAbort) {
            if ($errorNumber === CURLE_OPERATION_TIMEDOUT) {
                throw new AppError('REQUEST_TIMEOUT', 'Request timed out.', 504);
            }
            throw new AppError('RESOLUTION_FAILED', 'Unable to resolve link.', 502);
        }
        if ($status === 0) {
            throw new AppError('RESOLUTION_FAILED', 'Unable to resolve link.', 502);
        }
        if ($success === true && $status === 200 && stripos($contentType, 'text/html') !== false) {
            $bodyComplete = true;
        }

        return [
            'status' => $status,
            'location' => $headers['location'] ?? null,
            'contentType' => $contentType,
            'body' => $body,
            'bodyComplete' => $bodyComplete,
        ];
    }

    private function htmlRedirect(string $body, bool $bodyComplete, string $base): ?string
    {
        if (preg_match_all('/<meta\b[^>]*>/i', $body, $tags)) {
            foreach ($tags[0] as $tag) {
                $attributes = [];
                if (preg_match_all('/([a-zA-Z_:][a-zA-Z0-9_.:-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/s', $tag, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $attributes[strtolower($match[1])] = $match[2] !== ''
                            ? $match[2]
                            : ($match[3] !== '' ? $match[3] : ($match[4] ?? ''));
                    }
                }
                if (strtolower($attributes['http-equiv'] ?? '') !== 'refresh' || !isset($attributes['content'])) {
                    continue;
                }
                $content = html_entity_decode($attributes['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('/\burl\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^;\s]*))/i', $content, $match)) {
                    $location = $match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''));
                    if ($location !== '') {
                        return $this->resolveLocation($base, trim($location));
                    }
                }
            }
        }

        if (!$bodyComplete || strlen($body) > 16384) {
            return null;
        }
        $scriptContents = '';
        if (preg_match_all('#<script\b[^>]*>(.*?)</script\s*>#is', $body, $scripts)) {
            $scriptContents = implode("\n", $scripts[1]);
        }
        $patterns = [
            '#(?:window\.|document\.|top\.|self\.)?location(?:\.href)?\s*=\s*["\'](https?:[\\\\]?/[\\\\]?/[^"\']+)#i',
            '#location\.(?:replace|assign)\(\s*["\'](https?:[\\\\]?/[\\\\]?/[^"\']+)#i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $scriptContents, $match)) {
                $location = str_replace('\\/', '/', $match[1]);
                $location = preg_replace('/\\\\u0026/i', '&', $location);
                return $this->resolveLocation($base, $location);
            }
        }
        return null;
    }

    private function decodeDestination(string $value): ?string
    {
        $candidate = urldecode($value);
        for ($pass = 0; $pass <= 2; $pass++) {
            if (preg_match('#^https?://#i', $candidate)) {
                return $candidate;
            }
            if ($pass < 2) {
                $candidate = rawurldecode($candidate);
            }
        }
        return null;
    }

    private function hasDifferentHost(string $url, string $outerHost): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        return $this->normalizedHost((string) $parts['host']) !== $outerHost;
    }

    private function normalizedHost(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }

    private function sameSite(string $first, string $second): bool
    {
        return $this->normalizedHost($first) === $this->normalizedHost($second);
    }

    /** @param list<array{url: string, status: int}> $hops
     *  @return array{finalUrl: string, hops: list<array{url: string, status: int}>, warning: string}
     */
    private function stopped(string $url, array $hops, string $warning): array
    {
        return ['finalUrl' => $url, 'hops' => $hops, 'warning' => $warning];
    }

    private function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new AppError('RESOLUTION_FAILED', 'Unable to resolve link.', 502);
        }
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = $scheme . '://' . $host . $port;

        if (strpos($location, '//') === 0) {
            return $scheme . ':' . $location;
        }
        if (strpos($location, '#') === 0) {
            return preg_replace('/#.*$/', '', $base) . $location;
        }
        if (strpos($location, '?') === 0) {
            return $origin . ($parts['path'] ?? '/') . $location;
        }

        $suffix = '';
        $path = $location;
        if (preg_match('/^([^?#]*)(.*)$/s', $location, $match)) {
            $path = $match[1];
            $suffix = $match[2];
        }
        if (strpos($path, '/') !== 0) {
            $basePath = (string) ($parts['path'] ?? '/');
            $path = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return $origin . '/' . implode('/', $segments) . $suffix;
    }
}
