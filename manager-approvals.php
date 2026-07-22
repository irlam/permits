<?php
use Permits\SystemSettings;
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

require_once __DIR__ . '/src/permit-durations.php';
require_once __DIR__ . '/src/Auth.php';

$auth = new Auth($db);
$user = $auth->requireRoles(['manager', 'admin']);

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
            f.expiry_duration,
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

$durationPresets = getPermitDurationPresets($db);
$approveCsrfToken = \Permits\Csrf::generateToken('permit-approve');
$rejectCsrfToken = \Permits\Csrf::generateToken('permit-reject');

// Helper function
function formatDateUK($date) {
    if (!$date) return 'N/A';
    return date('d/m/Y H:i', strtotime($date));
}

$branding = SystemSettings::branding($db);
$companyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
$brandingCss = SystemSettings::brandingCssVariables($branding);
?>
<!DOCTYPE html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals - <?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <meta name="theme-color" content="<?= htmlspecialchars($branding['primary_colour'], ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/app.css') ?>">
    <style>
        body.theme-dark {
            background: #020617;
            color: #e5e7eb;
            min-height: 100vh;
            margin: 0;
        }

        .approvals-card {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .approval-grid {
            display: grid;
            gap: 18px;
        }

        @media (min-width: 900px) {
            .approval-grid {
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            }
        }

        .approval-card {
            display: flex;
            flex-direction: column;
            gap: 18px;
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(51, 65, 85, 0.7);
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 18px 36px rgba(2, 6, 23, 0.35);
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .approval-card:hover {
            transform: translateY(-2px);
            border-color: rgba(59, 130, 246, 0.45);
            box-shadow: 0 24px 46px rgba(2, 6, 23, 0.45);
        }

        .approval-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }

        .approval-title {
            font-size: 18px;
            font-weight: 600;
            color: #f8fafc;
        }

        .approval-ref {
            font-size: 14px;
            color: var(--brand-primary-light);
            font-weight: 600;
        }

        .approval-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
        }

        .info-item {
            background: rgba(15, 23, 42, 0.55);
            border: 1px solid rgba(51, 65, 85, 0.65);
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .info-label {
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .info-value {
            font-size: 15px;
            color: #e2e8f0;
            font-weight: 500;
            word-break: break-word;
        }

        .info-value a {
            color: inherit;
            text-decoration: none;
        }

        .info-value a:hover {
            color: var(--brand-primary-light);
            text-decoration: underline;
        }

        .approval-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            border-top: 1px solid rgba(51, 65, 85, 0.6);
            padding-top: 18px;
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

        .chip-large {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            background: rgba(59, 130, 246, 0.16);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #bfdbfe;
        }

        .empty-state-icon {
            font-size: 56px;
            display: block;
            margin-bottom: 12px;
        }

        .empty-state-title {
            font-size: 20px;
            color: #e2e8f0;
            margin-bottom: 8px;
        }
    </style>
</head>
<body class="theme-dark">
    <header class="site-header">
        <div class="brand-mark">
            <?php if ($companyLogoUrl): ?>
                <img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($companyName) ?> logo" class="brand-mark__logo">
            <?php endif; ?>
            <div>
                <div class="brand-mark__name"><?= htmlspecialchars($companyName) ?></div>
                <div class="brand-mark__sub">⏳ Pending Approvals</div>
            </div>
        </div>
        <div class="site-header__actions">
            <span class="user-info">👤 <?php echo htmlspecialchars($user['name'] ?? ($user['email'] ?? '')); ?></span>
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
                        <?php
                            $statusLabel = ucwords(str_replace('_', ' ', (string)($permit['status'] ?? 'pending_approval')));
                            $selectedDurationPreset = selectPermitDurationPreset(
                                $durationPresets,
                                null,
                                (string) ($permit['expiry_duration'] ?? '')
                            );
                            $selectedDurationMinutes = $selectedDurationPreset['minutes'] ?? null;
                        ?>
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

                            <div class="info-item" style="margin-top:14px;">
                                <label class="info-label" for="duration-<?php echo htmlspecialchars($permit['id']); ?>">Permit validity</label>
                                <select id="duration-<?php echo htmlspecialchars($permit['id']); ?>" class="duration-select" style="width:100%;margin-top:6px;padding:10px 12px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:#e2e8f0;">
                                    <?php foreach ($durationPresets as $durationPreset): ?>
                                        <option value="<?php echo (int) $durationPreset['minutes']; ?>" <?php echo (int) $durationPreset['minutes'] === $selectedDurationMinutes ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) $durationPreset['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="approval-actions">
                                <a class="btn btn-ghost btn-small" href="<?php echo htmlspecialchars($app->url('view-permit-public.php?link=' . urlencode((string) $permit['unique_link']))); ?>" target="_blank" rel="noopener">
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
        const APPROVE_PERMIT_URL = <?php echo json_encode($app->url('api/approve-permit.php')); ?>;
        const REJECT_PERMIT_URL = <?php echo json_encode($app->url('api/reject-permit.php')); ?>;
        const APPROVE_CSRF_TOKEN = <?php echo json_encode($approveCsrfToken); ?>;
        const REJECT_CSRF_TOKEN = <?php echo json_encode($rejectCsrfToken); ?>;

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

            const durationSelect = document.getElementById('duration-' + permitId);
            const durationMinutes = durationSelect ? Number(durationSelect.value) : null;
            const card = document.querySelector(`[data-permit-id="${permitId}"]`);
            const approveButton = card ? card.querySelector('.btn-success') : null;
            if (approveButton) {
                approveButton.disabled = true;
                approveButton.textContent = 'Approving...';
            }

            try {
                const response = await fetch(APPROVE_PERMIT_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': APPROVE_CSRF_TOKEN,
                    },
                    body: JSON.stringify({
                        permit_id: permitId,
                        duration_minutes: durationMinutes
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showToast('✅ Permit approved for ' + (data.duration_label || 'the selected duration') + '.', 'success');
                    
                    // Remove card from view
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
                    if (approveButton) {
                        approveButton.disabled = false;
                        approveButton.textContent = '✅ Approve';
                    }
                }
            } catch (error) {
                showToast('❌ Error approving permit', 'error');
                console.error('Error:', error);
                if (approveButton) {
                    approveButton.disabled = false;
                    approveButton.textContent = '✅ Approve';
                }
            }
        }

        // Reject permit
        async function rejectPermit(permitId) {
            const reason = prompt('Reason for rejection:');
            if (reason === null) {
                return; // User cancelled
            }
            if (reason.trim() === '') {
                showToast('Enter a reason so the applicant knows what to correct.', 'error');
                return;
            }

            try {
                const response = await fetch(REJECT_PERMIT_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': REJECT_CSRF_TOKEN,
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
