<?php

declare(strict_types=1);

namespace CleanLink;

final class ConfigMigrator
{
    public static function migrateEnv(string $path): bool
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to read the configuration file.');
        }

        $migratedPassword = false;
        if (!preg_match('/^APP_PASSWORD_HASH=\'([^\'\r\n]+)\'$/m', $contents, $hashMatch)) {
            if (!preg_match('/^APP_PASSWORD=\'([^\'\r\n]*)\'$/m', $contents, $match)) {
                throw new \RuntimeException('APP_PASSWORD or APP_PASSWORD_HASH is required.');
            }
            $hash = password_hash($match[1], PASSWORD_DEFAULT);
            if (!is_string($hash)) {
                throw new \RuntimeException('Unable to hash the application password.');
            }
            $replacement = "APP_PASSWORD_HASH='" . $hash . "'";
            $contents = preg_replace_callback(
                '/^APP_PASSWORD=\'[^\'\r\n]*\'$/m',
                static function () use ($replacement): string { return $replacement; },
                $contents,
                1
            );
            if (!is_string($contents)) {
                throw new \RuntimeException('Unable to migrate the application password.');
            }
            $migratedPassword = true;
            $hashMatch = [null, $hash];
        }

        $hashInfo = password_get_info($hashMatch[1]);
        if (empty($hashInfo['algo'])) {
            throw new \RuntimeException('APP_PASSWORD_HASH is invalid.');
        }

        $contents = preg_replace('/^APP_PASSWORD=\'[^\'\r\n]*\'\r?\n?/m', '', $contents);
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to remove the plaintext password.');
        }

        if ($migratedPassword) {
            $rotatedSecret = "SESSION_SECRET='" . bin2hex(random_bytes(32)) . "'";
            if (preg_match('/^SESSION_SECRET=.*$/m', $contents)) {
                $contents = preg_replace_callback(
                    '/^SESSION_SECRET=.*$/m',
                    static function () use ($rotatedSecret): string { return $rotatedSecret; },
                    $contents,
                    1
                );
            } else {
                $contents = rtrim($contents) . "\n" . $rotatedSecret . "\n";
            }
            if (!is_string($contents)) {
                throw new \RuntimeException('Unable to rotate the session secret.');
            }
        }

        if (!preg_match('/^SESSION_SECONDS=\d+$/m', $contents)) {
            $contents = rtrim($contents) . "\nSESSION_SECONDS=86400\n";
        }
        if (!preg_match('/^RATE_LIMIT_FILE=/m', $contents)) {
            $contents = rtrim($contents) . "\nRATE_LIMIT_FILE=/var/lib/clean-link/login-attempts.json\n";
        }

        self::writeEnv($path, $contents);

        return $migratedPassword;
    }

    public static function changePassword(string $path, string $password): void
    {
        if (strlen($password) < 20) {
            throw new \RuntimeException('The password must contain at least 20 characters.');
        }
        if (strpos($password, "\n") !== false || strpos($password, "\r") !== false) {
            throw new \RuntimeException('The password cannot contain a line break.');
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to read the configuration file.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash the application password.');
        }
        $hashLine = "APP_PASSWORD_HASH='" . $hash . "'";
        if (preg_match('/^APP_PASSWORD_HASH=.*$/m', $contents)) {
            $contents = preg_replace_callback(
                '/^APP_PASSWORD_HASH=.*$/m',
                static function () use ($hashLine): string { return $hashLine; },
                $contents,
                1
            );
        } elseif (preg_match('/^APP_PASSWORD=.*$/m', $contents)) {
            $contents = preg_replace_callback(
                '/^APP_PASSWORD=.*$/m',
                static function () use ($hashLine): string { return $hashLine; },
                $contents,
                1
            );
        } else {
            throw new \RuntimeException('APP_PASSWORD_HASH is missing.');
        }
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to update the password hash.');
        }
        $contents = preg_replace('/^APP_PASSWORD=\'[^\'\r\n]*\'\r?\n?/m', '', $contents);
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to remove the plaintext password.');
        }

        $secretLine = "SESSION_SECRET='" . bin2hex(random_bytes(32)) . "'";
        if (preg_match('/^SESSION_SECRET=.*$/m', $contents)) {
            $contents = preg_replace_callback(
                '/^SESSION_SECRET=.*$/m',
                static function () use ($secretLine): string { return $secretLine; },
                $contents,
                1
            );
        } else {
            $contents = rtrim($contents) . "\n" . $secretLine . "\n";
        }
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to rotate the session secret.');
        }

        self::writeEnv($path, $contents);
    }

    private static function writeEnv(string $path, string $contents): void
    {
        $metadata = @stat($path);
        $temporary = $path . '.migrate.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write the configuration.');
        }

        $mode = is_array($metadata) ? ((int) $metadata['mode'] & 0777) : 0640;
        chmod($temporary, $mode);
        if (is_array($metadata)) {
            if (function_exists('chown')) @chown($temporary, (int) $metadata['uid']);
            if (function_exists('chgrp')) @chgrp($temporary, (int) $metadata['gid']);
        }

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to replace the configuration.');
        }
    }
}
