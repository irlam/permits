<?php
/**
 * Email Template: Permit Expiring Soon
 * 
 * Variables available:
 * - $form: Complete form data array
 * - $permitNo: Permit reference number
 * - $daysUntilExpiry: Number of days until expiry
 * - $expiryDate: Expiry date
 */

$baseUrl = $_ENV['APP_URL'] ?? 'http://localhost:8080';
$formId = $form['id'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
            line-height: 1.6; 
            color: #111827; 
            margin: 0;
            padding: 0;
            background: #f3f4f6;
        }
        .container { 
            max-width: 600px; 
            margin: 20px auto; 
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header { 
            background: #f59e0b; 
            color: white; 
            padding: 32px 24px; 
            text-align: center; 
        }
        .header h1 { 
            margin: 0; 
            font-size: 24px; 
            font-weight: 600; 
        }
        .status-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            margin-top: 8px;
        }
        .content { 
            padding: 32px 24px; 
        }
        .content h2 {
            margin: 0 0 16px 0;
            color: #111827;
            font-size: 20px;
        }
        .info-row {
            margin: 12px 0;
            padding: 12px;
            background: #f9fafb;
            border-radius: 6px;
        }
        .info-row label {
            display: block;
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .info-row value {
            display: block;
            font-size: 16px;
            color: #111827;
            font-weight: 500;
        }
        .warning-box {
            margin: 20px 0;
            padding: 16px;
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            border-radius: 6px;
        }
        .expiry-counter {
            text-align: center;
            margin: 24px 0;
            padding: 20px;
            background: #fef3c7;
            border-radius: 8px;
        }
        .expiry-counter .number {
            font-size: 48px;
            font-weight: 700;
            color: #f59e0b;
            line-height: 1;
        }
        .expiry-counter .label {
            font-size: 14px;
            color: #92400e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 8px;
        }
        .btn { 
            display: inline-block; 
            padding: 14px 28px; 
            background: #3b82f6; 
            color: white; 
            text-decoration: none; 
            border-radius: 8px;
            font-weight: 500;
            margin-top: 24px;
        }
        .footer { 
            text-align: center; 
            padding: 24px; 
            background: #f9fafb;
            color: #6b7280; 
            font-size: 14px;
            border-top: 1px solid #e5e7eb;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠ Permit Expiring Soon</h1>
            <div class="status-badge">Expiry Reminder</div>
        </div>
        <div class="content">
            <h2>Your permit will expire soon</h2>
            <p>This is a reminder that your permit is approaching its expiry date.</p>
            
            <div class="expiry-counter">
                <div class="number"><?= htmlspecialchars($daysUntilExpiry) ?></div>
                <div class="label">Days Until Expiry</div>
            </div>
            
            <div class="info-row">
                <label>Permit Number</label>
                <value><?= htmlspecialchars($permitNo) ?></value>
            </div>
            
            <div class="info-row">
                <label>Expiry Date</label>
                <value><?= htmlspecialchars($expiryDate) ?></value>
            </div>
            
            <div class="warning-box">
                <strong>⚠ Action Required:</strong>
                <p style="margin: 8px 0 0 0;">
                    <?php if ($daysUntilExpiry <= 7): ?>
                        Your permit will expire in <?= $daysUntilExpiry ?> day<?= $daysUntilExpiry != 1 ? 's' : '' ?>. 
                        Please ensure all work is completed before the expiry date or apply for an extension if needed.
                    <?php else: ?>
                        Your permit will expire in <?= $daysUntilExpiry ?> days. 
                        Please plan accordingly to complete all work before the expiry date.
                    <?php endif; ?>
                </p>
            </div>
            
            <p style="margin-top: 24px;">
                <strong>Important Reminders:</strong>
            </p>
            <ul>
                <li>All work must be completed before the expiry date</li>
                <li>The permit cannot be used after it expires</li>
                <li>You may need to apply for a new permit or extension</li>
                <li>Ensure all safety protocols continue to be followed</li>
            </ul>
            
            <div style="text-align: center;">
                <a href="<?= htmlspecialchars($baseUrl . '/form/' . $formId) ?>" class="btn">View Permit Details</a>
            </div>
        </div>
        <div class="footer">
            <p>This is an automated reminder from the Permits System.</p>
            <p style="margin-top: 8px; font-size: 12px;">Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
