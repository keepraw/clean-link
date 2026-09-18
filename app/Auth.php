<?php

declare(strict_types=1);

namespace CleanLink;

final class Auth
{
    public const COOKIE_NAME = 'clean_link_session';
    private const SESSION_SECONDS = 604800;

    /** @var string */
    private $password;

    /** @var string */
    private $secret;

    public function __construct(string $password, string $secret)
    {
        $this->password = $password;
        $this->secret = $secret;
    }

    public function passwordMatches($candidate): bool
    {
        return is_string($candidate) && hash_equals($this->password, $candidate);
    }

    public function createToken(?int $now = null): string
    {
        $expires = (string) (($now ?? time()) + self::SESSION_SECONDS);
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
            . '; Path=/; Max-Age=' . self::SESSION_SECONDS
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
