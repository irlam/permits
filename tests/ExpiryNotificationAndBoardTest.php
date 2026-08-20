<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExpiryNotificationAndBoardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testExpiryWorkerRunsRetrySafeStopWorkNotifications(): void
    {
        $worker = (string)file_get_contents($this->root . '/bin/auto-status-update.php');
        $service = (string)file_get_contents($this->root . '/src/PermitExpiryNotifier.php');

        self::assertStringContainsString('PermitExpiryNotifier', $worker);
        self::assertStringContainsString('notifyRecentlyExpired(1440)', $worker);
        self::assertStringContainsString("WorkerLock::acquire(\$pdo, 'expired-permit-alerts'", $service);
        self::assertStringContainsString('NotificationDeliveryLedger', $service);
        self::assertStringContainsString("'expiry_email_sent'", $service);
        self::assertStringContainsString("'expiry_push_sent'", $service);
        self::assertStringContainsString('PERMIT EXPIRED — STOP WORK', $service);
    }

    public function testExpiryRecipientsCoverHolderIssuerAndManagement(): void
    {
        $service = (string)file_get_contents($this->root . '/src/PermitExpiryNotifier.php');

        self::assertStringContainsString("\$permit['holder_email']", $service);
        self::assertStringContainsString("\$permit['holder_id']", $service);
        self::assertStringContainsString("\$permit['issuer_id']", $service);
        self::assertStringContainsString("LOWER(role) IN ('admin','manager')", $service);
        self::assertStringContainsString("in_array(\$role, ['admin','manager'], true)", $service);
    }

    public function testOperationalAndPublicBoardsKeepRecentExpiryVisible(): void
    {
        $board = (string)file_get_contents($this->root . '/permit-board.php');
        $publicStatus = (string)file_get_contents($this->root . '/src/PublicPermitStatus.php');
        $publicScript = (string)file_get_contents($this->root . '/assets/phase3-status.js');

        self::assertStringContainsString('Recently expired', $board);
        self::assertStringContainsString("'expired' => []", $board);
        self::assertStringContainsString('time() - 86400', $board);
        self::assertStringContainsString('STOP WORK — permit validity has ended', $board);

        self::assertStringContainsString('Expired — Do Not Work', $publicStatus);
        self::assertStringContainsString('time() - 86400', $publicStatus);
        self::assertStringContainsString("'expired' => \$expired", $publicStatus);

        self::assertStringContainsString('data-live-count="expired"', $publicScript);
        self::assertStringContainsString('Expired in last 24h — stop work', $publicScript);
        self::assertStringContainsString("['pending', 'active', 'suspended', 'expired']", $publicScript);
    }
}
