<?php
declare(strict_types=1);

use Permits\Mailer;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'permits-mailer-test-' . bin2hex(random_bytes(4));
        if (is_dir($this->logDir)) {
            $this->deleteDir($this->logDir);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logDir)) {
            $this->deleteDir($this->logDir);
        }
    }

    public function testLogDriverWritesPayload(): void
    {
        $mailer = new Mailer([
            'driver'        => 'log',
            'log_directory' => $this->logDir,
            'from'          => 'qa@example.com',
            'from_name'     => 'Permits QA',
        ]);

        $result = $mailer->send('recipient@example.com', 'Test Subject', '<p>Hello</p>', 'Hello');
        $this->assertTrue($result, 'Expected send() to return true when logging');

        $files = glob($this->logDir . DIRECTORY_SEPARATOR . '*.log');
        $this->assertNotEmpty($files, 'Expected a log file to be created');

        $payload = json_decode((string)file_get_contents($files[0]), true);
        $this->assertIsArray($payload);
        $this->assertSame('recipient@example.com', $payload['to'] ?? null);
        $this->assertSame('Test Subject', $payload['subject'] ?? null);
    }

    private function deleteDir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
