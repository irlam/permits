<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminWorkflowUxRegressionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testDashboardLoadsChartLibraryBeforeCreatingCharts(): void
    {
        $source = file_get_contents($this->root . '/dashboard-legacy.php');
        self::assertIsString($source);

        self::assertMatchesRegularExpression('/^\s*<script[^\r\n]*chart\.umd\.min\.js[^\r\n]*<\/script>\s*$/m', $source);
        preg_match('/^\s*<script[^\r\n]*chart\.umd\.min\.js[^\r\n]*<\/script>\s*$/m', $source, $scriptTag);

        self::assertStringNotContainsString('defer', $scriptTag[0]);
        self::assertLessThan(strpos($source, 'new Chart('), strpos($source, 'chart.umd.min.js'));

        $wrapper = file_get_contents($this->root . '/dashboard.php');
        self::assertStringContainsString('dashboard-legacy.php', (string)$wrapper);
        self::assertStringContainsString('permit-board.php', (string)$wrapper);
        self::assertStringContainsString('🪧 Permit Board', (string)$wrapper);
    }

    public function testAllQrPrintLayoutKeepsTheQrContainerVisible(): void
    {
        $source = file_get_contents($this->root . '/admin/qr-codes-all.php');
        self::assertIsString($source);

        $printStart = strpos($source, '@media print');
        self::assertNotFalse($printStart);
        $styleEnd = strpos($source, '</style>', $printStart);
        self::assertNotFalse($styleEnd);
        $printCss = substr($source, $printStart, $styleEnd - $printStart);

        preg_match_all('/([^{}]+)\{[^{}]*display\s*:\s*none\s*;[^{}]*\}/', $printCss, $hiddenRules);
        self::assertStringNotContainsString('.site-container', implode("\n", $hiddenRules[1]));
        self::assertStringContainsString('.qr-grid', $printCss);
    }

    public function testIndividualQrSearchUsesAnExplicitGetFormAndKeepsSelection(): void
    {
        $source = file_get_contents($this->root . '/admin/qr-codes-individual.php');
        self::assertIsString($source);

        self::assertStringContainsString('<form class="search-box" method="get"', $source);
        self::assertStringContainsString('name="q"', $source);
        self::assertStringContainsString('name="permit_id"', $source);
        self::assertStringContainsString('type="submit">Search</button>', $source);
        self::assertStringNotContainsString("addEventListener('keyup'", $source);
    }

    public function testLoggedOutPermitViewerGetsAContextPreservingTeamLogin(): void
    {
        $view = file_get_contents($this->root . '/view-permit-public-legacy.php');
        $wrapper = file_get_contents($this->root . '/view-permit-public.php');
        $home = file_get_contents($this->root . '/index.php');
        self::assertIsString($view);
        self::assertIsString($wrapper);
        self::assertIsString($home);

        self::assertStringContainsString("$" . "permitReturnPath = '/view-permit-public.php?link='", $view);
        self::assertStringContainsString("urlencode($" . "permitReturnPath)", $view);
        self::assertStringContainsString('if ($isActive && !$currentUserIsActive)', $view);
        self::assertStringContainsString('Team sign in', $view);
        self::assertStringContainsString('Workflow / Handover', $wrapper);
        self::assertStringContainsString('>Team sign in</a>', $home);
    }

    public function testAdminCanHideTemplatesWithoutDeletingExistingPermits(): void
    {
        $editor = file_get_contents($this->root . '/admin-template-editor.php');
        self::assertIsString($editor);

        self::assertStringContainsString('active = ?', $editor);
        self::assertStringContainsString('name="active" value="1"', $editor);
        self::assertStringContainsString('Available for new permits', $editor);
        self::assertStringContainsString('Existing permits remain available.', $editor);
        self::assertStringContainsString("'Available' : 'Hidden'", $editor);
    }

    public function testCustomerGuideExplainsSignInBeforeLifecycleActions(): void
    {
        $guide = file_get_contents($this->root . '/customer-guide/index.html');
        self::assertIsString($guide);

        $signInPosition = strpos($guide, '<strong>Team sign in</strong>');
        $startPosition = strpos($guide, '<strong>Start work</strong>', $signInPosition ?: 0);
        self::assertNotFalse($signInPosition);
        self::assertNotFalse($startPosition);
        self::assertLessThan($startPosition, $signInPosition);
    }

    public function testPwaShortcutsOpenWorkingDestinationsAndPushClicksStayLocal(): void
    {
        $manifest = json_decode((string) file_get_contents($this->root . '/manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
        $serviceWorker = file_get_contents($this->root . '/sw.js');
        $home = file_get_contents($this->root . '/index.php');

        self::assertSame('/#permit-templates', $manifest['shortcuts'][0]['url']);
        self::assertSame('/#status-checker', $manifest['shortcuts'][1]['url']);
        self::assertStringContainsString("window.location.hash === '#permit-templates'", (string) $home);
        self::assertStringContainsString('candidate.origin === self.location.origin', (string) $serviceWorker);
    }

    public function testLoginWordingIncludesEveryTeamRole(): void
    {
        $login = file_get_contents($this->root . '/login.php');

        self::assertStringContainsString('<title>Team Sign In', (string) $login);
        self::assertStringNotContainsString('Manager Login', (string) $login);
    }
}
