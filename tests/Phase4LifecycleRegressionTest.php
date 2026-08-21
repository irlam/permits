<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Phase4LifecycleRegressionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testWorkflowPageContainsAcceptanceSuspensionRevalidationHandoverAndLinks(): void
    {
        $source = (string)file_get_contents($this->root . '/permit-workflow-legacy.php');
        foreach ([
            "value=\"accept\"",
            "value=\"suspend\"",
            "value=\"revalidate\"",
            "value=\"handover\"",
            "value=\"link_permit\"",
            'Holder / receiver acceptance',
            'Shift / team handover',
            'Linked permits / SIMOPS',
            're-accept',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        self::assertStringContainsString('PermitAccess::canAccessPermit', $source);
        self::assertStringContainsString("['manager', 'admin']", $source);

        $privacyWrapper = (string)file_get_contents($this->root . '/permit-workflow.php');
        self::assertStringContainsString('permit-workflow-legacy.php', $privacyWrapper);
        self::assertStringContainsString('name="accepted_email"', $privacyWrapper);
        self::assertStringContainsString('value="" autocomplete="email"', $privacyWrapper);
    }

    public function testStartWorkHasExplicitConflictInterlock(): void
    {
        $source = (string)file_get_contents($this->root . '/api/start-work.php');
        self::assertStringContainsString('PermitLinks::blockingConflicts', $source);
        self::assertStringContainsString('conflicting linked permit is active', $source);
        self::assertStringContainsString("'work_started'", $source);
    }

    public function testPublicVerificationMakesSuspensionImpossibleToMiss(): void
    {
        $wrapper = (string)file_get_contents($this->root . '/view-permit-public.php');
        self::assertStringContainsString('SUSPENDED — DO NOT WORK', $wrapper);
        self::assertStringContainsString('Work must remain stopped', $wrapper);
        self::assertStringContainsString('PermitLinks::forPermit', $wrapper);
        self::assertStringContainsString('view-permit-public-legacy.php', $wrapper);
    }

    public function testOperationalBoardSeparatesAllCurrentLifecycleStates(): void
    {
        $source = (string)file_get_contents($this->root . '/permit-board.php');
        self::assertStringContainsString("'pending_approval','awaiting_acceptance','active','issued','approved','open','suspended'", $source);
        self::assertStringContainsString('Operational Permit Board', $source);
        self::assertStringContainsString('Suspended', $source);
        self::assertStringContainsString('Awaiting acceptance', $source);
        self::assertStringContainsString('Control / handover', $source);
        self::assertStringContainsString("relation_type = 'conflict'", $source);
        self::assertStringContainsString('requires_approval = 1', $source);
    }

    public function testApprovalEmailDoesNotAuthoriseWorkBeforeAcceptance(): void
    {
        $source = (string)file_get_contents($this->root . '/templates/emails/permit-approved.php');
        self::assertStringContainsString('Permit Approved — Acceptance Required', $source);
        self::assertStringContainsString('Do not start work yet', $source);
        self::assertStringContainsString('permit-workflow.php?link=', $source);
        self::assertStringNotContainsString('You can now proceed with the work', $source);
    }

    public function testMigrationRunnerCreatesPhase4Tables(): void
    {
        $source = (string)file_get_contents($this->root . '/bin/migrate.php');
        self::assertStringContainsString('Phase4DatabaseMaintenance::ensureFormEventsTable', $source);
        self::assertStringContainsString('Phase4DatabaseMaintenance::ensurePermitLinksTable', $source);
    }

    public function testLivePublicBoardIncludesStopWorkState(): void
    {
        $service = (string)file_get_contents($this->root . '/src/PublicPermitStatus.php');
        $script = (string)file_get_contents($this->root . '/assets/phase3-status.js');
        self::assertStringContainsString("'suspended'", $service);
        self::assertStringContainsString('Suspended — Do Not Work', $service);
        self::assertStringContainsString('Suspended — stop work', $script);
        self::assertStringContainsString('STOP WORK', $script);
        self::assertStringContainsString("{ state:'active', label:'Active now', open:true }", $script);
        self::assertStringContainsString("{ state:'expired', label:'Expired in the last 24 hours', open:false", $script);
        self::assertStringContainsString('items.slice(0, 5)', $script);
        self::assertStringContainsString('data-show-group', $script);
        self::assertStringContainsString('live-permit-group__warning', $script);
        self::assertStringNotContainsString('This permit has expired and no longer authorises the work', $script);
    }

    public function testAdminHealthCheckIsProtectedAndReadOnly(): void
    {
        $source = (string)file_get_contents($this->root . '/admin/health-check.php');
        $admin = (string)file_get_contents($this->root . '/admin.php');

        self::assertStringContainsString("requireRoles(['admin'])", $source);
        self::assertStringContainsString('ProductionHealthCheck', $source);
        self::assertStringContainsString("header('Cache-Control: no-store", $source);
        self::assertStringContainsString('No settings or records are changed.', $source);
        self::assertStringContainsString('Run checks again', $source);
        self::assertStringNotContainsString('password', strtolower($source));
        self::assertStringContainsString('/admin/health-check.php', $admin);
    }
}
