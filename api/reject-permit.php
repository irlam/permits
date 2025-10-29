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

// Start session
session_start();

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get current user
$user_stmt = $db->pdo->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$_SESSION['user_id']]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Check role
if (!$user || !in_array($user['role'], ['manager', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$permit_id = $input['permit_id'] ?? null;
$reason = $input['reason'] ?? '';

if (!$permit_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing permit_id']);
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
    
    // Update permit status
    $updateStmt = $db->pdo->prepare("
        UPDATE forms 
        SET status = 'rejected'
        WHERE id = ?
    ");
    $updateStmt->execute([$permit_id]);

    try {
        clearPendingApprovalNotificationFlag($db, $permit_id);
    } catch (\Throwable $e) {
        error_log('Failed to clear approval notification flag after rejection: ' . $e->getMessage());
    }
    
    // Log activity
    if (function_exists('logActivity')) {
        $reasonText = !empty($reason) ? "Reason: $reason" : "No reason provided";
        logActivity(
            'permit_rejected',
            'approval',
            'form',
            $permit_id,
            "Permit {$permit['ref_number']} rejected by {$user['name']}. $reasonText"
        );
    }
    
    // Send email notification (if email system exists)
    if (!empty($permit['holder_email']) && function_exists('sendEmail')) {
        $email_subject = "❌ Permit #{$permit['ref_number']} Update";
        $email_body = "
            <h2>Permit Update</h2>
            <p>Your permit application has been reviewed.</p>
            <p><strong>Reference:</strong> #{$permit['ref_number']}</p>
            <p><strong>Status:</strong> Not approved at this time</p>
        ";
        
        if (!empty($reason)) {
            $email_body .= "<p><strong>Note:</strong> " . htmlspecialchars($reason) . "</p>";
        }
        
        $email_body .= "<p>If you have questions, please contact the safety manager.</p>";
        
        sendEmail($permit['holder_email'], $email_subject, $email_body);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Permit rejected'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("Error rejecting permit: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}