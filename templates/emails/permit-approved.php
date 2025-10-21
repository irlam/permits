<?php
/**
 * Email Template: Permit Approved
 * 
 * Variables available:
 * - $form: Complete form data array
 * - $permitNo: Permit reference number
 * - $siteBlock: Site/block location
 * - $validFrom: Valid from date
 * - $validTo: Valid to date
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
            background: #10b981; 
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
            <h1>✓ Permit Approved</h1>
            <div class="status-badge">Status: Approved</div>
        </div>
        <div class="content">
            <h2>Your permit has been approved</h2>
            <p>Good news! Your permit application has been reviewed and approved. You can now proceed with your planned work.</p>
            
            <div class="info-row">
                <label>Permit Number</label>
                <value><?= htmlspecialchars($permitNo) ?></value>
            </div>
            
            <div class="info-row">
                <label>Location</label>
                <value><?= htmlspecialchars($siteBlock) ?></value>
            </div>
            
            <div class="info-row">
                <label>Valid From</label>
                <value><?= htmlspecialchars($validFrom) ?></value>
            </div>
            
            <div class="info-row">
                <label>Valid Until</label>
                <value><?= htmlspecialchars($validTo) ?></value>
            </div>
            
            <p style="margin-top: 24px;">
                <strong>Important:</strong> Please ensure all work is completed within the validity period. The permit will expire on <?= htmlspecialchars($validTo) ?>.
            </p>
            
            <div style="text-align: center;">
                <a href="<?= htmlspecialchars($baseUrl . '/form/' . $formId) ?>" class="btn">View Permit Details</a>
            </div>
        </div>
        <div class="footer">
            <p>This is an automated notification from the Permits System.</p>
            <p style="margin-top: 8px; font-size: 12px;">Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
