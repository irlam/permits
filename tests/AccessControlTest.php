<?php
declare(strict_types=1);

use Permits\PermitAccess;
use Permits\UserAccountPolicy;
use PHPUnit\Framework\TestCase;

final class AccessControlTest extends TestCase
{
    public function testAccountPageUsesTheApplicationGlobalAuthClass(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/account.php');

        self::assertStringNotContainsString('use Permits\\Auth;', $source);
        self::assertStringContainsString('new Auth($db)', $source);
    }

    public function testRegularUserPermitScopeCoversHolderIssuerAndEmail(): void
    {
        $scope = PermitAccess::sqlScope([
            'id' => 'user-1',
            'email' => ' Person@Example.test ',
            'role' => 'user',
        ]);

        self::assertStringContainsString('f.holder_id', $scope['sql']);
        self::assertStringContainsString('f.issuer_id', $scope['sql']);
        self::assertStringContainsString('LOWER(TRIM(f.holder_email))', $scope['sql']);
        self::assertSame('user-1', $scope['params']['scope_holder']);
        self::assertSame('user-1', $scope['params']['scope_issuer']);
        self::assertSame('person@example.test', $scope['params']['scope_email']);
    }

    public function testManagersAndAdminsCanViewAllPermits(): void
    {
        foreach (['manager', 'admin'] as $role) {
            $scope = PermitAccess::sqlScope(['role' => $role]);
            self::assertSame('1 = 1', $scope['sql']);
            self::assertSame([], $scope['params']);
        }
    }

    public function testLoadedPermitOwnershipMatchesDashboardScope(): void
    {
        $user = ['id' => 'user-1', 'email' => 'Person@Example.test', 'role' => 'user'];

        self::assertTrue(PermitAccess::canAccessPermit($user, ['holder_id' => 'user-1']));
        self::assertTrue(PermitAccess::canAccessPermit($user, ['issuer_id' => 'user-1']));
        self::assertTrue(PermitAccess::canAccessPermit($user, ['holder_email' => ' person@example.TEST ']));
        self::assertFalse(PermitAccess::canAccessPermit($user, [
            'holder_id' => 'someone-else',
            'issuer_id' => 'someone-else',
            'holder_email' => 'other@example.test',
        ]));
        self::assertTrue(PermitAccess::canAccessPermit(['role' => 'manager'], []));
    }

    public function testUserProfileAndPasswordValidationRejectsTamperedValues(): void
    {
        $profile = UserAccountPolicy::validateProfile('not-an-email', "Bad\0Name", 'owner', 'enabled');
        self::assertCount(4, $profile['errors']);
        self::assertNotNull(UserAccountPolicy::passwordError('short', true));
        self::assertNull(UserAccountPolicy::passwordError('A-production-password-123', true));
        self::assertNull(UserAccountPolicy::passwordError('', false));
    }

    public function testLastActiveAdministratorCannotBeRemoved(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id TEXT PRIMARY KEY, role TEXT NOT NULL, status TEXT NOT NULL)');
        $pdo->exec("INSERT INTO users (id, role, status) VALUES ('admin-1', 'admin', 'active')");

        $target = ['id' => 'admin-1', 'role' => 'admin', 'status' => 'active'];
        self::assertTrue(UserAccountPolicy::wouldRemoveLastActiveAdmin($pdo, $target, 'user', 'active'));

