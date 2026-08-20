<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BrandIdentityRegressionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testCanonicalFaviconIsHardHatCheckSvg(): void
    {
        $svg = file_get_contents($this->root . '/favicon.svg');

        self::assertIsString($svg);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('#fbbf24', strtolower($svg));
        self::assertStringContainsString('stroke="#ffffff"', strtolower($svg));
        self::assertStringNotContainsString('>P<', $svg);
    }

    public function testSharedHtmlIdentityUsesOnlyCanonicalSvg(): void
    {
        $helper = file_get_contents($this->root . '/src/cache-helper.php');

        self::assertIsString($helper);
        self::assertStringContainsString("asset('/favicon.svg')", $helper);
        self::assertStringContainsString("asset('/assets/default-brand.js')", $helper);
        self::assertStringContainsString('text/html', $helper);
        self::assertStringNotContainsString("asset('/favicon.ico')", $helper);
        self::assertStringNotContainsString("asset('/icon-192.png')", $helper);
    }

    public function testPwaManifestUsesCanonicalSvg(): void
    {
        $manifest = json_decode(
            (string)file_get_contents($this->root . '/manifest.webmanifest'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertNotEmpty($manifest['icons']);
        foreach ($manifest['icons'] as $icon) {
            self::assertSame('/favicon.svg', $icon['src']);
            self::assertSame('image/svg+xml', $icon['type']);
        }

        foreach ($manifest['shortcuts'] ?? [] as $shortcut) {
            foreach ($shortcut['icons'] ?? [] as $icon) {
                self::assertSame('/favicon.svg', $icon['src']);
            }
        }
    }

    public function testServiceWorkerAndStaticPagesDoNotUseLegacyPIcons(): void
    {
        $serviceWorker = file_get_contents($this->root . '/sw.js');
        $offline = file_get_contents($this->root . '/offline.html');
        $help = file_get_contents($this->root . '/customer-guide/assets/help.js');

        self::assertStringContainsString("const DEFAULT_ICON = '/favicon.svg'", (string)$serviceWorker);
        self::assertStringContainsString("const DEFAULT_BADGE = '/favicon.svg'", (string)$serviceWorker);
        self::assertStringNotContainsString('/assets/pwa/icon-192.png', (string)$serviceWorker);
        self::assertStringNotContainsString('/icon-192.png', (string)$serviceWorker);

        self::assertStringContainsString('/favicon.svg', (string)$offline);
        self::assertStringNotContainsString('>P</div>', (string)$offline);
        self::assertStringNotContainsString('/icon-192.png', (string)$offline);

        self::assertStringContainsString('../favicon.svg', (string)$help);
        self::assertStringNotContainsString('../icon-192.png', (string)$help);
    }

    public function testDefaultBrandScriptCoversHeadersPublicFallbacksAndSettingsPreview(): void
    {
        $script = file_get_contents($this->root . '/assets/default-brand.js');

        self::assertIsString($script);
        self::assertStringContainsString("document.querySelectorAll('.brand-mark')", $script);
        self::assertStringContainsString("'.public-brand-symbol'", $script);
        self::assertStringContainsString("'.customer-brand__symbol'", $script);
        self::assertStringContainsString('Current Logo', $script);
        self::assertStringContainsString('Default Permit System logo', $script);
        self::assertStringContainsString('input[name="company_logo"]', $script);
    }

    public function testLegacyIconUrlsRedirectToCanonicalSvg(): void
    {
        $htaccess = file_get_contents($this->root . '/.htaccess');

        self::assertIsString($htaccess);
        self::assertStringContainsString('favicon\\.ico', $htaccess);
        self::assertStringContainsString('icon-(?:192|512)\\.png', $htaccess);
        self::assertStringContainsString('/favicon.svg [R=302,L,NC]', $htaccess);
    }
}
