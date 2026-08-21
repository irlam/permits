<?php
declare(strict_types=1);

use Permits\DashboardActivityPresenter;
use PHPUnit\Framework\TestCase;

final class DashboardActivityPresenterTest extends TestCase
{
    public function testTechnicalPermitIdentifiersAreHiddenFromDashboardCopy(): void
    {
        $display = DashboardActivityPresenter::present([
            'action' => 'permit_approved',
            'description' => 'Permit PTW-2026-2155297459 approved by System Administrator; holder acceptance required [form:38128100-e7cb-4d4e-bcc2-5806c2b2aa22] (approval)',
        ]);

        self::assertSame('Permit approved', $display['title']);
        self::assertStringContainsString('PTW-2026-2155297459', $display['description']);
        self::assertStringContainsString('must accept it before work starts', $display['description']);
        self::assertStringNotContainsString('38128100-', $display['description']);
        self::assertStringNotContainsString('(approval)', $display['description']);
    }

    public function testLoginDoesNotExposeEmailOrInternalUserId(): void
    {
        $display = DashboardActivityPresenter::present([
            'action' => 'user_login',
            'description' => 'User logged in: admin@permits.local [user:00000000-0000-0000-0000-000000000001] (auth)',
        ]);

        self::assertSame('Team member signed in', $display['title']);
        self::assertSame('A team member signed in to the permit system.', $display['description']);
    }

    public function testUnknownActionsStillReceiveReadableTitles(): void
    {
        $display = DashboardActivityPresenter::present([
            'action' => 'custom_safety_check',
            'description' => '',
        ]);

        self::assertSame('Custom Safety Check', $display['title']);
        self::assertSame('Activity recorded in the permit system.', $display['description']);
    }
}
