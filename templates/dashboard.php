<?php
/**
 * Dashboard Template (clickable stat cards filter)
 * Path: /templates/dashboard.php
 * Last Modified: 26/10/2025
 */

require_once __DIR__ . '/../src/cache-helper.php';
require_once __DIR__ . '/../src/SystemSettings.php';
require_once __DIR__ . '/../src/Auth.php';
use Permits\SystemSettings;
$auth = new Auth($db);

/** Status filter from query ?status=pending|active|issued|expired|draft|closed */
$validStatuses = ['draft','pending','issued','active','expired','closed'];
$statusFilter  = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : 'all';
if ($statusFilter !== 'all' && !in_array($statusFilter, $validStatuses, true)) {
  $statusFilter = 'all';
}

/** Build self URL safely (so links always come back here) */
$self = strtok($_SERVER['REQUEST_URI'] ?? '/templates/dashboard.php', '?');
$self = $self ?: '/templates/dashboard.php';

/** Stats */
$stats = [];
$stats['total']   = (int)$db->pdo->query("SELECT COUNT(*) FROM forms")->fetchColumn();
foreach (['draft','pending','issued','active','expired','closed'] as $s) {
  $stats[$s] = (int)$db->pdo->query("SELECT COUNT(*) FROM forms WHERE status=".$db->pdo->quote($s))->fetchColumn();
}

