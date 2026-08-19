<?php
declare(strict_types=1);

use Permits\Db;
use Permits\Phase4DatabaseMaintenance;
use PHPUnit\Framework\TestCase;

final class Phase4DatabaseMaintenanceTest extends TestCase
{
    public function testMigrationCreatesWorkflowTablesIdempotentlyOnSqlite(): void
    {
        $reflection = new ReflectionClass(Db::class);
        /** @var Db $db */
        $db = $reflection->newInstanceWithoutConstructor();
        $db->pdo = new PDO('sqlite::memory:');
        $db->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $eventsFirst = Phase4DatabaseMaintenance::ensureFormEventsTable($db);
        $linksFirst = Phase4DatabaseMaintenance::ensurePermitLinksTable($db);
        self::assertSame(['form_events'], $eventsFirst['added']);
        self::assertSame([], $eventsFirst['errors']);
        self::assertSame(['permit_links'], $linksFirst['added']);
        self::assertSame([], $linksFirst['errors']);

        $eventsSecond = Phase4DatabaseMaintenance::ensureFormEventsTable($db);
        $linksSecond = Phase4DatabaseMaintenance::ensurePermitLinksTable($db);
        self::assertSame(['form_events'], $eventsSecond['alreadyPresent']);
        self::assertSame(['permit_links'], $linksSecond['alreadyPresent']);

        $tables = $db->pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('form_events', $tables);
        self::assertContains('permit_links', $tables);

        $indexes = $db->pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('idx_form_events_form_at', $indexes);
        self::assertContains('idx_permit_links_a', $indexes);
        self::assertContains('idx_permit_links_b', $indexes);
    }
}
