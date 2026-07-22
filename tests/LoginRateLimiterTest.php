<?php
declare(strict_types=1);

use Permits\LoginRateLimiter;
use PHPUnit\Framework\TestCase;

final class LoginRateLimiterTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(
            'CREATE TABLE login_attempts (' .
            'key_hash TEXT PRIMARY KEY, attempts INTEGER NOT NULL, ' .
            'window_started_at INTEGER NOT NULL, last_failed_at INTEGER NOT NULL)'
        );
    }

    public function testFailuresPersistOutsideTheBrowserSessionAndExpire(): void
    {
        $now = 1_800_000_000;
        $firstRequest = new LoginRateLimiter($this->pdo);
        for ($attempt = 0; $attempt < LoginRateLimiter::MAX_FAILURES; $attempt++) {
            $firstRequest->recordFailure(' OWNER@Example.test ', '203.0.113.10', $now + $attempt);
        }

        $newRequest = new LoginRateLimiter($this->pdo);
        $status = $newRequest->status('owner@example.test', '203.0.113.10', $now + 5);
        self::assertTrue($status['limited']);
        self::assertSame(5, $status['attempts']);
        self::assertGreaterThan(0, $status['retry_after']);

        self::assertFalse($newRequest->status('owner@example.test', '203.0.113.11', $now + 5)['limited']);
        self::assertFalse($newRequest->status('other@example.test', '203.0.113.10', $now + 5)['limited']);

        $expired = $newRequest->status(
            'owner@example.test',
            '203.0.113.10',
            $now + LoginRateLimiter::WINDOW_SECONDS + 1
        );
        self::assertFalse($expired['limited']);
        self::assertSame(0, $expired['attempts']);
    }

    public function testSuccessfulLoginCanClearTheFailureWindow(): void
    {
        $limiter = new LoginRateLimiter($this->pdo);
        $limiter->recordFailure('person@example.test', '2001:db8::1', 1_800_000_000);
        self::assertSame(1, $limiter->status('person@example.test', '2001:db8::1', 1_800_000_001)['attempts']);

        $limiter->clear('person@example.test', '2001:db8::1');
        self::assertSame(0, $limiter->status('person@example.test', '2001:db8::1', 1_800_000_002)['attempts']);
    }
}
