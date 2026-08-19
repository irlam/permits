<?php
declare(strict_types=1);

use Permits\PermitLinks;
use PHPUnit\Framework\TestCase;

final class Phase4PermitLinksTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE forms (
            id TEXT PRIMARY KEY,
            ref_number TEXT,
            status TEXT,
            template_id TEXT,
            requires_approval INTEGER DEFAULT 1,
            valid_to DATETIME NULL,
            site_block TEXT
        )");
        $this->pdo->exec("CREATE TABLE form_templates (id TEXT PRIMARY KEY, name TEXT)");
        $this->pdo->exec("CREATE TABLE permit_links (
            id TEXT PRIMARY KEY,
            form_a_id TEXT NOT NULL,
            form_b_id TEXT NOT NULL,
            relation_type TEXT NOT NULL,
            note TEXT,
            created_by TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(form_a_id, form_b_id, relation_type)
        )");
        $this->pdo->exec("INSERT INTO form_templates (id,name) VALUES ('t1','Hot Works Permit'),('t2','Permit to Dig')");
    }

    private function addPermit(string $id, string $ref, string $status, int $requiresApproval = 1, ?string $validTo = null, string $template = 't1'): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO forms (id,ref_number,status,template_id,requires_approval,valid_to,site_block) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$id, $ref, $status, $template, $requiresApproval, $validTo, 'Area A']);
    }

    public function testLinksAreSymmetricAndDuplicateSafe(): void
    {
        $this->addPermit('a', 'PTW-A', 'active');
        $this->addPermit('b', 'PTW-B', 'active', 1, null, 't2');

        $first = PermitLinks::add($this->pdo, 'b', 'a', 'simops', 'Coordinate exclusion zones.', 'manager');
        $second = PermitLinks::add($this->pdo, 'a', 'b', 'simops', 'Duplicate attempt.', 'manager');

        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM permit_links')->fetchColumn());

        $fromA = PermitLinks::forPermit($this->pdo, 'a');
        $fromB = PermitLinks::forPermit($this->pdo, 'b');
        self::assertCount(1, $fromA);
        self::assertCount(1, $fromB);
        self::assertSame('PTW-B', $fromA[0]['ref_number']);
        self::assertSame('PTW-A', $fromB[0]['ref_number']);
        self::assertSame('SIMOPS / simultaneous work', $fromA[0]['relation_label']);
    }

    public function testInspectionRecordsCannotBeLinkedAsPermits(): void
    {
        $this->addPermit('permit', 'PTW-1', 'active');
        $this->addPermit('inspection', 'INS-1', 'closed', 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Inspection records');
        PermitLinks::add($this->pdo, 'permit', 'inspection', 'related', '', 'manager');
    }

    public function testOnlyActiveExplicitConflictBlocksStartWork(): void
    {
        $future = date('Y-m-d H:i:s', time() + 3600);
        $this->addPermit('target', 'PTW-TARGET', 'active', 1, $future);
        $this->addPermit('active-conflict', 'PTW-ACTIVE', 'active', 1, $future, 't2');
        $this->addPermit('suspended-conflict', 'PTW-SUSP', 'suspended', 1, $future, 't2');
        $this->addPermit('pending-conflict', 'PTW-PENDING', 'pending_approval', 1, null, 't2');

        PermitLinks::add($this->pdo, 'target', 'active-conflict', 'conflict', 'Cannot overlap.', 'manager');
        PermitLinks::add($this->pdo, 'target', 'suspended-conflict', 'conflict', 'Sequenced work.', 'manager');
        PermitLinks::add($this->pdo, 'target', 'pending-conflict', 'conflict', 'Future work.', 'manager');

        $blockers = PermitLinks::blockingConflicts($this->pdo, 'target');
        self::assertCount(1, $blockers);
        self::assertSame('PTW-ACTIVE', $blockers[0]['ref_number']);
    }

    public function testFindByReferenceIgnoresInspectionRecords(): void
    {
        $this->addPermit('permit', 'PTW-123', 'active');
        $this->addPermit('inspection', 'INS-123', 'closed', 0);

        self::assertSame('permit', PermitLinks::findByReference($this->pdo, 'ptw-123')['id']);
        self::assertNull(PermitLinks::findByReference($this->pdo, 'INS-123'));
    }
}
