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
require_once __DIR__ . '/src/Auth.php';

$auth = new Auth($db);
$currentUser = $auth->getCurrentUser();
$canApprove = $auth->isLoggedIn() && $auth->hasAnyRole(['manager', 'admin']);

// Get unique link from query string
$unique_link = $_GET['link'] ?? null;
$print_mode = isset($_GET['print']);
$canClose = false;

if (!$unique_link) {
    header('Location: ' . $app->url('/'));
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

    // Determine if current user can close or approve
    $canClose = false;
    if ($currentUser) {
        $role = strtolower($currentUser['role'] ?? '');
        if (in_array($role, ['admin', 'manager'], true)) {
            $canClose = true;
        } elseif (!empty($permit['holder_id']) && $permit['holder_id'] === $currentUser['id']) {
            $canClose = true;
        }
    } else {
        $canClose = false;
    }
    
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
// Compute checklist scores: Yes/(Yes+No) per section and overall
$scoring = [
    'overall' => ['yes' => 0, 'no' => 0],
    'sections' => []
];
if (is_array($form_structure)) {
    foreach ($form_structure as $sIdx => $section) {
        $secKey = 'section' . ($sIdx + 1);
        $secYes = 0; $secNo = 0;
        $fields = $section['fields'] ?? [];
        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (!is_array($field)) { continue; }
                $name = (string)($field['name'] ?? '');
                if ($name === '' || strpos($name, $secKey . '_item_') !== 0) { continue; }
                $value = strtolower(trim((string)($form_data[$name] ?? '')));
                if ($value === 'yes') { $secYes++; }
                elseif ($value === 'no') { $secNo++; }
            }
        }
        $den = $secYes + $secNo;
        $percent = $den > 0 ? round(($secYes / $den) * 100) : null;
        $scoring['sections'][$sIdx] = [
            'yes' => $secYes,
            'no' => $secNo,
            'percent' => $percent,
        ];
        $scoring['overall']['yes'] += $secYes;
        $scoring['overall']['no']  += $secNo;
    }
}
$overallDen = $scoring['overall']['yes'] + $scoring['overall']['no'];
$overallPercent = $overallDen > 0 ? round(($scoring['overall']['yes'] / $overallDen) * 100) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit #<?php echo htmlspecialchars($permit['ref_number']); ?> - <?php echo htmlspecialchars($permit['template_name']); ?></title>
    <link rel="manifest" href="<?=htmlspecialchars($app->url('manifest.webmanifest'))?>">
    <link rel="apple-touch-icon" sizes="192x192" href="<?=htmlspecialchars($app->url('assets/pwa/icon-192.png'))?>">
    <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
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

        .score-badge { display:inline-block; padding:6px 10px; border-radius:10px; font-weight:700; font-size:12px; background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; }

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

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .btn-success:hover {
            box-shadow: 0 8px 24px rgba(16, 185, 129, 0.35);
            transform: translateY(-1px);
        }

        .btn-success[disabled] {
            opacity: 0.75;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
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
<body class="theme-dark">
    <div class="container">
        <div class="permit-card">
            <!-- Header -->
            <div class="permit-header">
                <div class="permit-title">
                    <h1><?php echo htmlspecialchars($permit['template_name']); ?></h1>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <?php echo getStatusBadge($permit['status']); ?>
                        <?php if ($overallPercent !== null): ?>
                            <span class="score-badge" title="Yes/(Yes+No)">Score: <?php echo (int)$overallPercent; ?>%</span>
                        <?php endif; ?>
                    </div>
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
                    <div style="display:flex;flex-direction:column;gap:12px;align-items:flex-start;">
                        <div>⏳ <strong>Pending Approval:</strong> Your permit is being reviewed by a manager. We'll notify you once it's approved!</div>
                        <?php if ($canApprove): ?>
                            <button type="button" class="btn btn-success no-print" id="approve-permit-btn" data-permit-id="<?=htmlspecialchars($permit['id'])?>">
                                ✅ Approve Permit
                            </button>
                            <div id="approve-feedback" style="font-size:14px;color:#047857;display:none;"></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($permit['status'] === 'active'): ?>
                <div class="status-message active">
                    ✅ <strong>Approved:</strong> Your permit is now active and valid for use.
                </div>
            <?php endif; ?>

            <!-- Permit Details -->
            <?php foreach ($form_structure as $idx => $section): ?>
                <div class="section">
                    <h2 class="section-title" style="display:flex;align-items:center;gap:8px;justify-content:space-between;flex-wrap:wrap;">
                        <span><?php echo htmlspecialchars($section['title']); ?></span>
                        <?php $secScore = $scoring['sections'][$idx]['percent'] ?? null; if ($secScore !== null): ?>
                            <span class="score-badge" title="Yes/(Yes+No)"><?php echo (int)$secScore; ?>%</span>
                        <?php endif; ?>
                    </h2>
                    <?php if (!empty($section['items']) && is_array($section['items'])): ?>
                        <ul style="margin: 0 0 16px 20px; color:#374151;">
                            <?php foreach ($section['items'] as $item): ?>
                                <li style="margin-bottom:6px;"><?php echo htmlspecialchars((string)$item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    
                                        <?php foreach ($section['fields'] as $field): ?>
                        <div class="field-group">
                            <div class="field-label">
                                <?php echo htmlspecialchars($field['label']); ?>
                            </div>
                            <div class="field-value">
                                <?php 
                                                                $baseName = (string)$field['name'];
                                                                $value = $form_data[$baseName] ?? '';
                                                                if ($value === '') { $value = 'N/A'; }
                                                                echo nl2br(htmlspecialchars($value)); 
                                ?>
                            </div>
                                                        <?php 
                                                            $noteKey = $baseName . '_note';
                                                            $mediaKey = $baseName . '_media';
                                                            $noteVal = trim((string)($form_data[$noteKey] ?? ''));
                                                            $mediaVal = trim((string)($form_data[$mediaKey] ?? ''));
                                                        ?>
                                                        <?php if ($noteVal !== ''): ?>
                                                            <div style="margin-top:6px; font-size:14px; color:#374151; background:#f8fafc; border-left:3px solid #6366f1; padding:10px; border-radius:6px;">
                                                                <strong>Note:</strong> <?php echo nl2br(htmlspecialchars($noteVal)); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($mediaVal !== ''): 
                                                                $parts = array_values(array_filter(array_map('trim', explode(',', $mediaVal))));
                                                        ?>
                                                            <div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px;">
                                                                <?php foreach ($parts as $path): 
                                                                        $url = $app->url(ltrim($path, '/'));
                                                                        $lower = strtolower($path);
                                                                        $isImg = preg_match('/\.(png|jpg|jpeg|gif|webp)$/', $lower);
                                                                ?>
                                                                    <?php if ($isImg): ?>
                                                                        <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" style="display:inline-block;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff;">
                                                                            <img src="<?php echo htmlspecialchars($url); ?>" alt="attachment" style="width:120px;height:80px;object-fit:cover;display:block;">
                                                                        </a>
                                                                    <?php else: ?>
                                                                        <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" class="btn btn-secondary">📎 Attachment</a>
                                                                    <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
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
                    <img src="<?=htmlspecialchars($app->url('qr-code.php'))?>?id=<?php echo urlencode($permit['id']); ?>" 
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
    <?php if ($permit['status'] === 'active' && $canClose): ?>
            <button onclick="closePermit()" class="btn btn-danger no-print" style="background: #ef4444;">
                🔒 Close Permit
            </button>
    <?php endif; ?>
                <a href="<?=htmlspecialchars($app->url('/'))?>" class="btn btn-secondary">
                    ← Back to Homepage
                </a>
                <?php if ($permit['status'] === 'active'): ?>
                    <a href="<?=htmlspecialchars($app->url('qr-code.php'))?>?id=<?php echo urlencode($permit['id']); ?>&download=1" 
                       class="btn btn-secondary">
                        📥 Download QR Code
                    </a>
                <?php endif; ?>
                <?php if ($permit['status'] === 'active' && $overallPercent !== null): ?>
                    <button class="btn btn-success" onclick="startWork()" <?php echo ($overallPercent >= 80 ? '' : 'disabled'); ?>>
                        ▶️ Start Work
                    </button>
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
    <?php if ($permit['status'] === 'pending_approval' && $canApprove): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        var approveBtn = document.getElementById('approve-permit-btn');
        if(!approveBtn){return;}
        var feedback = document.getElementById('approve-feedback');
        approveBtn.addEventListener('click', function(){
            var permitId = approveBtn.getAttribute('data-permit-id');
            if(!permitId){return;}
            approveBtn.disabled = true;
            approveBtn.textContent = 'Approving...';
            fetch('/api/approve-permit.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ permit_id: permitId })
            }).then(function(res){
                if(!res.ok){throw new Error('Approve request failed');}
                return res.json();
            }).then(function(payload){
                if(payload && payload.success){
                    if(feedback){
                        feedback.textContent = 'Permit approved successfully. Reloading...';
                        feedback.style.display = 'block';
                        feedback.style.color = '#047857';
                    }
                    setTimeout(function(){ window.location.reload(); }, 1200);
                } else {
                    throw new Error(payload && payload.message ? payload.message : 'Approval failed');
                }
            }).catch(function(err){
                if(feedback){
                    feedback.textContent = err.message;
                    feedback.style.display = 'block';
                    feedback.style.color = '#b91c1c';
                }
                approveBtn.disabled = false;
                approveBtn.textContent = '✅ Approve Permit';
            });
        });
    });
    </script>
    <?php endif; ?>
	<script>
function startWork(){
    var btns = document.getElementsByClassName('btn btn-success');
    var score = <?php echo json_encode($overallPercent); ?>;
    var BASE_URL = <?php echo json_encode(rtrim($app->url(''), '/').'/'); ?>;
    var link = <?php echo json_encode($permit['unique_link']); ?>;
    fetch(BASE_URL + 'api/start-work.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ link: link })
    }).then(function(res){
        if (!res.ok) throw new Error('Request failed: ' + res.status);
        return res.json();
    }).then(function(payload){
        if (payload && payload.success) {
            alert('▶️ Work started' + (score !== null ? ('\nScore: ' + score + '%') : '') );
        } else {
            throw new Error(payload && payload.message ? payload.message : 'Unknown error');
        }
    }).catch(function(err){
        alert('Could not record start: ' + err.message);
    });
}
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
    
    const BASE_URL = <?= json_encode(rtrim($app->url(''), '/').'/') ?>;
    fetch(BASE_URL + 'api/close-permit.php', {
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