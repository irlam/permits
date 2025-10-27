<?php
declare(strict_types=1);

use Permits\Db;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

require_once __DIR__ . '/../src/check-expiry.php';

final class CheckExpiryTest extends TestCase
{
    private PDO $pdo;
    private object $db;

    protected function setUp(): void
    {
        // Create in-memory SQLite database for testing
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Create a minimal Db-like object for testing
        $this->db = new class($this->pdo) {
            public PDO $pdo;

            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }
        };

        // Create the forms table
        $this->pdo->exec("
            CREATE TABLE forms (
                id TEXT PRIMARY KEY,
                ref TEXT NOT NULL,
                ref_number TEXT,
                status TEXT NOT NULL,
                valid_to TEXT,
                updated_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create the form_events table
        $this->pdo->exec("
            CREATE TABLE form_events (
                id TEXT PRIMARY KEY,
                form_id TEXT NOT NULL,
                type TEXT NOT NULL,
                by_user TEXT,
                payload TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create activity_log table for logging
        $this->pdo->exec("
            CREATE TABLE activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id TEXT,
                type TEXT NOT NULL,
                description TEXT,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    protected function tearDown(): void
    {
        // Clean up is automatic with in-memory SQLite
        unset($this->pdo, $this->db);
    }

    public function testExpiresIssuedPermitWithPastValidTo(): void
    {
        $permitId = Uuid::uuid4()->toString();
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));

        $this->pdo->exec("
            INSERT INTO forms (id, ref, status, valid_to)
            VALUES ('{$permitId}', 'TEST-001', 'issued', '{$pastDate}')
        ");

        $expired = check_and_expire_permits($this->db);

        $this->assertSame(1, $expired, 'Should expire exactly 1 permit');

        $permit = $this->pdo->query("SELECT status FROM forms WHERE id = '{$permitId}'")->fetch();
        $this->assertSame('expired', $permit['status'], 'Permit status should be updated to expired');

        // Verify event was logged
        $event = $this->pdo->query("SELECT * FROM form_events WHERE form_id = '{$permitId}'")->fetch();
        $this->assertNotNull($event, 'An event should be logged');
        $this->assertSame('status_changed', $event['type']);
        $this->assertSame('auto-expiry', $event['by_user']);

        $payload = json_decode($event['payload'], true);
        $this->assertSame('issued', $payload['previous_status']);
        $this->assertSame('expired', $payload['new_status']);
        $this->assertSame('validity_window_elapsed', $payload['reason']);
    }

    public function testExpiresActivePermitWithPastValidTo(): void
    {
        $permitId = Uuid::uuid4()->toString();
        $pastDate = date('Y-m-d H:i:s', strtotime('-2 hours'));

        $this->pdo->exec("
            INSERT INTO forms (id, ref, status, valid_to)
            VALUES ('{$permitId}', 'TEST-002', 'active', '{$pastDate}')
        ");

        $expired = check_and_expire_permits($this->db);

        $this->assertSame(1, $expired, 'Should expire exactly 1 active permit');

        $permit = $this->pdo->query("SELECT status FROM forms WHERE id = '{$permitId}'")->fetch();
        $this->assertSame('expired', $permit['status']);
    }

    public function testDoesNotExpirePermitWithFutureValidTo(): void
    {
        $permitId = Uuid::uuid4()->toString();
        $futureDate = date('Y-m-d H:i:s', strtotime('+1 day'));

        $this->pdo->exec("
            INSERT INTO forms (id, ref, status, valid_to)
            VALUES ('{$permitId}', 'TEST-003', 'issued', '{$futureDate}')
        ");

        $expired = check_and_expire_permits($this->db);

        $this->assertSame(0, $expired, 'Should not expire any permits');

        $permit = $this->pdo->query("SELECT status FROM forms WHERE id = '{$permitId}'")->fetch();
        $this->assertSame('issued', $permit['status'], 'Permit status should remain issued');
    }

    public function testDoesNotExpirePermitWithNullValidTo(): void
    {
        $permitId = Uuid::uuid4()->toString();

        $this->pdo->exec("
            INSERT INTO forms (id, ref, status, valid_to)
            VALUES ('{$permitId}', 'TEST-004', 'issued', NULL)
        ");

        $expired = check_and_expire_permits($this->db);

        $this->assertSame(0, $expired, 'Should not expire permits with NULL valid_to');

        $permit = $this->pdo->query("SELECT status FROM forms WHERE id = '{$permitId}'")->fetch();
        $this->assertSame('issued', $permit['status']);
    }

    public function testDoesNotExpirePermitWithEmptyValidTo(): void
    {
        $permitId = Uuid::uuid4()->toString();

        $this->pdo->exec("
            INSERT INTO forms (id, ref, status, valid_to)
            VALUES ('{$permitId}', 'TEST-005', 'issued', '')
        ");

        $expired = check_and_expire_permits($this->db);

        $this->assertSame(0, $expired, 'Should not expire permits with empty valid_to');

        $permit = $this->pdo->query("SELECT status FROM forms WHERE id = '{$permitId}'")->fetch();
        $this->assertSame('issued', $permit['status']);
    }

    public function testOnlyExpiresIssuedAndActiveStatuses(): void
    {
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));
        $statuses = ['draft', 'pending', 'rejected', 'closed', 'expired'];

        foreach ($statuses as $status) {
            $permitId = Uuid::uuid4()->toString();
            $this->pdo->exec("
                INSERT INTO forms (id, ref, status, valid_to)
                VALUES ('{$permitId}', 'TEST-{$status}', '{$status}', '{$pastDate}')
            ");
        }

        $expired = check_and_expire_permits($this->db);

        $this->assertSame(0, $expired, 'Should not expire permits with statuses other than issued/active');

        foreach ($statuses as $status) {
            $permit = $this->pdo->query("SELECT status FROM forms WHERE ref = 'TEST-{$status}'")->fetch();
            $this->assertSame($status, $permit['status'], "Permit with status '{$status}' should remain unchanged");
        }
    }

    public function testExpiresMultiplePermitsAtOnce(): void
    {
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));

        for ($i = 1; $i <= 5; $i++) {
            $permitId = Uuid::uuid4()->toString();
            $status = ($i % 2 === 0) ? 'active' : 'issued';
            $this->pdo->exec("
                INSERT INTO forms (id, ref, status, valid_to)
                VALUES ('{$permitId}', 'MULTI-{$i}', '{$status}', '{$pastDate}')
            ");
        }

        $expired = check_and_expire_permits($this->db);

        $this->assertSame(5, $expired, 'Should expire all 5 permits');

        $allExpired = $this->pdo->query("SELECT COUNT(*) as cnt FROM forms WHERE status = 'expired'")->fetch();
        $this->assertSame(5, (int)$allExpired['cnt']);

        // Verify all events were logged
        $eventCount = $this->pdo->query("SELECT COUNT(*) as cnt FROM form_events")->fetch();
        $this->assertSame(5, (int)$eventCount['cnt']);
    }

    public function testMixedExpiryScenario(): void
    {
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));
        $futureDate = date('Y-m-d H:i:s', strtotime('+1 day'));

        // Should expire - issued with past date
        $permit1 = Uuid::uuid4()->toString();
        $this->pdo->exec("INSERT INTO forms (id, ref, status, valid_to) VALUES ('{$permit1}', 'MIXED-1', 'issued', '{$pastDate}')");

        // Should expire - active with past date
        $permit2 = Uuid::uuid4()->toString();
        $this->pdo->exec("INSERT INTO forms (id, ref, status, valid_to) VALUES ('{$permit2}', 'MIXED-2', 'active', '{$pastDate}')");

        // Should NOT expire - issued with future date
        $permit3 = Uuid::uuid4()->toString();
        $this->pdo->exec("INSERT INTO forms (id, ref, status, valid_to) VALUES ('{$permit3}', 'MIXED-3', 'issued', '{$futureDate}')");

        // Should NOT expire - draft with past date
        $permit4 = Uuid::uuid4()->toString();
        $this->pdo->exec("INSERT INTO forms (id, ref, status, valid_to) VALUES ('{$permit4}', 'MIXED-4', 'draft', '{$pastDate}')");

        // Should NOT expire - issued with NULL date
        $permit5 = Uuid::uuid4()->toString();
        $this->pdo->exec("INSERT INTO forms (id, ref, status, valid_to) VALUES ('{$permit5}', 'MIXED-5', 'issued', NULL)");

        $expired = check_and_expire_permits($this->db);

        $this->assertSame(2, $expired, 'Should expire exactly 2 permits');

        // Verify which ones were expired
        $expiredPermits = $this->pdo->query("SELECT ref FROM forms WHERE status = 'expired' ORDER BY ref")->fetchAll();
        $this->assertCount(2, $expiredPermits);
        $this->assertSame('MIXED-1', $expiredPermits[0]['ref']);
        $this->assertSame('MIXED-2', $expiredPermits[1]['ref']);

        // Verify which ones were NOT expired
        $notExpired = $this->pdo->query("SELECT ref, status FROM forms WHERE status != 'expired' ORDER BY ref")->fetchAll();
        $this->assertCount(3, $notExpired);
        $this->assertSame('issued', $notExpired[0]['status']); // MIXED-3
        $this->assertSame('draft', $notExpired[1]['status']);  // MIXED-4
        $this->assertSame('issued', $notExpired[2]['status']); // MIXED-5
    }

    public function testUpdatedAtTimestampIsSet(): void
    {
        $permitId = Uuid::uuid4()->toString();
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));

        $this->pdo->exec("
            INSERT INTO forms (id, ref, status, valid_to, updated_at)
            VALUES ('{$permitId}', 'TEST-006', 'issued', '{$pastDate}', NULL)
        ");

        check_and_expire_permits($this->db);

        $permit = $this->pdo->query("SELECT updated_at FROM forms WHERE id = '{$permitId}'")->fetch();
        $this->assertNotNull($permit['updated_at'], 'updated_at should be set');
        $this->assertNotEmpty($permit['updated_at'], 'updated_at should not be empty');
    }

    public function testUsesRefNumberAsFallback(): void
    {
        $permitId = Uuid::uuid4()->toString();
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));

        $this->pdo->exec("
            INSERT INTO forms (id, ref, ref_number, status, valid_to)
            VALUES ('{$permitId}', '', 'REF-NUM-123', 'issued', '{$pastDate}')
        ");

        $expired = check_and_expire_permits($this->db);

        $this->assertSame(1, $expired);

        $event = $this->pdo->query("SELECT payload FROM form_events WHERE form_id = '{$permitId}'")->fetch();
        $payload = json_decode($event['payload'], true);
        $this->assertSame('issued', $payload['previous_status']);
        $this->assertSame('expired', $payload['new_status']);
    }

    public function testHandlesEmptyDatabase(): void
    {
        $expired = check_and_expire_permits($this->db);
        $this->assertSame(0, $expired, 'Should return 0 when no permits exist');
    }

    public function testContinuesWhenEventStatementFails(): void
    {
        $pastDate = date('Y-m-d H:i:s', strtotime('-1 day'));

        // Create 3 permits
        for ($i = 1; $i <= 3; $i++) {
            $permitId = Uuid::uuid4()->toString();
            $this->pdo->exec("
                INSERT INTO forms (id, ref, status, valid_to)
                VALUES ('{$permitId}', 'PARTIAL-{$i}', 'issued', '{$pastDate}')
            ");
        }

        // Add a constraint that will cause event inserts to fail
        // This tests that the function continues processing permits even when event logging fails
        $this->pdo->exec("DROP TABLE form_events");
        $this->pdo->exec("
            CREATE TABLE form_events (
                id TEXT PRIMARY KEY,
                form_id TEXT NOT NULL,
                type TEXT NOT NULL CHECK(type = 'impossible_value'),
                by_user TEXT,
                payload TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Function should update permits even if event logging fails
        $expired = check_and_expire_permits($this->db);

        // All permits should still be updated despite event logging failure
        $this->assertSame(3, $expired, 'Should update all permits even if event logging fails');

        $expiredCount = $this->pdo->query("SELECT COUNT(*) as cnt FROM forms WHERE status = 'expired'")->fetch();
        $this->assertSame(3, (int)$expiredCount['cnt']);

        // Verify that no events were logged due to the constraint
        $eventCount = $this->pdo->query("SELECT COUNT(*) as cnt FROM form_events")->fetch();
        $this->assertSame(0, (int)$eventCount['cnt'], 'No events should be logged due to constraint failure');
    }
}
