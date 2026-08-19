<?php
declare(strict_types=1);

namespace Permits;

use PDO;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Central state transitions for the operational permit-to-work lifecycle.
 *
 * Form answers remain immutable after approval; lifecycle communication is
 * recorded separately in form_events so historical permit content is preserved.
 */
final class PermitWorkflow
{
    public const AWAITING_ACCEPTANCE = 'awaiting_acceptance';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';

    /** @return array<string,mixed> */
    public static function load(PDO $pdo, string $permitId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM forms WHERE id = ? LIMIT 1');
        $stmt->execute([$permitId]);
        $permit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($permit)) {
            throw new RuntimeException('Permit not found');
        }
        return $permit;
    }

    /**
     * Return the exact duration authorised by the manager at approval time.
     * This is read from the immutable approval event rather than today's admin
     * presets, so changing duration presets later cannot silently alter a permit
     * that is waiting for holder acceptance.
     */
    public static function approvedDurationMinutes(PDO $pdo, string $permitId): ?int
    {
        try {
            $stmt = $pdo->prepare("SELECT payload FROM form_events WHERE form_id = ? AND type = 'permit_approved' ORDER BY at DESC LIMIT 1");
            $stmt->execute([$permitId]);
            $payload = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_string($payload) || $payload === '') {
            return null;
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }
        $minutes = filter_var($decoded['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 1 || $minutes > 525600) {
            return null;
        }
        return (int)$minutes;
    }

    /**
     * Holder/receiver acceptance is required for permits approved after Phase 4
     * and after every formal revalidation. Existing already-active permits are
     * deliberately grandfathered and are not interrupted by deployment.
     *
     * @return array<string,mixed>
     */
    public static function accept(
        PDO $pdo,
        string $permitId,
        string $acceptedName,
        string $acceptedEmail,
        ?string $userId,
        ?int $initialDurationMinutes = null
    ): array {
        $acceptedName = trim($acceptedName);
        $acceptedEmail = strtolower(trim($acceptedEmail));
        if ($acceptedName === '' || filter_var($acceptedEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Enter the permit holder name and a valid email address.');
        }

        $permit = self::load($pdo, $permitId);
        if (strtolower((string)($permit['status'] ?? '')) !== self::AWAITING_ACCEPTANCE) {
            throw new RuntimeException('This permit is not awaiting holder acceptance.');
        }

        $expectedEmail = strtolower(trim((string)($permit['holder_email'] ?? '')));
        if ($expectedEmail === '' || !hash_equals($expectedEmail, $acceptedEmail)) {
            throw new RuntimeException('The email address does not match the current permit holder.');
        }

        $validTo = (string)($permit['valid_to'] ?? '');
        if ($validTo !== '' && $validTo !== '0000-00-00 00:00:00') {
            $validToTimestamp = strtotime($validTo);
            if ($validToTimestamp !== false && $validToTimestamp <= time()) {
                throw new RuntimeException('This permit expired before it was accepted. A new permit is required.');
            }
        }

        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
        $setValidity = empty($permit['valid_from']) || empty($permit['valid_to']);
        if ($setValidity) {
            $recordedDuration = self::approvedDurationMinutes($pdo, $permitId);
            if ($recordedDuration !== null) {
                $initialDurationMinutes = $recordedDuration;
            }
            if ($initialDurationMinutes === null || $initialDurationMinutes < 1) {
                throw new RuntimeException('The approved permit duration could not be resolved.');
            }
        }

        $pdo->beginTransaction();
        try {
            if ($setValidity) {
                $minutes = min(525600, max(1, (int)$initialDurationMinutes));
                $validToExpr = $driver === 'sqlite'
                    ? "datetime('now', '+{$minutes} minutes')"
                    : "DATE_ADD(NOW(), INTERVAL {$minutes} MINUTE)";
                $update = $pdo->prepare("
                    UPDATE forms
                    SET status = 'active', valid_from = {$now}, valid_to = {$validToExpr}, expires_at = {$validToExpr}, updated_at = {$now}
                    WHERE id = ? AND status = 'awaiting_acceptance'
                ");
            } else {
                $update = $pdo->prepare("
                    UPDATE forms
                    SET status = 'active', updated_at = {$now}
                    WHERE id = ? AND status = 'awaiting_acceptance'
                      AND (valid_to IS NULL OR valid_to > {$now})
                ");
            }
            $update->execute([$permitId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Permit state changed before acceptance could be recorded.');
            }

            self::insertEvent($pdo, $permitId, 'holder_accepted', $userId, [
                'accepted_name' => $acceptedName,
                'accepted_email' => $acceptedEmail,
                'declaration' => 'Holder/receiver confirmed understanding of the permit, hazards and stated controls.',
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::load($pdo, $permitId);
    }

    /** @return array<string,mixed> */
    public static function suspend(PDO $pdo, string $permitId, string $byUser, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 2000) {
            throw new RuntimeException('Give a short reason for suspending the permit.');
        }

        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare("
                UPDATE forms
                SET status = 'suspended', updated_at = {$now}
                WHERE id = ? AND status IN ('active', 'issued', 'approved', 'open')
            ");
            $update->execute([$permitId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Only an active permit can be suspended.');
            }
            self::insertEvent($pdo, $permitId, 'permit_suspended', $byUser, ['reason' => $reason]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return self::load($pdo, $permitId);
    }

    /** @return array<string,mixed> */
    public static function revalidate(
        PDO $pdo,
        string $permitId,
        string $byUser,
        string $notes,
        bool $controlsReviewed,
        bool $linkedPermitsReviewed
    ): array {
        $notes = trim($notes);
        if (!$controlsReviewed || !$linkedPermitsReviewed) {
            throw new RuntimeException('Confirm both the permit controls and linked/SIMOPS permits were reviewed.');
        }
        if (mb_strlen($notes, 'UTF-8') > 3000) {
            throw new RuntimeException('Revalidation notes are too long.');
        }

        $permit = self::load($pdo, $permitId);
        if (strtolower((string)($permit['status'] ?? '')) !== self::SUSPENDED) {
            throw new RuntimeException('Only a suspended permit can be revalidated.');
        }
        $validTo = (string)($permit['valid_to'] ?? '');
        if ($validTo === '' || $validTo === '0000-00-00 00:00:00') {
            throw new RuntimeException('This permit has no remaining authorised validity period.');
        }
        $validToTimestamp = strtotime($validTo);
        if ($validToTimestamp === false || $validToTimestamp <= time()) {
            throw new RuntimeException('This permit has expired. Revalidation cannot extend it; raise a new permit.');
        }

        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare("
                UPDATE forms
                SET status = 'awaiting_acceptance', updated_at = {$now}
                WHERE id = ? AND status = 'suspended' AND valid_to > {$now}
            ");
            $update->execute([$permitId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Permit state changed before revalidation could be recorded.');
            }
            self::insertEvent($pdo, $permitId, 'permit_revalidated', $byUser, [
                'notes' => $notes,
                'controls_reviewed' => true,
                'linked_permits_reviewed' => true,
                'holder_reacceptance_required' => true,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return self::load($pdo, $permitId);
    }

    /**
     * Capture a face-to-face, two-way shift/team handover and move current
     * responsibility to the incoming person while preserving the handover audit.
     *
     * @return array<string,mixed>
     */
    public static function handover(
        PDO $pdo,
        string $permitId,
        string $byUser,
        string $outgoingName,
        string $incomingName,
        string $incomingEmail,
        ?string $incomingUserId,
        string $notes,
        bool $safeStateConfirmed,
        bool $controlsReviewed,
        bool $linkedPermitsReviewed,
        bool $incomingAcknowledged
    ): array {
        $outgoingName = trim($outgoingName);
        $incomingName = trim($incomingName);
        $incomingEmail = strtolower(trim($incomingEmail));
        $notes = trim($notes);
        if ($outgoingName === '' || $incomingName === '' || filter_var($incomingEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Enter both handover names and a valid incoming holder email.');
        }
        if (!$safeStateConfirmed || !$controlsReviewed || !$linkedPermitsReviewed || !$incomingAcknowledged) {
            throw new RuntimeException('All handover confirmations must be completed.');
        }
        if (mb_strlen($notes, 'UTF-8') > 5000) {
            throw new RuntimeException('Handover notes are too long.');
        }

        $permit = self::load($pdo, $permitId);
        $status = strtolower((string)($permit['status'] ?? ''));
        if (!in_array($status, [self::ACTIVE, self::SUSPENDED], true)) {
            throw new RuntimeException('Only an active or suspended permit can be handed over.');
        }

        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $now = $driver === 'sqlite' ? "datetime('now')" : 'NOW()';
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare("
                UPDATE forms
                SET holder_name = ?, holder_email = ?, holder_id = ?, updated_at = {$now}
                WHERE id = ? AND status IN ('active', 'suspended')
            ");
            $update->execute([$incomingName, $incomingEmail, $incomingUserId, $permitId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Permit state changed before handover could be recorded.');
            }
            self::insertEvent($pdo, $permitId, 'shift_handover', $byUser, [
                'outgoing_name' => $outgoingName,
                'outgoing_email' => (string)($permit['holder_email'] ?? ''),
                'incoming_name' => $incomingName,
                'incoming_email' => $incomingEmail,
                'notes' => $notes,
                'safe_state_confirmed' => true,
                'controls_reviewed' => true,
                'linked_permits_reviewed' => true,
                'incoming_acknowledged' => true,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return self::load($pdo, $permitId);
    }

    /** @return array<int,array<string,mixed>> */
    public static function timeline(PDO $pdo, string $permitId, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $pdo->prepare("SELECT id, type, at, by_user, payload FROM form_events WHERE form_id = ? ORDER BY at DESC LIMIT {$limit}");
        $stmt->execute([$permitId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $payload = json_decode((string)($row['payload'] ?? ''), true);
            $row['payload'] = is_array($payload) ? $payload : [];
        }
        unset($row);
        return $rows;
    }

    /** @param array<string,mixed> $payload */
    public static function recordEvent(PDO $pdo, string $permitId, string $type, ?string $byUser, array $payload = []): string
    {
        return self::insertEvent($pdo, $permitId, $type, $byUser, $payload);
    }

    /** @param array<string,mixed> $payload */
    private static function insertEvent(PDO $pdo, string $permitId, string $type, ?string $byUser, array $payload): string
    {
        $id = Uuid::uuid4()->toString();
        $stmt = $pdo->prepare('INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $id,
            $permitId,
            mb_substr(trim($type), 0, 64, 'UTF-8'),
            $byUser !== '' ? $byUser : null,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return $id;
    }
}
