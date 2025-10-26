<?php
/**
 * Real-Time Expiry Checker
 * 
 * File Path: /src/check-expiry.php
 * Description: Check and expire permits in real-time (no cron needed!)
 * Created: 24/10/2025
 * 
 * Usage:
 * require __DIR__ . '/src/check-expiry.php';
 * check_and_expire_permits($db);
 * 
 * Call this function on ANY page that displays permits:
 * - dashboard.php
 * - manager-approvals.php
 * - index.php
 * - admin/activity.php
 */

function check_and_expire_permits($db) {
    try {
        // Find active permits that have passed their expiry time
        $stmt = $db->pdo->prepare("
            SELECT id, ref_number, expiry_duration
            FROM forms
            WHERE status = 'active'
            AND expires_at IS NOT NULL
            AND expires_at < NOW()
        ");
        $stmt->execute();
        $expiredPermits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Expire each one
        foreach ($expiredPermits as $permit) {
            $updateStmt = $db->pdo->prepare("
                UPDATE forms 
                SET status = 'expired',
                    expired_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$permit['id']]);
            
            // Log activity (optional)
            try {
                $logStmt = $db->pdo->prepare("
                    INSERT INTO activity_log (user_id, user_email, action, category, description, timestamp)
                    VALUES ('system', 'system@permits.local', 'permit_expired', 'system', ?, NOW())
                ");
                $logStmt->execute([
                    "Permit {$permit['ref_number']} auto-expired after {$permit['expiry_duration']}"
                ]);
            } catch (Exception $e) {
                // Ignore logging errors
            }
        }
        
        return count($expiredPermits);
        
    } catch (Exception $e) {
        error_log("Expiry check error: " . $e->getMessage());
        return 0;
    }
}