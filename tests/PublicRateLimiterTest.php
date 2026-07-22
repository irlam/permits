<?php
declare(strict_types=1);

use Permits\DatabaseMaintenance;
use Permits\Db;
use Permits\PublicRateLimiter;
use PHPUnit\Framework\TestCase;

final class PublicRateLimiterTest extends TestCase
{
    private PDO $pdo;
    private PublicRateLimiter $limiter;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(Db::class);
        /** @var Db $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::assertSame([], DatabaseMaintenance::ensurePublicRateLimitsTable($db)['errors']);
        $this->pdo = $db->pdo;
        $this->limiter = new PublicRateLimiter($this->pdo);
    }

    public function testAnonymousPermitIdentityIsLimitedPersistently(): void
    {
        $now = 1_700_000_000;
        for ($attempt = 1; $attempt <= PublicRateLimiter::ANONYMOUS_SUBMISSION_IDENTITY_LIMIT; $attempt++) {
            $result = $this->limiter->consumePermitSubmission('203.0.113.8', 'person@example.com', false, $now);
            self::assertFalse($result['limited']);
        }

        $blocked = $this->limiter->consumePermitSubmission('203.0.113.8', 'person@example.com', false, $now);
        self::assertTrue($blocked['limited']);
        self::assertSame('identity', $blocked['scope']);
        self::assertGreaterThan(0, $blocked['retry_after']);
    }

    public function testStatusLookupsHaveASeparateLimit(): void
    {
        $now = 1_700_000_000;
        for ($attempt = 0; $attempt < PublicRateLimiter::LOOKUP_IDENTITY_LIMIT; $attempt++) {
            self::assertFalse(
                $this->limiter->consumeStatusLookup('203.0.113.9', 'person@example.com', $now)['limited']
            );
        }

        $blocked = $this->limiter->consumeStatusLookup('203.0.113.9', 'person@example.com', $now);
        self::assertTrue($blocked['limited']);
        self::assertSame('identity', $blocked['scope']);
    }

    public function testDraftSavesHaveASeparateHigherLimit(): void
    {
        $now = 1_700_000_000;
        for ($attempt = 0; $attempt < PublicRateLimiter::ANONYMOUS_SUBMISSION_IDENTITY_LIMIT + 1; $attempt++) {
            self::assertFalse(
                $this->limiter->consumePermitDraft('203.0.113.12', 'person@example.com', false, $now)['limited']
            );
        }
    }

    public function testWindowResetsAndAuthenticatedUsersHaveAHigherSubmissionLimit(): void
    {
        $now = 1_700_000_000;
        for ($attempt = 0; $attempt < PublicRateLimiter::ANONYMOUS_SUBMISSION_IDENTITY_LIMIT + 1; $attempt++) {
            self::assertFalse(
                $this->limiter->consumePermitSubmission('203.0.113.10', 'staff@example.com', true, $now)['limited']
            );
        }

        $reset = $this->limiter->consumePermitSubmission(
            '203.0.113.10',
            'staff@example.com',
            false,
            $now + PublicRateLimiter::SUBMISSION_WINDOW_SECONDS + 1
        );
        self::assertFalse($reset['limited']);
    }

    public function testOnlyHashesAreStored(): void
    {
        $this->limiter->consumePermitSubmission('203.0.113.11', 'private@example.com', false, 1_700_000_000);
        $values = $this->pdo->query('SELECT key_hash FROM public_rate_limits')->fetchAll(PDO::FETCH_COLUMN);

        self::assertCount(3, $values);
        foreach ($values as $value) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$value);
            self::assertStringNotContainsString('private@example.com', (string)$value);
            self::assertStringNotContainsString('203.0.113.11', (string)$value);
        }
    }
}
