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
        @media (max-width: 720px) {
            .actions { flex-direction: column; }
            .btn { width: 100%; }
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
    </div>
</body>
</html>
