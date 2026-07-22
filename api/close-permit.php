<?php
/**
 * Close Permit API
 * 
 * File Path: /api/close-permit.php
 * Description: Allow users to close their active permits
 * Created: 24/10/2025
 * Last Modified: 24/10/2025
 * 
 * Features:
 * - Users can close their own permits
 * - Admins/managers can close any permit
 * - Changes status to 'closed'
 * - Records who closed it and when
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$auth = new Auth($db);
$currentUser = $auth->requireJson();

if (!\Permits\Csrf::validateRequest('permit-close')) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session token expired. Refresh the page and try again.']);
    exit;
}

// Accept the form request used by the public view and JSON API clients.
$data = $_POST;
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$permitId = is_scalar($data['permit_id'] ?? null) ? trim((string) $data['permit_id']) : '';
$reason = is_scalar($data['reason'] ?? null) ? trim((string) $data['reason']) : '';

if (empty($permitId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Permit ID required']);
    exit;
}

try {
    // Get permit
    $stmt = $db->pdo->prepare("SELECT * FROM forms WHERE id = ?");
    $stmt->execute([$permitId]);
    $permit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$permit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Permit not found']);
        exit;
    }
    
    // Check permissions
    $canClose = \Permits\PermitAccess::canAccessPermit($currentUser, $permit);
    
    if (!$canClose) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to close this permit']);
        exit;
    }
    
    // Check if permit can be closed
    $permitStatus = strtolower((string) ($permit['status'] ?? ''));
    if ($permitStatus === 'closed') {
        echo json_encode([
            'success' => true,
            'message' => 'Permit was already closed',
            'permit_id' => $permitId,
            'closed_at' => $permit['closed_at'] ?? null,
        ]);
        exit;
    }
    
    if (!in_array($permitStatus, ['active', 'issued', 'approved', 'open'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Only active permits can be closed']);
        exit;
    }
    
    if (mb_strlen($reason) > 5000) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Reason is too long']);
        exit;
    }
    $now = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    $db->pdo->beginTransaction();
    // Close the permit atomically
    $stmt = $db->pdo->prepare("
        UPDATE forms 
        SET status = 'closed',
            closed_by = ?,
            closed_at = $now,
            closure_reason = ?,
            updated_at = $now
        WHERE id = ? AND status IN ('active', 'issued', 'approved', 'open')
    ");
    
    $stmt->execute([
        $currentUser['id'],
        $reason,
        $permitId
    ]);
    if ($stmt->rowCount() !== 1) {
        $db->pdo->rollBack();
        $stateStmt = $db->pdo->prepare('SELECT status, closed_at FROM forms WHERE id = ?');
        $stateStmt->execute([$permitId]);
        $state = $stateStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (strtolower((string) ($state['status'] ?? '')) === 'closed') {
            echo json_encode([
                'success' => true,
                'message' => 'Permit was already closed',
                'permit_id' => $permitId,
                'closed_at' => $state['closed_at'] ?? null,
            ]);
            exit;
        }
        throw new RuntimeException('Permit state changed during closure');
    }
    $db->pdo->commit();
    
    if (function_exists('logActivity')) {
        try {
            $description = sprintf(
                'Closed permit #%s%s',
                $permit['ref_number'],
                $reason !== '' ? ': ' . $reason : ''
            );

            logActivity(
                'permit_closed',
                'permit',
                'form',
                $permit['id'],
                $description
            );
        } catch (Throwable $logError) {
            error_log('Unable to log permit closure: ' . $logError->getMessage());
        }
    }
    
    $closedAtStmt = $db->pdo->prepare('SELECT closed_at FROM forms WHERE id = ?');
    $closedAtStmt->execute([$permitId]);
    $closedAt = $closedAtStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => 'Permit closed successfully',
        'permit_id' => $permitId,
        'closed_by' => $currentUser['name'],
        'closed_at' => $closedAt
    ]);
    
} catch (Throwable $e) {
    if ($db->pdo->inTransaction()) { $db->pdo->rollBack(); }
    error_log('Permit close failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to close permit'
    ]);
}
