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
        self::assertFileExists($this->root . '/.env.example');
        self::assertStringNotContainsString('replace-with-a-secret\nDB_CHARSET', str_replace("\r", '', file_get_contents($this->root . '/.env.example')));
    }

    public function testInternalMutationRoutesRequireAuthorization(): void
    {
        $routes = file_get_contents($this->root . '/src/routes.php');
        foreach (["post('/api/forms'", "put('/api/forms/{formId}'", "delete('/api/forms/{formId}'", "post('/api/forms/{formId}/attachments'", "delete('/api/attachments/{attachmentId}'"] as $route) {
            $position = strpos($routes, $route);
            self::assertNotFalse($position, 'Missing route ' . $route);
            $block = substr($routes, $position, 350);
            self::assertStringContainsString('routeRequireUser', $block, 'Route lacks an authorization guard: ' . $route);
        }
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
}
