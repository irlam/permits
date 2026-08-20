<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Permits\PublicPermitStatus;

final class PublicPermitStatusTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE form_templates (id TEXT PRIMARY KEY, name TEXT NOT NULL)");
        $this->pdo->exec("CREATE TABLE forms (
            id TEXT PRIMARY KEY,
            ref_number TEXT,
            template_id TEXT NOT NULL,
            status TEXT NOT NULL,
            requires_approval INTEGER NOT NULL DEFAULT 1,
            site_block TEXT,
            holder_name TEXT,
            holder_email TEXT,
            holder_phone TEXT,
            form_data TEXT,
            approval_notes TEXT,
            unique_link TEXT,
            created_at DATETIME,
            valid_from DATETIME,
            valid_to DATETIME
        )");
        $this->pdo->exec("INSERT INTO form_templates (id, name) VALUES ('hot', 'Hot Works Permit')");
    }

    public function testCurrentFeedShowsOperationalAndRecentlyExpiredStatesWithoutPersonalData(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO forms
            (id, ref_number, template_id, status, requires_approval, site_block, holder_name, holder_email, holder_phone, form_data, approval_notes, unique_link, created_at, valid_from, valid_to)
            VALUES (?, ?, 'hot', ?, ?, ?, 'Private Person', 'private@example.com', '07123456789', ?, 'Private note', 'private-token', ?, ?, ?)");

        $now = time();
        $insert->execute(['1','PTW-001','pending_approval',1,null,json_encode(['location'=>'Roof A','secret'=>true]),date('Y-m-d H:i:s',$now-300),null,null]);
        $insert->execute(['2','PTW-002','active',1,'Plant Room',json_encode(['location'=>'Should not override site_block']),date('Y-m-d H:i:s',$now-600),date('Y-m-d H:i:s',$now-300),date('Y-m-d H:i:s',$now+3600)]);
        // A delayed expiry worker must not make an out-of-time permit disappear or look active.
        $insert->execute(['3','PTW-003','active',1,'Old Area',json_encode(['location'=>'Old Area']),date('Y-m-d H:i:s',$now-7200),date('Y-m-d H:i:s',$now-7200),date('Y-m-d H:i:s',$now-3600)]);
        $insert->execute(['4','PTW-004','rejected',1,'Hidden Area',json_encode(['location'=>'Hidden Area']),date('Y-m-d H:i:s',$now-300),null,null]);
        $insert->execute(['5','PTW-005','awaiting_acceptance',1,'Level 3',json_encode([]),date('Y-m-d H:i:s',$now-200),null,null]);
        $insert->execute(['6','PTW-006','suspended',1,'Basement',json_encode([]),date('Y-m-d H:i:s',$now-1000),date('Y-m-d H:i:s',$now-900),date('Y-m-d H:i:s',$now+1800)]);
        $insert->execute(['7','INS-007','closed',0,'Inspection Area',json_encode([]),date('Y-m-d H:i:s',$now-100),null,null]);
        $insert->execute(['8','PTW-008','expired',1,'Level 1',json_encode([]),date('Y-m-d H:i:s',$now-9000),date('Y-m-d H:i:s',$now-9000),date('Y-m-d H:i:s',$now-7200)]);
        $insert->execute(['9','PTW-009','expired',1,'Yesterday',json_encode([]),date('Y-m-d H:i:s',$now-100000),date('Y-m-d H:i:s',$now-100000),date('Y-m-d H:i:s',$now-90000)]);

        $permits = PublicPermitStatus::current($this->pdo);

        self::assertCount(6, $permits);
        self::assertSame(['PTW-003','PTW-008','PTW-006','PTW-005','PTW-001','PTW-002'], array_column($permits, 'reference'));
        self::assertSame(['expired','expired','suspended','pending','pending','active'], array_column($permits, 'status'));
        self::assertSame('Expired — Do Not Work', $permits[0]['status_label']);
        self::assertSame('Expired — Do Not Work', $permits[1]['status_label']);
        self::assertSame('Suspended — Do Not Work', $permits[2]['status_label']);
        self::assertSame('Awaiting Holder Acceptance', $permits[3]['status_label']);
        self::assertSame(['Old Area','Level 1','Basement','Level 3','Roof A','Plant Room'], array_column($permits, 'location'));

        foreach ($permits as $permit) {
            self::assertSame(
                ['reference','permit_type','location','status','status_label','submitted_at','valid_from','valid_to'],
                array_keys($permit)
            );
            foreach (['holder_name','holder_email','holder_phone','form_data','approval_notes','unique_link'] as $privateKey) {
                self::assertArrayNotHasKey($privateKey, $permit);
            }
        }

        self::assertSame(
            ['pending'=>2,'active'=>1,'suspended'=>1,'expired'=>2,'total'=>6],
            PublicPermitStatus::counts($permits)
        );
    }
}
