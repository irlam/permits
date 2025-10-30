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
        ? "valid_to IS NOT NULL AND TRIM(valid_to) <> '' AND TRIM(valid_to) NOT LIKE '0000%'"
        : "valid_to IS NOT NULL AND valid_to NOT LIKE '0000%'";

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

    // Track count while processing to avoid loading all results into memory
    $candidateCount = 0;
    $updatedCount = 0;
    $updateStatement = $db->pdo->prepare(
        "UPDATE forms SET status = 'expired', updated_at = $nowExpression WHERE id = ?"
    );
    $eventStatement = $db->pdo->prepare(
        'INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?, ?, ?, ?, ?)'
    );

    $now = new DateTimeImmutable('now');
    foreach ($expiredPermits as $permit) {
        if (empty($permit['id'])) {
            continue;
        }

        $validToRaw = $permit['valid_to'] ?? null;
        if (!is_string($validToRaw) || $validToRaw === '' || $validToRaw === '0000-00-00 00:00:00' || $validToRaw === '0000-00-00') {
            // MySQL strict mode treats zero dates as errors, so skip them entirely
            continue;
        }

        try {
            $validTo = new DateTimeImmutable($validToRaw);
        } catch (\Throwable $e) {
            continue;
        }

        if ($validTo > $now) {
            continue;
        }

        $candidateCount++;

        // Log candidates found on first iteration
        if ($candidateCount === 1 && function_exists('logActivity')) {
            // We found at least one candidate, log it
            // (We can't know the total count without materializing all results)
            logActivity('permit_expiry_candidates', 'system', '', null, 'Processing permits eligible for expiration.');
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
        if ($candidateCount === 0) {
            logActivity('permit_expiry_complete', 'system', '', null, 'Automatic permit expiry completed. No permits found requiring expiration.');
        } elseif ($updatedCount > 0) {
            logActivity('permit_expiry_complete', 'system', '', null, "Automatic permit expiry completed. {$updatedCount} of {$candidateCount} permit(s) expired successfully.");
        } else {
            logActivity('permit_expiry_complete', 'system', '', null, "Automatic permit expiry completed. {$candidateCount} permit(s) found but none could be expired due to errors.");
        }
    }

    return $updatedCount;
}

/**
 * Opportunistically trigger the expiry sweep if it hasn't run recently.
 * Allows admin page hits to keep permits tidy without a dedicated cron.
 */
function maybe_check_and_expire_permits(object $db, int $intervalSeconds = 900): void
{
    try {
        $driver = $db->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) ?: 'mysql';
    } catch (\Throwable $e) {
        return;
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    if ($driver === 'sqlite') {
        $ensure = $db->pdo->prepare("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)");
        $ensure->execute();
        $select = $db->pdo->prepare("SELECT value FROM settings WHERE key = :key LIMIT 1");
    } else {
        $ensure = $db->pdo->prepare("CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(191) PRIMARY KEY, value TEXT, updated_at DATETIME NULL)");
        $ensure->execute();
        $select = $db->pdo->prepare("SELECT value FROM settings WHERE `key` = :key LIMIT 1");
    }

    $select->execute([':key' => 'auto_expiry_last_run']);
    $lastRun = $select->fetchColumn();

    if ($lastRun) {
        try {
            $last = new DateTimeImmutable($lastRun, new DateTimeZone('UTC'));
            if (($now->getTimestamp() - $last->getTimestamp()) < $intervalSeconds) {
                return;
            }
        } catch (\Exception $e) {
            // Ignore parse errors and force a run
        }
    }

    $expired = check_and_expire_permits($db);

    if ($driver === 'sqlite') {
        $upsert = $db->pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at");
        $upsert->execute([
            ':key' => 'auto_expiry_last_run',
            ':value' => $now->format('c'),
            ':updated' => $now->format('c'),
        ]);
    } else {
        $upsert = $db->pdo->prepare("INSERT INTO settings (`key`, value, updated_at) VALUES (:key, :value, NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()");
        $upsert->execute([
            ':key' => 'auto_expiry_last_run',
            ':value' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    if (function_exists('logActivity')) {
        logActivity(
            'permit_expiry_complete',
            'system',
            '',
            null,
            $expired > 0
                ? "Opportunistic expiry sweep completed via web request. {$expired} permit(s) updated."
                : 'Opportunistic expiry sweep completed via web request. No permits required updates.'
        );
    }
}