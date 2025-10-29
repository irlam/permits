<?php
/**
 * Approval Notification Helpers
 *
 * Utilities for storing the approval notification recipient list and
 * queueing emails when permits move into the pending approval state.
 */

use Permits\Db;
use Permits\Email;
use Ramsey\Uuid\Uuid;

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Roles.php';

const APPROVAL_RECIPIENTS_SETTING_KEY = 'approval_notification_recipients';
const APPROVAL_LINK_EXPIRY_DAYS = 7;

/**
 * Ensure the settings table exists (MySQL/SQLite compatible).
 */
function ensure_settings_table_exists(Db $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql';

    if ($driver === 'sqlite') {
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)");
    } else {
        $db->pdo->exec("CREATE TABLE IF NOT EXISTS settings (`key` VARCHAR(191) PRIMARY KEY, value TEXT, updated_at DATETIME NULL)");
    }

    $checked = true;
}

/**
 * Ensure the permit_approval_links table exists for email-based approvals.
 */
function ensure_approval_links_table_exists(Db $db): void
{
    static $linksChecked = false;
    if ($linksChecked) {
        return;
    }

    $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql';

    if ($driver === 'sqlite') {
        $db->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS permit_approval_links (
    id TEXT PRIMARY KEY,
    permit_id TEXT NOT NULL,
    recipient_email TEXT NOT NULL,
    recipient_name TEXT NULL,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT NULL,
    used_action TEXT NULL,
    used_comment TEXT NULL,
    used_ip TEXT NULL,
    user_agent TEXT NULL,
    metadata TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);
        $db->pdo->exec("CREATE INDEX IF NOT EXISTS idx_approval_links_token ON permit_approval_links(token_hash);");
        $db->pdo->exec("CREATE INDEX IF NOT EXISTS idx_approval_links_permit ON permit_approval_links(permit_id);");
        $db->pdo->exec("CREATE INDEX IF NOT EXISTS idx_approval_links_expires ON permit_approval_links(expires_at);");
    } else {
        $db->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS permit_approval_links (
    id VARCHAR(36) PRIMARY KEY,
    permit_id VARCHAR(36) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255) NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    used_action VARCHAR(20) NULL,
    used_comment TEXT NULL,
    used_ip VARCHAR(45) NULL,
    user_agent TEXT NULL,
    metadata TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_approval_links_token (token_hash),
    INDEX idx_approval_links_permit (permit_id),
    INDEX idx_approval_links_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    $linksChecked = true;
}

/**
 * Simple SHA-256 hash wrapper for approval tokens.
 */
function hashApprovalToken(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Expire or mark as used all outstanding approval links for a permit.
 */
function cancelApprovalLinksForPermit(
    Db $db,
    string $permitId,
    string $reason = 'cancelled',
    ?string $excludeId = null,
    ?string $recipientEmail = null
): void {
    ensure_approval_links_table_exists($db);

    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $sql = "UPDATE permit_approval_links
            SET used_at = CASE WHEN used_at IS NULL THEN :now ELSE used_at END,
                used_action = CASE WHEN used_action IS NULL THEN :reason ELSE used_action END,
                expires_at = CASE WHEN expires_at > :now THEN :now ELSE expires_at END
            WHERE permit_id = :permit AND used_at IS NULL";

    $params = [
        ':now' => $now,
        ':reason' => $reason,
        ':permit' => $permitId,
    ];

    if ($excludeId !== null) {
        $sql .= ' AND id <> :exclude';
        $params[':exclude'] = $excludeId;
    }

    if ($recipientEmail !== null) {
        $sql .= ' AND recipient_email = :recipient';
        $params[':recipient'] = $recipientEmail;
    }

    $stmt = $db->pdo->prepare($sql);
    $stmt->execute($params);
}

/**
 * Create (or refresh) a secure approval token for a recipient.
 *
 * @param array<string,mixed> $form
 * @param array<string,mixed> $recipient
 * @return array{id:string,token:string,expires_at:DateTimeImmutable}
 */
function createApprovalLink(Db $db, array $form, array $recipient, ?DateInterval $ttl = null): array
{
    ensure_settings_table_exists($db);
    ensure_approval_links_table_exists($db);

    $ttl = $ttl ?? new DateInterval('P' . max(1, (int)APPROVAL_LINK_EXPIRY_DAYS) . 'D');
    $now = new DateTimeImmutable('now');
    $expiresAt = $now->add($ttl);

    $permitId = (string)($form['id'] ?? '');
    if ($permitId === '') {
        throw new InvalidArgumentException('Permit lacks an ID.');
    }

    $email = trim((string)($recipient['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Recipient email invalid when creating approval link.');
    }

    cancelApprovalLinksForPermit($db, $permitId, 'superseded', null, $email);

    $token = bin2hex(random_bytes(32));
    $hash = hashApprovalToken($token);
    $id = Uuid::uuid4()->toString();

    $metadata = [
        'recipient_id' => $recipient['id'] ?? null,
        'recipient_name' => $recipient['name'] ?? null,
        'permit_ref' => $form['ref_number'] ?? $form['ref'] ?? $form['id'] ?? null,
        'template_name' => $form['template_name'] ?? null,
    ];

    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $db->pdo->prepare(
        'INSERT INTO permit_approval_links (id, permit_id, recipient_email, recipient_name, token_hash, expires_at, created_at, metadata)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $id,
        $permitId,
        $email,
        trim((string)($recipient['name'] ?? '')),
        $hash,
        $expiresAt->format('Y-m-d H:i:s'),
        $now->format('Y-m-d H:i:s'),
        $metadataJson,
    ]);

    return [
        'id' => $id,
        'token' => $token,
        'expires_at' => $expiresAt,
    ];
}

/**
 * Retrieve a stored approval link row (without joining permit data).
 *
 * @return array<string,mixed>|null
 */
function fetchApprovalLinkByToken(Db $db, string $token): ?array
{
    ensure_approval_links_table_exists($db);

    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $hash = hashApprovalToken($token);
    $stmt = $db->pdo->prepare('SELECT * FROM permit_approval_links WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $row['metadata'] = isset($row['metadata']) && $row['metadata'] !== null
        ? (json_decode((string)$row['metadata'], true) ?: [])
        : [];
    $row['token'] = $token;

    return $row;
}

/**
 * Fetch the permit associated with a token.
 *
 * @return array<string,mixed>|null
 */
function fetchPermitForApproval(Db $db, string $permitId): ?array
{
    $stmt = $db->pdo->prepare(
        'SELECT f.*, ft.name AS template_name
         FROM forms f
         LEFT JOIN form_templates ft ON ft.id = f.template_id
         WHERE f.id = ?
         LIMIT 1'
    );
    $stmt->execute([$permitId]);
    $permit = $stmt->fetch(PDO::FETCH_ASSOC);

    return $permit ?: null;
}

/**
 * Mark an approval token as used and persist auditing metadata.
 */
function markApprovalLinkUsed(Db $db, array $link, string $action, array $options = []): bool
{
    ensure_approval_links_table_exists($db);

    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $comment = isset($options['comment']) ? trim((string)$options['comment']) : '';
    $comment = $comment !== '' ? $comment : null;
    $ip = $options['ip'] ?? null;
    $agent = $options['user_agent'] ?? null;

    $metadata = [];
    if (isset($link['metadata']) && is_array($link['metadata'])) {
        $metadata = $link['metadata'];
    }
    if (isset($options['metadata']) && is_array($options['metadata'])) {
        $metadata = array_merge($metadata, $options['metadata']);
    }
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $db->pdo->prepare(
        'UPDATE permit_approval_links
         SET used_at = :now,
             used_action = :action,
             used_comment = :comment,
             used_ip = :ip,
             user_agent = :agent,
             metadata = :meta
         WHERE id = :id AND used_at IS NULL'
    );

    $stmt->execute([
        ':now' => $now,
        ':action' => $action,
        ':comment' => $comment,
        ':ip' => $ip,
        ':agent' => $agent,
        ':meta' => $metadataJson,
        ':id' => $link['id'],
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * Apply an approval decision made through a secure email link.
 *
 * @return array{status:string,title:string,permit_id:string,permit_ref:?string,comment:?string}
 */
function processApprovalLinkDecision(Db $db, array $link, string $action, array $options = []): array
{
    $action = strtolower(trim($action));
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException('Invalid approval action supplied.');
    }

    ensure_approval_links_table_exists($db);

    $pdo = $db->pdo;
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql';

    $tokenId = (string)($link['id'] ?? '');
    if ($tokenId === '') {
        throw new InvalidArgumentException('Approval link is missing an identifier.');
    }

    $comment = isset($options['comment']) ? trim((string)$options['comment']) : '';
    $ip = isset($options['ip']) ? trim((string)$options['ip']) : null;
    $agent = isset($options['user_agent']) ? trim((string)$options['user_agent']) : null;

    $pdo->beginTransaction();

    try {
        $linkSql = $driver === 'mysql'
            ? 'SELECT * FROM permit_approval_links WHERE id = ? FOR UPDATE'
            : 'SELECT * FROM permit_approval_links WHERE id = ?';
        $linkStmt = $pdo->prepare($linkSql);
        $linkStmt->execute([$tokenId]);
        $freshLink = $linkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$freshLink) {
            throw new RuntimeException('Approval link not found.');
        }

        if (!empty($freshLink['used_at'])) {
            throw new RuntimeException('This approval link has already been used.');
        }

        if (!empty($freshLink['expires_at']) && strtotime((string)$freshLink['expires_at']) < time()) {
            throw new RuntimeException('This approval link has expired.');
        }

        $permitSql = $driver === 'mysql'
            ? 'SELECT * FROM forms WHERE id = ? FOR UPDATE'
            : 'SELECT * FROM forms WHERE id = ?';
        $permitStmt = $pdo->prepare($permitSql);
        $permitStmt->execute([$freshLink['permit_id']]);
        $permit = $permitStmt->fetch(PDO::FETCH_ASSOC);

        if (!$permit) {
            throw new RuntimeException('Permit not found.');
        }

        if (strtolower((string)$permit['status']) !== 'pending_approval') {
            throw new RuntimeException('This permit is no longer awaiting approval.');
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $decision = $action === 'approve' ? 'approved' : 'rejected';
        $newStatus = $action === 'approve' ? 'active' : 'rejected';
        $title = $action === 'approve' ? 'Permit approved successfully' : 'Permit rejected successfully';

        $decisionNote = sprintf(
            '[%s] %s via emailed link by %s%s',
            $now,
            ucfirst($decision),
            $freshLink['recipient_email'],
            $comment !== '' ? ' – ' . $comment : ''
        );

        $existingNotes = trim((string)($permit['approval_notes'] ?? ''));
        $combinedNotes = $existingNotes === '' ? $decisionNote : $existingNotes . PHP_EOL . $decisionNote;

        $update = $pdo->prepare('UPDATE forms SET status = ?, approval_status = ?, approved_at = ?, approved_by = NULL, approval_notes = ? WHERE id = ?');
        $update->execute([$newStatus, $decision, $now, $combinedNotes, $permit['id']]);

        cancelApprovalLinksForPermit($db, $permit['id'], 'decision_taken', $freshLink['id']);

        $linkMetadata = isset($link['metadata']) && is_array($link['metadata']) ? $link['metadata'] : [];
        $linkMetadata['decision'] = $decision;
        $linkMetadata['decided_at'] = $now;

        if (!markApprovalLinkUsed($db, ['id' => $freshLink['id'], 'metadata' => $linkMetadata], $decision, [
            'comment' => $comment,
            'ip' => $ip,
            'user_agent' => $agent,
            'metadata' => $linkMetadata,
        ])) {
            throw new RuntimeException('Failed to mark approval link as used.');
        }

        $evt = $pdo->prepare('INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?,?,?,?,?)');
        $evt->execute([
            Uuid::uuid4()->toString(),
            $permit['id'],
            'approval_email_action',
            $freshLink['recipient_email'],
            json_encode([
                'decision' => $decision,
                'comment' => $comment,
                'link_id' => $freshLink['id'],
                'ip' => $ip,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $pdo->commit();

        try {
            clearPendingApprovalNotificationFlag($db, $permit['id']);
        } catch (Throwable $e) {
            error_log('Failed to clear approval notification flag after email decision: ' . $e->getMessage());
        }

        if (function_exists('logActivity')) {
            $description = ucfirst($decision) . ' via email link by ' . $freshLink['recipient_email'];
            if ($comment !== '') {
                $description .= ' – ' . $comment;
            }
            logActivity(
                'permit_' . $decision . '_email',
                'approval',
                'form',
                $permit['id'],
                $description
            );
        }

        return [
            'status' => $decision,
            'title' => $title,
            'permit_id' => $permit['id'],
            'permit_ref' => $permit['ref_number'] ?? $permit['id'],
            'comment' => $comment !== '' ? $comment : null,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Retrieve the stored approval notification recipients.
 *
 * @return array<int, array{name:string,email:string,id:string,created_at:?string}>
 */
function getApprovalNotificationRecipients(Db $db): array
{
    ensure_settings_table_exists($db);

    $stmt = $db->pdo->prepare("SELECT value FROM settings WHERE `key` = ? LIMIT 1");
    $stmt->execute([APPROVAL_RECIPIENTS_SETTING_KEY]);
    $raw = $stmt->fetchColumn();

    if (!$raw) {
        return [];
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $dedupe = [];
    $normalized = [];

    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $email = trim((string) ($entry['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $emailKey = strtolower($email);
        if (isset($dedupe[$emailKey])) {
            continue;
        }

        $dedupe[$emailKey] = true;

        $normalized[] = [
            'id' => (string) ($entry['id'] ?? Uuid::uuid4()->toString()),
            'name' => trim((string) ($entry['name'] ?? '')),
            'email' => $email,
            'created_at' => isset($entry['created_at']) ? (string) $entry['created_at'] : null,
        ];
    }

    usort($normalized, static function (array $a, array $b): int {
        return strcmp(strtolower($a['name'] ?? $a['email']), strtolower($b['name'] ?? $b['email']));
    });

    return $normalized;
}

/**
 * Persist the approval notification recipients array into the settings table.
 */
function saveApprovalNotificationRecipients(Db $db, array $recipients): void
{
    ensure_settings_table_exists($db);

    $payload = json_encode(array_values($recipients), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql';

    if ($driver === 'sqlite') {
        $sql = "INSERT INTO settings (key, value, updated_at)
                VALUES (:key, :value, datetime('now'))
                ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')";
    } else {
        $sql = "INSERT INTO settings (`key`, value, updated_at)
                VALUES (:key, :value, NOW())
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()";
    }

    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([
        ':key' => APPROVAL_RECIPIENTS_SETTING_KEY,
        ':value' => $payload,
    ]);
}

/**
 * Add a new approval recipient and return the updated list.
 */
function addApprovalNotificationRecipient(Db $db, string $name, string $email): array
{
    $email = trim($email);
    $name = trim($name);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please provide a valid email address.');
    }

    $recipients = getApprovalNotificationRecipients($db);
    foreach ($recipients as $recipient) {
        if (strcasecmp($recipient['email'], $email) === 0) {
            throw new InvalidArgumentException('That email address is already on the notification list.');
        }
    }

    $recipients[] = [
        'id' => Uuid::uuid4()->toString(),
        'name' => $name,
        'email' => $email,
        'created_at' => gmdate('c'),
    ];

    saveApprovalNotificationRecipients($db, $recipients);

    return $recipients;
}

/**
 * Update an existing approval recipient and return the updated list.
 */
function updateApprovalNotificationRecipient(Db $db, string $id, string $name, string $email): array
{
    $email = trim($email);
    $name = trim($name);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please provide a valid email address.');
    }

    $recipients = getApprovalNotificationRecipients($db);
    $found = false;

    foreach ($recipients as &$recipient) {
        if ($recipient['id'] === $id) {
            $found = true;
            $recipient['name'] = $name;
            $recipient['email'] = $email;
        } elseif (strcasecmp($recipient['email'], $email) === 0) {
            throw new InvalidArgumentException('Another record already uses that email address.');
        }
    }
    unset($recipient);

    if (!$found) {
        throw new InvalidArgumentException('Recipient not found.');
    }

    saveApprovalNotificationRecipients($db, $recipients);

    return $recipients;
}

/**
 * Delete a recipient by ID and return the updated list.
 */
function deleteApprovalNotificationRecipient(Db $db, string $id): array
{
    $recipients = getApprovalNotificationRecipients($db);
    $filtered = array_filter($recipients, static fn (array $recipient): bool => $recipient['id'] !== $id);

    if (count($filtered) === count($recipients)) {
        throw new InvalidArgumentException('Recipient not found.');
    }

    $filtered = array_values($filtered);

    saveApprovalNotificationRecipients($db, $filtered);

    return $filtered;
}

/**
 * Clear the notified_at flag so the permit can trigger notifications again later.
 */
function clearPendingApprovalNotificationFlag(Db $db, string $permitId): void
{
    $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql';
    $sql = $driver === 'sqlite'
        ? "UPDATE forms SET notified_at = NULL WHERE id = ?"
        : "UPDATE forms SET notified_at = NULL WHERE id = ?";

    $stmt = $db->pdo->prepare($sql);
    $stmt->execute([$permitId]);
}

/**
 * Queue emails to all configured recipients when a permit enters pending approval.
 *
 * @return int Number of queued emails.
 */
function notifyPendingApprovalRecipients(Db $db, string $root, string $permitId, ?Email $mailer = null): int
{
    $stmt = $db->pdo->prepare("
        SELECT f.*, ft.name AS template_name
        FROM forms f
        LEFT JOIN form_templates ft ON ft.id = f.template_id
        WHERE f.id = ?
        LIMIT 1
    ");
    $stmt->execute([$permitId]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        return 0;
    }

    if (strtolower((string) ($form['status'] ?? '')) !== 'pending_approval') {
        return 0;
    }

    if (!empty($form['notified_at'])) {
        return 0;
    }

    $recipients = getApprovalNotificationRecipients($db);
    if (empty($recipients)) {
        return 0;
    }

    if ($mailer === null) {
        $mailer = new Email($db, $root);
    }

    $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
    if ($baseUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $scheme . '://' . $host;
    }

    ensure_approval_links_table_exists($db);

    // Invalidate any old links before issuing fresh ones for this permit.
    cancelApprovalLinksForPermit($db, $permitId, 'superseded');

    $viewUrl = null;
    if (!empty($form['unique_link'])) {
        $viewUrl = $baseUrl . '/view-permit-public.php?link=' . urlencode((string) $form['unique_link']);
    }

    $queued = 0;

    foreach ($recipients as $recipient) {
        try {
            $link = createApprovalLink($db, $form, $recipient);

            $decisionUrl = $baseUrl . '/permit-approval.php?token=' . urlencode($link['token']);
            $quickApproveUrl = $decisionUrl . '&intent=approve';
            $quickRejectUrl = $decisionUrl . '&intent=reject';

            $mailer->sendPendingApprovalNotification($form, $recipient['email'], [
                'recipient' => $recipient,
                'decisionUrl' => $decisionUrl,
                'quickApproveUrl' => $quickApproveUrl,
                'quickRejectUrl' => $quickRejectUrl,
                'viewUrl' => $viewUrl,
                'expiresAt' => $link['expires_at']->format('Y-m-d H:i'),
                'managerUrl' => $baseUrl . '/manager-approvals.php',
            ]);
            $queued++;
        } catch (Throwable $e) {
            error_log('Failed to queue approval notification: ' . $e->getMessage());
        }
    }

    if ($queued > 0) {
        $driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql';
        $sql = $driver === 'sqlite'
            ? "UPDATE forms SET notified_at = datetime('now') WHERE id = ?"
            : "UPDATE forms SET notified_at = NOW() WHERE id = ?";
        $upd = $db->pdo->prepare($sql);
        $upd->execute([$permitId]);

        // Log form event
        try {
            $event = $db->pdo->prepare("INSERT INTO form_events (id, form_id, type, by_user, payload) VALUES (?, ?, ?, ?, ?)");
            $event->execute([
                Uuid::uuid4()->toString(),
                $permitId,
                'notification_queued',
                'system',
                json_encode([
                    'kind' => 'pending_approval_alert',
                    'recipients' => array_column($recipients, 'email'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            error_log('Failed to log approval notification event: ' . $e->getMessage());
        }

        if (function_exists('logActivity')) {
            $list = implode(', ', array_column($recipients, 'email'));
            logActivity(
                'permit_pending_notified',
                'approval',
                'form',
                $permitId,
                'Notification queued for pending approval: ' . $list
            );
        }
    }

    return $queued;
}

/**
 * Permanently remove a permit and its related records. Restricted to administrators.
 */
function deletePermit(Db $db, string $permitId): bool
{
    $permitId = trim($permitId);
    if ($permitId === '') {
        throw new InvalidArgumentException('Permit ID is required.');
    }

    if (!isLoggedIn() || !isAdmin()) {
        throw new RuntimeException('Only administrators can delete permits.');
    }

    $pdo = $db->pdo;
    $pdo->beginTransaction();

    try {
        cancelApprovalLinksForPermit($db, $permitId, 'permit_deleted');

        $pdo->prepare('DELETE FROM attachments WHERE form_id = ?')->execute([$permitId]);
        $pdo->prepare('DELETE FROM form_events WHERE form_id = ?')->execute([$permitId]);

        $delete = $pdo->prepare('DELETE FROM forms WHERE id = ?');
        $delete->execute([$permitId]);

        if ($delete->rowCount() === 0) {
            $pdo->rollBack();
            return false;
        }

        if (function_exists('logActivity')) {
            $actor = getCurrentUser();
            $actorEmail = $actor['email'] ?? 'unknown';
            logActivity(
                'permit_deleted',
                'permits',
                'form',
                $permitId,
                sprintf('Permit %s deleted by %s', $permitId, $actorEmail)
            );
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
