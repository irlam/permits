<?php

declare(strict_types=1);

use Permits\Db;
use Ramsey\Uuid\Uuid;

/**
 * Locate permits whose validity window has elapsed and update them to expired.
 *
 * @param object{pdo: \PDO} $db Database wrapper with a public PDO instance
 * @return int Number of permits transitioned to the expired state.
 */
function check_and_expire_permits(object $db): int
{
    if (function_exists('logActivity')) {
        logActivity('permit_expiry_check', 'system', '', null, 'Starting automatic permit expiry check.');
    }

    try {
        $driver = $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?: 'mysql';
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('permit_expiry_failed', 'system', '', null, 'Unable to detect database driver: ' . $e->getMessage());
        }

        return 0;
    }

    $nowExpression = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
    $validToCheck = $driver === 'sqlite'
        ? "valid_to IS NOT NULL AND TRIM(valid_to) <> '' AND datetime(valid_to) <= $nowExpression"
        : "valid_to IS NOT NULL AND valid_to <> '0000-00-00 00:00:00' AND valid_to <= $nowExpression";

    $sql = <<<SQL
        SELECT id, status, valid_to, ref, ref_number
        FROM forms
        WHERE status IN ('issued', 'active')
          AND $validToCheck
    SQL;

    try {
        $expiredPermits = $db->pdo->query($sql, \PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('permit_expiry_failed', 'system', '', null, 'Expiry lookup failed: ' . $e->getMessage());
        }

        return 0;
    }

    if (!is_iterable($expiredPermits)) {
        return 0;
    }

    // Convert to array to get count for logging
    $expiredPermitsArray = is_array($expiredPermits) ? $expiredPermits : iterator_to_array($expiredPermits);
    $candidateCount = count($expiredPermitsArray);

    if (function_exists('logActivity') && $candidateCount > 0) {
        logActivity('permit_expiry_candidates', 'system', '', null, "Found {$candidateCount} permit(s) eligible for expiration.");
    }

    $updatedCount = 0;
    $updateStatement = $db->pdo->prepare(
        "UPDATE forms SET status = 'expired', updated_at = $nowExpression WHERE id = ?"
    );
    $eventStatement = $db->pdo->prepare(
        'INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($expiredPermitsArray as $permit) {
        if (empty($permit['id'])) {
            continue;
        }

        try {
            $updateStatement->execute([$permit['id']]);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('permit_expiry_failed', 'system', 'form', (string)($permit['id'] ?? ''), 'Unable to update permit status: ' . $e->getMessage());
            }
            continue;
        }

        try {
            $eventPayload = json_encode([
                'previous_status' => $permit['status'] ?? null,
                'new_status' => 'expired',
                'reason' => 'validity_window_elapsed',
                'expired_at' => gmdate('c'),
                'previous_valid_to' => $permit['valid_to'] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $eventStatement->execute([
                Uuid::uuid4()->toString(),
                $permit['id'],
                'status_changed',
                'auto-expiry',
                $eventPayload,
            ]);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('permit_expiry_failed', 'system', 'form', (string)($permit['id'] ?? ''), 'Unable to log expiry event: ' . $e->getMessage());
            }
        }

        if (function_exists('logActivity')) {
            $ref = $permit['ref'] ?? ($permit['ref_number'] ?? $permit['id']);
            logActivity(
                'permit_expired',
                'system',
                'form',
                (string)$permit['id'],
                "Permit {$ref} automatically expired after exceeding its valid window."
            );
        }

        $updatedCount++;
    }

    if (function_exists('logActivity')) {
        if ($updatedCount > 0) {
            logActivity('permit_expiry_complete', 'system', '', null, "Automatic permit expiry completed. {$updatedCount} permit(s) expired.");
        } else {
            logActivity('permit_expiry_complete', 'system', '', null, 'Automatic permit expiry completed. No permits needed expiration.');
        }
    }

    return $updatedCount;
}