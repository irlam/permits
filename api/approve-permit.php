<?php
/**
 * Approve Permit API
 * 
 * File Path: /api/approve-permit.php
 * Description: API endpoint to approve pending permits
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - Approves permit (changes status to active)
 * - Sends email notification
 * - Sends push notification (if subscribed)
 * - Manager/Admin only
 */

header('Content-Type: application/json');

// Load bootstrap
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

// Get request data
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
    // Get permit details
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
    $validTo = $driver === 'sqlite'
        ? "datetime('now', '+{$durationMinutes} minutes')"
        : "DATE_ADD(NOW(), INTERVAL {$durationMinutes} MINUTE)";
    $db->pdo->beginTransaction();
    // Update permit status atomically
    $updateStmt = $db->pdo->prepare("
        UPDATE forms 
        SET status = 'active',
            approval_status = 'approved',
            approved_by = ?,
            approved_at = $now,
            valid_from = $now,
            valid_to = $validTo,
            expires_at = $validTo,
            expiry_duration = ?,
            updated_at = $now
        WHERE id = ? AND status = 'pending_approval'
    ");
    $updateStmt->execute([$user['id'], $durationLabel, $permit_id]);
    if ($updateStmt->rowCount() !== 1) { throw new RuntimeException('Permit state changed during approval'); }
    $db->pdo->commit();

    $validityStmt = $db->pdo->prepare('SELECT valid_from, valid_to FROM forms WHERE id = ?');
    $validityStmt->execute([$permit_id]);
    $validity = $validityStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    try {
        clearPendingApprovalNotificationFlag($db, $permit_id);
    } catch (\Throwable $e) {
        error_log('Failed to clear approval notification flag after approval: ' . $e->getMessage());
    }
    
    // Log activity
    if (function_exists('logActivity')) {
        try {
            logActivity(
                'permit_approved',
                'approval',
                'form',
                $permit_id,
                "Permit " . ($permit['ref_number'] ?? $permit['ref'] ?? $permit_id) . " approved by {$user['name']}"
            );
        } catch (Throwable $logError) {
            error_log('Unable to log permit approval: ' . $logError->getMessage());
        }
    }
    
    // Queue the holder notification after the permit transaction has committed.
    if (!empty($permit['holder_email'])) {
        try {
            $ref = $permit['ref_number'] ?? $permit['ref'] ?? $permit_id;
            $notificationPermit = array_merge($permit, [
                'ref' => (string) $ref,
                'ref_number' => (string) $ref,
                'valid_from' => $validity['valid_from'] ?? null,
                'valid_to' => $validity['valid_to'] ?? null,
                'duration_label' => $durationLabel,
            ]);
            $mailer = new \Permits\Email($db, $root);
            $mailer->sendApprovalNotification($notificationPermit, (string) $permit['holder_email']);
        } catch (Throwable $emailError) {
            error_log('Unable to queue permit approval email: ' . $emailError->getMessage());
        }
    }
    
    // Send push notification (if subscribed)
    // This would integrate with your push notification system
    
    echo json_encode([
        'success' => true,
        'message' => 'Permit approved successfully',
        'duration_label' => $durationLabel,
        'valid_from' => $validity['valid_from'] ?? null,
        'valid_to' => $validity['valid_to'] ?? null,
    ]);
    
} catch (Throwable $e) {
    if ($db->pdo->inTransaction()) { $db->pdo->rollBack(); }
    http_response_code(500);
    error_log("Error approving permit: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
