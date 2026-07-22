<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotificationRegressionTest extends TestCase
{
    public function testExpiryWorkersUseTheCanonicalActiveStatusSet(): void
    {
        $expected = "status IN ('issued', 'active', 'approved', 'open')";

        self::assertStringContainsString($expected, (string)file_get_contents(dirname(__DIR__) . '/bin/send-notifications.php'));
        self::assertStringContainsString($expected, (string)file_get_contents(dirname(__DIR__) . '/src/check-expiry.php'));
    }

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testLifecycleEndpointsQueueRealEmailNotifications(): void
    {
        foreach (['approve-permit.php', 'reject-permit.php'] as $endpoint) {
            $source = (string)file_get_contents($this->root . '/api/' . $endpoint);
            self::assertStringNotContainsString('sendEmail(', $source);
            self::assertStringContainsString('new \\Permits\\Email($db, $root)', $source);
        }
    }

    public function testEmailLinksUsePublicTokensRatherThanRemovedIdRoutes(): void
    {
        $mailer = (string)file_get_contents($this->root . '/src/Mailer.php');
        self::assertStringNotContainsString("'/form/'", $mailer);
        self::assertStringContainsString('view-permit-public.php?link=', $mailer);

        foreach (['permit-approved.php', 'permit-rejected.php', 'permit-expiring.php', 'permit-created.php'] as $template) {
            $source = (string)file_get_contents($this->root . '/templates/emails/' . $template);
            self::assertStringNotContainsString("'/form/'", $source, $template);
            self::assertStringContainsString('view-permit-public.php?link=', $source, $template);
        }
    }

    public function testPushRemindersAreScopedAndDeduplicated(): void
    {
        $source = (string)file_get_contents($this->root . '/bin/reminders.php');

        self::assertStringContainsString("f.status IN ('active', 'issued', 'approved', 'open')", $source);
        self::assertStringContainsString("'push_expiry_reminder'", $source);
        self::assertStringContainsString('deliveredRecipientKeys', $source);
        self::assertStringContainsString('recordDelivery', $source);
        self::assertStringContainsString("WorkerLock::acquire(\$pdo, 'push-expiry-reminders'", $source);
        self::assertStringContainsString("['admin', 'manager']", $source);
        self::assertStringContainsString('$holderId', $source);
        self::assertStringContainsString('$issuerId', $source);
        self::assertStringContainsString('$holderEmail', $source);
        self::assertStringContainsString('view-permit-public.php?link=', $source);
        self::assertStringNotContainsString("'/?form='", $source);
    }

    public function testExpiryEmailQueryIncludesThePublicToken(): void
    {
        $source = (string)file_get_contents($this->root . '/bin/send-notifications.php');
        self::assertStringContainsString('unique_link', $source);
        self::assertStringContainsString('deliveredRecipientKeys', $source);
        self::assertStringContainsString('recordDelivery', $source);
        self::assertStringContainsString("WorkerLock::acquire(\$pdo, 'email-expiry-reminders'", $source);
        self::assertStringNotContainsString('DATE_SUB(', $source);
    }

    public function testDisabledEmailExitsBeforeExpiryPermitsAreRead(): void
    {
        $source = (string)file_get_contents($this->root . '/bin/send-notifications.php');
        $guard = strpos($source, 'if (!$mailer->isEnabled())');
        $permitQuery = strpos($source, 'SELECT id,');

        self::assertNotFalse($guard);
        self::assertNotFalse($permitQuery);
        self::assertLessThan($permitQuery, $guard);
        self::assertStringContainsString('no expiry notifications were processed', $source);
    }
}
