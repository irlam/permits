<?php
declare(strict_types=1);

use Permits\Csrf;
use Permits\PermitAccess;
use Permits\PermitLinks;
use Permits\PermitWorkflow;
use Permits\SystemSettings;

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/permit-durations.php';

$auth = new Auth($db);
$currentUser = $auth->isLoggedIn() ? $auth->getCurrentUser() : null;
$currentUserIsActive = is_array($currentUser)
    && strtolower((string)($currentUser['status'] ?? '')) === 'active';
if (!$currentUserIsActive) {
    $currentUser = null;
}
$currentRole = strtolower((string)($currentUser['role'] ?? ''));
$isManager = $currentUser !== null && in_array($currentRole, ['manager', 'admin'], true);

$uniqueLink = isset($_GET['link']) && is_string($_GET['link']) ? trim($_GET['link']) : '';
if (strlen($uniqueLink) < 32 || strlen($uniqueLink) > 100) {
    http_response_code(404);
    exit('Permit not found or link is invalid.');
}

$stmt = $db->pdo->prepare("SELECT f.*, COALESCE(ft.name, 'Permit') AS template_name FROM forms f LEFT JOIN form_templates ft ON ft.id = f.template_id WHERE f.unique_link = ? LIMIT 1");
$stmt->execute([$uniqueLink]);
$permit = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($permit) || (int)($permit['requires_approval'] ?? 1) !== 1) {
    http_response_code(404);
    exit('Permit not found or link is invalid.');
}

$canManage = $currentUser !== null && PermitAccess::canAccessPermit($currentUser, $permit);
$flash = null;
$flashType = 'success';

function phase4_redirect($app, string $uniqueLink, string $message, string $type = 'success'): void
{
    $query = http_build_query([
        'link' => $uniqueLink,
        'message' => $message,
        'type' => $type,
    ]);
    header('Location: ' . $app->url('/permit-workflow.php?' . $query));
    exit;
}

