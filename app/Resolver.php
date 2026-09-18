<?php

declare(strict_types=1);

namespace CleanLink;

final class Resolver
{
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    /** @var NetSafety */
    private $netSafety;

    public function __construct(NetSafety $netSafety)
    {
        $this->netSafety = $netSafety;
    }

    /** @return array{finalUrl: string, hops: list<array{url: string, status: int}>} */
    public function resolveWithHttp(string $input, int $maxRedirects = 10, int $timeoutMs = 8000): array
    {
        $current = $input;
        $hops = [];

        for ($redirects = 0; ; $redirects++) {
            $target = $this->netSafety->validateUrl($current);
            $address = $this->netSafety->resolvePublicAddress($target['host']);
            $response = $this->requestHeaders($target, $address['address'], $timeoutMs);
            $hops[] = ['url' => $current, 'status' => $response['status']];

            if (!in_array($response['status'], self::REDIRECT_CODES, true) || $response['location'] === null) {
                return ['finalUrl' => $target['url'], 'hops' => $hops];
            }
            if ($redirects >= $maxRedirects) {
                throw new AppError('REDIRECT_LIMIT', 'Redirect limit exceeded.', 508);
            }

            $current = $this->resolveLocation($current, $response['location']);
        }
    }

    /** @param array{scheme: string, host: string, port: int, url: string} $target
     *  @return array{status: int, location: ?string}
     */
    private function requestHeaders(array $target, string $address, int $timeoutMs): array
    {
        $headers = [];
        $receivedBody = false;
        $curl = curl_init($target['url']);
        $pinnedAddress = strpos($address, ':') !== false ? '[' . $address . ']' : $address;

        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Clean-Link/1.0 (+private redirect resolver)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.1', 'Accept-Encoding: identity'],
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], $pinnedAddress)],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $length = strlen($line);
                $position = strpos($line, ':');
                if ($position !== false) {
                    $name = strtolower(trim(substr($line, 0, $position)));
                    $headers[$name] = trim(substr($line, $position + 1));
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$receivedBody): int {
                $receivedBody = true;
                return 0;
            },
        ]);

        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errorNumber = curl_errno($curl);
        curl_close($curl);

        $expectedBodyAbort = $receivedBody && $errorNumber === CURLE_WRITE_ERROR && $status > 0;
        if ($success === false && !$expectedBodyAbort) {
            if ($errorNumber === CURLE_OPERATION_TIMEDOUT) {
                throw new AppError('REQUEST_TIMEOUT', 'Request timed out.', 504);
            }
            throw new AppError('RESOLUTION_FAILED', 'Unable to resolve link.', 502);
        }
        if ($status === 0) {
            throw new AppError('RESOLUTION_FAILED', 'Unable to resolve link.', 502);
        }

        return ['status' => $status, 'location' => $headers['location'] ?? null];
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
