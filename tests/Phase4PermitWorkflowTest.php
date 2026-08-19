<?php
declare(strict_types=1);

use Permits\PermitWorkflow;
use PHPUnit\Framework\TestCase;

final class Phase4PermitWorkflowTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE forms (
            id TEXT PRIMARY KEY,
            status TEXT NOT NULL,
            holder_name TEXT,
            holder_email TEXT,
            holder_id TEXT,
            valid_from DATETIME NULL,
            valid_to DATETIME NULL,
            expires_at DATETIME NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec("CREATE TABLE form_events (
            id TEXT PRIMARY KEY,
            form_id TEXT NOT NULL,
            type TEXT NOT NULL,
            at DATETIME DEFAULT CURRENT_TIMESTAMP,
            by_user TEXT,
            payload TEXT
        )");
    }

    private function insertPermit(
        string $id,
        string $status,
        string $holderEmail = 'holder@example.test',
        ?string $validFrom = null,
        ?string $validTo = null
    ): void {
        $stmt = $this->pdo->prepare('INSERT INTO forms (id,status,holder_name,holder_email,holder_id,valid_from,valid_to,expires_at) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$id, $status, 'Current Holder', $holderEmail, 'holder-user', $validFrom, $validTo, $validTo]);
    }

    public function testInitialHolderAcceptanceStartsValidityClock(): void
    {
        $this->insertPermit('p1', 'awaiting_acceptance');

        $permit = PermitWorkflow::accept(
            $this->pdo,
            'p1',
            'Current Holder',
            'holder@example.test',
            'holder-user',
            240
        );

        self::assertSame('active', $permit['status']);
        self::assertNotEmpty($permit['valid_from']);
        self::assertNotEmpty($permit['valid_to']);
        $from = strtotime((string)$permit['valid_from']);
        $to = strtotime((string)$permit['valid_to']);
        self::assertNotFalse($from);
        self::assertNotFalse($to);
        self::assertEqualsWithDelta(240 * 60, $to - $from, 5);

        $event = $this->pdo->query("SELECT type,payload FROM form_events WHERE form_id='p1'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('holder_accepted', $event['type']);
        self::assertStringContainsString('accepted_email', (string)$event['payload']);
    }

    public function testAcceptanceRejectsWrongHolderEmail(): void
    {
        $this->insertPermit('p2', 'awaiting_acceptance');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match');
        PermitWorkflow::accept($this->pdo, 'p2', 'Someone Else', 'wrong@example.test', null, 60);
    }

    public function testSuspendRevalidateAndReacceptPreserveOriginalExpiry(): void
    {
        $validFrom = date('Y-m-d H:i:s', time() - 600);
        $validTo = date('Y-m-d H:i:s', time() + 3600);
        $this->insertPermit('p3', 'active', 'holder@example.test', $validFrom, $validTo);

        $suspended = PermitWorkflow::suspend($this->pdo, 'p3', 'manager-1', 'Adjacent work changed the agreed conditions.');
        self::assertSame('suspended', $suspended['status']);
        self::assertSame($validTo, $suspended['valid_to']);

        $revalidated = PermitWorkflow::revalidate(
            $this->pdo,
            'p3',
            'manager-1',
            'Controls rechecked and interface resolved.',
            true,
            true
        );
        self::assertSame('awaiting_acceptance', $revalidated['status']);
        self::assertSame($validTo, $revalidated['valid_to']);

        $accepted = PermitWorkflow::accept(
            $this->pdo,
            'p3',
            'Current Holder',
            'holder@example.test',
            'holder-user',
            null
        );
        self::assertSame('active', $accepted['status']);
        self::assertSame($validTo, $accepted['valid_to']);

        $types = $this->pdo->query("SELECT type FROM form_events WHERE form_id='p3' ORDER BY at,rowid")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['permit_suspended', 'permit_revalidated', 'holder_accepted'], $types);
    }

    public function testExpiredSuspensionCannotBeRevalidated(): void
    {
        $this->insertPermit(
            'p4',
            'suspended',
            'holder@example.test',
            date('Y-m-d H:i:s', time() - 7200),
            date('Y-m-d H:i:s', time() - 60)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');
        PermitWorkflow::revalidate($this->pdo, 'p4', 'manager-1', '', true, true);
    }

    public function testShiftHandoverChangesCurrentHolderAndRecordsTwoWayChecks(): void
    {
        $this->insertPermit(
            'p5',
            'active',
            'outgoing@example.test',
            date('Y-m-d H:i:s', time() - 60),
            date('Y-m-d H:i:s', time() + 3600)
        );

        $permit = PermitWorkflow::handover(
            $this->pdo,
            'p5',
            'manager-1',
            'Outgoing Person',
            'Incoming Person',
            'incoming@example.test',
            'incoming-user',
            'Outstanding task and isolation status reviewed face to face.',
            true,
            true,
            true,
            true
        );

        self::assertSame('active', $permit['status']);
        self::assertSame('Incoming Person', $permit['holder_name']);
        self::assertSame('incoming@example.test', $permit['holder_email']);
        self::assertSame('incoming-user', $permit['holder_id']);

        $event = $this->pdo->query("SELECT type,payload FROM form_events WHERE form_id='p5'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('shift_handover', $event['type']);
        $payload = json_decode((string)$event['payload'], true);
        self::assertTrue($payload['safe_state_confirmed']);
        self::assertTrue($payload['controls_reviewed']);
        self::assertTrue($payload['linked_permits_reviewed']);
        self::assertTrue($payload['incoming_acknowledged']);
    }
}
