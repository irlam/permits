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
    <link rel="stylesheet" href="<?= asset('/assets/app.css') ?>">
    <style>
        body.theme-dark {
            background: #0f172a;
            color: #e5e7eb;
            min-height: 100vh;
            margin: 0;
        }

        .approvals-card {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .approval-grid {
            display: grid;
            gap: 16px;
        }

        @media (min-width: 900px) {
            .approval-grid {
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            }
        }

        .approval-card {
            background: #111827;
            border: 1px solid #1f2937;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .approval-card:hover {
            transform: translateY(-3px);
            border-color: #3b82f6;
        }

        .approval-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .approval-title {
            font-size: 18px;
            font-weight: 600;
            color: #e5e7eb;
        }

        .approval-ref {
            font-size: 14px;
            color: #38bdf8;
            font-weight: 600;
        }

        .approval-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .info-item {
            background: #0a101a;
            border: 1px solid #1f2937;
            border-radius: 10px;
            padding: 10px 12px;
        }

        .info-label {
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 4px;
            display: block;
        }

        .info-value {
            font-size: 15px;
            color: #e5e7eb;
            font-weight: 500;
        }

        .approval-info .info-value a {
            color: inherit;
            text-decoration: none;
        }

        .approval-info .info-value a:hover {
            color: #38bdf8;
            text-decoration: underline;
        }

        .approval-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            border-top: 1px solid #1f2937;
            padding-top: 16px;
        }

        .approval-actions .btn {
            justify-content: center;
            min-width: 140px;
        }

        @media (max-width: 640px) {
            .approval-actions .btn {
                flex: 1 1 100%;
                min-width: 0;
            }
        }

        .empty-state-icon {
            font-size: 56px;
            display: block;
            margin-bottom: 12px;
        }

        .empty-state-title {
            font-size: 20px;
            color: #e5e7eb;
            margin-bottom: 8px;
        }

        .chip-large {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(59, 130, 246, 0.18);
            border: 1px solid rgba(59, 130, 246, 0.35);
            color: #bfdbfe;
            font-size: 13px;
        }
    </style>
</head>
<body class="theme-dark">
    <header class="site-header">
        <h1 class="site-header__title">⏳ Pending Approvals</h1>
        <div class="site-header__actions">
            <span class="user-info">👤 <?php echo htmlspecialchars($user['name'] ?? ($user['email'] ?? '')); ?></span>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('presentation-dashboard.php')); ?>">🎬 Presentation</a>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('dashboard.php')); ?>">📊 Dashboard</a>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('/')); ?>">🏠 Home</a>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('logout.php')); ?>">🚪 Logout</a>
        </div>
    </header>

    <main class="site-container">
        <section class="hero-card">
            <h2>Approvals Queue</h2>
            <p>
                <?php if (count($pending_permits) === 0): ?>
                    All clear—nothing needs a decision right now.
                <?php else: ?>
                    <?php echo count($pending_permits); ?> permits are waiting for review. Prioritise the oldest submissions first.
                <?php endif; ?>
            </p>
        </section>

        <section class="surface-card approvals-card">
            <div class="card-header">
                <h3>Awaiting Decisions</h3>
                <span class="chip-large">Pending: <?php echo count($pending_permits); ?></span>
            </div>

            <?php if (empty($pending_permits)): ?>
                <div class="empty-state">
                    <span class="empty-state-icon">✅</span>
                    <div class="empty-state-title">All caught up!</div>
                    <p>There are no permits waiting for approval.</p>
                </div>
            <?php else: ?>
                <div class="approval-grid">
                    <?php foreach ($pending_permits as $permit): ?>
                        <?php $statusLabel = ucwords(str_replace('_', ' ', (string)($permit['status'] ?? 'pending_approval'))); ?>
                        <article class="approval-card" data-permit-id="<?php echo htmlspecialchars($permit['id']); ?>">
                            <div class="approval-header">
                                <div>
                                    <div class="approval-title"><?php echo htmlspecialchars($permit['template_name']); ?></div>
                                    <div class="approval-ref">#<?php echo htmlspecialchars($permit['ref_number']); ?></div>
                                </div>
                                <span class="chip"><?php echo htmlspecialchars($statusLabel); ?></span>
                            </div>

                            <div class="approval-info">
                                <div class="info-item">
                                    <span class="info-label">Submitted By</span>
                                    <span class="info-value"><?php echo htmlspecialchars($permit['holder_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Email</span>
                                    <span class="info-value"><a href="mailto:<?php echo htmlspecialchars($permit['holder_email']); ?>"><?php echo htmlspecialchars($permit['holder_email']); ?></a></span>
                                </div>
                                <?php if (!empty($permit['holder_phone'])): ?>
                                <div class="info-item">
                                    <span class="info-label">Phone</span>
                                    <span class="info-value"><?php echo htmlspecialchars($permit['holder_phone']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="info-item">
                                    <span class="info-label">Submitted</span>
                                    <span class="info-value"><?php echo formatDateUK($permit['created_at']); ?></span>
                                </div>
                            </div>

                            <div class="approval-actions">
                                <a class="btn btn-ghost btn-small" href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>" target="_blank" rel="noopener">
                                    👁️ View Details
                                </a>
                                <button class="btn btn-success btn-small" type="button" onclick="approvePermit('<?php echo htmlspecialchars($permit['id']); ?>')">
                                    ✅ Approve
                                </button>
                                <button class="btn btn-danger btn-small" type="button" onclick="rejectPermit('<?php echo htmlspecialchars($permit['id']); ?>')">
                                    ❌ Reject
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

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