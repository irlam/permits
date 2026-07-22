<?php
declare(strict_types=1);

use Permits\SafeExternalResource;
use PHPUnit\Framework\TestCase;

final class SafeExternalResourceTest extends TestCase
{
    /** @var array<int,string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->temporaryFiles = [];
    }

    public function testAllowsHttpsHostResolvingOnlyToPublicAddresses(): void
    {
        $guard = new SafeExternalResource(static fn(string $host): array => ['93.184.216.34']);

        $target = $guard->validateUrl('https://example.com/templates/permit.pdf?download=1', ['html', 'pdf']);

        self::assertSame('example.com', $target['host']);
        self::assertSame('93.184.216.34', $target['address']);
        self::assertSame('pdf', $target['extension']);
    }

    public function testRejectsNonHttpsAndCredentialBearingAddresses(): void
    {
        $guard = new SafeExternalResource(static fn(string $host): array => ['93.184.216.34']);

        foreach (['http://example.com/form.html', 'https://user:pass@example.com/form.html'] as $url) {
            try {
                $guard->validateUrl($url, ['html']);
                self::fail('Expected unsafe URL to be rejected: ' . $url);
            } catch (RuntimeException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testRejectsPrivateOrMixedDnsAnswers(): void
    {
        foreach ([['127.0.0.1'], ['93.184.216.34', '10.0.0.8'], ['::1']] as $addresses) {
            $guard = new SafeExternalResource(static fn(string $host): array => $addresses);

            try {
                $guard->validateUrl('https://example.com/form.html', ['html']);
                self::fail('Expected private DNS answer to be rejected.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('Private or local', $e->getMessage());
            }
        }
    }

    public function testRejectsIpLiteralsCustomPortsAndUnsupportedExtensions(): void
    {
        $guard = new SafeExternalResource(static fn(string $host): array => ['93.184.216.34']);
        $urls = [
            'https://93.184.216.34/form.html',
            'https://example.com:8443/form.html',
            'https://example.com/template.exe',
        ];

        foreach ($urls as $url) {
            try {
                $guard->validateUrl($url, ['html', 'pdf', 'docx']);
                self::fail('Expected unsupported target to be rejected: ' . $url);
            } catch (RuntimeException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testAcceptsSmallHtmlUploadAfterMimeAndSignatureChecks(): void
    {
        $path = $this->temporaryFile('<!doctype html><html><body><form></form></body></html>');
        $size = (int)filesize($path);

        $upload = SafeExternalResource::validateUpload(
            $path,
            'permit-checklist.html',
            $size,
            ['html', 'pdf', 'docx'],
            ['text/html', 'text/plain']
        );

        self::assertSame('permit-checklist.html', $upload['name']);
        self::assertSame('html', $upload['extension']);
        self::assertSame($size, $upload['size']);
    }

    public function testRejectsOversizedAndExtensionMimeMismatchUploads(): void
    {
        $large = $this->temporaryFile('<!doctype html><html>' . str_repeat('x', 100) . '</html>');
        try {
            SafeExternalResource::validateUpload(
                $large,
                'large.html',
                (int)filesize($large),
                ['html'],
                ['text/html', 'text/plain'],
                32
            );
            self::fail('Expected oversized upload to be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('smaller', $e->getMessage());
        }

        $disguised = $this->temporaryFile('<!doctype html><html><body>Not a PDF</body></html>');
        try {
            SafeExternalResource::validateUpload(
                $disguised,
                'not-really.pdf',
                (int)filesize($disguised),
                ['pdf'],
                ['application/pdf', 'text/html', 'text/plain']
            );
            self::fail('Expected mismatched extension and MIME to be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('do not match', $e->getMessage());
        }
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'permits-import-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;
        return $path;
    }
}
