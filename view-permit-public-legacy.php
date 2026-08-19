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
require_once __DIR__ . '/src/permit-durations.php';

$branding = \Permits\SystemSettings::branding($db, 'Permit System');
$companyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
$brandingCss = \Permits\SystemSettings::brandingCssVariables($branding);

$auth = new Auth($db);
$currentUser = $auth->isLoggedIn() ? $auth->getCurrentUser() : null;
$currentUserIsActive = is_array($currentUser)
    && strtolower((string) ($currentUser['status'] ?? '')) === 'active';
if (!$currentUserIsActive) {
    $currentUser = null;
}
$currentRole = strtolower((string) ($currentUser['role'] ?? ''));
$canApprove = $currentUserIsActive && in_array($currentRole, ['manager', 'admin'], true);
$canViewContactDetails = $canApprove;

// Get unique link from query string
$unique_link = isset($_GET['link']) && is_string($_GET['link']) ? trim($_GET['link']) : '';
$print_mode = isset($_GET['print']);
$canClose = false;

if (strlen($unique_link) < 32 || strlen($unique_link) > 100) {
    http_response_code(404);
    exit('Permit not found or link is invalid.');
}

// Get permit by unique link
try {
    $stmt = $db->pdo->prepare("
        SELECT 
            f.*,
            COALESCE(ft.name, 'Permit') as template_name,
            ft.form_structure,
            ft.json_schema
        FROM forms f
        LEFT JOIN form_templates ft ON f.template_id = ft.id
        WHERE f.unique_link = ?
    ");
    $stmt->execute([$unique_link]);
    $permit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$permit) {
        http_response_code(404);
        exit('Permit not found or link is invalid.');
    }
    
    // Decode form data
    $form_data = json_decode((string) ($permit['form_data'] ?? ''), true);
    if (!is_array($form_data)) {
        $form_data = [];
    }
    $form_structure = json_decode((string) ($permit['form_structure'] ?? ''), true);
    if (!is_array($form_structure) || $form_structure === []) {
        $schema = json_decode((string) ($permit['json_schema'] ?? ''), true);
        $form_structure = is_array($schema)
            ? \Permits\FormTemplateSeeder::buildPublicFormStructure($schema)
            : [];
    }

    // Match the same ownership rules used by the authenticated dashboard.
    $ownsPermit = $currentUser
        ? \Permits\PermitAccess::canAccessPermit($currentUser, $permit)
        : false;
    $canClose = $canApprove || $ownsPermit;
    $canViewContactDetails = $canApprove || $ownsPermit;
    
} catch (Throwable $e) {
    error_log('Public permit view failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load the permit.');
}

// Helper functions
function getStatusBadge($status) {
    $badges = [
        'active' => '<span class="badge badge-success">✅ Active</span>',
        'issued' => '<span class="badge badge-success">✅ Active</span>',
        'approved' => '<span class="badge badge-success">✅ Active</span>',
        'open' => '<span class="badge badge-success">✅ Active</span>',
        'pending_approval' => '<span class="badge badge-warning">⏳ Awaiting Approval</span>',
        'pending' => '<span class="badge badge-warning">⏳ Pending</span>',
        'expired' => '<span class="badge badge-danger">❌ Expired</span>',
        'rejected' => '<span class="badge badge-danger">❌ Rejected</span>',
        'closed' => '<span class="badge badge-gray">Closed</span>',
        'draft' => '<span class="badge badge-gray">📝 Draft</span>'
    ];
    $normalised = strtolower((string) $status);
    return $badges[$normalised] ?? '<span class="badge badge-gray">' . htmlspecialchars((string) $status) . '</span>';
}

