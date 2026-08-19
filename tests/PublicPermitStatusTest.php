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
        $this->pdo->exec("CREATE TABLE form_templates (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE forms (
            id TEXT PRIMARY KEY,
            ref_number TEXT,
            template_id TEXT NOT NULL,
            status TEXT NOT NULL,
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

    public function testCurrentFeedContainsOnlyPendingAndLivePermitsWithoutPersonalData(): void
    {
        $insert = $this->pdo->prepare("INSERT INTO forms
            (id, ref_number, template_id, status, site_block, holder_name, holder_email, holder_phone, form_data, approval_notes, unique_link, created_at, valid_from, valid_to)
            VALUES (?, ?, 'hot', ?, ?, 'Private Person', 'private@example.com', '07123456789', '{\"secret\":true}', 'Private note', 'private-token', ?, ?, ?)");

        $now = time();
        $insert->execute(['1', 'PTW-001', 'pending_approval', 'Roof A', date('Y-m-d H:i:s', $now - 300), null, null]);
        $insert->execute(['2', 'PTW-002', 'active', 'Plant Room', date('Y-m-d H:i:s', $now - 600), date('Y-m-d H:i:s', $now - 300), date('Y-m-d H:i:s', $now + 3600)]);
        $insert->execute(['3', 'PTW-003', 'active', 'Old Area', date('Y-m-d H:i:s', $now - 7200), date('Y-m-d H:i:s', $now - 7200), date('Y-m-d H:i:s', $now - 3600)]);
        $insert->execute(['4', 'PTW-004', 'rejected', 'Hidden Area', date('Y-m-d H:i:s', $now - 300), null, null]);

        $permits = PublicPermitStatus::current($this->pdo);

        self::assertCount(2, $permits);
        self::assertSame(['PTW-001', 'PTW-002'], array_column($permits, 'reference'));
        self::assertSame(['pending', 'active'], array_column($permits, 'status'));

        foreach ($permits as $permit) {
            self::assertSame(
                ['reference', 'permit_type', 'location', 'status', 'status_label', 'submitted_at', 'valid_from', 'valid_to'],
                array_keys($permit)
            );
            self::assertArrayNotHasKey('holder_name', $permit);
            self::assertArrayNotHasKey('holder_email', $permit);
            self::assertArrayNotHasKey('holder_phone', $permit);
            self::assertArrayNotHasKey('form_data', $permit);
            self::assertArrayNotHasKey('approval_notes', $permit);
            self::assertArrayNotHasKey('unique_link', $permit);
        }

        self::assertSame(['pending' => 1, 'active' => 1, 'total' => 2], PublicPermitStatus::counts($permits));
    }
}
