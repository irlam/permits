<?php
$recipientName = isset($recipient['name']) && $recipient['name'] !== ''
    ? htmlspecialchars($recipient['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    : 'Approvals Team';

$permitRef = htmlspecialchars(
    $form['ref_number'] ?? $form['ref'] ?? $form['id'] ?? 'Permit',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$templateName = htmlspecialchars($form['template_name'] ?? 'Permit To Work', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$holderName = htmlspecialchars($form['holder_name'] ?? 'Unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$holderEmail = htmlspecialchars($form['holder_email'] ?? 'N/A', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$createdAt = !empty($form['created_at']) ? date('d/m/Y H:i', strtotime($form['created_at'])) : 'Unknown';

$decisionUrlEsc = isset($decisionUrl) && $decisionUrl ? htmlspecialchars($decisionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
$quickApproveUrlEsc = isset($quickApproveUrl) && $quickApproveUrl ? htmlspecialchars($quickApproveUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $decisionUrlEsc;
$quickRejectUrlEsc = isset($quickRejectUrl) && $quickRejectUrl ? htmlspecialchars($quickRejectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ($decisionUrlEsc !== '' ? $decisionUrlEsc : '');
$viewUrlEsc = isset($viewUrl) && $viewUrl ? htmlspecialchars($viewUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
$managerUrlEsc = isset($managerUrl) && $managerUrl ? htmlspecialchars($managerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
$expiresEsc = isset($expiresAt) && $expiresAt ? htmlspecialchars($expiresAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Permit Awaiting Approval</title>
    <style>
        body { font-family: Arial, sans-serif; color: #0f172a; margin: 0; padding: 0; background: #f1f5f9; }
        .wrapper { max-width: 640px; margin: 0 auto; padding: 24px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.12); }
        .header { text-align: center; margin-bottom: 24px; }
        .header h1 { font-size: 24px; margin: 0; color: #1d4ed8; }
        .meta { margin: 24px 0; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; padding: 16px 0; }
        .meta dl { display: grid; grid-template-columns: 140px 1fr; gap: 8px 16px; margin: 0; }
        .meta dt { font-weight: 600; color: #475569; }
        .meta dd { margin: 0; color: #0f172a; }
        .cta { margin-top: 32px; display: flex; flex-direction: column; gap: 12px; align-items: center; text-align: center; }
        .btn { display: inline-block; padding: 14px 28px; border-radius: 999px; font-weight: 600; text-decoration: none; }
        .btn-primary { background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%); color: #fff; }
        .btn-outline { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .cta-links { margin-top: 18px; font-size: 13px; color: #64748b; text-align: center; line-height: 1.6; }
        .cta-links a { color: #1d4ed8; text-decoration: none; }
        .cta-links a:hover { text-decoration: underline; }
        @media (max-width: 600px) {
            .card { padding: 24px 20px; }
            .meta dl { grid-template-columns: 1fr; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <div class="header">
                <p style="font-size:14px; color:#64748b; margin-bottom: 6px;">Hello <?= $recipientName; ?>,</p>
                <h1>Permit awaiting your approval</h1>
            </div>

            <p>The following permit has been submitted and is waiting for approval:</p>

            <div class="meta">
                <dl>
                    <dt>Permit Reference</dt>
                    <dd>#<?= $permitRef; ?></dd>

                    <dt>Template</dt>
                    <dd><?= $templateName; ?></dd>

                    <dt>Submitted By</dt>
                    <dd><?= $holderName; ?></dd>

                    <dt>Contact Email</dt>
                    <dd><?= $holderEmail; ?></dd>

                    <dt>Submitted</dt>
                    <dd><?= htmlspecialchars($createdAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></dd>
                </dl>
            </div>

            <p>Review the details and approve or reject the permit using the quick links below.</p>

            <div class="cta">
                <?php if ($quickApproveUrlEsc !== ''): ?>
                    <a class="btn btn-primary" href="<?= $quickApproveUrlEsc; ?>" target="_blank" rel="noopener">Approve Permit</a>
                <?php elseif ($decisionUrlEsc !== ''): ?>
                    <a class="btn btn-primary" href="<?= $decisionUrlEsc; ?>" target="_blank" rel="noopener">Open Approval Page</a>
                <?php endif; ?>

                <?php if ($decisionUrlEsc !== '' && $quickApproveUrlEsc !== $decisionUrlEsc): ?>
                    <a class="btn btn-outline" href="<?= $decisionUrlEsc; ?>" target="_blank" rel="noopener">Open Approval Page</a>
                <?php endif; ?>
            </div>

            <div class="cta-links">
                <?php if ($viewUrlEsc !== ''): ?>
                    Review the full permit: <a href="<?= $viewUrlEsc; ?>" target="_blank" rel="noopener">View permit</a><br>
                <?php endif; ?>
                <?php if ($quickRejectUrlEsc !== ''): ?>
                    Need changes? <a href="<?= $quickRejectUrlEsc; ?>" target="_blank" rel="noopener">Reject permit</a><br>
                <?php endif; ?>
                <?php if ($managerUrlEsc !== ''): ?>
                    Prefer the dashboard? <a href="<?= $managerUrlEsc; ?>" target="_blank" rel="noopener">Open manager approvals</a><br>
                <?php endif; ?>
                <?php if ($expiresEsc !== ''): ?>
                    <em>This approval link expires <?= $expiresEsc; ?>.</em><br>
                <?php endif; ?>
                You are receiving this email because you are listed as an approver in the permits system.
            </div>
        </div>
    </div>
</body>
</html>