if (isset($_GET['message']) && is_scalar($_GET['message'])) {
    $flash = mb_substr(trim((string)$_GET['message']), 0, 500, 'UTF-8');
    $flashType = isset($_GET['type']) && $_GET['type'] === 'error' ? 'error' : 'success';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::validateRequest('permit-workflow', true)) {
        http_response_code(419);
        exit('Your form session expired. Refresh the page and try again.');
    }

    $action = is_scalar($_POST['action'] ?? null) ? trim((string)$_POST['action']) : '';
    try {
        if ($action === 'accept') {
            $status = strtolower((string)($permit['status'] ?? ''));
            if ($status !== PermitWorkflow::AWAITING_ACCEPTANCE) {
                throw new RuntimeException('This permit is not awaiting acceptance.');
            }
            if (!isset($_POST['accept_declaration']) || (string)$_POST['accept_declaration'] !== '1') {
                throw new RuntimeException('Confirm that you understand the permit, hazards and controls.');
            }
            $acceptedName = is_scalar($_POST['accepted_name'] ?? null) ? trim((string)$_POST['accepted_name']) : '';
            $acceptedEmail = is_scalar($_POST['accepted_email'] ?? null) ? trim((string)$_POST['accepted_email']) : '';

            $durationMinutes = null;
            if (empty($permit['valid_from']) || empty($permit['valid_to'])) {
                $duration = selectPermitDurationPreset(
                    getPermitDurationPresets($db),
                    null,
                    (string)($permit['expiry_duration'] ?? '')
                );
                $durationMinutes = $duration['minutes'] ?? null;
            }

            PermitWorkflow::accept(
                $db->pdo,
                (string)$permit['id'],
                $acceptedName,
                $acceptedEmail,
                $currentUser !== null ? (string)$currentUser['id'] : null,
                $durationMinutes
            );
            if (function_exists('logActivity')) {
                logActivity('holder_accepted', 'permit', 'form', (string)$permit['id'], 'Permit holder/receiver acceptance recorded');
            }
            phase4_redirect($app, $uniqueLink, 'Permit accepted. It is now active within its authorised validity period.');
        }

        if (!$canManage) {
            throw new RuntimeException('Sign in with the current permit holder, issuer or manager account to perform this action.');
        }

        if ($action === 'suspend') {
            $reason = is_scalar($_POST['reason'] ?? null) ? trim((string)$_POST['reason']) : '';
            PermitWorkflow::suspend($db->pdo, (string)$permit['id'], (string)$currentUser['id'], $reason);
            if (function_exists('logActivity')) {
                logActivity('permit_suspended', 'permit', 'form', (string)$permit['id'], 'Permit suspended: ' . $reason);
            }
            phase4_redirect($app, $uniqueLink, 'Permit suspended. Work must remain stopped until it is revalidated and re-accepted.');
        }

        if ($action === 'revalidate') {
            if (!$isManager) {
                throw new RuntimeException('Only a manager or administrator can revalidate a suspended permit.');
            }
            $notes = is_scalar($_POST['revalidation_notes'] ?? null) ? trim((string)$_POST['revalidation_notes']) : '';
            PermitWorkflow::revalidate(
                $db->pdo,
                (string)$permit['id'],
                (string)$currentUser['id'],
                $notes,
                isset($_POST['controls_reviewed']) && (string)$_POST['controls_reviewed'] === '1',
                isset($_POST['linked_reviewed']) && (string)$_POST['linked_reviewed'] === '1'
            );
            if (function_exists('logActivity')) {
                logActivity('permit_revalidated', 'permit', 'form', (string)$permit['id'], 'Suspended permit revalidated; holder re-acceptance required');
            }
            phase4_redirect($app, $uniqueLink, 'Permit revalidated. The holder must re-accept it before work resumes.');
        }

        if ($action === 'handover') {
            $outgoingName = is_scalar($_POST['outgoing_name'] ?? null) ? trim((string)$_POST['outgoing_name']) : '';
            $incomingName = is_scalar($_POST['incoming_name'] ?? null) ? trim((string)$_POST['incoming_name']) : '';
            $incomingEmail = is_scalar($_POST['incoming_email'] ?? null) ? strtolower(trim((string)$_POST['incoming_email'])) : '';
            $notes = is_scalar($_POST['handover_notes'] ?? null) ? trim((string)$_POST['handover_notes']) : '';

            $incomingUserId = null;
            if ($incomingEmail !== '') {
                try {
                    $userStmt = $db->pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = ? AND status = 'active' LIMIT 1");
                    $userStmt->execute([$incomingEmail]);
                    $matchedUser = $userStmt->fetchColumn();
                    if (is_string($matchedUser) && $matchedUser !== '') {
                        $incomingUserId = $matchedUser;
                    }
                } catch (Throwable $lookupError) {
                    error_log('Unable to match handover user: ' . $lookupError->getMessage());
                }
            }

            PermitWorkflow::handover(
                $db->pdo,
                (string)$permit['id'],
                (string)$currentUser['id'],
                $outgoingName,
                $incomingName,
                $incomingEmail,
                $incomingUserId,
                $notes,
                isset($_POST['safe_state']) && (string)$_POST['safe_state'] === '1',
                isset($_POST['handover_controls']) && (string)$_POST['handover_controls'] === '1',
                isset($_POST['handover_links']) && (string)$_POST['handover_links'] === '1',
                isset($_POST['incoming_ack']) && (string)$_POST['incoming_ack'] === '1'
            );
            if (function_exists('logActivity')) {
                logActivity('shift_handover', 'permit', 'form', (string)$permit['id'], 'Permit responsibility handed over to ' . $incomingName);
            }
            phase4_redirect($app, $uniqueLink, 'Shift/team handover recorded and current holder updated.');
        }

        if ($action === 'link_permit') {
            if (!$isManager) {
                throw new RuntimeException('Only a manager or administrator can manage linked permits.');
            }
            $reference = is_scalar($_POST['linked_reference'] ?? null) ? trim((string)$_POST['linked_reference']) : '';
            $relationType = is_scalar($_POST['relation_type'] ?? null) ? trim((string)$_POST['relation_type']) : '';
            $linkNote = is_scalar($_POST['link_note'] ?? null) ? trim((string)$_POST['link_note']) : '';
            $otherPermit = PermitLinks::findByReference($db->pdo, $reference);
            if ($otherPermit === null) {
                throw new RuntimeException('No permit was found with that reference.');
            }
            PermitLinks::add(
                $db->pdo,
                (string)$permit['id'],
                (string)$otherPermit['id'],
                $relationType,
                $linkNote,
                (string)$currentUser['id']
            );
            PermitWorkflow::recordEvent($db->pdo, (string)$permit['id'], 'permit_linked', (string)$currentUser['id'], [
                'linked_reference' => (string)$otherPermit['ref_number'],
                'relation_type' => $relationType,
                'note' => $linkNote,
            ]);
            phase4_redirect($app, $uniqueLink, 'Linked permit added.');
        }

        if ($action === 'unlink_permit') {
            if (!$isManager) {
                throw new RuntimeException('Only a manager or administrator can manage linked permits.');
            }
            $linkId = is_scalar($_POST['link_id'] ?? null) ? trim((string)$_POST['link_id']) : '';
            $currentLinks = PermitLinks::forPermit($db->pdo, (string)$permit['id']);
            $allowed = false;
            foreach ($currentLinks as $currentLink) {
                if (hash_equals((string)($currentLink['id'] ?? ''), $linkId)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed || !PermitLinks::remove($db->pdo, $linkId)) {
                throw new RuntimeException('That permit link could not be removed.');
            }
            PermitWorkflow::recordEvent($db->pdo, (string)$permit['id'], 'permit_unlinked', (string)$currentUser['id'], []);
            phase4_redirect($app, $uniqueLink, 'Linked permit removed.');
        }

        throw new RuntimeException('Unknown workflow action.');
    } catch (Throwable $e) {
        phase4_redirect($app, $uniqueLink, $e->getMessage(), 'error');
    }
}