        $pdo->exec("INSERT INTO users (id, role, status) VALUES ('admin-2', 'admin', 'active')");
        self::assertFalse(UserAccountPolicy::wouldRemoveLastActiveAdmin($pdo, $target, 'user', 'active'));
    }

    public function testDashboardAndAdminSurfacesApplyAccessRules(): void
    {
        $root = dirname(__DIR__);
        $dashboard = (string)file_get_contents($root . '/dashboard-legacy.php');
        self::assertStringContainsString('PermitAccess::sqlScope', $dashboard);
        self::assertStringContainsString('activity_user_id', $dashboard);

        $dashboardWrapper = (string)file_get_contents($root . '/dashboard.php');
        self::assertStringContainsString('dashboard-legacy.php', $dashboardWrapper);
        self::assertStringContainsString('permit-board.php', $dashboardWrapper);

        $userManagement = (string)file_get_contents($root . '/admin/users.php');
        self::assertStringContainsString('UserAccountPolicy::validateProfile', $userManagement);
        self::assertStringContainsString('UserAccountPolicy::wouldRemoveLastActiveAdmin', $userManagement);
        self::assertStringContainsString('You cannot remove your own administrator access', $userManagement);
        self::assertStringNotContainsString("\$message = 'Error updating user: '", $userManagement);

        $adminPages = [
            'admin.php',
            'admin-approval-notifications.php',
            'admin-custom-permit.php',
            'admin-permit-durations.php',
            'admin-template-editor.php',
            'admin-template-import.php',
            'admin/activity.php',
            'admin/admin-external-template-import.php',
            'admin/backup.php',
            'admin/email-settings.php',
            'admin/qr-codes-all.php',
            'admin/qr-codes-individual.php',
            'admin/settings.php',
            'admin/users.php',
        ];
        foreach ($adminPages as $relativePath) {
            $source = (string)file_get_contents($root . '/' . $relativePath);
            self::assertStringContainsString(
                "requireRoles(['admin'])",
                $source,
                $relativePath . ' must use the central active-account, timeout, and role gate.'
            );
        }

        $managerApprovals = (string)file_get_contents($root . '/manager-approvals.php');
        self::assertStringContainsString("requireRoles(['manager', 'admin'])", $managerApprovals);

        foreach (['account.php', 'dashboard-legacy.php'] as $relativePath) {
            $source = (string)file_get_contents($root . '/' . $relativePath);
            self::assertStringContainsString(
                '$auth->requireLogin()',
                $source,
                $relativePath . ' must use the central active-account and timeout gate.'
            );
        }
    }

    public function testEveryProtectedApiUsesTheCentralJsonGate(): void
    {
        $root = dirname(__DIR__);
        $expectedGates = [
            'api/approve-permit.php' => "requireJson(['manager', 'admin'])",
            'api/reject-permit.php' => "requireJson(['manager', 'admin'])",
            'api/close-permit.php' => 'requireJson()',
            'api/start-work.php' => 'requireJson()',
            'api/push/subscribe.php' => 'requireJson()',
            'api/push/unsubscribe.php' => 'requireJson()',
        ];

        foreach ($expectedGates as $relativePath => $gate) {
            $source = (string)file_get_contents($root . '/' . $relativePath);
            self::assertStringContainsString(
                '$auth->' . $gate,
                $source,
                $relativePath . ' must not bypass idle-timeout or active-account enforcement.'
            );
        }

        $qr = (string)file_get_contents($root . '/qr-code.php');
        self::assertStringContainsString("userForRoles(['manager', 'admin'])", $qr);
    }

    public function testSessionHardeningIsConfiguredCentrally(): void
    {
        $root = dirname(__DIR__);
        $bootstrap = (string)file_get_contents($root . '/src/bootstrap.php');
        $auth = (string)file_get_contents($root . '/src/Auth.php');

        self::assertStringContainsString("session.use_strict_mode', '1", $bootstrap);
        self::assertStringContainsString('SESSION_IDLE_TIMEOUT', $bootstrap);
        self::assertStringContainsString("['status'] ?? ''", $auth);
        self::assertStringContainsString('clearAuthenticationSession()', $auth);
        self::assertStringContainsString('new LoginRateLimiter($db->pdo)', $auth);
        self::assertStringContainsString("\$_SERVER['REMOTE_ADDR']", $auth);
        self::assertStringContainsString('session_get_cookie_params()', $auth);
        self::assertStringNotContainsString("setcookie(session_name(), '', time() - 3600, '/')", $auth);
    }
}
