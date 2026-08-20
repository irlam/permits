<?php
declare(strict_types=1);

use Permits\PublicStartCatalog;
use Permits\TemplateCatalog;
use PHPUnit\Framework\TestCase;

final class SiteAdoptionTest extends TestCase
{
    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE form_templates (id TEXT PRIMARY KEY, name TEXT NOT NULL, version INTEGER NOT NULL, created_at TEXT, active INTEGER NOT NULL DEFAULT 1)');
        return $pdo;
    }

    public function testPermanentSlugResolvesPreferredCurrentPermit(): void
    {
        $pdo = $this->database();
        $insert = $pdo->prepare('INSERT INTO form_templates (id, name, version, created_at, active) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['hot-works-v1', 'Hot Works Permit', 99, '2026-08-01 10:00:00', 1]);
        $insert->execute(['hot-works-v2', 'Hot Works Permit', 2, '2026-08-02 10:00:00', 1]);

        $template = PublicStartCatalog::findBySlug($pdo, 'hot-works');

        self::assertNotNull($template);
        self::assertSame('hot-works-v2', $template['id']);
        self::assertSame('/create-permit-public.php?template=hot-works-v2', TemplateCatalog::publicStartPath($template));
    }

    public function testPermanentSlugResolvesInspectionWorkflow(): void
    {
        $pdo = $this->database();
        $insert = $pdo->prepare('INSERT INTO form_templates (id, name, version, created_at, active) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['building-inspection-v2', 'Building Inspection Checklist', 2, '2026-08-02 10:00:00', 1]);

        $template = PublicStartCatalog::findBySlug($pdo, 'building-inspection');

        self::assertNotNull($template);
        self::assertTrue(TemplateCatalog::isInspection($template));
        self::assertSame('/create-inspection-public.php?template=building-inspection-v2', TemplateCatalog::publicStartPath($template));
    }

    public function testInactiveTemplateCannotBeStartedFromPermanentQr(): void
    {
        $pdo = $this->database();
        $insert = $pdo->prepare('INSERT INTO form_templates (id, name, version, created_at, active) VALUES (?, ?, ?, ?, ?)');
        $insert->execute(['hot-works-v2', 'Hot Works Permit', 2, '2026-08-02 10:00:00', 0]);

        self::assertNull(PublicStartCatalog::findBySlug($pdo, 'hot-works'));
    }

    public function testPermanentRoutesAndSafetyMessagingArePresent(): void
    {
        $root = dirname(__DIR__);
        $htaccess = (string)file_get_contents($root . '/.htaccess');
        $start = (string)file_get_contents($root . '/start.php');
        $qrManager = (string)file_get_contents($root . '/admin/site-qr-codes.php');
        $showcase = (string)file_get_contents($root . '/how-it-works.php');
        $branding = (string)file_get_contents($root . '/assets/default-brand.js');

        self::assertStringContainsString('^start/([a-z0-9][a-z0-9-]{0,79})/?$', $htaccess);
        self::assertStringContainsString('PublicStartCatalog::findBySlug', $start);
        self::assertStringContainsString('TemplateCatalog::publicStartPath', $start);
        self::assertStringContainsString('/start-qr.php?slug=', $qrManager);
        self::assertStringContainsString('Scanning or submitting a form does not authorise work', $qrManager);
        self::assertStringContainsString('approved, accepted by the permit holder and is shown as ACTIVE', $showcase);
        self::assertStringContainsString('/how-it-works.php', $branding);
        self::assertStringContainsString('/admin/site-qr-codes.php', $branding);
    }

    public function testQrEndpointEncodesPermanentSlugInsteadOfTemplateVersion(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/start-qr.php');

        self::assertStringContainsString("'/start/' . rawurlencode(\$slug)", $source);
        self::assertStringNotContainsString('create-permit-public.php?template=', $source);
        self::assertStringNotContainsString('create-inspection-public.php?template=', $source);
    }
}
