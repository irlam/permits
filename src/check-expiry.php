<?php

declare(strict_types=1);

use Ramsey\Uuid\Uuid;

/**
 * Locate permits whose validity window has elapsed and update them to expired.
 *
 * @param object{pdo: \PDO} $db Database wrapper with a public PDO instance
 * @param bool $throwOnFailure Let the CLI worker surface database failures.
 * @return int Number of permits transitioned to the expired state.
 */
function check_and_expire_permits(object $db, bool $throwOnFailure = false): int
{
    try {
        $driver = $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?: 'mysql';
    } catch (\Throwable $e) {
        if ($throwOnFailure) {
            throw $e;
        }
        return 0;
    }

    $nowExpression = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
    $validToCheck = $driver === 'sqlite'
        ? "valid_to IS NOT NULL AND TRIM(valid_to) <> '' AND TRIM(valid_to) NOT LIKE '0000%' AND datetime(valid_to) <= $nowExpression"
        : "valid_to IS NOT NULL AND valid_to NOT LIKE '0000%' AND valid_to <= $nowExpression";

    $sql = <<<SQL
        SELECT id, status, valid_to, ref, ref_number
        FROM forms
        WHERE status IN ('issued', 'active', 'approved', 'open')
          AND $validToCheck
    SQL;

    try {
        $expiredPermits = $db->pdo->query($sql, \PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        if ($throwOnFailure) {
            throw $e;
        }
        return 0;
    }

    if (!is_iterable($expiredPermits)) {
        return 0;
    }

    $updatedCount = 0;
    $updateStatement = $db->pdo->prepare(
        "UPDATE forms
         SET status = 'expired', updated_at = $nowExpression
         WHERE id = ?
           AND status IN ('issued', 'active', 'approved', 'open')
           AND $validToCheck"
    );
    try {
        $eventStatement = $db->pdo->prepare(
            'INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?, ?, ?, ?, ?)'
        );
    } catch (\Throwable $e) {
        $eventStatement = null;
    }

    foreach ($expiredPermits as $permit) {
        if (empty($permit['id'])) {
            continue;
        }

        try {
            $updateStatement->execute([$permit['id']]);
        } catch (\Throwable $e) {
            if ($throwOnFailure) {
                throw $e;
            }
            continue;
        }

        // Another lifecycle action or expiry worker may have changed this row
        // after the SELECT. Only the process that wins the constrained UPDATE
        // records events or activity.
        if ($updateStatement->rowCount() !== 1) {
            continue;
        }

        $updatedCount++;

        try {
            $eventPayload = json_encode([
                'previous_status' => $permit['status'] ?? null,
                'new_status' => 'expired',
                'reason' => 'validity_window_elapsed',
                'expired_at' => gmdate('c'),
                'previous_valid_to' => $permit['valid_to'] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($eventStatement !== null) {
                $eventStatement->execute([
                    Uuid::uuid4()->toString(),
                    $permit['id'],
                    'status_changed',
                    'auto-expiry',
                    $eventPayload,
                ]);
            }
        } catch (\Throwable $e) {
            // Expiry remains authoritative even if the audit table is temporarily
            // unavailable; a later run must not duplicate the status transition.
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

    }

    return $updatedCount;
}