function formatDateUK($date) {
    if (!$date || $date === '0000-00-00 00:00:00') return 'N/A';
    $timestamp = strtotime($date);
    if ($timestamp === false) return 'N/A';
    return date('d/m/Y H:i', $timestamp);
}
// Compute checklist scores: Yes/(Yes+No) per section and overall
$scoring = [
    'overall' => ['yes' => 0, 'no' => 0, 'items' => 0],
    'sections' => []
];
if (is_array($form_structure)) {
    foreach ($form_structure as $sIdx => $section) {
        $secYes = 0; $secNo = 0;
        $fields = $section['fields'] ?? [];
        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (!is_array($field)) { continue; }
                $name = (string)($field['name'] ?? '');
                if ($name === '' || empty($field['scoreItem'])) { continue; }
                $scoring['overall']['items']++;
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
$overallScoreItems = (int) $scoring['overall']['items'];
$scoreAllowsWork = $overallScoreItems === 0 || ($overallPercent !== null && $overallPercent >= 80);
$permitStatus = strtolower((string) ($permit['status'] ?? ''));
$activeStatuses = ['active', 'issued', 'approved', 'open'];
$isActive = in_array($permitStatus, $activeStatuses, true);
$validToTimestamp = !empty($permit['valid_to']) ? strtotime((string)$permit['valid_to']) : false;
if ($isActive && $validToTimestamp !== false && $validToTimestamp <= time()) {
    // The CLI expiry sweep remains authoritative for persistence, but a delayed
    // cron run must never present out-of-date work as safe to continue.
    $permitStatus = 'expired';
    $isActive = false;
}
$hasWorkStarted = !empty($permit['work_started_at']) && $permit['work_started_at'] !== '0000-00-00 00:00:00';
$canStartWork = $isActive && ($canApprove || $ownsPermit);
$approvalDurationPresets = $canApprove && $permitStatus === 'pending_approval'
    ? getPermitDurationPresets($db)
    : [];
$approvalDurationPreset = selectPermitDurationPreset(
    $approvalDurationPresets,
    null,
    (string) ($permit['expiry_duration'] ?? '')
);
$approvalDurationMinutes = $approvalDurationPreset['minutes'] ?? null;
$approveCsrfToken = $canApprove ? \Permits\Csrf::generateToken('permit-approve') : '';
$startWorkCsrfToken = $canStartWork ? \Permits\Csrf::generateToken('permit-start-work') : '';
$closeCsrfToken = $canClose ? \Permits\Csrf::generateToken('permit-close') : '';
$permitReturnPath = '/view-permit-public.php?link=' . rawurlencode($unique_link);
$teamLoginUrl = $app->url('/login.php?redirect=' . urlencode($permitReturnPath));
?>
<!DOCTYPE html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit #<?php echo htmlspecialchars($permit['ref_number']); ?> - <?php echo htmlspecialchars($permit['template_name']); ?> - <?php echo htmlspecialchars($companyName); ?></title>
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
            background: <?php echo $print_mode ? 'white' : 'linear-gradient(135deg, var(--brand-primary-dark) 0%, #0f172a 100%)'; ?>;
            min-height: 100vh;
            padding: <?php echo $print_mode ? '0' : '20px'; ?>;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .customer-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            width: fit-content;
            max-width: 100%;
            margin: 0 0 16px;
            padding: 9px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.96);
            color: #111827;
            text-decoration: none;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.2);
        }

        .customer-brand:hover {
            box-shadow: 0 10px 30px rgba(var(--brand-primary-rgb), 0.3);
        }

        .customer-brand:focus-visible {
            outline: 3px solid rgba(var(--brand-primary-light-rgb), 0.7);
            outline-offset: 2px;
        }

        .customer-brand__logo,
        .customer-brand__symbol {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            border-radius: 9px;
        }

        .customer-brand__logo {
            object-fit: contain;
            background: #ffffff;
            padding: 3px;
            border: 1px solid #e5e7eb;
        }

        .customer-brand__symbol {
            display: grid;
            place-items: center;
            background: var(--brand-primary);
            color: var(--brand-on-primary);
            font-size: 19px;
            font-weight: 800;
        }

        .customer-brand__copy {
            min-width: 0;
        }

        .customer-brand__name,
        .customer-brand__sub {
            display: block;
        }

        .customer-brand__name {
            max-width: min(65vw, 520px);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 750;
        }

        .customer-brand__sub {
            margin-top: 2px;
            color: #64748b;
            font-size: 12px;
        }

        .permit-card {
            background: white;
            color: #111827;
            border-radius: <?php echo $print_mode ? '0' : '16px'; ?>;
            padding: 40px;
            box-shadow: <?php echo $print_mode ? 'none' : '0 8px 32px rgba(0, 0, 0, 0.1)'; ?>;
        }

        .permit-header {
            border-bottom: 3px solid var(--brand-primary);
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
            color: var(--brand-primary-dark);
            overflow-wrap: anywhere;
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
            border-left: 3px solid var(--brand-primary);
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

        .team-action-hint {
            flex: 1 0 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            background: #eff6ff;
            color: #1e3a8a;
        }

        .team-action-hint p {
            margin: 0;
            line-height: 1.5;
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
            background: linear-gradient(135deg, var(--brand-primary-light), var(--brand-primary));
            border-color: var(--brand-primary);
            color: var(--brand-on-primary);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(var(--brand-primary-rgb), 0.4);
        }

        .btn-secondary {
            background: white;
            color: var(--brand-primary-dark);
            border: 2px solid var(--brand-primary);
        }

        .btn-secondary:hover {
            background: var(--brand-primary);
            color: var(--brand-on-primary);
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

            .customer-brand {
                margin-bottom: 12px;
                padding: 0 0 10px;
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
            body {
                padding: 10px;
            }

            .permit-card {
                padding: 20px 16px;
                border-radius: 12px;
            }

            .permit-title h1 {
                font-size: 22px;
            }

            .actions {
                flex-direction: column;
            }

            .team-action-hint {
                align-items: stretch;
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .permit-header {
                margin-bottom: 24px;
                padding-bottom: 20px;
            }

            .permit-info-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .qr-section {
                padding: 18px 12px;
            }
        }
    </style>
</head>
<body class="theme-dark">
    <div class="container">
        <a class="customer-brand" href="<?= htmlspecialchars($app->url('/'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars('Return to ' . $companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if ($companyLogoUrl): ?>
                <img class="customer-brand__logo" src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
                <span class="customer-brand__symbol" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($companyName, 0, 1, 'UTF-8'), 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <?php endif; ?>
            <span class="customer-brand__copy">
                <span class="customer-brand__name"><?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="customer-brand__sub">Permit verification · Home</span>
            </span>
        </a>
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
                    
                    <?php if ($canViewContactDetails): ?>
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
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <div class="info-label">Submitted</div>
                        <div class="info-value"><?php echo formatDateUK($permit['created_at']); ?></div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">Applicant Declaration</div>
                        <div class="info-value">
                            <?php if (($form_data['_applicant_declaration'] ?? '') === 'confirmed'): ?>
                                Confirmed<?php if (!empty($form_data['_applicant_declared_at'])): ?> · <?php echo htmlspecialchars(formatDateUK((string) $form_data['_applicant_declared_at'])); ?><?php endif; ?>
                            <?php else: ?>
                                Not recorded
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($isActive && $permit['valid_to']): ?>
                    <div class="info-item">
                        <div class="info-label">Valid Until</div>
                        <div class="info-value"><?php echo formatDateUK($permit['valid_to']); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if ($hasWorkStarted): ?>
                    <div class="info-item">
                        <div class="info-label">Work Started</div>
                        <div class="info-value"><?php echo formatDateUK($permit['work_started_at']); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if ($permitStatus === 'closed' && !empty($permit['closed_at'])): ?>
                    <div class="info-item">
                        <div class="info-label">Closed</div>
                        <div class="info-value"><?php echo formatDateUK($permit['closed_at']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status Message -->
            <?php if ($permitStatus === 'pending_approval'): ?>
                <div class="status-message pending">
                    <div style="display:flex;flex-direction:column;gap:12px;align-items:flex-start;">
                        <div>⏳ <strong>Pending Approval:</strong> Your permit is being reviewed by a manager. We'll notify you once it's approved!</div>
                        <?php if ($canApprove): ?>
                            <label for="approve-duration" style="font-weight:600;">Permit validity</label>
                            <select id="approve-duration" style="width:min(100%, 320px);padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#111827;">
                                <?php foreach ($approvalDurationPresets as $durationPreset): ?>
                                    <option value="<?php echo (int) $durationPreset['minutes']; ?>" <?php echo (int) $durationPreset['minutes'] === $approvalDurationMinutes ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) $durationPreset['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-success no-print" id="approve-permit-btn" data-permit-id="<?=htmlspecialchars($permit['id'])?>">
                                ✅ Approve Permit
                            </button>
                            <div id="approve-feedback" style="font-size:14px;color:#047857;display:none;"></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($isActive): ?>
                <div class="status-message active">
                    <?php if ($hasWorkStarted): ?>
                        ✅ <strong>Work in progress:</strong> Work started on <?php echo htmlspecialchars(formatDateUK($permit['work_started_at'])); ?>.
                    <?php else: ?>
                        ✅ <strong>Approved:</strong> This permit is active. Record the work start before beginning the task.
                    <?php endif; ?>
                </div>
            <?php elseif ($permitStatus === 'closed'): ?>
                <div class="status-message pending">
                    <strong>Permit closed:</strong> This permit is no longer valid for work.
                    <?php if (!empty($permit['closure_reason'])): ?>
                        <br><?php echo nl2br(htmlspecialchars((string) $permit['closure_reason'])); ?>
                    <?php endif; ?>
                </div>
            <?php elseif ($permitStatus === 'expired'): ?>
                <div class="status-message pending"><strong>Permit expired:</strong> Create a new permit before work continues.</div>
            <?php elseif ($permitStatus === 'rejected'): ?>
                <div class="status-message pending">
                    <strong>Permit rejected:</strong> Review the details and submit a corrected permit.
                    <?php if (!empty($permit['approval_notes'])): ?>
                        <br><strong>Manager note:</strong> <?php echo nl2br(htmlspecialchars((string) $permit['approval_notes'])); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Permit Details -->
            <?php if ($form_structure === []): ?>
                <div class="status-message pending">Permit details are unavailable. Please contact a permit administrator.</div>
            <?php endif; ?>
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
                    
                                        <?php foreach (($section['fields'] ?? []) as $field): ?>
                        <?php if (!is_array($field) || empty($field['name'])) { continue; } ?>
                        <div class="field-group">
                            <div class="field-label">
                                <?php echo htmlspecialchars((string) ($field['label'] ?? $field['name'])); ?>
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
                                                            <div style="margin-top:6px; font-size:14px; color:#374151; background:#f8fafc; border-left:3px solid var(--brand-primary); padding:10px; border-radius:6px;">
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
            <?php if ($isActive): ?>
                <div class="qr-section no-print">
                    <h3>QR Code</h3>
                    <p style="color: #6b7280; margin-bottom: 16px;">Scan to verify this permit</p>
                    <div class="qr-code">
                    <img src="<?=htmlspecialchars($app->url('qr-code.php'))?>?link=<?php echo urlencode((string) $permit['unique_link']); ?>"
                             alt="QR Code"
                             style="width: 100%; height: auto;">
                    </div>
                </div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="actions no-print">
                <?php if ($isActive && !$currentUserIsActive): ?>
                    <div class="team-action-hint">
                        <p><strong>Starting or finishing the job?</strong><br>Sign in with the permit holder or manager account to record start work or close this permit.</p>
                        <a href="<?php echo htmlspecialchars($teamLoginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="btn btn-primary">Team sign in</a>
                    </div>
                <?php endif; ?>
                <button onclick="window.print()" class="btn btn-primary">
                    🖨️ Print Permit
                </button>
    			<!-- Close Permit Button (only for active permits) -->
    <?php if ($isActive && $canClose): ?>
            <button type="button" id="close-permit-btn" onclick="closePermit()" class="btn btn-danger no-print" style="background: #ef4444;">
                🔒 Close Permit
            </button>
    <?php endif; ?>
                <a href="<?=htmlspecialchars($app->url('/'))?>" class="btn btn-secondary">
                    ← Back to Homepage
                </a>
                <?php if ($isActive): ?>
                    <a href="<?=htmlspecialchars($app->url('qr-code.php'))?>?link=<?php echo urlencode((string) $permit['unique_link']); ?>&download=1"
                       class="btn btn-secondary" download="permit-<?php echo htmlspecialchars((string) ($permit['ref_number'] ?? 'qr')); ?>.png">
                        📥 Download QR Code
                    </a>
                <?php endif; ?>
                <?php if ($canStartWork && !$hasWorkStarted): ?>
                    <button type="button" id="start-work-btn" class="btn btn-success" onclick="startWork()" <?php echo $scoreAllowsWork ? '' : 'disabled'; ?> title="<?php echo $scoreAllowsWork ? 'Record the work start' : 'Complete applicable safety checks and achieve at least 80%'; ?>">
                        ▶️ Start Work
                    </button>
                <?php endif; ?>
                <?php if (in_array($permitStatus, ['active', 'issued', 'approved', 'closed', 'expired', 'rejected'], true)): ?>
                    <a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('create-permit-public.php?reopen=' . urlencode((string) $permit['unique_link']))); ?>">
                        Create Similar Permit
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
    <?php if ($permitStatus === 'pending_approval' && $canApprove): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function(){
        var approveBtn = document.getElementById('approve-permit-btn');
        if(!approveBtn){return;}
        var feedback = document.getElementById('approve-feedback');
        approveBtn.addEventListener('click', function(){
            var permitId = approveBtn.getAttribute('data-permit-id');
            var durationSelect = document.getElementById('approve-duration');
            var durationMinutes = durationSelect ? Number(durationSelect.value) : null;
            if(!permitId){return;}
            approveBtn.disabled = true;
            approveBtn.textContent = 'Approving...';
            fetch(<?php echo json_encode($app->url('api/approve-permit.php')); ?>, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': <?php echo json_encode($approveCsrfToken); ?>
                },
                body: JSON.stringify({ permit_id: permitId, duration_minutes: durationMinutes })
            }).then(readJsonResponse).then(function(payload){
                if(payload && payload.success){
                    if(feedback){
                        feedback.textContent = 'Permit approved for ' + (payload.duration_label || 'the selected duration') + '. Reloading...';
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
function readJsonResponse(response) {
    return response.json().catch(function() {
        throw new Error('The server returned an invalid response. Please try again.');
    }).then(function(payload) {
        if (!response.ok || !payload || payload.success !== true) {
            throw new Error(payload && payload.message ? payload.message : 'Request failed (' + response.status + ')');
        }
        return payload;
    });
}

function startWork(){
    if (!confirm('Record that work is starting now?')) {
        return;
    }

    var button = document.getElementById('start-work-btn');
    var score = <?php echo json_encode($overallPercent); ?>;
    var BASE_URL = <?php echo json_encode(rtrim($app->url(''), '/').'/'); ?>;
    var link = <?php echo json_encode($permit['unique_link']); ?>;
    if (button) {
        button.disabled = true;
        button.textContent = 'Recording...';
    }
    fetch(BASE_URL + 'api/start-work.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': <?php echo json_encode($startWorkCsrfToken); ?>
        },
        body: JSON.stringify({ link: link })
    }).then(readJsonResponse).then(function(payload){
        alert('▶️ Work started' + (score !== null ? ('\nScore: ' + score + '%') : '') );
        window.location.reload();
    }).catch(function(err){
        alert('Could not record start: ' + err.message);
        if (button) {
            button.disabled = false;
            button.textContent = '▶️ Start Work';
        }
    });
}
function closePermit() {
    if (!confirm('Are you sure you want to close this permit? This action cannot be undone.')) {
        return;
    }
    
    const reason = prompt('Optional: Enter reason for closing this permit');
    if (reason === null) {
        return;
    }

    const button = document.getElementById('close-permit-btn');
    if (button) {
        button.disabled = true;
        button.textContent = 'Closing...';
    }
    
    const formData = new FormData();
    formData.append('permit_id', '<?php echo $permit['id']; ?>');
    formData.append('csrf_token', <?php echo json_encode($closeCsrfToken); ?>);
    if (reason) {
        formData.append('reason', reason);
    }
    
    const BASE_URL = <?= json_encode(rtrim($app->url(''), '/').'/') ?>;
    fetch(BASE_URL + 'api/close-permit.php', {
        method: 'POST',
        body: formData
    })
    .then(readJsonResponse)
    .then(data => {
        alert('✅ ' + data.message);
        location.reload();
    })
    .catch(error => {
        alert('❌ Error closing permit: ' + error.message);
        if (button) {
            button.disabled = false;
            button.textContent = '🔒 Close Permit';
        }
    });
}
</script>
</body>
</html>
