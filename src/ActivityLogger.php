<?php
/**
 * Simple Activity Logger Function
 * 
 * File Path: /src/log_activity.php
 * Description: Simple function to log activities
 * Created: 24/10/2025
 * 
 * Usage:
 * require __DIR__ . '/src/log_activity.php';
 * log_activity($db, $user_id, 'user_login', 'User logged in successfully');
 */

function log_activity($db, $user_id, $type, $description) {
    try {
        $stmt = $db->pdo->prepare("
            INSERT INTO activity_log (user_id, type, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->execute([
            $user_id,
            $type,
            $description,
            $ip_address,
            $user_agent
        ]);
        
        return true;
    } catch (Exception $e) {
        // Silent fail - don't break the app if logging fails
        error_log("Activity log error: " . $e->getMessage());
        return false;
    }
}

/**
 * Activity Types Reference:
 * 
 * - user_login        : User logged in
 * - user_logout       : User logged out
 * - user_created      : New user account created
 * - user_updated      : User account updated
 * - user_deleted      : User account deleted
 * - permit_created    : New permit created
 * - permit_viewed     : Permit viewed
 * - permit_approved   : Permit approved
 * - permit_rejected   : Permit rejected
 * - permit_closed     : Permit closed
 * - permit_expired    : Permit expired
 * - settings_updated  : System settings updated
 * - template_created  : Form template created
 * - template_updated  : Form template updated
 * - backup_created    : Database backup created
 */