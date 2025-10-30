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

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get current user
$stmt = $db->pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Get permit ID
$permitId = $_POST['permit_id'] ?? '';
$reason = trim($_POST['reason'] ?? '');

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
    $canClose = false;
    
    if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'manager') {
        // Admins and managers can close any permit
        $canClose = true;
    } elseif ($permit['holder_id'] === $currentUser['id']) {
        // Users can close their own permits
        $canClose = true;
    }
    
    if (!$canClose) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to close this permit']);
        exit;
    }
    
    // Check if permit can be closed
    if ($permit['status'] === 'closed') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Permit is already closed']);
        exit;
    }
    
    if ($permit['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Only active permits can be closed']);
        exit;
    }
    
    // Close the permit
    $stmt = $db->pdo->prepare("
        UPDATE forms 
        SET status = 'closed',
            closed_by = ?,
            closed_at = NOW(),
            closure_reason = ?
        WHERE id = ?
    ");
    
    $stmt->execute([
        $currentUser['id'],
        $reason,
        $permitId
    ]);
    
    if (function_exists('logActivity')) {
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
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Permit closed successfully',
        'permit_id' => $permitId,
        'closed_by' => $currentUser['name'],
        'closed_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error closing permit: ' . $e->getMessage()
    ]);
}