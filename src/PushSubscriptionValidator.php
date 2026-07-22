<?php
declare(strict_types=1);

namespace Permits;

use InvalidArgumentException;

/** Validates browser Push API subscriptions before an endpoint is stored or used. */
final class PushSubscriptionValidator
{
    public const MAX_ENDPOINT_LENGTH = 4096;

    /** @var list<string> */
    private const EXACT_HOSTS = [
        'android.googleapis.com',
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
    ];

    /** @var list<string> */
    private const HOST_SUFFIXES = [
        '.notify.windows.com',
    ];

    /** @return array{endpoint:string,p256dh:string,auth:string} */
    public static function validate(string $endpoint, string $p256dh, string $auth): array
    {
        $endpoint = trim($endpoint);
        $p256dh = trim($p256dh);
        $auth = trim($auth);

        if ($endpoint === '' || strlen($endpoint) > self::MAX_ENDPOINT_LENGTH) {
            throw new InvalidArgumentException('The push subscription address is invalid.');
        }

        $parts = parse_url($endpoint);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new InvalidArgumentException('The push subscription must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The push subscription address is invalid.');
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            throw new InvalidArgumentException('The push subscription must use the standard HTTPS port.');
        }

        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        $path = (string)($parts['path'] ?? '');
        if ($host === '' || $path === '' || $path === '/') {
            throw new InvalidArgumentException('The push subscription address is incomplete.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || !self::isTrustedPushHost($host)) {
            throw new InvalidArgumentException('The push subscription service is not supported.');
        }

        $publicKey = self::decodeBase64Url($p256dh, 'public key');
        if (strlen($publicKey) !== 65 || $publicKey[0] !== "\x04") {
            throw new InvalidArgumentException('The push subscription public key is invalid.');
        }

        $authSecret = self::decodeBase64Url($auth, 'authentication key');
        if (strlen($authSecret) !== 16) {
            throw new InvalidArgumentException('The push subscription authentication key is invalid.');
        }

        return ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth];
    }

    private static function isTrustedPushHost(string $host): bool
    {
        if (in_array($host, self::EXACT_HOSTS, true)) {
            return true;
        }

        foreach (self::HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function decodeBase64Url(string $value, string $label): string
    {
        if ($value === '' || strlen($value) > 200 || preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('The push subscription ' . $label . ' is invalid.');
        }

        $unpadded = rtrim($value, '=');
        $padding = (4 - (strlen($unpadded) % 4)) % 4;
        $decoded = base64_decode(strtr($unpadded, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded)) {
            throw new InvalidArgumentException('The push subscription ' . $label . ' is invalid.');
        }

        return $decoded;
    }
}
