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
$approvalUrl = htmlspecialchars($approvalUrl ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$viewUrl = isset($viewUrl) && $viewUrl ? htmlspecialchars($viewUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $approvalUrl;
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
        .cta { text-align: center; margin-top: 32px; }
        .btn { display: inline-block; padding: 14px 28px; background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%); color: #fff; text-decoration: none; border-radius: 999px; font-weight: 600; }
        .note { font-size: 13px; color: #64748b; margin-top: 16px; text-align: center; }
        @media (max-width: 600px) {
            .card { padding: 24px 20px; }
            .meta dl { grid-template-columns: 1fr; }
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

            <p>Review the details and approve or reject the permit using the link below.</p>

            <div class="cta">
                <a class="btn" href="<?= $approvalUrl; ?>" target="_blank" rel="noopener">Open approval dashboard</a>
                <?php if ($viewUrl && $viewUrl !== $approvalUrl): ?>
                    <div class="note">View permit directly: <a href="<?= $viewUrl; ?>" target="_blank" rel="noopener"><?= $viewUrl; ?></a></div>
                <?php endif; ?>
            </div>

            <p class="note">You are receiving this email because you are listed as an approver in the permits system.</p>
        </div>
    </div>
</body>
</html>
