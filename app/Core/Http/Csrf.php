<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Double-submit-cookie CSRF: a token is set in a cookie and must be echoed
 * back as a hidden form field; comparing the two requires no server-side
 * session store of its own, just a constant-time string comparison.
 */
final class Csrf
{
    public const COOKIE_NAME = 'hinace_csrf';

    public static function issue(): string
    {
        $token = bin2hex(random_bytes(32));
        setcookie(self::COOKIE_NAME, $token, [
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
            'path' => '/',
        ]);

        return $token;
    }

    public static function verify(Request $request): bool
    {
        $cookieToken = $request->cookies[self::COOKIE_NAME] ?? null;
        $formToken = $request->body['_csrf'] ?? null;

        if (!is_string($cookieToken) || !is_string($formToken)) {
            return false;
        }

        return hash_equals($cookieToken, $formToken);
    }
}
