<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PublicPermitLifecycleRegressionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testPublicViewPreservesDefensiveExpiryAndAddsPhase4StopWorkLayer(): void
    {
        $legacy = (string)file_get_contents($this->root . '/view-permit-public-legacy.php');
        $wrapper = (string)file_get_contents($this->root . '/view-permit-public.php');

        self::assertStringContainsString('$validToTimestamp <= time()', $legacy);
        self::assertStringContainsString("\$permitStatus = 'expired'", $legacy);
        self::assertStringContainsString("require __DIR__ . '/view-permit-public-legacy.php'", $wrapper);
        self::assertStringContainsString('SUSPENDED — DO NOT WORK', $wrapper);
        self::assertStringContainsString('PermitLinks::forPermit', $wrapper);
        self::assertStringContainsString('Workflow / Handover', $wrapper);
    }

    public function testEmailLookupUsesExecutableSqlAndNormalisedAddress(): void
    {
        $source = file_get_contents($this->root . '/index.php');
        $start = strpos($source, '$lookup =');
        self::assertNotFalse($start);
        $lookupBlock = substr($source, $start, 700);

        self::assertStringNotContainsString('\\n', $lookupBlock);
        self::assertStringContainsString('LOWER(TRIM(f.holder_email))', $lookupBlock);
        self::assertStringContainsString('LEFT JOIN form_templates', $lookupBlock);
    }

    public function testDraftAndFinalSubmissionHaveDistinctStatuses(): void
    {
        $source = file_get_contents($this->root . '/create-permit-public.php');

        self::assertStringContainsString("$" . "targetStatus = $" . "isDraftAction ? 'draft' : 'pending_approval'", $source);
        self::assertStringContainsString('PermitFormValidator::validate', $source);
        self::assertStringContainsString('status=?', $source);
        self::assertStringContainsString("validateRequest('public-permit-submit'", $source);
        self::assertStringContainsString('http_response_code(419)', $source);
        self::assertStringContainsString('_applicant_declaration', $source);
        self::assertStringContainsString('value="save_draft" class="btn btn-secondary" formnovalidate', $source);
        self::assertStringContainsString('$previousData = $existingData', $source);
        self::assertStringContainsString('$insertAttempt < 10', $source);
        self::assertStringContainsString('$unique_link = bin2hex(random_bytes(32))', $source);
        self::assertStringNotContainsString("SELECT COUNT(*) FROM forms WHERE ref_number", $source);
    }

    public function testNewPublicPermitsUsePublishedTemplatesAndKeepAccountOwnership(): void
    {
        $source = file_get_contents($this->root . '/create-permit-public.php');

        self::assertStringContainsString("$" . "existingPermit === null", $source);
        self::assertStringContainsString("AND active = 1", $source);
        self::assertStringContainsString("$" . "auth->getCurrentUser()", $source);
        self::assertStringContainsString('holder_id', $source);
        self::assertStringContainsString('issuer_id', $source);
        self::assertStringContainsString('hash_equals(strtolower(trim', $source);
    }

    public function testPublicUploadsHaveCountAndActualSizeLimits(): void
    {
        $source = file_get_contents($this->root . '/create-permit-public.php');

        self::assertStringContainsString('$maxUploadFilesPerField = 5', $source);
        self::assertStringContainsString('$maxTotalUploadFiles = 10', $source);
        self::assertStringContainsString('$maxUploadFileBytes = ($currentUser !== null ? 25 : 10) * 1024 * 1024', $source);
        self::assertStringContainsString('$maxTotalUploadBytes = ($currentUser !== null ? 50 : 20) * 1024 * 1024', $source);
        self::assertStringContainsString('$actualSize = filesize($tmp)', $source);
        self::assertStringContainsString("!@mkdir($" . "baseUploadDir, 0775, true) && !is_dir($" . "baseUploadDir)", $source);
    }

    public function testPublicQrUsesUnguessableLinkAndDownloadHeaders(): void
    {
        $view = file_get_contents($this->root . '/view-permit-public-legacy.php');
        $qr = file_get_contents($this->root . '/qr-code.php');

        self::assertStringContainsString("qr-code.php'))?>?link=", $view);
        self::assertStringNotContainsString("qr-code.php'))?>?id=", $view);
        self::assertStringContainsString('Content-Disposition: attachment', $qr);
        self::assertStringContainsString("$" . "app->url('/view-permit-public.php?link='", $qr);
    }

    public function testApprovalRequiresHolderAcceptanceBeforeValidityBegins(): void
    {
        $source = file_get_contents($this->root . '/api/approve-permit.php');

        self::assertStringContainsString('getPermitDurationPresets', $source);
        self::assertStringContainsString("status = 'awaiting_acceptance'", $source);
        self::assertStringContainsString('valid_from = NULL', $source);
        self::assertStringContainsString('valid_to = NULL', $source);
        self::assertStringContainsString('PermitFormValidator::validate', $source);
        self::assertStringContainsString("validateRequest('permit-approve')", $source);
        self::assertStringContainsString("approval_status = 'approved'", $source);
        self::assertStringContainsString('holder_acceptance_required', $source);
        self::assertStringContainsString('sendApprovalNotification', $source);
        self::assertStringNotContainsString("SET status = 'active'", $source);
    }

    public function testLifecycleMutationEndpointsUseActionScopedCsrfTokens(): void
    {
        $actions = [
            'api/approve-permit.php' => 'permit-approve',
            'api/reject-permit.php' => 'permit-reject',
            'api/start-work.php' => 'permit-start-work',
            'api/close-permit.php' => 'permit-close',
        ];

        foreach ($actions as $path => $action) {
            $source = file_get_contents($this->root . '/' . $path);
            self::assertStringContainsString("Csrf::validateRequest('{$action}')", $source, $path);
            self::assertStringContainsString('http_response_code(419)', $source, $path);
        }

        foreach (array_keys($actions) as $path) {
            $source = file_get_contents($this->root . '/' . $path);
            self::assertStringContainsString('$auth->requireJson(', $source, $path);
        }

        $workflow = file_get_contents($this->root . '/permit-workflow.php');
        self::assertStringContainsString("Csrf::validateRequest('permit-workflow'", $workflow);
        self::assertStringContainsString("Csrf::getFormField('permit-workflow')", $workflow);

        $reject = file_get_contents($this->root . '/api/reject-permit.php');
        self::assertStringContainsString('sendRejectionNotification', $reject);
        self::assertStringNotContainsString("function_exists('sendEmail')", $reject);

        $close = file_get_contents($this->root . '/api/close-permit.php');
        self::assertStringContainsString('PermitAccess::canAccessPermit', $close);
        self::assertStringContainsString("'suspended'", $close);
    }

    public function testAnonymousBearerViewerCannotStartWork(): void
    {
        $api = file_get_contents($this->root . '/api/start-work.php');
        self::assertStringContainsString('$auth->requireJson()', $api);
        self::assertStringContainsString('PermitAccess::canAccessPermit($currentUser, $permit)', $api);
        self::assertStringContainsString('http_response_code(403)', $api);
        self::assertStringContainsString('PermitLinks::blockingConflicts', $api);

        $auth = file_get_contents($this->root . '/src/Auth.php');
        self::assertStringContainsString("['status'] ?? ''", $auth);
        self::assertStringContainsString("$" . "this->jsonError(401, 'Authentication required')", $auth);

        $view = file_get_contents($this->root . '/view-permit-public-legacy.php');
        self::assertStringContainsString('$canStartWork = $isActive && ($canApprove || $ownsPermit)', $view);
        self::assertStringContainsString('$startWorkCsrfToken = $canStartWork ?', $view);
        self::assertStringContainsString('if ($canStartWork && !$hasWorkStarted)', $view);
        self::assertStringNotContainsString('$startWorkCsrfToken = $isActive ?', $view);
        self::assertStringNotContainsString('DatabaseMaintenance::', $api);
    }

    public function testExpiryIsCliOwnedAndIncludesPhase4OperationalStates(): void
    {
        foreach (['index.php', 'view-permit-public-legacy.php', 'manager-approvals.php', 'admin.php', 'dashboard.php'] as $path) {
            $source = file_get_contents($this->root . '/' . $path);
            self::assertStringNotContainsString('check_and_expire_permits', $source, $path);
            self::assertStringNotContainsString('maybe_check_and_expire_permits', $source, $path);
        }

        $expiry = file_get_contents($this->root . '/src/check-expiry.php');
        self::assertStringContainsString("'suspended', 'awaiting_acceptance'", $expiry);
        self::assertStringContainsString('valid_to <= $nowExpression', $expiry);
        self::assertStringContainsString('$updateStatement->rowCount() !== 1', $expiry);

        $worker = file_get_contents($this->root . '/bin/auto-status-update.php');
        self::assertStringContainsString("PHP_SAPI !== 'cli'", $worker);
        self::assertStringContainsString('check_and_expire_permits($db, true)', $worker);
    }
}
