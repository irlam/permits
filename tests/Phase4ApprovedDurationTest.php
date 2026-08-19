<?php
declare(strict_types=1);

use Permits\PermitWorkflow;
use PHPUnit\Framework\TestCase;

final class Phase4ApprovedDurationTest extends TestCase
{
    public function testApprovalEventPinsDurationEvenIfCallerPresetChangesLater(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE forms (
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
        $pdo->exec("CREATE TABLE form_events (
            id TEXT PRIMARY KEY,
            form_id TEXT NOT NULL,
            type TEXT NOT NULL,
            at DATETIME DEFAULT CURRENT_TIMESTAMP,
            by_user TEXT,
            payload TEXT
        )");
        $pdo->exec("INSERT INTO forms (id,status,holder_name,holder_email) VALUES ('permit-1','awaiting_acceptance','Holder','holder@example.test')");
        $approvalPayload = json_encode(['duration_minutes' => 60, 'duration_label' => '1 hour']);
        $stmt = $pdo->prepare("INSERT INTO form_events (id,form_id,type,payload) VALUES ('approval-1','permit-1','permit_approved',?)");
        $stmt->execute([$approvalPayload]);

        self::assertSame(60, PermitWorkflow::approvedDurationMinutes($pdo, 'permit-1'));

        // Simulate an admin changing the current preset / caller falling back to
        // 4 hours after the permit was approved. The original 60 minutes must win.
        $permit = PermitWorkflow::accept(
            $pdo,
            'permit-1',
            'Holder',
            'holder@example.test',
            null,
            240
        );

        $from = strtotime((string)$permit['valid_from']);
        $to = strtotime((string)$permit['valid_to']);
        self::assertNotFalse($from);
        self::assertNotFalse($to);
        self::assertEqualsWithDelta(3600, $to - $from, 5);
    }
}
