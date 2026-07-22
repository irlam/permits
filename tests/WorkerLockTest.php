<?php
declare(strict_types=1);

use Permits\WorkerLock;
use PHPUnit\Framework\TestCase;

final class WorkerLockTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE worker_locks (
                name TEXT NOT NULL PRIMARY KEY,
                owner_token TEXT NOT NULL,
                acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL
            )
        SQL);
    }

    public function testOnlyOneWorkerCanHoldALiveLease(): void
    {
        $first = WorkerLock::acquire($this->pdo, 'expiry-email');
        self::assertNotNull($first);
        self::assertNull(WorkerLock::acquire($this->pdo, 'expiry-email'));

        self::assertTrue($first->release());
        $second = WorkerLock::acquire($this->pdo, 'expiry-email');
        self::assertNotNull($second);
        self::assertTrue($second->release());
    }

    public function testExpiredLeaseCanBeReclaimedWithoutOldOwnerDeletingIt(): void
    {
        $old = WorkerLock::acquire($this->pdo, 'push-reminders');
        self::assertNotNull($old);
        $this->pdo->exec("UPDATE worker_locks SET expires_at = datetime(CURRENT_TIMESTAMP, '-1 second')");

        $replacement = WorkerLock::acquire($this->pdo, 'push-reminders');
        self::assertNotNull($replacement);
        self::assertFalse($old->release());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM worker_locks')->fetchColumn());
        self::assertTrue($replacement->release());
    }

    public function testLeaseCanBeRefreshedWhileItIsHeld(): void
    {
        $lock = WorkerLock::acquire($this->pdo, 'expiry-email', 60);
        self::assertNotNull($lock);
        self::assertTrue($lock->refresh());
        self::assertTrue($lock->release());
        self::assertFalse($lock->refresh());
    }

    public function testMissingTableProducesAnActionableError(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php bin/migrate.php');
        WorkerLock::acquire($pdo, 'expiry-email');
    }

    public function testMalformedLockTableIsNotMistakenForAnActiveWorker(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE worker_locks (name TEXT PRIMARY KEY)');
        $pdo->exec("INSERT INTO worker_locks (name) VALUES ('expiry-email')");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php bin/migrate.php');
        WorkerLock::acquire($pdo, 'expiry-email');
    }
}
