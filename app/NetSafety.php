<?php

declare(strict_types=1);

namespace CleanLink;

final class NetSafety
{
    private function endsWith(string $value, string $suffix): bool
    {
        return $suffix === '' || substr($value, -strlen($suffix)) === $suffix;
    }

    /** @return array{scheme: string, host: string, port: int, url: string} */
    public function validateUrl(string $input): array
    {
        if (strlen($input) > 8192 || filter_var($input, FILTER_VALIDATE_URL) === false) {
            throw new AppError('INVALID_URL', 'Invalid URL.');
        }

        $parts = parse_url($input);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim(strtolower(rtrim((string) ($parts['host'] ?? ''), '.')), '[]');

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new AppError('INVALID_URL', 'Only http:// and https:// URLs are allowed.');
        }
        if ($host === '' || $host === 'localhost' || $this->endsWith($host, '.localhost')) {
            throw new AppError('UNSAFE_ADDRESS', 'Blocked unsafe address.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new AppError('UNSAFE_ADDRESS', 'URLs containing credentials are not allowed.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new AppError('INVALID_URL', 'Invalid URL.');
        }

        $normalizedUrl = $scheme . '://' . (strpos($host, ':') !== false ? '[' . $host . ']' : $host);
        if (isset($parts['port'])) {
            $normalizedUrl .= ':' . $port;
        }
        $normalizedUrl .= $parts['path'] ?? '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalizedUrl .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $normalizedUrl .= '#' . $parts['fragment'];
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'url' => $normalizedUrl];
    }

    /** @return array{address: string, family: int} */
    public function resolvePublicAddress(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $records = [['address' => $host, 'family' => strpos($host, ':') !== false ? 6 : 4]];
        } else {
            $records = [];
            foreach ([DNS_A, DNS_AAAA] as $recordType) {
                $answers = @dns_get_record($host, $recordType);
                if (is_array($answers)) {
                    foreach ($answers as $answer) {
                        if (isset($answer['ip'])) {
                            $records[] = ['address' => $answer['ip'], 'family' => 4];
                        } elseif (isset($answer['ipv6'])) {
                            $records[] = ['address' => $answer['ipv6'], 'family' => 6];
                        }
                    }
                }
            }
        }

        if ($records === []) {
            throw new AppError('RESOLUTION_FAILED', 'Unable to resolve link.', 502);
        }

        foreach ($records as $record) {
            if (!$this->isPublicIp($record['address'])) {
                throw new AppError('UNSAFE_ADDRESS', 'Blocked unsafe address.');
            }
        }

        return $records[0];
    }

    public function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
