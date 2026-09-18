<?php

declare(strict_types=1);

namespace CleanLink;

final class Auth
{
    public const COOKIE_NAME = 'clean_link_session';

    /** @var string */
    private $passwordHash;

    /** @var string */
    private $secret;

    /** @var int */
    private $sessionSeconds;

    public function __construct(string $passwordHash, string $secret, int $sessionSeconds = 86400)
    {
        $this->passwordHash = $passwordHash;
        $this->secret = $secret;
        $this->sessionSeconds = $sessionSeconds;
    }

    public function passwordMatches($candidate): bool
    {
        return is_string($candidate) && password_verify($candidate, $this->passwordHash);
    }

    public function createToken(?int $now = null): string
    {
        $expires = (string) (($now ?? time()) + $this->sessionSeconds);
        return $expires . '.' . $this->sign($expires);
    }

    public function tokenIsValid(?string $token, ?int $now = null): bool
    {
        if (!is_string($token) || !preg_match('/^(\d+)\.([A-Za-z0-9_-]+)$/', $token, $matches)) {
            return false;
        }

        return (int) $matches[1] > ($now ?? time())
            && hash_equals($this->sign($matches[1]), $matches[2]);
    }

    public function isAuthenticated(): bool
    {
        return $this->tokenIsValid($_COOKIE[self::COOKIE_NAME] ?? null);
    }

    public function setSessionCookie(bool $secure): void
    {
        $cookie = self::COOKIE_NAME . '=' . $this->createToken()
            . '; Path=/; Max-Age=' . $this->sessionSeconds
            . '; HttpOnly; SameSite=Strict'
            . ($secure ? '; Secure' : '');
        header('Set-Cookie: ' . $cookie, false);
    }

    public function clearSessionCookie(bool $secure): void
    {
        $cookie = self::COOKIE_NAME . '=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:01 GMT'
            . '; HttpOnly; SameSite=Strict'
            . ($secure ? '; Secure' : '');
        header('Set-Cookie: ' . $cookie, false);
    }

    private function sign(string $value): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $value, $this->secret, true)), '+/', '-_'), '=');
    }
}
