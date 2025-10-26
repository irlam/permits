<?php
/**
 * Public Permit View
 * 
 * File Path: /view-permit-public.php
 * Description: View permit details via unique link (no login required)
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - Access via unique link
 * - No authentication required
 * - View permit details
 * - Print functionality
 * - QR code display (if active)
 */

// Load bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

// Get unique link from query string
$unique_link = $_GET['link'] ?? null;
$print_mode = isset($_GET['print']);

if (!$unique_link) {
    header('Location: /');
    exit;
}

// Get permit by unique link
try {
    $stmt = $db->pdo->prepare("
        SELECT 
            f.*,
            ft.name as template_name,
            ft.form_structure
        FROM forms f
        JOIN form_templates ft ON f.template_id = ft.id
        WHERE f.unique_link = ?
    ");
    $stmt->execute([$unique_link]);
    $permit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$permit) {
        die("Permit not found or link is invalid.");
    }
    
    // Decode form data
    $form_data = json_decode($permit['form_data'], true) ?? [];
    $form_structure = json_decode($permit['form_structure'], true) ?? [];
    
} catch (Exception $e) {
    die("Error loading permit: " . $e->getMessage());
}

// Helper functions
function getStatusBadge($status) {
    $badges = [
        'active' => '<span class="badge badge-success">✅ Active</span>',
        'pending_approval' => '<span class="badge badge-warning">⏳ Awaiting Approval</span>',
        'pending' => '<span class="badge badge-warning">⏳ Pending</span>',
        'expired' => '<span class="badge badge-danger">❌ Expired</span>',
        'draft' => '<span class="badge badge-gray">📝 Draft</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-gray">' . htmlspecialchars($status) . '</span>';
}

function formatDateUK($date) {
    if (!$date || $date === '0000-00-00 00:00:00') return 'N/A';
    $timestamp = strtotime($date);
    return date('d/m/Y H:i', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit #<?php echo htmlspecialchars($permit['ref_number']); ?> - <?php echo htmlspecialchars($permit['template_name']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: <?php echo $print_mode ? 'white' : 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'; ?>;
            min-height: 100vh;
            padding: <?php echo $print_mode ? '0' : '20px'; ?>;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .permit-card {
            background: white;
            border-radius: <?php echo $print_mode ? '0' : '16px'; ?>;
            padding: 40px;
            box-shadow: <?php echo $print_mode ? 'none' : '0 8px 32px rgba(0, 0, 0, 0.1)'; ?>;
        }

        .permit-header {
            border-bottom: 3px solid #667eea;
            padding-bottom: 24px;
            margin-bottom: 32px;
        }

        .permit-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .permit-title h1 {
            font-size: 28px;
            color: #111827;
        }

        .permit-ref {
            font-size: 20px;
            font-weight: 700;
            color: #667eea;
        }

        .permit-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .info-item {
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
        }

        .info-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 15px;
            color: #111827;
            font-weight: 500;
        }

        .section {
            margin-bottom: 32px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e5e7eb;
        }

        .field-group {
            margin-bottom: 20px;
        }

        .field-label {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .field-value {
            font-size: 15px;
            color: #111827;
            padding: 12px;
            background: #f9fafb;
            border-radius: 6px;
            border-left: 3px solid #667eea;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-gray {
            background: #f3f4f6;
            color: #4b5563;
        }

        /* Buttons */
        .actions {
            display: flex;
            gap: 12px;
            margin-top: 32px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-secondary:hover {
            background: #667eea;
            color: white;
        }

        /* QR Code */
        .qr-section {
            text-align: center;
            padding: 24px;
            background: #f9fafb;
            border-radius: 12px;
            margin-top: 32px;
        }

        .qr-code {
            max-width: 200px;
            margin: 16px auto;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
            }

            .no-print {
                display: none !important;
            }

            .permit-card {
                box-shadow: none;
                border-radius: 0;
            }
        }

        /* Status Message */
        .status-message {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-weight: 500;
        }

        .status-message.pending {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
        }

        .status-message.active {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }

        @media (max-width: 768px) {
            .permit-card {
                padding: 24px;
            }

            .permit-title h1 {
                font-size: 22px;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="permit-card">
            <!-- Header -->
            <div class="permit-header">
                <div class="permit-title">
                    <h1><?php echo htmlspecialchars($permit['template_name']); ?></h1>
                    <?php echo getStatusBadge($permit['status']); ?>
                </div>
                
                <div class="permit-ref">
                    Reference: #<?php echo htmlspecialchars($permit['ref_number']); ?>
                </div>
                
                <div class="permit-info-grid">
                    <div class="info-item">
                        <div class="info-label">Permit Holder</div>
                        <div class="info-value"><?php echo htmlspecialchars($permit['holder_name'] ?? 'N/A'); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($permit['holder_email'] ?? 'N/A'); ?></div>
                    </div>
                    
                    <?php if (!empty($permit['holder_phone'])): ?>
                    <div class="info-item">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?php echo htmlspecialchars($permit['holder_phone']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <div class="info-label">Submitted</div>
                        <div class="info-value"><?php echo formatDateUK($permit['created_at']); ?></div>
                    </div>
                    
                    <?php if ($permit['status'] === 'active' && $permit['valid_to']): ?>
                    <div class="info-item">
                        <div class="info-label">Valid Until</div>
                        <div class="info-value"><?php echo formatDateUK($permit['valid_to']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status Message -->
            <?php if ($permit['status'] === 'pending_approval'): ?>
                <div class="status-message pending">
                    ⏳ <strong>Pending Approval:</strong> Your permit is being reviewed by a manager. We'll notify you once it's approved!
                </div>
            <?php elseif ($permit['status'] === 'active'): ?>
                <div class="status-message active">
                    ✅ <strong>Approved:</strong> Your permit is now active and valid for use.
                </div>
            <?php endif; ?>

            <!-- Permit Details -->
            <?php foreach ($form_structure as $section): ?>
                <div class="section">
                    <h2 class="section-title"><?php echo htmlspecialchars($section['title']); ?></h2>
                    
                    <?php foreach ($section['fields'] as $field): ?>
                        <div class="field-group">
                            <div class="field-label">
                                <?php echo htmlspecialchars($field['label']); ?>
                            </div>
                            <div class="field-value">
                                <?php 
                                $value = $form_data[$field['name']] ?? 'N/A';
                                echo nl2br(htmlspecialchars($value)); 
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <!-- QR Code (if active) -->
            <?php if ($permit['status'] === 'active'): ?>
                <div class="qr-section no-print">
                    <h3>QR Code</h3>
                    <p style="color: #6b7280; margin-bottom: 16px;">Scan to verify this permit</p>
                    <div class="qr-code">
                        <img src="/qr-code.php?id=<?php echo urlencode($permit['id']); ?>" 
                             alt="QR Code" 
                             style="width: 100%; height: auto;">
                    </div>
                </div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="actions no-print">
                <button onclick="window.print()" class="btn btn-primary">
                    🖨️ Print Permit
                </button>
				<!-- Close Permit Button (only for active permits) -->
<?php if ($permit['status'] === 'active'): ?>
    <?php
    // Check if user is logged in and has permission
    session_start();
    $canClose = false;
    if (isset($_SESSION['user_id'])) {
        $stmt = $db->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            if ($user['role'] === 'admin' || $user['role'] === 'manager' || $permit['holder_id'] === $user['id']) {
                $canClose = true;
            }
        }
    }
    ?>
    
    <?php if ($canClose): ?>
        <button onclick="closePermit()" class="btn btn-danger no-print" style="background: #ef4444;">
            🔒 Close Permit
        </button>
    <?php endif; ?>
 <?php endif; ?>
                <a href="/" class="btn btn-secondary">
                    ← Back to Homepage
                </a>
                <?php if ($permit['status'] === 'active'): ?>
                    <a href="/qr-code.php?id=<?php echo urlencode($permit['id']); ?>&download=1" 
                       class="btn btn-secondary">
                        📥 Download QR Code
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-print if print parameter is set
        <?php if ($print_mode): ?>
        window.onload = function() {
            window.print();
        }
        <?php endif; ?>
    </script>
	<script>
function closePermit() {
    if (!confirm('Are you sure you want to close this permit? This action cannot be undone.')) {
        return;
    }
    
    const reason = prompt('Optional: Enter reason for closing this permit');
    
    const formData = new FormData();
    formData.append('permit_id', '<?php echo $permit['id']; ?>');
    if (reason) {
        formData.append('reason', reason);
    }
    
    fetch('/api/close-permit.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            location.reload();
        } else {
            alert('❌ Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('❌ Error closing permit: ' + error.message);
    });
}
</script>
</body>
</html>