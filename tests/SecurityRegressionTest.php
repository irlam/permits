<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SecurityRegressionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testEnvironmentFileIsIgnoredAndExampleExists(): void
    {
        $ignore = file_get_contents($this->root . '/.gitignore');
        self::assertStringContainsString('.env', $ignore);
        self::assertStringContainsString('!/uploads/.htaccess', $ignore);
        self::assertFileExists($this->root . '/.env.example');
        self::assertStringNotContainsString('replace-with-a-secret\nDB_CHARSET', str_replace("\r", '', file_get_contents($this->root . '/.env.example')));
        self::assertFileDoesNotExist($this->root . '/.php-preview-router.php');
    }

    public function testPermitMutationEndpointsRequireAuthenticationAndRoles(): void
    {
        self::assertFileDoesNotExist($this->root . '/src/routes.php', 'The unused legacy mutation router must not be deployed.');

        foreach (['approve-permit.php', 'reject-permit.php', 'close-permit.php'] as $endpoint) {
            $source = file_get_contents($this->root . '/api/' . $endpoint);
            self::assertStringContainsString('$auth->requireJson(', $source, $endpoint . ' lacks the central authentication guard.');
            self::assertStringContainsString("\$_SERVER['REQUEST_METHOD']", $source, $endpoint . ' lacks a method guard.');
        }

        $approve = file_get_contents($this->root . '/api/approve-permit.php');
        $reject = file_get_contents($this->root . '/api/reject-permit.php');
        self::assertStringContainsString("['manager', 'admin']", $approve);
        self::assertStringContainsString("['manager', 'admin']", $reject);
    }

    public function testPublicStartWorkDoesNotAcceptPermitId(): void
    {
        $source = file_get_contents($this->root . '/api/start-work.php');
        self::assertStringNotContainsString("data['permit_id']", $source);
        self::assertStringContainsString('unique_link = ?', $source);
    }

    public function testUploadExecutionIsDenied(): void
    {
        self::assertFileExists($this->root . '/uploads/.htaccess');
        self::assertStringContainsString('Require all denied', file_get_contents($this->root . '/uploads/.htaccess'));
    }

    public function testRootUploadDenyRuleRunsBeforeExistingFileShortCircuit(): void
    {
        $apache = (string)file_get_contents($this->root . '/.htaccess');
        $deny = strpos($apache, 'RewriteRule ^uploads/');
        $existing = strpos($apache, 'RewriteCond %{REQUEST_FILENAME} -f');

        self::assertNotFalse($deny);
        self::assertNotFalse($existing);
        self::assertLessThan($existing, $deny);
    }

    public function testPushEndpointsBootstrapFromProjectRoot(): void
    {
        foreach (['subscribe.php', 'unsubscribe.php'] as $endpoint) {
            $source = (string)file_get_contents($this->root . '/api/push/' . $endpoint);
            self::assertStringContainsString('dirname(__DIR__, 2)', $source, $endpoint . ' resolves the wrong project root.');
            self::assertStringContainsString("/src/bootstrap.php", $source);
        }
    }

    public function testPushSubscriptionsAreValidatedAndDeliveryRedirectsAreDisabled(): void
    {
        $subscribe = (string)file_get_contents($this->root . '/api/push/subscribe.php');
        $reminders = (string)file_get_contents($this->root . '/bin/reminders.php');

        self::assertStringContainsString('PushSubscriptionValidator::validate', $subscribe);
        self::assertStringContainsString('PushSubscriptionValidator::validate', $reminders);
        self::assertStringContainsString("'allow_redirects' => false", $reminders);
        self::assertStringContainsString('sendOneNotification', $reminders);
        self::assertStringContainsString('catch (Throwable', $reminders);
    }

    public function testLoginDoesNotExposeSessionDiagnostics(): void
    {
        $login = file_get_contents($this->root . '/login.php');
        $admin = file_get_contents($this->root . '/admin.php');

        self::assertStringNotContainsString("isset(\$_GET['debug'])", $login);
        self::assertStringNotContainsString("isset(\$_GET['debug'])", $admin);
        self::assertStringNotContainsString('print_r($_SESSION', $login);
        self::assertStringNotContainsString('print_r($_COOKIE', $login);
        self::assertStringContainsString('$auth->login(', $login);
        self::assertStringContainsString('safeLoginRedirect', $login);
    }

    public function testBrandLogoUploadRejectsSvgAndUsesRandomNames(): void
    {
        $settings = file_get_contents($this->root . '/admin/settings.php');

        self::assertStringNotContainsString("'image/svg+xml' =>", $settings);
        self::assertStringContainsString('accept="image/png,image/jpeg,image/webp"', $settings);
        self::assertStringContainsString('random_bytes(12)', $settings);
        self::assertStringContainsString('getimagesize', $settings);
    }

    public function testQrAdminPagesOnlyPublishApprovedPermitsAndPreservePublicHolderDetails(): void
    {
        $all = (string)file_get_contents($this->root . '/admin/qr-codes-all.php');
        $individual = (string)file_get_contents($this->root . '/admin/qr-codes-individual.php');

        self::assertStringContainsString("f.status IN ('active', 'issued', 'approved', 'open')", $all);
        self::assertStringNotContainsString("f.status IN ('active', 'pending_approval')", $all);
        self::assertStringContainsString("COALESCE(NULLIF(u.name, ''), f.holder_name) as display_holder_name", $individual);
        self::assertStringContainsString("COALESCE(NULLIF(u.email, ''), f.holder_email) as display_holder_email", $individual);
        self::assertStringContainsString("f.status IN ('active', 'issued', 'approved', 'open', 'closed', 'expired')", $individual);
    }

    public function testQrResponsesCannotBeStoredInSharedOrBrowserCaches(): void
    {
        $qr = (string)file_get_contents($this->root . '/qr-code.php');

        self::assertStringContainsString("Cache-Control: private, no-store", $qr);
        self::assertStringNotContainsString('Cache-Control: public', $qr);
        self::assertStringNotContainsString('immutable', $qr);
    }

    public function testEveryPhpBinEntryPointIsCliOnly(): void
    {
        $scripts = glob($this->root . '/bin/*.php') ?: [];
        self::assertNotSame([], $scripts);

        foreach ($scripts as $script) {
            $source = (string)file_get_contents($script);
            self::assertStringContainsString(
                "PHP_SAPI !== 'cli'",
                $source,
                basename($script) . ' must not be executable through the web server.'
            );
            $guardPosition = strpos($source, "PHP_SAPI !== 'cli'");
            preg_match('/\\brequire(?:_once)?\\s+/', $source, $requireMatch, PREG_OFFSET_CAPTURE);
            if ($requireMatch !== []) {
                self::assertLessThan(
                    $requireMatch[0][1],
                    $guardPosition,
                    basename($script) . ' must reject web requests before loading application code.'
                );
            }
        }
    }

    public function testBootstrapDoesNotTrustTheRequestHostForPublicUrls(): void
    {
        $bootstrap = (string)file_get_contents($this->root . '/src/bootstrap.php');

        self::assertStringContainsString("\$_SERVER['SERVER_NAME']", $bootstrap);
        self::assertStringNotContainsString("\$_SERVER['HTTP_HOST'] ??", $bootstrap);
        self::assertStringContainsString('FILTER_VALIDATE_DOMAIN', $bootstrap);
    }

    public function testPublicLandingPageDoesNotRunMigrationsOrSeedData(): void
    {
        $landing = (string)file_get_contents($this->root . '/index.php');

        self::assertStringNotContainsString('DatabaseMaintenance::', $landing);
        self::assertStringNotContainsString('FormTemplateSeeder::', $landing);
    }

    public function testBackupDoesNotRecursivelyArchiveBackupsOrEnvironmentSecrets(): void
    {
        $backup = (string)file_get_contents($this->root . '/admin/backup.php');

        self::assertStringContainsString("'backups'", $backup);
        self::assertStringContainsString("\$relativePath === '.env'", $backup);
        self::assertStringNotContainsString('name="include_backups"', $backup);
        self::assertStringContainsString('permits_backup_\\d{8}_\\d{6}', $backup);
        self::assertStringContainsString('BackupStorage::ensure($root)', $backup);
        self::assertStringNotContainsString("\$root . '/backups'", $backup);
    }

    public function testAdminSettingsDoNotRewriteEnvironmentSecrets(): void
    {
        $settings = file_get_contents($this->root . '/admin/settings.php');
        self::assertStringNotContainsString("file_put_contents(\$envFile", $settings);
        self::assertStringNotContainsString("__DIR__ . '/../.env'", $settings);
        self::assertStringContainsString("'app_timezone' => \$timezone", $settings);
        self::assertStringContainsString("'permit_prefix' => \$permitPrefix", $settings);
    }

    public function testCustomTemplateRedirectUsesAnExistingRoute(): void
    {
        $source = (string)file_get_contents($this->root . '/admin-custom-permit.php');

        self::assertStringNotContainsString("'/new/'", $source);
        self::assertStringContainsString("create-permit-public.php?template=", $source);
    }
}
