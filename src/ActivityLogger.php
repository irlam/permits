<?php
declare(strict_types=1);

use Permits\Db;

/**
 * Persist a row in the activity log table.
 */
function log_activity(Db $db, string $user_id, string $type, string $description): bool
{
    try {
        $stmt = $db->pdo->prepare('
            INSERT INTO activity_log (user_id, type, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt->execute([
            $user_id,
            $type,
            $description,
            $ip_address,
            $user_agent,
        ]);

        return true;
    } catch (\Throwable $e) {
        error_log('Activity log error: ' . $e->getMessage());
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

if (!function_exists('logActivity')) {
    /**
     * Backwards-compatible wrapper that enriches activity records with context.
     */
    function logActivity(string $type, string $category, string $entityType, $entityId, string $description = ''): bool
    {
        global $db;

        if (!isset($db) || !$db instanceof Db) {
            return false;
        }

        if (function_exists('startSession')) {
            startSession();
        } elseif (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = (string)($_SESSION['user_id'] ?? 'system');

        $parts = [];
        if ($description !== '') {
            $parts[] = $description;
        }
        if ($entityType !== '' && $entityId !== null && $entityId !== '') {
            $parts[] = '[' . $entityType . ':' . $entityId . ']';
        }
        if ($category !== '') {
            $parts[] = '(' . $category . ')';
        }

        $message = trim(implode(' ', $parts));
        if ($message === '') {
            $message = ucfirst($type);
        }

        return log_activity($db, $userId, $type, $message);
    }
}