/** Data for lists (only fetch filtered list if a filter is active) */
$filteredPermits = [];
if ($statusFilter !== 'all') {
  $stmt = $db->pdo->prepare("
    SELECT id, ref_number, template_id, site_block, valid_to, status, updated_at
    FROM forms
    WHERE status = ?
    ORDER BY updated_at DESC
    LIMIT 100
  ");
  $stmt->execute([$statusFilter]);
  $filteredPermits = $stmt->fetchAll();
} else {
  // Light defaults when no filter (keep dashboard feel)
  $now   = date('Y-m-d H:i:s');
  $soon  = date('Y-m-d H:i:s', strtotime('+7 days'));

  $expiringSoonList = $db->pdo->prepare("
    SELECT id, ref_number, template_id, site_block, valid_to, status
    FROM forms
    WHERE status IN ('issued','active') AND valid_to BETWEEN ? AND ?
    ORDER BY valid_to ASC
    LIMIT 10
  ");
  $expiringSoonList->execute([$now, $soon]);
  $expiringSoonList = $expiringSoonList->fetchAll();

  $recentActivity = $db->pdo->query("
    SELECT id, ref_number, template_id, status, updated_at
    FROM forms
    ORDER BY updated_at DESC
    LIMIT 10
  ")->fetchAll();
}

/** Helpers */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function cardHref($self, $status){ return $status === 'all' ? $self : $self.'?status='.$status; }
function isActive($current, $status){ return ($current === $status) ? ' active' : ''; }
function badgeClass($status){
  $m = [
    'active'=>'badge-active','issued'=>'badge-issued','expired'=>'badge-expired',
    'draft'=>'badge-draft','pending'=>'badge-pending','closed'=>'badge-closed'
  ];
  return $m[strtolower($status)] ?? 'badge-draft';
}

$companyName = SystemSettings::companyName($db) ?? 'Permits System';
$companyLogoPath = SystemSettings::companyLogoPath($db);
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php cache_meta_tags(); ?>
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0ea5e9">
  <title>Dashboard - Permits System</title>
  <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
  <style>
    .dashboard-wrap{max-width:1400px;margin:0 auto;padding:16px}
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
    .stat-link{text-decoration:none}
    .stat-card{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:20px;transition:.2s}
    .stat-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(0,0,0,.25)}
    .stat-card.active{outline:2px solid #3b82f6;box-shadow:0 0 0 4px rgba(59,130,246,.25)}
    .stat-label{font-size:14px;color:#94a3b8;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}
    .stat-value{font-size:36px;font-weight:700;color:#e5e7eb}
    .stat-icon{font-size:24px;margin-bottom:8px;opacity:.8}
    .lists-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(450px,1fr));gap:16px}
    .list-card{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:20px}
    .list-title{font-size:18px;font-weight:600;color:#e5e7eb;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center}
    .list-item{padding:12px;background:#0a101a;border-radius:8px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center}
    .list-item:hover{background:#1f2937}
    .item-info{flex:1}
    .item-ref{font-weight:600;color:#e5e7eb}
    .item-meta{font-size:12px;color:#94a3b8;margin-top:4px}
    .item-badge{padding:4px 8px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase}
    .badge-active{background:#10b981;color:#fff}
    .badge-issued{background:#3b82f6;color:#fff}
    .badge-expired{background:#ef4444;color:#fff}
    .badge-draft{background:#6b7280;color:#fff}
    .badge-pending{background:#f59e0b;color:#fff}
    .badge-closed{background:#6b7280;color:#fff}
    .btn-clear{background:#0b1220;border:1px solid #1f2937;padding:6px 10px;border-radius:8px;color:#e5e7eb;font-size:12px;text-decoration:none}
    @media (max-width:768px){ .lists-grid{grid-template-columns:1fr} }
  </style>
</head>
<body>
<header class="top">
  <div class="brand-mark">
    <?php if ($companyLogoUrl): ?>
      <img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($companyName) ?> logo" class="brand-mark__logo">
    <?php endif; ?>
    <div>
      <div class="brand-mark__name"><?= htmlspecialchars($companyName) ?></div>
      <div class="brand-mark__sub">📊 Dashboard</div>
    </div>
  </div>
  <div class="top-actions">
    <?php if($auth->isLoggedIn() && $auth->hasRole('admin')): ?>
      <a class="btn" href="/admin.php" style="background:#f59e0b">⚙️ Admin Panel</a>
    <?php endif; ?>
    <a class="btn" href="/">View All Permits</a>
    <?php if($auth->isLoggedIn()): ?>
      <a class="btn" href="/logout.php">🚪 Logout</a>
    <?php else: ?>
      <a class="btn" href="/login.php">🔐 Login</a>
    <?php endif; ?>
  </div>
</header>

<div class="dashboard-wrap">
  <!-- Stat Cards (click to filter) -->
  <div class="stats-grid">
    <a class="stat-link" href="<?=h(cardHref($self,'all'))?>">
      <div class="stat-card<?=isActive($statusFilter,'all')?>">
        <div class="stat-icon">📋</div>
        <div class="stat-label">Total Permits</div>
        <div class="stat-value"><?=number_format($stats['total'])?></div>
      </div>
    </a>

    <a class="stat-link" href="<?=h(cardHref($self,'pending'))?>">
      <div class="stat-card<?=isActive($statusFilter,'pending')?>">
        <div class="stat-icon">⏳</div>
        <div class="stat-label">Pending</div>
        <div class="stat-value" style="color:#f59e0b"><?=number_format($stats['pending'])?></div>
      </div>
    </a>

    <a class="stat-link" href="<?=h(cardHref($self,'active'))?>">
      <div class="stat-card<?=isActive($statusFilter,'active')?>">
        <div class="stat-icon">✅</div>
        <div class="stat-label">Active</div>
        <div class="stat-value" style="color:#10b981"><?=number_format($stats['active'])?></div>
      </div>
    </a>

    <a class="stat-link" href="<?=h(cardHref($self,'issued'))?>">
      <div class="stat-card<?=isActive($statusFilter,'issued')?>">
        <div class="stat-icon">📄</div>
        <div class="stat-label">Issued</div>
        <div class="stat-value" style="color:#3b82f6"><?=number_format($stats['issued'])?></div>
      </div>
    </a>

    <a class="stat-link" href="<?=h(cardHref($self,'expired'))?>">
      <div class="stat-card<?=isActive($statusFilter,'expired')?>">
        <div class="stat-icon">❌</div>
        <div class="stat-label">Expired</div>
        <div class="stat-value" style="color:#ef4444"><?=number_format($stats['expired'])?></div>
      </div>
    </a>

    <a class="stat-link" href="<?=h(cardHref($self,'draft'))?>">
      <div class="stat-card<?=isActive($statusFilter,'draft')?>">
        <div class="stat-icon">📝</div>
        <div class="stat-label">Draft</div>
        <div class="stat-value" style="color:#6b7280"><?=number_format($stats['draft'])?></div>
      </div>
    </a>

    <a class="stat-link" href="<?=h(cardHref($self,'closed'))?>">
      <div class="stat-card<?=isActive($statusFilter,'closed')?>">
        <div class="stat-icon">✓</div>
        <div class="stat-label">Closed</div>
        <div class="stat-value" style="color:#6b7280"><?=number_format($stats['closed'])?></div>
      </div>
    </a>
  </div>

  <?php if ($statusFilter !== 'all'): ?>
    <!-- Filtered view only -->
    <div class="lists-grid">
      <div class="list-card" style="grid-column:1/-1">
        <div class="list-title">
          🔎 Permits — <?=strtoupper(h($statusFilter))?> (<?=count($filteredPermits)?>)
          <a class="btn-clear" href="<?=h($self)?>">Clear filter</a>
        </div>
        <?php if (empty($filteredPermits)): ?>
          <div class="item-meta">No permits found for this status.</div>
        <?php else: ?>
          <?php foreach ($filteredPermits as $f): ?>
            <div class="list-item">
              <div class="item-info">
                <div class="item-ref"><?=h($f['ref_number'])?></div>
                <div class="item-meta">
                  <?=h($f['template_id'])?> • <?=h($f['site_block'] ?? 'N/A')?> •
                  Status: <?=strtoupper(h($f['status']))?> •
                  Updated: <?=date('d/m/Y H:i', strtotime((string)$f['updated_at']))?>
                </div>
              </div>
              <div style="display:flex;gap:8px;align-items:center">
                <span class="item-badge <?=badgeClass($f['status'])?>"><?=strtoupper(h($f['status']))?></span>
                <a href="/form/<?=h($f['id'])?>" class="btn" style="font-size:12px;padding:6px 12px">View</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <!-- Normal dashboard (no filter) -->
    <div class="lists-grid">
      <?php if (!empty($expiringSoonList)): ?>
      <div class="list-card">
        <div class="list-title">⚠️ Expiring Soon (Next 7 Days)</div>
        <?php foreach($expiringSoonList as $form): ?>
          <div class="list-item">
            <div class="item-info">
              <div class="item-ref"><?=h($form['ref_number'])?></div>
              <div class="item-meta">
                <?=h($form['template_id'])?> • <?=h($form['site_block'] ?? 'N/A')?> •
                Expires: <?=date('d/m/Y H:i', strtotime((string)$form['valid_to']))?>
              </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
              <span class="item-badge <?=badgeClass($form['status'])?>"><?=strtoupper(h($form['status']))?></span>
              <a href="/form/<?=h($form['id'])?>" class="btn" style="font-size:12px;padding:6px 12px">View</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="list-card">
        <div class="list-title">📌 Recent Activity</div>
        <?php if (empty($recentActivity)): ?>
          <div class="item-meta">No recent updates yet.</div>
        <?php else: ?>
          <?php foreach($recentActivity as $form): ?>
            <div class="list-item">
              <div class="item-info">
                <div class="item-ref"><?=h($form['ref_number'])?></div>
                <div class="item-meta">
                  <?=h($form['template_id'])?> •
                  Updated: <?=date('d/m/Y H:i', strtotime((string)$form['updated_at']))?>
                </div>
              </div>
              <div style="display:flex;gap:8px;align-items:center">
                <span class="item-badge <?=badgeClass($form['status'])?>"><?=strtoupper(h($form['status']))?></span>
                <a href="/form/<?=h($form['id'])?>" class="btn" style="font-size:12px;padding:6px 12px">View</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="<?=asset('/assets/app.js')?>"></script>
</body>
</html>
