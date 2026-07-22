<?php
declare(strict_types=1);

namespace Permits;

/** Session-bound CSRF tokens for HTML forms and same-origin API requests. */
final class Csrf
{
    private const SESSION_KEY = 'csrf_action_tokens';
    private const TOKEN_LIFETIME = 7200;
    private const MAX_ACTIONS = 32;

    private static function ensureSession(): void
    {
        if (\session_status() === \PHP_SESSION_NONE) {
            if (\function_exists('startSession')) {
                \startSession();
            } else {
                \session_start();
            }
        }
    }

    public static function generateToken(string $action = 'default'): string
    {
        self::ensureSession();
        self::cleanupTokens();

        $existing = $_SESSION[self::SESSION_KEY][$action] ?? null;
        if (\is_array($existing)
            && isset($existing['token'], $existing['time'])
            && (\time() - (int)$existing['time']) <= self::TOKEN_LIFETIME
        ) {
            return (string)$existing['token'];
        }

        $token = \bin2hex(\random_bytes(32));
        $_SESSION[self::SESSION_KEY][$action] = [
            'token' => $token,
            'time' => \time(),
        ];

        if (\count($_SESSION[self::SESSION_KEY]) > self::MAX_ACTIONS) {
            \uasort($_SESSION[self::SESSION_KEY], static fn(array $left, array $right): int =>
                ((int)($left['time'] ?? 0)) <=> ((int)($right['time'] ?? 0))
            );
            $_SESSION[self::SESSION_KEY] = \array_slice(
                $_SESSION[self::SESSION_KEY],
                -self::MAX_ACTIONS,
                null,
                true
            );
        }

        return $token;
    }

    public static function validateToken(
        string $token,
        string $action = 'default',
        bool $consumeToken = false
    ): bool {
        self::ensureSession();
        self::cleanupTokens();

        $stored = $_SESSION[self::SESSION_KEY][$action] ?? null;
        if (!\is_array($stored) || !isset($stored['token'], $stored['time'])) {
            return false;
        }

        if ((\time() - (int)$stored['time']) > self::TOKEN_LIFETIME) {
            unset($_SESSION[self::SESSION_KEY][$action]);
            return false;
        }

        $valid = \hash_equals((string)$stored['token'], $token);
        if ($valid && $consumeToken) {
            unset($_SESSION[self::SESSION_KEY][$action]);
        }

        return $valid;
    }

    public static function getTokenFromRequest(): ?string
    {
        if (isset($_POST['csrf_token']) && \is_string($_POST['csrf_token'])) {
            return $_POST['csrf_token'];
        }

        if (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && \is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return $_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        return null;
    }

    public static function validateRequest(string $action = 'default', bool $consumeToken = false): bool
    {
        $token = self::getTokenFromRequest();
        return $token !== null && self::validateToken($token, $action, $consumeToken);
    }

    public static function getFormField(string $action = 'default'): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . \htmlspecialchars(self::generateToken($action), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            . '">';
    }

    public static function clearTokens(): void
    {
        self::ensureSession();
        unset($_SESSION[self::SESSION_KEY]);
    }

    private static function cleanupTokens(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !\is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
            return;
        }

        $now = \time();
        foreach ($_SESSION[self::SESSION_KEY] as $action => $data) {
            if (!\is_array($data) || ($now - (int)($data['time'] ?? 0)) > self::TOKEN_LIFETIME) {
                unset($_SESSION[self::SESSION_KEY][$action]);
            }
        }
    }
}
