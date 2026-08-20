<?php
declare(strict_types=1);

use Permits\PermitAccess;
use Permits\SystemSettings;

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/Auth.php';

$auth = new Auth($db);
$auth->requireLogin();
$currentUser = $auth->getCurrentUser();
if (!is_array($currentUser)) {
    header('Location: ' . $app->url('/login.php'));
    exit;
}

$scope = PermitAccess::sqlScope($currentUser, 'f', 'board');
$scopeSql = $scope['sql'];
$scopeParams = $scope['params'];
$canViewAll = PermitAccess::canViewAll($currentUser);
$recentExpiredSince = date('Y-m-d H:i:s', time() - 86400);

$rows = [];
try {
    $stmt = $db->pdo->prepare("
        SELECT
            f.id,
            f.ref_number,
            f.status,
            f.holder_name,
            f.holder_email,
            f.site_block,
            f.form_data,
            f.valid_from,
            f.valid_to,
            f.work_started_at,
            f.created_at,
            f.unique_link,
            COALESCE(ft.name, 'Permit') AS template_name,
            (SELECT COUNT(*) FROM permit_links pl WHERE pl.form_a_id = f.id OR pl.form_b_id = f.id) AS linked_count,
            (SELECT COUNT(*) FROM permit_links pl WHERE (pl.form_a_id = f.id OR pl.form_b_id = f.id) AND pl.relation_type = 'conflict') AS conflict_count
        FROM forms f
        LEFT JOIN form_templates ft ON ft.id = f.template_id
        WHERE {$scopeSql}
          AND f.requires_approval = 1
          AND (
            LOWER(f.status) IN ('pending_approval','awaiting_acceptance','active','issued','approved','open','suspended')
            OR (LOWER(f.status) = 'expired' AND f.valid_to IS NOT NULL AND f.valid_to >= :recent_expired_since)
          )
        ORDER BY COALESCE(f.valid_to, f.valid_from, f.created_at) DESC
        LIMIT 300
    ");
    $stmt->execute(array_merge($scopeParams, ['recent_expired_since' => $recentExpiredSince]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Permit board load failed: ' . $e->getMessage());
}

function board_location(array $row): string
{
    $siteBlock = trim((string)($row['site_block'] ?? ''));
    if ($siteBlock !== '') return mb_substr($siteBlock, 0, 150, 'UTF-8');
    $data = json_decode((string)($row['form_data'] ?? ''), true);
    if (!is_array($data)) return '';
    foreach (['location','exactWorkLocation','workLocation','exactLocation','siteLocation','siteBlock','area','siteProject'] as $key) {
        if (!isset($data[$key]) || !is_scalar($data[$key])) continue;
        $value = trim((string)$data[$key]);
        if ($value !== '') return mb_substr($value, 0, 150, 'UTF-8');
    }
    return '';
}

function board_date(?string $value): string
{
    if (!$value || $value === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($value);
    return $ts === false ? $value : date('d/m/Y H:i', $ts);
}

function board_bucket(array $row): string
{
    $status = strtolower((string)($row['status'] ?? ''));
    $validTo = !empty($row['valid_to']) ? strtotime((string)$row['valid_to']) : false;
    if ($status === 'expired' || ($validTo !== false && $validTo <= time() && in_array($status, ['active','issued','approved','open','suspended','awaiting_acceptance'], true))) return 'expired';
    if ($status === 'suspended') return 'suspended';
    if ($status === 'pending_approval') return 'pending';
    if ($status === 'awaiting_acceptance') return 'acceptance';
    return 'active';
}

$groups = ['expired' => [], 'suspended' => [], 'pending' => [], 'acceptance' => [], 'active' => []];
foreach ($rows as $row) {
    $groups[board_bucket($row)][] = $row;
}

$branding = SystemSettings::branding($db, 'Permit System');
$brandingCss = SystemSettings::brandingCssVariables($branding);
$companyName = (string)$branding['company_name'];
?>
<!doctype html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Permit Board · <?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></title>
<?php if (function_exists('cache_meta_tags')) { cache_meta_tags(); } ?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset('/assets/app.css'), ENT_QUOTES, 'UTF-8') ?>">
<style>
body{margin:0;background:#0f172a;color:#e5e7eb;font-family:system-ui,-apple-system,'Segoe UI',sans-serif}.shell{max-width:1600px;margin:0 auto;padding:24px 18px 80px}.top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:20px}.top h1{margin:3px 0 5px}.muted{color:#94a3b8}.actions{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-flex;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:700;background:#1e293b;color:#e2e8f0;border:1px solid #475569}.btn.primary{background:var(--brand-primary);color:var(--brand-on-primary);border-color:transparent}.stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}.stat{background:#111827;border:1px solid #334155;border-radius:15px;padding:15px}.stat strong{display:block;font-size:30px}.stat.expired{border-color:#ef4444;background:#450a0a}.stat.suspended{border-color:#f97316;background:#431407}.stat.acceptance{border-color:#f59e0b}.tools{display:flex;gap:10px;align-items:center;margin:0 0 20px;flex-wrap:wrap}.search{flex:1 1 300px;background:#111827;color:#f8fafc;border:1px solid #475569;border-radius:11px;padding:12px 14px;font-size:16px}.board{display:grid;grid-template-columns:repeat(5,minmax(240px,1fr));gap:14px;align-items:start}.lane{background:#111827;border:1px solid #334155;border-radius:17px;overflow:hidden}.lane__head{padding:15px 16px;border-bottom:1px solid #334155}.lane__head h2{font-size:18px;margin:0}.lane__head p{margin:5px 0 0;font-size:13px;color:#94a3b8}.lane.expired{border-color:#dc2626}.lane.expired .lane__head{background:#450a0a}.lane.suspended{border-color:#c2410c}.lane.suspended .lane__head{background:#431407}.lane.acceptance .lane__head{background:#422006}.cards{padding:12px;display:grid;gap:11px}.card{background:#0b1220;border:1px solid #293548;border-radius:13px;padding:14px;display:grid;gap:9px}.card[data-hidden="true"]{display:none}.card.expired{border:2px solid #ef4444;background:#2a0d0d}.card.suspended{border-color:#f97316}.card__top{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}.card h3{font-size:16px;margin:0}.ref{font-size:12px;color:#94a3b8;margin-top:3px}.badge{font-size:11px;border-radius:999px;padding:5px 8px;font-weight:800;white-space:nowrap;background:#334155}.badge.red{background:#7f1d1d;color:#fecaca}.badge.orange{background:#7c2d12;color:#fed7aa}.badge.amber{background:#713f12;color:#fde68a}.badge.green{background:#14532d;color:#bbf7d0}.detail{font-size:13px;color:#cbd5e1}.detail strong{color:#f8fafc}.stop{padding:8px 10px;border-radius:8px;background:#7f1d1d;color:#fff;font-size:12px;font-weight:900}.flags{display:flex;gap:6px;flex-wrap:wrap}.flag{font-size:11px;padding:4px 7px;border-radius:7px;background:#1e293b;color:#cbd5e1}.flag.conflict{background:#7f1d1d;color:#fecaca}.card__actions{display:flex;gap:7px;flex-wrap:wrap}.card__actions a{font-size:12px;padding:7px 9px;border-radius:8px;text-decoration:none;background:#1e293b;color:#e2e8f0;border:1px solid #475569}.card__actions a.workflow{background:var(--brand-primary);color:var(--brand-on-primary);border-color:transparent}.empty{padding:16px;color:#64748b;text-align:center;font-size:13px}@media(max-width:1350px){.board{grid-template-columns:repeat(2,minmax(280px,1fr))}.stats{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:700px){.stats{grid-template-columns:1fr}.board{grid-template-columns:1fr}.actions,.actions .btn{width:100%}.btn{justify-content:center}.shell{padding:16px 12px 60px}}
</style>
</head>
<body>
<div class="shell">
<header class="top">
<div><div class="muted"><?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></div><h1>Operational Permit Board</h1><div class="muted"><?= $canViewAll ? 'System-wide permit control' : 'Your permits' ?> · recently expired permits remain visible for 24 hours</div></div>
<div class="actions"><a class="btn primary" href="<?= htmlspecialchars($app->url('/'), ENT_QUOTES, 'UTF-8') ?>">Raise a permit</a><a class="btn" href="<?= htmlspecialchars($app->url('/dashboard.php'), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a></div>
</header>

<section class="stats" aria-label="Permit board totals">
<div class="stat expired"><span class="muted">Expired last 24h</span><strong><?= count($groups['expired']) ?></strong></div>
<div class="stat suspended"><span class="muted">Suspended</span><strong><?= count($groups['suspended']) ?></strong></div>
<div class="stat"><span class="muted">Pending approval</span><strong><?= count($groups['pending']) ?></strong></div>
<div class="stat acceptance"><span class="muted">Awaiting acceptance</span><strong><?= count($groups['acceptance']) ?></strong></div>
<div class="stat"><span class="muted">Active</span><strong><?= count($groups['active']) ?></strong></div>
</section>

<div class="tools"><label class="sr-only" for="permitBoardSearch">Search permits</label><input id="permitBoardSearch" class="search" type="search" placeholder="Search reference, type, holder or area"><button class="btn" type="button" onclick="window.location.reload()">Refresh board</button></div>

<div class="board">
<?php
$laneMeta = [
 'expired' => ['title'=>'⛔ Recently expired','help'=>'Expired within 24 hours. Do not work under these permits.','badge'=>'red'],
 'suspended' => ['title'=>'⏸ Suspended','help'=>'Work stopped. Revalidate before resuming.','badge'=>'orange'],
 'pending' => ['title'=>'⏳ Pending approval','help'=>'Submitted and awaiting manager decision.','badge'=>'amber'],
 'acceptance' => ['title'=>'✋ Awaiting acceptance','help'=>'Approved by management; holder must accept.','badge'=>'amber'],
 'active' => ['title'=>'✅ Active','help'=>'Authorised and accepted within validity.','badge'=>'green'],
];
foreach ($laneMeta as $key => $meta): ?>
<section class="lane <?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" data-lane="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
<header class="lane__head"><h2><?= htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8') ?> <span data-lane-count>(<?= count($groups[$key]) ?>)</span></h2><p><?= htmlspecialchars($meta['help'], ENT_QUOTES, 'UTF-8') ?></p></header>
<div class="cards">
<?php if ($groups[$key] === []): ?><div class="empty" data-empty>No permits in this state.</div><?php endif; ?>
<?php foreach ($groups[$key] as $permit):
  $location = board_location($permit);
  $searchText = strtolower(implode(' ', [(string)$permit['ref_number'],(string)$permit['template_name'],(string)$permit['holder_name'],$location,(string)$permit['status']]));
?>
<article class="card <?= $key === 'expired' ? 'expired' : ($key === 'suspended' ? 'suspended' : '') ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
<div class="card__top"><div><h3><?= htmlspecialchars((string)$permit['template_name'], ENT_QUOTES, 'UTF-8') ?></h3><div class="ref">#<?= htmlspecialchars((string)$permit['ref_number'], ENT_QUOTES, 'UTF-8') ?></div></div><span class="badge <?= htmlspecialchars($meta['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= $key === 'expired' ? 'EXPIRED' : htmlspecialchars(ucfirst(str_replace('_',' ',(string)$permit['status'])), ENT_QUOTES, 'UTF-8') ?></span></div>
<?php if ($key === 'expired'): ?><div class="stop">STOP WORK — permit validity has ended</div><?php endif; ?>
<div class="detail"><strong>Holder:</strong> <?= htmlspecialchars((string)($permit['holder_name'] ?: 'Not recorded'), ENT_QUOTES, 'UTF-8') ?></div>
<?php if ($location !== ''): ?><div class="detail"><strong>Area:</strong> <?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if (!empty($permit['valid_to'])): ?><div class="detail"><strong><?= $key === 'expired' ? 'Expired:' : 'Valid until:' ?></strong> <?= htmlspecialchars(board_date((string)$permit['valid_to']), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if (!empty($permit['work_started_at'])): ?><div class="detail"><strong>Work started:</strong> <?= htmlspecialchars(board_date((string)$permit['work_started_at']), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<div class="flags"><?php if ((int)$permit['linked_count'] > 0): ?><span class="flag">🔗 <?= (int)$permit['linked_count'] ?> linked</span><?php endif; ?><?php if ((int)$permit['conflict_count'] > 0): ?><span class="flag conflict">⚠ <?= (int)$permit['conflict_count'] ?> conflict link<?= (int)$permit['conflict_count'] === 1 ? '' : 's' ?></span><?php endif; ?></div>
<div class="card__actions"><?php if (!empty($permit['unique_link'])): ?><a href="<?= htmlspecialchars($app->url('/view-permit-public.php?link=' . rawurlencode((string)$permit['unique_link'])), ENT_QUOTES, 'UTF-8') ?>">View</a><?php if ($key !== 'expired'): ?><a class="workflow" href="<?= htmlspecialchars($app->url('/permit-workflow.php?link=' . rawurlencode((string)$permit['unique_link'])), ENT_QUOTES, 'UTF-8') ?>">Control / handover</a><?php endif; ?><?php endif; ?></div>
</article>
<?php endforeach; ?>
</div></section>
<?php endforeach; ?>
</div>
</div>
<script>
(() => {
 const input=document.getElementById('permitBoardSearch'); if(!input)return;
 const update=()=>{const term=input.value.toLowerCase().trim();document.querySelectorAll('[data-lane]').forEach(lane=>{let visible=0;lane.querySelectorAll('.card[data-search]').forEach(card=>{const show=!term||card.dataset.search.includes(term);card.dataset.hidden=show?'false':'true';if(show)visible++;});const count=lane.querySelector('[data-lane-count]');if(count)count.textContent=`(${visible})`;const empty=lane.querySelector('[data-empty]');if(empty)empty.style.display=visible===0?'block':'none';});}; input.addEventListener('input',update);
})();
</script>
</body>
</html>
