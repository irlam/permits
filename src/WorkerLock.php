<?php
declare(strict_types=1);

namespace Permits;

use PDO;
use PDOException;
use RuntimeException;

/**
 * A short-lived database lease for CLI workers.
 *
 * The owner token makes releasing a stale lease safe: an older process cannot
 * alter a lease that has already expired and been acquired by another worker.
 */
final class WorkerLock
{
    private PDO $pdo;
    private string $name;
    private string $ownerToken;
    private int $ttlSeconds;
    private string $driver;
    private bool $held = true;

    private function __construct(PDO $pdo, string $name, string $ownerToken, int $ttlSeconds, string $driver)
    {
        $this->pdo = $pdo;
        $this->name = $name;
        $this->ownerToken = $ownerToken;
        $this->ttlSeconds = $ttlSeconds;
        $this->driver = $driver;
    }

    /**
     * Acquire a lease, or return null when another live worker owns it.
     *
     * @throws RuntimeException when the lock table is missing or inaccessible
     */
    public static function acquire(PDO $pdo, string $name, int $ttlSeconds = 1800): ?self
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100 || preg_match('/^[a-z0-9][a-z0-9:._-]*$/i', $name) !== 1) {
            throw new RuntimeException('Worker lock name is invalid.');
        }

        $ttlSeconds = max(60, min(86400, $ttlSeconds));
        $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported worker lock database driver: ' . $driver);
        }

        $ownerToken = bin2hex(random_bytes(32));
        $expiryExpression = self::expiryExpression($driver, $ttlSeconds);

        // Reclaiming an expired row is a single conditional write. If two
        // processes race, only the first can replace the expired token.
        try {
            $update = $pdo->prepare(
                "UPDATE worker_locks
                    SET owner_token = ?, acquired_at = CURRENT_TIMESTAMP, expires_at = {$expiryExpression}
                  WHERE name = ? AND expires_at <= CURRENT_TIMESTAMP"
            );
            $update->execute([$ownerToken, $name]);
            if ($update->rowCount() === 1) {
                return new self($pdo, $name, $ownerToken, $ttlSeconds, $driver);
            }
        } catch (PDOException $exception) {
            throw self::unavailable($exception);
        }

        // For a new lock, the primary key makes this insert atomic. A
        // duplicate means a concurrent process owns the live lease.
        try {
            $insert = $pdo->prepare(
                "INSERT INTO worker_locks (name, owner_token, acquired_at, expires_at)
                 VALUES (?, ?, CURRENT_TIMESTAMP, {$expiryExpression})"
            );
            $insert->execute([$name, $ownerToken]);

            return new self($pdo, $name, $ownerToken, $ttlSeconds, $driver);
        } catch (PDOException $exception) {
            if (self::rowExists($pdo, $name)) {
                return null;
            }

            throw self::unavailable($exception);
        }
    }

    /** Extend the lease while a long-running worker is still making progress. */
    public function refresh(): bool
    {
        if (!$this->held) {
            return false;
        }

        $expiryExpression = self::expiryExpression($this->driver, $this->ttlSeconds);
        $statement = $this->pdo->prepare(
            "UPDATE worker_locks
                SET expires_at = {$expiryExpression}
              WHERE name = ? AND owner_token = ? AND expires_at > CURRENT_TIMESTAMP"
        );
        $statement->execute([$this->name, $this->ownerToken]);
        // MySQL may report zero changed rows when two refreshes happen in the
        // same second and produce the same DATETIME value. Verify ownership in
        // that case instead of incorrectly abandoning a live lease.
        $this->held = $statement->rowCount() === 1
            || self::ownsLiveLease($this->pdo, $this->name, $this->ownerToken);

        return $this->held;
    }

    /** Release only the lease owned by this process. */
    public function release(): bool
    {
        if (!$this->held) {
            return false;
        }

        try {
            // Keep the row as an expired lease. This avoids a delete/insert gap
            // where a new worker could race with another initial acquisition.
            $statement = $this->pdo->prepare(
                'UPDATE worker_locks SET expires_at = CURRENT_TIMESTAMP WHERE name = ? AND owner_token = ?'
            );
            $statement->execute([$this->name, $this->ownerToken]);
            $released = $statement->rowCount() === 1;
        } finally {
            $this->held = false;
        }

        return $released;
    }

    private static function expiryExpression(string $driver, int $ttlSeconds): string
    {
        return $driver === 'mysql'
            ? "DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$ttlSeconds} SECOND)"
            : "datetime(CURRENT_TIMESTAMP, '+{$ttlSeconds} seconds')";
    }

    private static function rowExists(PDO $pdo, string $name): bool
    {
        try {
            $statement = $pdo->prepare('SELECT 1 FROM worker_locks WHERE name = ? LIMIT 1');
            $statement->execute([$name]);

            return $statement->fetchColumn() !== false;
        } catch (PDOException $exception) {
            return false;
        }
    }

    private static function ownsLiveLease(PDO $pdo, string $name, string $ownerToken): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM worker_locks WHERE name = ? AND owner_token = ? AND expires_at > CURRENT_TIMESTAMP LIMIT 1'
        );
        $statement->execute([$name, $ownerToken]);

        return $statement->fetchColumn() !== false;
    }

    private static function unavailable(PDOException $exception): RuntimeException
    {
        return new RuntimeException(
            'Unable to acquire the worker lock. Run php bin/migrate.php and check database access.',
            0,
            $exception
        );
    }
}
