<?php
/**
 * Reject Permit API
 * 
 * File Path: /api/reject-permit.php
 * Description: API endpoint to reject pending permits
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - Rejects permit (changes status to rejected)
 * - Optionally stores rejection reason
 * - Sends email notification
 * - Manager/Admin only
 */

header('Content-Type: application/json');

// Load bootstrap
[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/approval-notifications.php';
require_once __DIR__ . '/../src/Auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$auth = new Auth($db);
$user = $auth->requireJson(['manager', 'admin']);

if (!\Permits\Csrf::validateRequest('permit-reject')) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session token expired. Refresh the page and try again.']);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$permit_id = is_array($input) && is_scalar($input['permit_id'] ?? null)
    ? trim((string) $input['permit_id'])
    : '';
$reason = is_array($input) && is_scalar($input['reason'] ?? null)
    ? trim((string) $input['reason'])
    : '';

if (!$permit_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing permit_id']);
    exit;
}
if ($reason === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a reason so the applicant knows what to correct.']);
    exit;
}

try {
    // Get permit details
    $stmt = $db->pdo->prepare("SELECT * FROM forms WHERE id = ?");
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
    if (mb_strlen($reason) > 5000) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Reason is too long']);
        exit;
    }
    $now = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    $db->pdo->beginTransaction();
    
    // Update permit status
    $updateStmt = $db->pdo->prepare("
        UPDATE forms 
        SET status = 'rejected', approval_status = 'rejected', approval_notes = ?,
            approved_by = ?, approved_at = $now, updated_at = $now
        WHERE id = ? AND status = 'pending_approval'
    ");
    $updateStmt->execute([$reason, $user['id'], $permit_id]);
    if ($updateStmt->rowCount() !== 1) { throw new RuntimeException('Permit state changed during rejection'); }
    $db->pdo->commit();

    try {
        clearPendingApprovalNotificationFlag($db, $permit_id);
    } catch (\Throwable $e) {
        error_log('Failed to clear approval notification flag after rejection: ' . $e->getMessage());
    }
    
    // Log activity
    if (function_exists('logActivity')) {
        try {
            logActivity(
                'permit_rejected',
                'approval',
                'form',
                $permit_id,
                "Permit {$permit['ref_number']} rejected by {$user['name']}. Reason: {$reason}"
            );
        } catch (Throwable $logError) {
            error_log('Unable to log permit rejection: ' . $logError->getMessage());
        }
    }
    
    // Queue the holder notification after the permit transaction has committed.
    if (!empty($permit['holder_email'])) {
        try {
            $ref = $permit['ref_number'] ?? $permit['ref'] ?? $permit_id;
            $notificationPermit = array_merge($permit, [
                'ref' => (string) $ref,
                'ref_number' => (string) $ref,
            ]);
            $mailer = new \Permits\Email($db, $root);
            $mailer->sendRejectionNotification(
                $notificationPermit,
                (string) $permit['holder_email'],
                $reason
            );
        } catch (Throwable $emailError) {
            error_log('Unable to queue permit rejection email: ' . $emailError->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Permit rejected'
    ]);
    
} catch (Throwable $e) {
    if ($db->pdo->inTransaction()) { $db->pdo->rollBack(); }
    http_response_code(500);
    error_log("Error rejecting permit: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}
