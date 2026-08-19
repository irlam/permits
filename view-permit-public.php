<?php
declare(strict_types=1);

/**
 * Phase 4 wrapper around the established permit detail renderer.
 *
 * The legacy renderer is kept byte-for-byte so the mature permit display,
 * approval, QR, printing and attachment behaviour remain stable. This wrapper
 * adds lifecycle-critical banners, linked-permit visibility and the workflow
 * control entry point without rewriting historical display logic.
 */
ob_start();
require __DIR__ . '/view-permit-public-legacy.php';
$html = (string)ob_get_clean();

if (!isset($permit) || !is_array($permit) || !isset($app, $unique_link)) {
    echo $html;
    return;
}

$status = strtolower((string)($permitStatus ?? ($permit['status'] ?? '')));
$workflowUrl = $app->url('/permit-workflow.php?link=' . rawurlencode((string)$unique_link));
$escWorkflowUrl = htmlspecialchars($workflowUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Make new lifecycle states visually unambiguous in the existing badge area.
$html = str_replace(
    '<span class="badge badge-gray">awaiting_acceptance</span>',
    '<span class="badge badge-warning">✋ Awaiting Holder Acceptance</span>',
    $html
);
$html = str_replace(
    '<span class="badge badge-gray">suspended</span>',
    '<span class="badge badge-danger">⛔ SUSPENDED</span>',
    $html
);

$panel = '';
if ($status === 'awaiting_acceptance') {
    $panel .= '<div class="no-print" style="margin:0 0 24px;padding:20px;border:3px solid #d97706;border-radius:12px;background:#fffbeb;color:#78350f;">'
        . '<div style="font-size:20px;font-weight:800;margin-bottom:8px;">⚠️ APPROVED — HOLDER ACCEPTANCE STILL REQUIRED</div>'
        . '<div style="line-height:1.55;">This permit is <strong>not active yet</strong>. Do not start or resume work until the current permit holder/receiver has reviewed and accepted the permit conditions.</div>'
        . '<a href="' . $escWorkflowUrl . '#accept" class="btn btn-primary" style="margin-top:14px;">Review &amp; Accept Permit</a>'
        . '</div>';
} elseif ($status === 'suspended') {
    $panel .= '<div class="no-print" role="alert" style="margin:0 0 24px;padding:22px;border:4px solid #dc2626;border-radius:12px;background:#450a0a;color:#fff;box-shadow:0 0 0 4px rgba(220,38,38,.18);">'
        . '<div style="font-size:25px;font-weight:900;letter-spacing:.02em;margin-bottom:8px;">⛔ SUSPENDED — DO NOT WORK</div>'
        . '<div style="font-size:16px;line-height:1.55;color:#fecaca;">Work must remain stopped. A manager must revalidate the permit and the holder must re-accept it before work can resume. Revalidation cannot extend the original expiry time.</div>'
        . '<a href="' . $escWorkflowUrl . '" class="btn" style="margin-top:14px;background:#fff;color:#991b1b;border:2px solid #fff;">Open Permit Controls</a>'
        . '</div>';
}

// Surface linked permits, SIMOPS, isolation dependencies and explicit conflicts
// on the verification page without exposing private holder information.
try {
    $linkedPermits = \Permits\PermitLinks::forPermit($db->pdo, (string)$permit['id']);
} catch (\Throwable $e) {
    $linkedPermits = [];
    error_log('Unable to load Phase 4 linked permits on public view: ' . $e->getMessage());
}

if ($linkedPermits !== []) {
    $hasConflict = false;
    $items = '';
    foreach ($linkedPermits as $linked) {
        $relationType = (string)($linked['relation_type'] ?? 'related');
        if ($relationType === 'conflict') $hasConflict = true;
        $relation = htmlspecialchars((string)($linked['relation_label'] ?? $relationType), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ref = htmlspecialchars((string)($linked['ref_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $type = htmlspecialchars((string)($linked['template_name'] ?? 'Permit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linkedStatus = htmlspecialchars(ucfirst(str_replace('_', ' ', (string)($linked['status'] ?? ''))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $note = trim((string)($linked['note'] ?? ''));
        $noteHtml = $note !== '' ? '<div style="margin-top:4px;color:#4b5563;">' . htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>' : '';
        $accent = $relationType === 'conflict' ? '#dc2626' : ($relationType === 'isolation_dependency' ? '#d97706' : '#2563eb');
        $items .= '<div style="padding:12px;border-left:4px solid ' . $accent . ';background:#f8fafc;border-radius:7px;">'
            . '<div style="font-size:12px;text-transform:uppercase;font-weight:800;color:' . $accent . ';">' . $relation . '</div>'
            . '<div style="font-weight:700;margin-top:3px;">#' . $ref . ' · ' . $type . '</div>'
            . '<div style="font-size:13px;color:#6b7280;">Status: ' . $linkedStatus . '</div>' . $noteHtml . '</div>';
    }
    $heading = $hasConflict ? '⚠️ Linked permits / SIMOPS — conflict relationship recorded' : '🔗 Linked permits / SIMOPS';
    $border = $hasConflict ? '#dc2626' : '#cbd5e1';
    $panel .= '<div class="section" style="border:2px solid ' . $border . ';border-radius:12px;padding:18px;margin-bottom:26px;">'
        . '<h2 class="section-title" style="margin-top:0;">' . $heading . '</h2>'
        . '<div style="display:grid;gap:9px;">' . $items . '</div>'
        . '<div class="no-print" style="margin-top:12px;"><a class="btn btn-secondary" href="' . $escWorkflowUrl . '">Open workflow &amp; linked-permit controls</a></div>'
        . '</div>';
}

if ($panel !== '') {
    $marker = '<!-- Permit Details -->';
    $html = str_replace($marker, $panel . "\n            " . $marker, $html);
}

// Every authorised viewer gets an obvious route to lifecycle/handover controls.
$workflowButton = '<a href="' . $escWorkflowUrl . '" class="btn btn-secondary">🔄 Workflow / Handover</a>';
$html = str_replace(
    '<div class="actions no-print">',
    '<div class="actions no-print">' . $workflowButton,
    $html
);

// Suspended permits can be formally closed without being reactivated first.
if ($status === 'suspended' && !empty($canClose)) {
    $suspendedClose = '<button type="button" id="close-permit-btn" onclick="closePermit()" class="btn btn-danger no-print" style="background:#ef4444;color:#fff;">🔒 Close Suspended Permit</button>';
    $html = str_replace(
        '<div class="actions no-print">' . $workflowButton,
        '<div class="actions no-print">' . $workflowButton . $suspendedClose,
        $html
    );
}

echo $html;