// Reload after any possible transition and build the view model.
$stmt->execute([$uniqueLink]);
$permit = $stmt->fetch(PDO::FETCH_ASSOC) ?: $permit;
$canManage = $currentUser !== null && PermitAccess::canAccessPermit($currentUser, $permit);
$status = strtolower((string)($permit['status'] ?? ''));
$links = [];
$timeline = [];
try {
    $links = PermitLinks::forPermit($db->pdo, (string)$permit['id']);
    $timeline = PermitWorkflow::timeline($db->pdo, (string)$permit['id']);
} catch (Throwable $e) {
    error_log('Phase 4 workflow data unavailable: ' . $e->getMessage());
}

$statusLabels = [
    'pending_approval' => 'Pending approval',
    'awaiting_acceptance' => 'Awaiting holder acceptance',
    'active' => 'Active',
    'issued' => 'Active',
    'approved' => 'Active',
    'open' => 'Active',
    'suspended' => 'Suspended — do not work',
    'closed' => 'Closed',
    'expired' => 'Expired',
    'rejected' => 'Rejected',
    'draft' => 'Draft',
];
$statusLabel = $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));

$branding = SystemSettings::branding($db, 'Permit System');
$companyName = (string)$branding['company_name'];
$brandingCss = SystemSettings::brandingCssVariables($branding);
$loginReturn = '/permit-workflow.php?link=' . rawurlencode($uniqueLink);
$loginUrl = $app->url('/login.php?redirect=' . urlencode($loginReturn));

function phase4_date(?string $value): string
{
    if (!$value || $value === '0000-00-00 00:00:00') {
        return 'Not set';
    }
    $ts = strtotime($value);
    return $ts === false ? $value : date('d/m/Y H:i', $ts);
}

