<?php
/**
 * Approval Notification Recipients Admin UI
 */

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/approval-notifications.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$stmt = $db->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || $currentUser['role'] !== 'admin') {
    http_response_code(403);
    echo '<h1>Access denied</h1><p>Administrator role required.</p>';
    exit;
}

$successMessage = '';
$errorMessage = '';
$pendingPermits = [];
$pendingStatusMap = [];
$pendingStatusError = '';

try {
    $recipients = getApprovalNotificationRecipients($db);
} catch (Throwable $e) {
    $recipients = [];
    $errorMessage = 'Unable to load recipients: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $recipients = addApprovalNotificationRecipient($db, $name, $email);
            $successMessage = 'Recipient added successfully.';
        } elseif ($action === 'update') {
            $id = (string)($_POST['id'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $recipients = updateApprovalNotificationRecipient($db, $id, $name, $email);
            $successMessage = 'Recipient updated successfully.';
        } elseif ($action === 'delete') {
            $id = (string)($_POST['id'] ?? '');
            $recipients = deleteApprovalNotificationRecipient($db, $id);
            $successMessage = 'Recipient removed.';
        }

        if ($successMessage && function_exists('logActivity')) {
            logActivity('settings_updated', 'approval', 'setting', 'approval_notification_recipients', $successMessage);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

try {
    $pendingStmt = $db->pdo->prepare(
        "SELECT f.id, f.ref_number, f.ref, f.template_id, f.notified_at, f.created_at, ft.name AS template_name
         FROM forms f
         LEFT JOIN form_templates ft ON ft.id = f.template_id
         WHERE f.status = 'pending_approval'
         ORDER BY COALESCE(f.notified_at, f.created_at) DESC
         LIMIT ?"
    );
    $pendingStmt->bindValue(1, 10, PDO::PARAM_INT);
    $pendingStmt->execute();
    $pendingPermits = $pendingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!empty($pendingPermits)) {
        $pendingIds = array_column($pendingPermits, 'id');
        $pendingStatusMap = getApprovalLinkStatusMap($db, $pendingIds, $recipients ?? []);
    }
} catch (Throwable $e) {
    $pendingStatusError = 'Unable to load pending permits: ' . $e->getMessage();
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Approval Notification Recipients</title>
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: system-ui, -apple-system, sans-serif; margin: 0; min-height: 100vh; }
        .wrap { max-width: 960px; margin: 0 auto; padding: 32px 16px 80px; }
        h1 { font-size: 28px; margin-bottom: 10px; }
        p.lead { color: #94a3b8; margin-bottom: 24px; }
        a.back { color: #60a5fa; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 24px; }
        a.back:hover { text-decoration: underline; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35); }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 12px; text-align: left; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; border-bottom: 1px solid #334155; }
        tr + tr td { border-top: 1px solid #1f2937; }
        input[type="text"], input[type="email"] { width: 100%; padding: 10px; border-radius: 10px; border: 1px solid #1f2937; background: #111827; color: #e2e8f0; }
        .actions { display: flex; gap: 8px; }
        .btn { border: none; border-radius: 10px; padding: 10px 18px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-secondary { background: #475569; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
        .alert-success { background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .alert-error { background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        .empty { padding: 24px; text-align: center; color: #94a3b8; }
        form.inline { display: contents; }
        .status-card { margin-top: 32px; }
        .permit-status { background: #111827; border-radius: 14px; padding: 20px; border: 1px solid #1f2937; margin-top: 18px; }
        .permit-status:first-of-type { margin-top: 0; }
        .permit-header { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center; }
        .permit-title { font-weight: 600; font-size: 18px; color: #e2e8f0; }
        .permit-subtitle { color: #94a3b8; font-size: 13px; }
        .status-list { list-style: none; margin: 16px 0 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
        .status-item { background: #0f172a; border: 1px solid #1f2937; border-radius: 12px; padding: 12px 16px; display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
        .status-info-block { display: grid; gap: 4px; }
        .status-label { font-weight: 600; color: #e2e8f0; }
        .status-detail { color: #94a3b8; font-size: 13px; }
        .status-pill { border-radius: 999px; padding: 6px 12px; font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; }
        .status-pill.status-success { background: rgba(34, 197, 94, 0.16); color: #bbf7d0; border: 1px solid rgba(34, 197, 94, 0.35); }
        .status-pill.status-info { background: rgba(59, 130, 246, 0.16); color: #cbd5f5; border: 1px solid rgba(59, 130, 246, 0.35); }
        .status-pill.status-warning { background: rgba(251, 191, 36, 0.16); color: #fcd34d; border: 1px solid rgba(251, 191, 36, 0.35); }
        .status-pill.status-danger { background: rgba(239, 68, 68, 0.16); color: #fecaca; border: 1px solid rgba(239, 68, 68, 0.35); }
        .status-pill.status-muted { background: rgba(148, 163, 184, 0.18); color: #cbd5f5; border: 1px solid rgba(148, 163, 184, 0.25); }
        .pending-meta { color: #94a3b8; font-size: 13px; }
        .pending-meta strong { color: #e2e8f0; }
        .muted-link { color: #60a5fa; text-decoration: none; font-size: 13px; }
        .muted-link:hover { text-decoration: underline; }
        .status-empty { margin-top: 16px; padding: 20px; border-radius: 12px; background: rgba(148, 163, 184, 0.08); color: #94a3b8; border: 1px dashed #334155; text-align: center; }
        .pending-error { margin-top: 16px; padding: 16px; border-radius: 12px; background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        @media (max-width: 720px) {
            .actions { flex-direction: column; }
            .btn { width: 100%; }
            .status-item { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">⬅ Back to Admin</a>
        <h1>Approval Notification Recipients</h1>
        <p class="lead">Control which email addresses are alerted when a permit is submitted and awaiting approval.</p>

        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-error"><?= htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-top:0;">Existing recipients</h2>
            <?php if (empty($recipients)): ?>
                <div class="empty">No approval recipients configured yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th style="width: 200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recipients as $recipient): ?>
                            <?php $formId = 'update-' . htmlspecialchars($recipient['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            <form id="<?= $formId; ?>" method="post" style="display: contents;">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($recipient['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            </form>
                            <tr>
                                <td>
                                    <input form="<?= $formId; ?>" type="text" name="name" value="<?= htmlspecialchars($recipient['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="Name (optional)">
                                </td>
                                <td>
                                    <input form="<?= $formId; ?>" type="email" name="email" value="<?= htmlspecialchars($recipient['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button form="<?= $formId; ?>" type="submit" class="btn btn-secondary">Save</button>
                                        <form method="post" onsubmit="return confirm('Remove this recipient?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= htmlspecialchars($recipient['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                            <button type="submit" class="btn btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr style="border: none; border-top: 1px solid #334155; margin: 32px 0;">

            <h2>Add recipient</h2>
            <form method="post" style="margin-top: 16px; display: grid; gap: 16px;">
                <input type="hidden" name="action" value="add">
                <div style="display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
                    <div>
                        <label style="display:block; font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:6px;">Name (optional)</label>
                        <input type="text" name="name" placeholder="e.g. Safety Manager">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:6px;">Email address</label>
                        <input type="email" name="email" placeholder="approvals@example.com" required>
                    </div>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Add recipient</button>
                </div>
            </form>
        </div>

        <div class="card status-card">
            <h2 style="margin-top:0;">Pending permits &amp; email delivery</h2>
            <p class="pending-meta">Monitor which configured recipients have active approval emails for permits awaiting a decision.</p>

            <?php if ($pendingStatusError): ?>
                <div class="pending-error"><?= htmlspecialchars($pendingStatusError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            <?php elseif (empty($pendingPermits)): ?>
                <div class="status-empty">No permits are currently waiting for approval.</div>
            <?php else: ?>
                <?php foreach ($pendingPermits as $permit): ?>
                    <?php
                        $ref = $permit['ref_number'] ?? $permit['ref'] ?? substr($permit['id'], 0, 8);
                        $submitted = formatApprovalStatusDate($permit['created_at'] ?? null) ?? 'Unknown';
                        $queued = formatApprovalStatusDate($permit['notified_at'] ?? null);
                        $statusBundle = $pendingStatusMap[$permit['id']] ?? ['recipients' => [], 'extra' => []];
                        $recipientStatuses = $statusBundle['recipients'];
                        $extraStatuses = $statusBundle['extra'];
                        $allStatuses = array_merge($recipientStatuses, $extraStatuses);
                    ?>
                    <div class="permit-status">
                        <div class="permit-header">
                            <div>
                                <div class="permit-title"><?= htmlspecialchars($permit['template_name'] ?? 'Permit', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                <div class="permit-subtitle">#<?= htmlspecialchars($ref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> · Submitted <?= htmlspecialchars($submitted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                            </div>
                            <?php if ($queued): ?>
                                <div class="pending-meta"><strong>Emails queued:</strong> <?= htmlspecialchars($queued, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                            <?php else: ?>
                                <div class="pending-meta"><strong>Emails queued:</strong> Not yet queued</div>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($allStatuses)): ?>
                            <div class="status-empty" style="margin-top:16px;">No approval emails have been queued for the current configuration.</div>
                        <?php else: ?>
                            <ul class="status-list">
                                <?php foreach ($allStatuses as $entry): ?>
                                    <?php
                                        $displayName = $entry['name'] !== '' ? $entry['name'] : $entry['email'];
                                        $emailLine = $entry['name'] !== '' ? $entry['email'] : '';
                                        $detail = $entry['detail'] ?? '';
                                    ?>
                                    <li class="status-item">
                                        <div class="status-info-block">
                                            <div class="status-label"><?= htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                            <?php if ($emailLine !== ''): ?>
                                                <div class="status-detail"><?= htmlspecialchars($emailLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                            <?php endif; ?>
                                            <?php if ($detail !== ''): ?>
                                                <div class="status-detail"><?= htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="status-pill <?= htmlspecialchars($entry['status_class'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"><?= htmlspecialchars($entry['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
