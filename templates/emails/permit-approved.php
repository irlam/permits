<?php
/**
 * Email Template: Permit Approved — Holder Acceptance Required
 */

$baseUrl = rtrim((string)($_ENV['APP_URL'] ?? 'http://localhost:8080'), '/');
$uniqueLink = trim((string)($form['unique_link'] ?? ''));
$permitUrl = $uniqueLink !== ''
    ? $baseUrl . '/permit-workflow.php?link=' . rawurlencode($uniqueLink) . '#accept'
    : $baseUrl;
$durationLabel = trim((string)($form['duration_label'] ?? $form['expiry_duration'] ?? ''));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;line-height:1.6;color:#111827;margin:0;background:#f3f4f6}.container{max-width:600px;margin:20px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,.1)}.header{background:#b45309;color:#fff;padding:30px 24px;text-align:center}.header h1{margin:0;font-size:24px}.status-badge{display:inline-block;background:rgba(255,255,255,.18);padding:6px 12px;border-radius:20px;font-size:14px;margin-top:8px}.content{padding:30px 24px}.notice{background:#fffbeb;border:1px solid #f59e0b;border-left:5px solid #d97706;padding:15px;border-radius:7px;margin:18px 0;color:#78350f}.info-row{margin:12px 0;padding:12px;background:#f9fafb;border-radius:6px}.info-row label{display:block;font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}.info-row value{display:block;font-size:16px;color:#111827;font-weight:600}.btn{display:inline-block;padding:14px 28px;background:#2563eb;color:#fff!important;text-decoration:none;border-radius:8px;font-weight:650;margin-top:20px}.footer{text-align:center;padding:22px;background:#f9fafb;color:#6b7280;font-size:13px;border-top:1px solid #e5e7eb}
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Permit Approved — Acceptance Required</h1>
        <div class="status-badge">Status: Awaiting holder acceptance</div>
    </div>
    <div class="content">
        <h2 style="margin-top:0;">Management authorisation is complete</h2>
        <p>Your permit has been reviewed and approved, but there is one final control before the permit becomes active.</p>

        <div class="notice">
            <strong>Do not start work yet.</strong><br>
            The current permit holder/receiver must review and accept the permit conditions. The authorised validity period starts when that acceptance is recorded.
        </div>

        <div class="info-row">
            <label>Permit Number</label>
            <value><?= htmlspecialchars((string)$permitNo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></value>
        </div>
        <?php if (trim((string)$siteBlock) !== '' && (string)$siteBlock !== 'Unknown'): ?>
        <div class="info-row">
            <label>Location</label>
            <value><?= htmlspecialchars((string)$siteBlock, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></value>
        </div>
        <?php endif; ?>
        <?php if ($durationLabel !== ''): ?>
        <div class="info-row">
            <label>Approved validity duration</label>
            <value><?= htmlspecialchars($durationLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></value>
        </div>
        <?php endif; ?>

        <p>Open the permit, review its scope, hazards, controls and linked work, then record the holder/receiver acceptance.</p>
        <div style="text-align:center;">
            <a href="<?= htmlspecialchars($permitUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="btn">Review and Accept Permit</a>
        </div>
    </div>
    <div class="footer">
        <p>This is an automated notification from the Permit System.</p>
    </div>
</div>
</body>
</html>
