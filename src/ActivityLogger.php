<?php
declare(strict_types=1);

use Permits\Db;

/**
 * Persist a row in the activity log table.
 */
function log_activity(Db $db, string $user_id, string $type, string $description): bool
{
    try {
        static $columnCache = null;

        if ($columnCache === null) {
            $driver = $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $rows = $db->pdo->query('SHOW COLUMNS FROM activity_log')->fetchAll(\PDO::FETCH_ASSOC);
                $columnCache = array_map(static fn($row) => strtolower((string)$row['Field']), $rows);
            } else {
                $rows = $db->pdo->query('PRAGMA table_info(activity_log)')->fetchAll(\PDO::FETCH_ASSOC);
                $columnCache = array_map(static fn($row) => strtolower((string)$row['name']), $rows);
            }
        }

        $columns = $columnCache ?? [];
        $insertColumns = [];
        $placeholders = [];
        $params = [];

        $insertColumns[] = 'user_id';
        $placeholders[] = '?';
        $params[] = $user_id;

        $recordDescription = $description;

        if (in_array('type', $columns, true)) {
            $insertColumns[] = 'type';
            $placeholders[] = '?';
            $params[] = $type;
        } elseif (in_array('action', $columns, true)) {
            $insertColumns[] = 'action';
            $placeholders[] = '?';
            $params[] = $type;
        } else {
            $recordDescription = trim($type . ' ' . $recordDescription);
        }

        if (in_array('description', $columns, true)) {
            $insertColumns[] = 'description';
            $placeholders[] = '?';
            $params[] = $recordDescription;
        } elseif (in_array('details', $columns, true)) {
            $insertColumns[] = 'details';
            $placeholders[] = '?';
            $params[] = $recordDescription;
        }

        if (in_array('ip_address', $columns, true)) {
            $insertColumns[] = 'ip_address';
            $placeholders[] = '?';
            $params[] = $_SERVER['REMOTE_ADDR'] ?? null;
        }

        if (in_array('user_agent', $columns, true)) {
            $insertColumns[] = 'user_agent';
            $placeholders[] = '?';
            $params[] = $_SERVER['HTTP_USER_AGENT'] ?? null;
        }

        $sql = 'INSERT INTO activity_log (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        $stmt = $db->pdo->prepare($sql);
        $stmt->execute($params);

        return true;
    } catch (\Throwable $e) {
        error_log('Activity log error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Activity Types Reference:
 * 
 * - user_login               : User logged in
 * - user_logout              : User logged out
 * - user_created             : New user account created
 * - user_updated             : User account updated
 * - user_deleted             : User account deleted
 * - permit_created           : New permit created
 * - permit_viewed            : Permit viewed
 * - permit_approved          : Permit approved
 * - permit_rejected          : Permit rejected
 * - permit_closed            : Permit closed
 * - permit_expired           : Permit expired (individual permit)
 * - permit_expiry_check      : Automatic permit expiry check started
 * - permit_expiry_candidates : Permits found eligible for expiration
 * - permit_expiry_complete   : Automatic permit expiry check completed
 * - permit_expiry_failed     : Permit expiry process encountered an error
 * - settings_updated         : System settings updated
 * - template_created         : Form template created
 * - template_updated         : Form template updated
 * - backup_created           : Database backup created
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