function phase4_event_label(string $type): string
{
    $labels = [
        'permit_approved' => 'Manager authorised permit',
        'holder_accepted' => 'Holder accepted permit',
        'work_started' => 'Work started',
        'permit_suspended' => 'Permit suspended',
        'permit_revalidated' => 'Permit revalidated',
        'shift_handover' => 'Shift/team handover',
        'permit_linked' => 'Permit linked',
        'permit_unlinked' => 'Permit link removed',
        'permit_closed' => 'Permit closed',
    ];
    return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
}
?>
<!doctype html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Permit workflow · <?= htmlspecialchars((string)($permit['ref_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></title>
    <?php if (function_exists('cache_meta_tags')) { cache_meta_tags(); } ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('/assets/app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        body{margin:0;background:#0f172a;color:#e5e7eb;font-family:system-ui,-apple-system,'Segoe UI',sans-serif}.shell{max-width:1100px;margin:0 auto;padding:24px 16px 72px;display:grid;gap:18px}.top{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap}.panel{background:#111827;border:1px solid #334155;border-radius:18px;padding:22px}.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.datum{background:#0b1220;border:1px solid #243244;border-radius:12px;padding:14px}.datum small{display:block;color:#94a3b8;margin-bottom:5px}.status{display:inline-flex;padding:7px 12px;border-radius:999px;font-weight:750;background:#334155}.status.active{background:#14532d;color:#bbf7d0}.status.awaiting_acceptance,.status.pending_approval{background:#713f12;color:#fef3c7}.status.suspended{background:#7f1d1d;color:#fee2e2}.alert{padding:16px;border-radius:12px;font-weight:650}.alert.error{background:#451a1a;border:1px solid #b91c1c;color:#fecaca}.alert.success{background:#052e16;border:1px solid #15803d;color:#bbf7d0}.danger{background:#7f1d1d;border:1px solid #ef4444;color:#fff}.grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.stack{display:grid;gap:12px}label{font-weight:650}input,textarea,select{width:100%;box-sizing:border-box;background:#0b1220;color:#f8fafc;border:1px solid #475569;border-radius:10px;padding:11px 12px;font:inherit}textarea{min-height:90px;resize:vertical}.check{display:flex;align-items:flex-start;gap:9px}.check input{width:auto;margin-top:4px}.btn{display:inline-flex;justify-content:center;align-items:center;gap:7px;border:1px solid transparent;border-radius:10px;padding:10px 15px;font-weight:700;text-decoration:none;cursor:pointer}.btn.primary{background:var(--brand-primary);color:var(--brand-on-primary)}.btn.secondary{background:#1e293b;color:#e2e8f0;border-color:#475569}.btn.warn{background:#b45309;color:#fff}.btn.danger{background:#b91c1c;color:#fff}.link-row{display:grid;grid-template-columns:1fr auto;gap:12px;padding:13px 0;border-bottom:1px solid #263448}.link-row:last-child{border-bottom:0}.relation{font-size:12px;font-weight:750;text-transform:uppercase;color:#cbd5e1}.conflict{color:#fca5a5}.timeline{display:grid;gap:10px}.event{border-left:3px solid #64748b;padding:8px 0 8px 14px}.event small{color:#94a3b8}.event p{margin:5px 0 0;color:#cbd5e1}.muted{color:#94a3b8}.actions-row{display:flex;gap:10px;flex-wrap:wrap}.section-title{margin:0 0 14px;font-size:20px}.hidden-details summary{cursor:pointer;font-weight:750}.form-card{background:#0b1220;border:1px solid #334155;border-radius:14px;padding:16px}.form-card h3{margin:0 0 12px}.form-card form{display:grid;gap:11px}@media(max-width:760px){.grid2{grid-template-columns:1fr}.panel{padding:18px 14px}.btn{width:100%}.link-row{grid-template-columns:1fr}.top{align-items:stretch}}
    </style>
</head>
<body>
<div class="shell">
    <div class="top">
        <div>
            <div class="muted"><?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?> · Permit control</div>
            <h1 style="margin:4px 0 0;font-size:26px;"><?= htmlspecialchars((string)$permit['template_name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <div class="muted">#<?= htmlspecialchars((string)$permit['ref_number'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="actions-row">
            <a class="btn secondary" href="<?= htmlspecialchars($app->url('/view-permit-public.php?link=' . rawurlencode($uniqueLink)), ENT_QUOTES, 'UTF-8') ?>">View permit</a>
            <a class="btn secondary" href="<?= htmlspecialchars($app->url('/'), ENT_QUOTES, 'UTF-8') ?>">Home</a>
        </div>
    </div>

    <?php if ($flash !== null && $flash !== ''): ?>
        <div class="alert <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($status === 'suspended'): ?>
        <div class="alert danger">⛔ SUSPENDED — WORK MUST NOT CONTINUE. A manager must revalidate the permit and the holder must re-accept it before work resumes.</div>
    <?php elseif ($status === 'awaiting_acceptance'): ?>
        <div class="alert" style="background:#422006;border:1px solid #d97706;color:#fde68a;">⚠️ Manager authorisation is complete, but this permit is <strong>not active yet</strong>. The current holder/receiver must accept the permit below before work starts or resumes.</div>
    <?php endif; ?>

    <section class="panel">
        <div class="summary">
            <div class="datum"><small>Status</small><span class="status <?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="datum"><small>Current holder</small><?= htmlspecialchars((string)($permit['holder_name'] ?? 'Not recorded'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="datum"><small>Valid from</small><?= htmlspecialchars(phase4_date($permit['valid_from'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="datum"><small>Valid until</small><?= htmlspecialchars(phase4_date($permit['valid_to'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="datum"><small>Work started</small><?= htmlspecialchars(phase4_date($permit['work_started_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </section>

    <?php if ($status === 'awaiting_acceptance'): ?>
    <section class="panel" id="accept">
        <h2 class="section-title">Holder / receiver acceptance</h2>
        <p class="muted">Review the permit itself first. Acceptance confirms that the current holder understands the work, hazards, controls and limitations.</p>
        <form method="post" class="stack" style="max-width:650px;">
            <?= Csrf::getFormField('permit-workflow') ?>
            <input type="hidden" name="action" value="accept">
            <label>Your name<input type="text" name="accepted_name" required maxlength="255" value="<?= htmlspecialchars((string)($permit['holder_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label>Your permit email<input type="email" name="accepted_email" required maxlength="255" value="<?= htmlspecialchars((string)($permit['holder_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
            <label class="check"><input type="checkbox" name="accept_declaration" value="1" required><span>I have reviewed this permit, understand the hazards and stated controls, and accept responsibility for complying with the permit conditions. I will stop work if conditions change or the permit is suspended.</span></label>
            <button class="btn primary" type="submit">Accept permit and make active</button>
        </form>
    </section>
    <?php endif; ?>

    <div class="grid2">
        <section class="panel">
            <h2 class="section-title">Linked permits / SIMOPS</h2>
            <?php if ($links === []): ?>
                <p class="muted">No related permits are currently linked.</p>
            <?php else: ?>
                <?php foreach ($links as $link): ?>
                    <div class="link-row">
                        <div>
                            <div class="relation <?= ($link['relation_type'] ?? '') === 'conflict' ? 'conflict' : '' ?>"><?= htmlspecialchars((string)$link['relation_label'], ENT_QUOTES, 'UTF-8') ?></div>
                            <strong>#<?= htmlspecialchars((string)($link['ref_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)($link['template_name'] ?? 'Permit'), ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="muted">Status: <?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($link['status'] ?? ''))), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($link['note'])): ?> · <?= htmlspecialchars((string)$link['note'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></div>
                        </div>
                        <?php if ($isManager): ?>
                        <form method="post">
                            <?= Csrf::getFormField('permit-workflow') ?>
                            <input type="hidden" name="action" value="unlink_permit">
                            <input type="hidden" name="link_id" value="<?= htmlspecialchars((string)$link['id'], ENT_QUOTES, 'UTF-8') ?>">
                            <button class="btn secondary" type="submit">Unlink</button>
                        </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($isManager): ?>
            <details class="hidden-details" style="margin-top:16px;">
                <summary>Link another permit</summary>
                <form method="post" class="stack" style="margin-top:14px;">
                    <?= Csrf::getFormField('permit-workflow') ?>
                    <input type="hidden" name="action" value="link_permit">
                    <label>Permit reference<input type="text" name="linked_reference" placeholder="PTW-2026-..." required maxlength="50"></label>
                    <label>Relationship<select name="relation_type" required><?php foreach (PermitLinks::RELATION_TYPES as $value => $label): ?><option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
                    <label>Coordination note<textarea name="link_note" maxlength="2000" placeholder="Interfaces, sequencing, isolation dependency or conflict controls"></textarea></label>
                    <button class="btn primary" type="submit">Add linked permit</button>
                </form>
            </details>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2 class="section-title">Permit timeline</h2>
            <div class="timeline">
            <?php if ($timeline === []): ?>
                <p class="muted">No Phase 4 lifecycle events have been recorded yet. Older permit activity remains available in the normal audit log.</p>
            <?php else: ?>
                <?php foreach ($timeline as $event): $payload = is_array($event['payload'] ?? null) ? $event['payload'] : []; ?>
                <div class="event">
                    <strong><?= htmlspecialchars(phase4_event_label((string)$event['type']), ENT_QUOTES, 'UTF-8') ?></strong>
                    <small> · <?= htmlspecialchars(phase4_date((string)($event['at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></small>
                    <?php if (!empty($payload['reason'])): ?><p><?= htmlspecialchars((string)$payload['reason'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    <?php if (!empty($payload['notes'])): ?><p><?= htmlspecialchars((string)$payload['notes'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                    <?php if (!empty($payload['incoming_name'])): ?><p>Handover to <?= htmlspecialchars((string)$payload['incoming_name'], ENT_QUOTES, 'UTF-8') ?>.</p><?php endif; ?>
                    <?php if (!empty($payload['linked_reference'])): ?><p>Linked #<?= htmlspecialchars((string)$payload['linked_reference'], ENT_QUOTES, 'UTF-8') ?>.</p><?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </section>
    </div>

    <?php if ($canManage): ?>
    <section class="panel">
        <h2 class="section-title">Operational controls</h2>
        <div class="grid2">
            <?php if (in_array($status, ['active', 'issued', 'approved', 'open'], true)): ?>
            <div class="form-card">
                <h3>⏸ Suspend permit</h3>
                <p class="muted">Use when conditions, interfaces, controls or the job situation change. Work must stop.</p>
                <form method="post">
                    <?= Csrf::getFormField('permit-workflow') ?>
                    <input type="hidden" name="action" value="suspend">
                    <label>Reason<textarea name="reason" required minlength="5" maxlength="2000" placeholder="What changed and why work must stop"></textarea></label>
                    <button class="btn warn" type="submit">Suspend permit / stop work</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($status === 'suspended' && $isManager): ?>
            <div class="form-card">
                <h3>🔄 Revalidate permit</h3>
                <p class="muted">Revalidation cannot extend the original expiry time. If the permit has expired, raise a new permit.</p>
                <form method="post">
                    <?= Csrf::getFormField('permit-workflow') ?>
                    <input type="hidden" name="action" value="revalidate">
                    <label>Revalidation notes<textarea name="revalidation_notes" maxlength="3000" placeholder="Changed conditions, checks completed and controls re-established"></textarea></label>
                    <label class="check"><input type="checkbox" name="controls_reviewed" value="1" required><span>Permit conditions, hazards, isolations and controls have been reviewed and remain suitable.</span></label>
                    <label class="check"><input type="checkbox" name="linked_reviewed" value="1" required><span>Linked permits, dependencies and simultaneous operations have been checked.</span></label>
                    <button class="btn primary" type="submit">Revalidate — require holder re-acceptance</button>
                </form>
            </div>
            <?php endif; ?>

            <?php if (in_array($status, ['active', 'suspended'], true)): ?>
            <div class="form-card">
                <h3>🤝 Shift / team handover</h3>
                <p class="muted">Record the outgoing and incoming people, current work state and a two-way review of permit conditions.</p>
                <form method="post">
                    <?= Csrf::getFormField('permit-workflow') ?>
                    <input type="hidden" name="action" value="handover">
                    <label>Outgoing holder<input type="text" name="outgoing_name" required maxlength="255" value="<?= htmlspecialchars((string)($permit['holder_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                    <label>Incoming holder<input type="text" name="incoming_name" required maxlength="255"></label>
                    <label>Incoming email<input type="email" name="incoming_email" required maxlength="255"></label>
                    <label>Handover notes<textarea name="handover_notes" maxlength="5000" placeholder="Work completed, work outstanding, isolations, abnormal conditions and key precautions"></textarea></label>
                    <label class="check"><input type="checkbox" name="safe_state" value="1" required><span>Current work/plant condition and safe state were confirmed.</span></label>
                    <label class="check"><input type="checkbox" name="handover_controls" value="1" required><span>Permit hazards, controls and outstanding work were reviewed together.</span></label>
                    <label class="check"><input type="checkbox" name="handover_links" value="1" required><span>Linked permits / SIMOPS / dependencies were reviewed together.</span></label>
                    <label class="check"><input type="checkbox" name="incoming_ack" value="1" required><span>The incoming holder confirms understanding and accepts responsibility for the handover.</span></label>
                    <button class="btn primary" type="submit">Record handover</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </section>
    <?php elseif ($currentUser === null && $status !== 'awaiting_acceptance'): ?>
        <section class="panel"><p class="muted">Team lifecycle actions require sign-in.</p><a class="btn primary" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Team sign in</a></section>
    <?php endif; ?>
</div>
</body>
</html>
