<?php
declare(strict_types=1);

namespace Permits;

use PDO;

/** Database-backed login throttling that cannot be bypassed by clearing cookies. */
final class LoginRateLimiter
{
    public const WINDOW_SECONDS = 900;
    public const MAX_FAILURES = 5;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{limited:bool,retry_after:int,attempts:int} */
    public function status(string $email, string $ipAddress, ?int $now = null): array
    {
        $now ??= time();
        $key = $this->key($email, $ipAddress);
        $stmt = $this->pdo->prepare('SELECT attempts, window_started_at FROM login_attempts WHERE key_hash = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['limited' => false, 'retry_after' => 0, 'attempts' => 0];
        }

        $startedAt = (int) ($row['window_started_at'] ?? 0);
        $attempts = max(0, (int) ($row['attempts'] ?? 0));
        $elapsed = max(0, $now - $startedAt);
        if ($startedAt <= 0 || $elapsed >= self::WINDOW_SECONDS) {
            $this->clear($email, $ipAddress);
            return ['limited' => false, 'retry_after' => 0, 'attempts' => 0];
        }

        $limited = $attempts >= self::MAX_FAILURES;
        return [
            'limited' => $limited,
            'retry_after' => $limited ? max(1, self::WINDOW_SECONDS - $elapsed) : 0,
            'attempts' => $attempts,
        ];
    }

    /** Record one failed login using an atomic engine-specific upsert. */
    public function recordFailure(string $email, string $ipAddress, ?int $now = null): void
    {
        $now ??= time();
        $threshold = $now - self::WINDOW_SECONDS;
        $key = $this->key($email, $ipAddress);
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO login_attempts (key_hash, attempts, window_started_at, last_failed_at) ' .
                'VALUES (?, 1, ?, ?) ON DUPLICATE KEY UPDATE ' .
                'attempts = IF(window_started_at <= ?, 1, attempts + 1), ' .
                'window_started_at = IF(window_started_at <= ?, ?, window_started_at), ' .
                'last_failed_at = ?'
            );
            $stmt->execute([$key, $now, $now, $threshold, $threshold, $now, $now]);
            return;
        }

        if ($driver === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO login_attempts (key_hash, attempts, window_started_at, last_failed_at) ' .
                'VALUES (?, 1, ?, ?) ON CONFLICT(key_hash) DO UPDATE SET ' .
                'attempts = CASE WHEN window_started_at <= ? THEN 1 ELSE attempts + 1 END, ' .
                'window_started_at = CASE WHEN window_started_at <= ? THEN ? ELSE window_started_at END, ' .
                'last_failed_at = ?'
            );
            $stmt->execute([$key, $now, $now, $threshold, $threshold, $now, $now]);
            return;
        }

        throw new \RuntimeException('Unsupported database driver for login throttling.');
    }

    public function clear(string $email, string $ipAddress): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE key_hash = ?');
        $stmt->execute([$this->key($email, $ipAddress)]);
    }

    private function key(string $email, string $ipAddress): string
    {
        $normalisedEmail = strtolower(trim($email));
        $normalisedIp = trim($ipAddress) !== '' ? trim($ipAddress) : 'unknown';
        return hash('sha256', $normalisedEmail . "\n" . $normalisedIp);
    }
}
