<?php

declare(strict_types=1);

namespace CleanLink;

final class LoginRateLimiter
{
    private const WINDOW_SECONDS = 300;
    private const PER_CLIENT_LIMIT = 5;
    private const CLIENT_LOCK_SECONDS = 900;
    private const GLOBAL_LIMIT = 50;
    private const GLOBAL_LOCK_SECONDS = 300;

    /** @var string */
    private $path;

    /** @var string */
    private $secret;

    public function __construct(string $path, string $secret)
    {
        $this->path = $path;
        $this->secret = $secret;
    }

    public function retryAfter(string $client, ?int $now = null): int
    {
        $timestamp = $now ?? time();
        $key = $this->clientKey($client);

        return $this->withState(function (array &$state) use ($key, $timestamp): int {
            $this->normalizeState($state, $timestamp);
            $clientUntil = isset($state['clients'][$key]['blockedUntil'])
                ? (int) $state['clients'][$key]['blockedUntil']
                : 0;
            $globalUntil = isset($state['global']['blockedUntil'])
                ? (int) $state['global']['blockedUntil']
                : 0;
            return max(0, max($clientUntil, $globalUntil) - $timestamp);
        });
    }

    public function recordFailure(string $client, ?int $now = null): int
    {
        $timestamp = $now ?? time();
        $key = $this->clientKey($client);

        return $this->withState(function (array &$state) use ($key, $timestamp): int {
            $this->normalizeState($state, $timestamp);

            if (!isset($state['clients'][$key])) {
                $state['clients'][$key] = [
                    'windowStarted' => $timestamp,
                    'failures' => 0,
                    'blockedUntil' => 0,
                ];
            }

            $state['clients'][$key]['failures']++;
            $state['global']['failures']++;

            if ($state['clients'][$key]['failures'] >= self::PER_CLIENT_LIMIT) {
                $state['clients'][$key]['blockedUntil'] = $timestamp + self::CLIENT_LOCK_SECONDS;
            }
            if ($state['global']['failures'] >= self::GLOBAL_LIMIT) {
                $state['global']['blockedUntil'] = $timestamp + self::GLOBAL_LOCK_SECONDS;
            }

            return max(
                0,
                max(
                    (int) $state['clients'][$key]['blockedUntil'],
                    (int) $state['global']['blockedUntil']
                ) - $timestamp
            );
        });
    }

    public function clearClient(string $client, ?int $now = null): void
    {
        $timestamp = $now ?? time();
        $key = $this->clientKey($client);

        $this->withState(function (array &$state) use ($key, $timestamp): int {
            $this->normalizeState($state, $timestamp);
            unset($state['clients'][$key]);
            return 0;
        });
    }

    private function clientKey(string $client): string
    {
        return hash_hmac('sha256', $client, $this->secret);
    }

    /** @param array<string, mixed> $state */
    private function normalizeState(array &$state, int $now): void
    {
        if (!isset($state['clients']) || !is_array($state['clients'])) {
            $state['clients'] = [];
        }
        if (!isset($state['global']) || !is_array($state['global'])) {
            $state['global'] = [];
        }

        $globalStarted = (int) ($state['global']['windowStarted'] ?? 0);
        if ($globalStarted <= 0 || $globalStarted + self::WINDOW_SECONDS <= $now) {
            $state['global'] = ['windowStarted' => $now, 'failures' => 0, 'blockedUntil' => 0];
        } else {
            $state['global']['failures'] = (int) ($state['global']['failures'] ?? 0);
            $state['global']['blockedUntil'] = (int) ($state['global']['blockedUntil'] ?? 0);
        }

        foreach ($state['clients'] as $key => $entry) {
            if (!is_array($entry)) {
                unset($state['clients'][$key]);
                continue;
            }
            $started = (int) ($entry['windowStarted'] ?? 0);
            $blockedUntil = (int) ($entry['blockedUntil'] ?? 0);
            if ($started <= 0 || ($started + self::WINDOW_SECONDS <= $now && $blockedUntil <= $now)) {
                unset($state['clients'][$key]);
                continue;
            }
            $state['clients'][$key] = [
                'windowStarted' => $started,
                'failures' => (int) ($entry['failures'] ?? 0),
                'blockedUntil' => $blockedUntil,
            ];
        }
    }

    /** @return int */
    private function withState(callable $callback)
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            throw new AppError('RATE_LIMIT_UNAVAILABLE', 'Login is temporarily unavailable.', 503);
        }

        try {
            rewind($handle);
            $contents = stream_get_contents($handle);
            $decoded = is_string($contents) && $contents !== '' ? json_decode($contents, true) : [];
            $state = is_array($decoded) ? $decoded : [];
            $result = $callback($state);
            $encoded = json_encode($state);
            if (!is_string($encoded)) {
                throw new AppError('RATE_LIMIT_UNAVAILABLE', 'Login is temporarily unavailable.', 503);
            }
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded) === false || !fflush($handle)) {
                throw new AppError('RATE_LIMIT_UNAVAILABLE', 'Login is temporarily unavailable.', 503);
            }
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
