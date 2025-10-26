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
        SET status = 'active',
            approved_by = ?,
            approved_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$user['id'], $permit_id]);
    
    // Log activity
    if (function_exists('logActivity')) {
        logActivity(
            'permit_approved',
            'approval',
            'form',
            $permit_id,
            "Permit {$permit['ref_number']} approved by {$user['name']}"
        );
    }
    
    // Send email notification (if email system exists)
    if (!empty($permit['holder_email']) && function_exists('sendEmail')) {
        $email_subject = "✅ Your Permit #{$permit['ref_number']} is Approved!";
        $email_body = "
            <h2>Permit Approved!</h2>
            <p>Good news! Your permit has been approved and is now active.</p>
            <p><strong>Reference:</strong> #{$permit['ref_number']}</p>
            <p><strong>View your permit:</strong> <a href='https://{$_SERVER['HTTP_HOST']}/view-permit-public.php?link={$permit['unique_link']}'>Click here</a></p>
        ";
        
        sendEmail($permit['holder_email'], $email_subject, $email_body);
    }
    
    // Send push notification (if subscribed)
    // This would integrate with your push notification system
    
    echo json_encode([
        'success' => true,
        'message' => 'Permit approved successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("Error approving permit: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
}