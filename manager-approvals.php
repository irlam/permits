<?php
/**
 * Manager Approval Dashboard
 * 
 * File Path: /manager-approvals.php
 * Description: Manager interface for approving/rejecting permits
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - Shows pending approval permits
 * - Approve/reject functionality
 * - Email notifications on approval
 * - Push notifications (if enabled)
 * - Manager/Admin only access
 */

// Load bootstrap (includes auth automatically)
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

require_once __DIR__ . '/src/check-expiry.php';
require_once __DIR__ . '/src/approval-notifications.php';

if (function_exists('check_and_expire_permits')) {
    check_and_expire_permits($db);
}

// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $app->url('login.php'));
    exit;
}

// Get current user
$user_stmt = $db->pdo->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$_SESSION['user_id']]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Check if user has manager or admin role
if (!$user || !in_array($user['role'], ['manager', 'admin'])) {
    die("Access denied. Manager or Admin role required.");
}

// Get pending approvals
$approvalStatusMap = [];
try {
    $stmt = $db->pdo->query("
        SELECT 
            f.id,
            f.ref_number,
            f.status,
            f.holder_name,
            f.holder_email,
            f.holder_phone,
            f.created_at,
            f.unique_link,
            ft.name as template_name
        FROM forms f
        JOIN form_templates ft ON f.template_id = ft.id
        WHERE f.status = 'pending_approval'
        ORDER BY f.created_at ASC
    ");
    $pending_permits = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pending_permits = [];
    error_log("Error fetching pending permits: " . $e->getMessage());
}

if (!empty($pending_permits)) {
    try {
        $approvalStatusMap = getApprovalLinkStatusMap($db, array_column($pending_permits, 'id'));
    } catch (Throwable $e) {
        error_log('Error building approval link status map: ' . $e->getMessage());
    }
}

// Helper function
function formatDateUK($date) {
    if (!$date) return 'N/A';
    return date('d/m/Y H:i', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals - Manager Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 24px 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header h1 {
            font-size: 28px;
            color: #111827;
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
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

        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 13px;
        }

        /* Card */
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-bottom: 24px;
        }

        .card-title {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 24px;
        }

        /* Approval Cards */
        .approval-grid {
            display: grid;
            gap: 20px;
        }

        .approval-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            border-left: 4px solid #f59e0b;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .approval-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .approval-title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }

        .approval-ref {
            font-size: 14px;
            color: #667eea;
            font-weight: 600;
        }

        .approval-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .info-item {
            padding: 8px 12px;
            background: #f9fafb;
            border-radius: 6px;
        }

        .approval-recipients {
            margin-top: 16px;
        }

        .recipient-status-list {
            list-style: none;
            margin: 12px 0 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .recipient-status-item {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            background: #f3f4f6;
            border-radius: 10px;
            padding: 12px 14px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .recipient-status-item.extra {
            border: 1px dashed #9ca3af;
            background: #ffffff;
        }

        .recipient-labels {
            display: grid;
            gap: 4px;
        }

        .recipient-name {
            font-weight: 600;
            color: #111827;
        }

        .recipient-meta {
            font-size: 13px;
            color: #4b5563;
        }

        .recipient-chip {
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            align-self: flex-start;
        }

        .recipient-chip.status-success { background: rgba(16, 185, 129, 0.15); color: #047857; border: 1px solid rgba(16, 185, 129, 0.35); }
        .recipient-chip.status-info { background: rgba(59, 130, 246, 0.15); color: #1d4ed8; border: 1px solid rgba(59, 130, 246, 0.35); }
        .recipient-chip.status-warning { background: rgba(251, 191, 36, 0.15); color: #b45309; border: 1px solid rgba(251, 191, 36, 0.4); }
        .recipient-chip.status-danger { background: rgba(239, 68, 68, 0.15); color: #b91c1c; border: 1px solid rgba(239, 68, 68, 0.35); }
        .recipient-chip.status-muted { background: #e5e7eb; color: #374151; border: 1px solid #d1d5db; }

        .recipient-empty {
            margin-top: 12px;
            background: #eef2ff;
            border: 1px dashed #c7d2fe;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            color: #4c1d95;
        }

        .info-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .info-value {
            font-size: 14px;
            color: #111827;
        }

        .approval-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            flex-wrap: wrap;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 64px 24px;
            color: #6b7280;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }

        .empty-state-title {
            font-size: 20px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        /* Toast Notification */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            display: none;
            animation: slideIn 0.3s ease-out;
        }

        .toast.show {
            display: block;
        }

        .toast.success {
            border-left: 4px solid #10b981;
        }

        .toast.error {
            border-left: 4px solid #ef4444;
        }

        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .approval-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .recipient-status-item {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>⏳ Pending Approvals (<?php echo count($pending_permits); ?>)</h1>
            <div class="header-actions">
                <a href="<?php echo htmlspecialchars($app->url('presentation-dashboard.php')); ?>" class="btn btn-secondary">🎬 Presentation Mode</a>
                <a href="<?php echo htmlspecialchars($app->url('dashboard.php')); ?>" class="btn btn-secondary">← Back to Dashboard</a>
            </div>
        </div>

        <!-- Approvals List -->
        <div class="card">
            <?php if (empty($pending_permits)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">✅</div>
                    <div class="empty-state-title">All caught up!</div>
                    <p>There are no permits waiting for approval.</p>
                </div>
            <?php else: ?>
                <div class="approval-grid">
                    <?php foreach ($pending_permits as $permit): ?>
                        <div class="approval-card" data-permit-id="<?php echo htmlspecialchars($permit['id']); ?>">
                            <div class="approval-header">
                                <div>
                                    <div class="approval-title">
                                        <?php echo htmlspecialchars($permit['template_name']); ?>
                                    </div>
                                    <div class="approval-ref">
                                        #<?php echo htmlspecialchars($permit['ref_number']); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="approval-info">
                                <div class="info-item">
                                    <div class="info-label">Submitted By</div>
                                    <div class="info-value"><?php echo htmlspecialchars($permit['holder_name']); ?></div>
                                </div>

                                <div class="info-item">
                                    <div class="info-label">Email</div>
                                    <div class="info-value"><?php echo htmlspecialchars($permit['holder_email']); ?></div>
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
                            </div>

                            <?php
                                $statusBundle = $approvalStatusMap[$permit['id']] ?? ['recipients' => [], 'extra' => []];
                                $recipientStatuses = $statusBundle['recipients'];
                                $extraStatuses = $statusBundle['extra'];
                                $allStatuses = array_merge($recipientStatuses, $extraStatuses);
                            ?>
                            <div class="approval-recipients">
                                <div class="info-label">Approver Emails</div>
                                <?php if (empty($allStatuses)): ?>
                                    <div class="recipient-empty">No approval emails queued yet for the configured recipients.</div>
                                <?php else: ?>
                                    <ul class="recipient-status-list">
                                        <?php foreach ($allStatuses as $entry): ?>
                                            <?php
                                                $displayName = $entry['name'] !== '' ? $entry['name'] : $entry['email'];
                                                $emailLine = $entry['name'] !== '' ? $entry['email'] : '';
                                                $detail = $entry['detail'] ?? '';
                                                $itemClass = !empty($entry['configured']) ? 'recipient-status-item' : 'recipient-status-item extra';
                                            ?>
                                            <li class="<?php echo $itemClass; ?>">
                                                <div class="recipient-labels">
                                                    <div class="recipient-name"><?php echo htmlspecialchars($displayName); ?></div>
                                                    <?php if ($emailLine !== ''): ?>
                                                        <div class="recipient-meta"><?php echo htmlspecialchars($emailLine); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($detail !== ''): ?>
                                                        <div class="recipient-meta"><?php echo htmlspecialchars($detail); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="recipient-chip <?php echo htmlspecialchars($entry['status_class']); ?>"><?php echo htmlspecialchars($entry['label']); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>

                            <div class="approval-actions">
                                <a href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>" 
                                   target="_blank" 
                                   class="btn btn-secondary btn-small">
                                    👁️ View Details
                                </a>
                                <button onclick="approvePermit('<?php echo htmlspecialchars($permit['id']); ?>')" 
                                        class="btn btn-success btn-small">
                                    ✅ Approve
                                </button>
                                <button onclick="rejectPermit('<?php echo htmlspecialchars($permit['id']); ?>')" 
                                        class="btn btn-danger btn-small">
                                    ❌ Reject
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <script>
        // Show toast notification
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast ' + type + ' show';
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Approve permit
        async function approvePermit(permitId) {
            if (!confirm('Approve this permit?')) {
                return;
            }

            try {
                const response = await fetch('/api/approve-permit.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        permit_id: permitId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showToast('✅ Permit approved successfully!', 'success');
                    
                    // Remove card from view
                    const card = document.querySelector(`[data-permit-id="${permitId}"]`);
                    if (card) {
                        card.style.transition = 'opacity 0.3s, transform 0.3s';
                        card.style.opacity = '0';
                        card.style.transform = 'translateX(-20px)';
                        setTimeout(() => {
                            card.remove();
                            
                            // Reload if no more permits
                            if (document.querySelectorAll('.approval-card').length === 0) {
                                location.reload();
                            }
                        }, 300);
                    }
                } else {
                    showToast('❌ Error: ' + (data.message || 'Failed to approve'), 'error');
                }
            } catch (error) {
                showToast('❌ Error approving permit', 'error');
                console.error('Error:', error);
            }
        }

        // Reject permit
        async function rejectPermit(permitId) {
            const reason = prompt('Reason for rejection (optional):');
            if (reason === null) {
                return; // User cancelled
            }

            try {
                const response = await fetch('/api/reject-permit.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        permit_id: permitId,
                        reason: reason
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showToast('✅ Permit rejected', 'success');
                    
                    // Remove card from view
                    const card = document.querySelector(`[data-permit-id="${permitId}"]`);
                    if (card) {
                        card.style.transition = 'opacity 0.3s, transform 0.3s';
                        card.style.opacity = '0';
                        card.style.transform = 'translateX(-20px)';
                        setTimeout(() => {
                            card.remove();
                            
                            // Reload if no more permits
                            if (document.querySelectorAll('.approval-card').length === 0) {
                                location.reload();
                            }
                        }, 300);
                    }
                } else {
                    showToast('❌ Error: ' + (data.message || 'Failed to reject'), 'error');
                }
            } catch (error) {
                showToast('❌ Error rejecting permit', 'error');
                console.error('Error:', error);
            }
        }
    </script>
</body>
</html>