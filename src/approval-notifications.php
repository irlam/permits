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

const APPROVAL_RECIPIENTS_SETTING_KEY = 'approval_notification_recipients';

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

    $approvalUrl = $baseUrl . '/manager-approvals.php';
    $viewUrl = null;
    if (!empty($form['unique_link'])) {
        $viewUrl = $baseUrl . '/view-permit-public.php?link=' . urlencode((string) $form['unique_link']);
    }

    $queued = 0;

    foreach ($recipients as $recipient) {
        try {
            $mailer->sendPendingApprovalNotification($form, $recipient['email'], [
                'recipient' => $recipient,
                'approvalUrl' => $approvalUrl,
                'viewUrl' => $viewUrl,
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
