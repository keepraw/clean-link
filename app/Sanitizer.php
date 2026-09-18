<?php

declare(strict_types=1);

namespace CleanLink;

final class Sanitizer
{
    private const TRACKING_PARAMETERS = [
        'gclid', 'dclid', 'fbclid', 'msclkid', 'twclid', 'igshid', 'srsltid',
        'ref', 'ref_', 'tag', 'ascsubtag', 'linkcode', 'camp', 'creative',
        'creativeasin', 'adid', 'aff_id', 'affiliate', 'affiliate_id', 'clickid',
        'irclickid', 'irgwc', 'mc_cid', 'mc_eid', 'mkt_tok', 'vero_id', '_hsenc', '_hsmi',
    ];

    private const AMAZON_SUFFIXES = [
        'com', 'ca', 'com.mx', 'com.br', 'co.uk', 'de', 'fr', 'it', 'es', 'nl',
        'se', 'pl', 'com.be', 'co.jp', 'in', 'com.au', 'sg', 'ae', 'sa', 'com.tr',
    ];

    public function sanitize(string $input): string
    {
        $parts = parse_url($input);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new AppError('INVALID_URL', 'Invalid URL.');
        }

        $suffix = $this->amazonSuffix((string) $parts['host']);
        $asin = $suffix === null ? null : $this->amazonAsin((string) ($parts['path'] ?? '/'));
        if ($asin !== null) {
            return sprintf('%s://www.amazon.%s/dp/%s', $parts['scheme'], $suffix, $asin);
        }

        if (isset($parts['query'])) {
            $kept = [];
            foreach (explode('&', $parts['query']) as $pair) {
                $rawKey = explode('=', $pair, 2)[0];
                $key = strtolower(rawurldecode(str_replace('+', ' ', $rawKey)));
                if (!$this->isTrackingParameter($key)) {
                    $kept[] = $pair;
                }
            }
            $parts['query'] = implode('&', $kept);
        }

        return $this->buildUrl($parts);
    }

    public function isTrackingParameter(string $name): bool
    {
        $normalized = strtolower($name);
        return strpos($normalized, 'utm_') === 0 || in_array($normalized, self::TRACKING_PARAMETERS, true);
    }

    private function amazonSuffix(string $hostname): ?string
    {
        $host = strtolower($hostname);
        foreach (self::AMAZON_SUFFIXES as $suffix) {
            $ending = '.amazon.' . $suffix;
            if ($host === 'amazon.' . $suffix || substr($host, -strlen($ending)) === $ending) {
                return $suffix;
            }
        }
        return null;
    }

    private function amazonAsin(string $path): ?string
    {
        $patterns = [
            '#/(?:dp|gp/product|gp/aw/d|product)/([a-z0-9]{10})(?:[/?]|$)#i',
            '#/exec/obidos/asin/([a-z0-9]{10})(?:[/?]|$)#i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path, $match)) {
                return strtoupper($match[1]);
            }
        }
        return null;
    }

    /** @param array<string, mixed> $parts */
    private function buildUrl(array $parts): string
    {
        $url = $parts['scheme'] . '://';
        $host = (string) $parts['host'];
        $url .= strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $url .= $parts['path'] ?? '/';
        if (!empty($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#' . $parts['fragment'];
        }
        return $url;
    }
}
