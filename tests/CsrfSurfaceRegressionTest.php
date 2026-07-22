<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CsrfSurfaceRegressionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testHtmlMutationSurfacesValidateAndRenderActionScopedTokens(): void
    {
        $surfaces = [
            'login.php' => 'login',
            'admin-template-import.php' => 'admin-template-import',
            'admin-template-editor.php' => 'admin-template-editor',
            'admin-custom-permit.php' => 'admin-custom-permit',
            'admin-approval-notifications.php' => 'admin-approval-notifications',
            'admin-permit-durations.php' => 'admin-permit-durations',
            'admin/activity.php' => 'admin-activity',
            'admin/admin-external-template-import.php' => 'admin-external-template-import',
            'admin/backup.php' => 'admin-backup',
            'admin/email-settings.php' => 'admin-email-settings',
            'admin/users.php' => 'admin-users',
            'admin/settings.php' => 'admin-settings',
        ];

        foreach ($surfaces as $file => $action) {
            $source = (string)file_get_contents($this->root . '/' . $file);
            self::assertMatchesRegularExpression(
                "/Csrf::validateRequest\\('" . preg_quote($action, '/') . "'(?:,\\s*true)?\\)/",
                $source,
                "{$file} does not validate its CSRF token."
            );
            self::assertStringContainsString(
                "Csrf::getFormField('{$action}')",
                $source,
                "{$file} does not render its CSRF token."
            );
            self::assertStringContainsString('http_response_code(419)', $source, "{$file} lacks a 419 response.");
        }
    }

    public function testJsonMutationEndpointsRequireHeaderTokensAndReturn419(): void
    {
        $endpoints = [
            'api/push/subscribe.php' => 'push-subscription',
            'api/push/unsubscribe.php' => 'push-subscription',
        ];

        foreach ($endpoints as $file => $action) {
            $source = (string)file_get_contents($this->root . '/' . $file);
            self::assertStringContainsString("Csrf::validateRequest('{$action}')", $source);
            self::assertStringContainsString('419', $source);
        }

        $javascript = (string)file_get_contents($this->root . '/assets/app.js');
        self::assertStringContainsString("'X-CSRF-TOKEN': getCsrfToken()", $javascript);

        $landingPage = (string)file_get_contents($this->root . '/index.php');
        self::assertStringContainsString('meta name="csrf-token"', $landingPage);
        self::assertStringContainsString("Csrf::generateToken('push-subscription')", $landingPage);
    }
}
