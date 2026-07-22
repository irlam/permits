<?php
declare(strict_types=1);

namespace Permits;

use PDO;
use RuntimeException;

/** Persistent privacy-preserving limits for anonymous public actions. */
final class PublicRateLimiter
{
    public const SUBMISSION_WINDOW_SECONDS = 3600;
    public const ANONYMOUS_SUBMISSION_IP_LIMIT = 15;
    public const ANONYMOUS_SUBMISSION_IDENTITY_LIMIT = 6;
    public const AUTHENTICATED_SUBMISSION_IP_LIMIT = 60;
    public const AUTHENTICATED_SUBMISSION_IDENTITY_LIMIT = 40;
    public const GLOBAL_SUBMISSION_LIMIT = 120;

    public const ANONYMOUS_DRAFT_IP_LIMIT = 30;
    public const ANONYMOUS_DRAFT_IDENTITY_LIMIT = 20;
    public const AUTHENTICATED_DRAFT_IP_LIMIT = 120;
    public const AUTHENTICATED_DRAFT_IDENTITY_LIMIT = 100;
    public const GLOBAL_DRAFT_LIMIT = 300;

    public const LOOKUP_WINDOW_SECONDS = 900;
    public const LOOKUP_IP_LIMIT = 30;
    public const LOOKUP_IDENTITY_LIMIT = 10;
    public const GLOBAL_LOOKUP_LIMIT = 300;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{limited:bool,retry_after:int,scope:string} */
    public function consumePermitSubmission(
        string $ipAddress,
        string $identity,
        bool $authenticated,
        ?int $now = null
    ): array {
        return $this->consume(
            'permit-submission',
            $ipAddress,
            $identity,
            [
                'global' => self::GLOBAL_SUBMISSION_LIMIT,
                'ip' => $authenticated ? self::AUTHENTICATED_SUBMISSION_IP_LIMIT : self::ANONYMOUS_SUBMISSION_IP_LIMIT,
                'identity' => $authenticated ? self::AUTHENTICATED_SUBMISSION_IDENTITY_LIMIT : self::ANONYMOUS_SUBMISSION_IDENTITY_LIMIT,
            ],
            self::SUBMISSION_WINDOW_SECONDS,
            $now
        );
    }

    /** @return array{limited:bool,retry_after:int,scope:string} */
    public function consumeStatusLookup(string $ipAddress, string $identity, ?int $now = null): array
    {
        return $this->consume(
            'status-lookup',
            $ipAddress,
            $identity,
            [
                'global' => self::GLOBAL_LOOKUP_LIMIT,
                'ip' => self::LOOKUP_IP_LIMIT,
                'identity' => self::LOOKUP_IDENTITY_LIMIT,
            ],
            self::LOOKUP_WINDOW_SECONDS,
            $now
        );
    }

    /** @return array{limited:bool,retry_after:int,scope:string} */
    public function consumePermitDraft(
        string $ipAddress,
        string $identity,
        bool $authenticated,
        ?int $now = null
    ): array {
        return $this->consume(
            'permit-draft',
            $ipAddress,
            $identity,
            [
                'global' => self::GLOBAL_DRAFT_LIMIT,
                'ip' => $authenticated ? self::AUTHENTICATED_DRAFT_IP_LIMIT : self::ANONYMOUS_DRAFT_IP_LIMIT,
                'identity' => $authenticated ? self::AUTHENTICATED_DRAFT_IDENTITY_LIMIT : self::ANONYMOUS_DRAFT_IDENTITY_LIMIT,
            ],
            self::SUBMISSION_WINDOW_SECONDS,
            $now
        );
    }

    /**
     * @param array{global:int,ip:int,identity:int} $limits
     * @return array{limited:bool,retry_after:int,scope:string}
     */
    private function consume(
        string $action,
        string $ipAddress,
        string $identity,
        array $limits,
        int $windowSeconds,
        ?int $now
    ): array {
        $now ??= time();
        $ipAddress = trim($ipAddress) !== '' ? trim($ipAddress) : 'unknown';
        $identity = strtolower(trim($identity));
        if ($identity === '') {
            throw new RuntimeException('A public action identity is required for rate limiting.');
        }

        $this->deleteExpiredRows($now);
        $keys = [
            'global' => self::key($action, 'global', 'all'),
            'ip' => self::key($action, 'ip', $ipAddress),
            'identity' => self::key($action, 'identity', $identity),
        ];

        $limitedScope = '';
        $retryAfter = 0;
        foreach ($keys as $scope => $key) {
            $bucket = $this->increment($key, $now, $windowSeconds);
            if ($bucket['attempts'] > $limits[$scope]) {
                $limitedScope = $limitedScope === '' ? $scope : $limitedScope;
                $retryAfter = max(
                    $retryAfter,
                    $windowSeconds - max(0, $now - $bucket['window_started_at'])
                );
            }
        }

        return [
            'limited' => $limitedScope !== '',
            'retry_after' => $limitedScope !== '' ? max(1, $retryAfter) : 0,
            'scope' => $limitedScope,
        ];
    }

    /** @return array{attempts:int,window_started_at:int} */
    private function increment(string $key, int $now, int $windowSeconds): array
    {
        $threshold = $now - $windowSeconds;
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $sql = 'INSERT INTO public_rate_limits (key_hash, attempts, window_started_at, last_attempt_at) ' .
                'VALUES (?, 1, ?, ?) ON DUPLICATE KEY UPDATE ' .
                'attempts = IF(window_started_at <= ?, 1, attempts + 1), ' .
                'window_started_at = IF(window_started_at <= ?, ?, window_started_at), ' .
                'last_attempt_at = ?';
        } elseif ($driver === 'sqlite') {
            $sql = 'INSERT INTO public_rate_limits (key_hash, attempts, window_started_at, last_attempt_at) ' .
                'VALUES (?, 1, ?, ?) ON CONFLICT(key_hash) DO UPDATE SET ' .
                'attempts = CASE WHEN window_started_at <= ? THEN 1 ELSE attempts + 1 END, ' .
                'window_started_at = CASE WHEN window_started_at <= ? THEN ? ELSE window_started_at END, ' .
                'last_attempt_at = ?';
        } else {
            throw new RuntimeException('Unsupported database driver for public action throttling.');
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$key, $now, $now, $threshold, $threshold, $now, $now]);
        $read = $this->pdo->prepare('SELECT attempts, window_started_at FROM public_rate_limits WHERE key_hash = ?');
        $read->execute([$key]);
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Unable to read the public action rate limit.');
        }

        return [
            'attempts' => max(0, (int)$row['attempts']),
            'window_started_at' => max(0, (int)$row['window_started_at']),
        ];
    }

    private function deleteExpiredRows(int $now): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM public_rate_limits WHERE last_attempt_at < ?');
        $stmt->execute([$now - (self::SUBMISSION_WINDOW_SECONDS * 2)]);
    }

    private static function key(string $action, string $scope, string $value): string
    {
        return hash('sha256', $action . "\n" . $scope . "\n" . $value);
    }
}
