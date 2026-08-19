<?php
/**
 * Approve Permit API
 *
 * Phase 4 separates management authorisation from holder/receiver acceptance.
 * Approval therefore moves a permit to awaiting_acceptance; the validity clock
 * starts only when the holder accepts the permit conditions.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/approval-notifications.php';
require_once __DIR__ . '/../src/permit-durations.php';
require_once __DIR__ . '/../src/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$auth = new Auth($db);
$user = $auth->requireJson(['manager', 'admin']);

if (!\Permits\Csrf::validateRequest('permit-approve')) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session token expired. Refresh the page and try again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$permit_id = is_array($input) && is_scalar($input['permit_id'] ?? null)
    ? trim((string) $input['permit_id'])
    : '';

if (!$permit_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing permit_id']);
    exit;
}

try {
    $stmt = $db->pdo->prepare("
        SELECT f.*, ft.form_structure, ft.json_schema
        FROM forms f
        INNER JOIN form_templates ft ON ft.id = f.template_id
        WHERE f.id = ?
        LIMIT 1
    ");
    $stmt->execute([$permit_id]);
    $permit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$permit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Permit not found']);
        exit;
    }

    if ($permit['status'] !== 'pending_approval') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Permit is not awaiting approval']);
        exit;
    }

    $structure = json_decode((string) ($permit['form_structure'] ?? ''), true);
    if (!is_array($structure) || $structure === []) {
        $schema = json_decode((string) ($permit['json_schema'] ?? ''), true);
        $structure = is_array($schema)
            ? \Permits\FormTemplateSeeder::buildPublicFormStructure($schema)
            : [];
    }
    $answers = json_decode((string) ($permit['form_data'] ?? ''), true);
    if (!is_array($answers)) {
        $answers = [];
    }

    if (($answers['_applicant_declaration'] ?? '') !== 'confirmed') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'The applicant declaration must be confirmed before approval.']);
        exit;
    }

    if ($structure === []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This permit template has no usable fields and cannot be approved.']);
        exit;
    }

    $validationErrors = \Permits\PermitFormValidator::validate($structure, $answers, true);
    if ($validationErrors !== []) {
        http_response_code(422);
        $summary = implode(' ', array_slice(array_values($validationErrors), 0, 3));
        $remaining = count($validationErrors) - min(3, count($validationErrors));
        if ($remaining > 0) {
            $summary .= sprintf(' %d more required field%s must be completed.', $remaining, $remaining === 1 ? '' : 's');
        }
        echo json_encode([
            'success' => false,
            'message' => 'This permit is incomplete and cannot be approved. ' . $summary,
        ]);
        exit;
    }

    $durationPresets = getPermitDurationPresets($db);
    $durationWasRequested = is_array($input) && array_key_exists('duration_minutes', $input);
    $requestedDuration = $durationWasRequested
        ? filter_var($input['duration_minutes'], FILTER_VALIDATE_INT)
        : false;
    if ($durationWasRequested && $requestedDuration === false) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid permit duration.']);
        exit;
    }
    $durationPreset = selectPermitDurationPreset(
        $durationPresets,
        $durationWasRequested ? (int) $requestedDuration : null,
        (string) ($permit['expiry_duration'] ?? '')
    );
    if ($durationPreset === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $durationWasRequested
            ? 'Select a valid permit duration.'
            : 'No valid permit durations are configured.']);
        exit;
    }
    $durationMinutes = $durationPreset['minutes'];
    $durationLabel = $durationPreset['label'];

    $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $now = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
    $db->pdo->beginTransaction();

    $updateStmt = $db->pdo->prepare("
        UPDATE forms
        SET status = 'awaiting_acceptance',
            approval_status = 'approved',
            approved_by = ?,
            approved_at = $now,
            valid_from = NULL,
            valid_to = NULL,
            expires_at = NULL,
            expiry_duration = ?,
            updated_at = $now
        WHERE id = ? AND status = 'pending_approval'
    ");
    $updateStmt->execute([$user['id'], $durationLabel, $permit_id]);
    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException('Permit state changed during approval');
    }

    \Permits\PermitWorkflow::recordEvent($db->pdo, $permit_id, 'permit_approved', (string)$user['id'], [
        'approved_by_name' => (string)($user['name'] ?? ''),
        'duration_minutes' => (int)$durationMinutes,
        'duration_label' => (string)$durationLabel,
        'holder_acceptance_required' => true,
    ]);
    $db->pdo->commit();

    try {
        clearPendingApprovalNotificationFlag($db, $permit_id);
    } catch (\Throwable $e) {
        error_log('Failed to clear approval notification flag after approval: ' . $e->getMessage());
    }

    if (function_exists('logActivity')) {
        try {
            logActivity(
                'permit_approved',
                'approval',
                'form',
                $permit_id,
                "Permit " . ($permit['ref_number'] ?? $permit['ref'] ?? $permit_id) . " approved by {$user['name']}; holder acceptance required"
            );
        } catch (Throwable $logError) {
            error_log('Unable to log permit approval: ' . $logError->getMessage());
        }
    }

    if (!empty($permit['holder_email'])) {
        try {
            $ref = $permit['ref_number'] ?? $permit['ref'] ?? $permit_id;
            $notificationPermit = array_merge($permit, [
                'ref' => (string) $ref,
                'ref_number' => (string) $ref,
                'valid_from' => null,
                'valid_to' => null,
                'duration_label' => $durationLabel,
                'status' => 'awaiting_acceptance',
            ]);
            $mailer = new \Permits\Email($db, $root);
            $mailer->sendApprovalNotification($notificationPermit, (string) $permit['holder_email']);
        } catch (Throwable $emailError) {
            error_log('Unable to queue permit approval email: ' . $emailError->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Permit approved. The holder must now accept the permit before work can start.',
        'status' => 'awaiting_acceptance',
        'duration_label' => $durationLabel,
        'duration_minutes' => $durationMinutes,
    ]);

} catch (Throwable $e) {
    if ($db->pdo->inTransaction()) { $db->pdo->rollBack(); }
    http_response_code(500);
    error_log('Error approving permit: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
