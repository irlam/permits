<?php
/**
 * Dashboard Template
 * 
 * Variables available:
 * - $stats: Array of statistics (total, active, draft, pending, etc.)
 * - $recentForms: Recent permit forms
 * - $recentEvents: Recent activity events
 * - $tpls: Available form templates
 */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0ea5e9">
  <title>Dashboard - Permits</title>
  <link rel="stylesheet" href="/assets/app.css">
  <style>
    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 20px;
      margin-bottom: 32px;
    }
    .stat-card {
      background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
      border: 1px solid #374151;
      border-radius: 12px;
      padding: 24px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
      transition: all 0.2s ease;
    }
    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 15px rgba(0, 0, 0, 0.3);
    }
    .stat-card .icon {
      font-size: 32px;
      margin-bottom: 12px;
      opacity: 0.8;
    }
    .stat-card .label {
      font-size: 13px;
      color: #9ca3af;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 8px;
    }
    .stat-card .value {
      font-size: 36px;
      font-weight: 700;
      color: #f9fafb;
      line-height: 1;
    }
    .stat-card.accent {
      background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
      border-color: #3b82f6;
    }
    .stat-card.success {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      border-color: #10b981;
    }
    .stat-card.warning {
      background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
      border-color: #f59e0b;
    }
    .stat-card.danger {
      background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
      border-color: #ef4444;
    }
    .activity-feed {
      background: #111827;
      border: 1px solid #1f2937;
      border-radius: 12px;
      padding: 24px;
      margin-bottom: 24px;
    }
    .activity-item {
      display: flex;
      gap: 16px;
      padding: 12px 0;
      border-bottom: 1px solid #1f2937;
    }
    .activity-item:last-child {
      border-bottom: none;
    }
    .activity-icon {
      width: 40px;
      height: 40px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
    }
    .activity-content {
      flex: 1;
      min-width: 0;
    }
    .activity-title {
      font-weight: 500;
      color: #f9fafb;
      margin-bottom: 4px;
    }
    .activity-meta {
      font-size: 13px;
      color: #9ca3af;
    }
    .quick-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 24px;
    }
    .section-title {
      font-size: 20px;
      font-weight: 600;
      color: #f9fafb;
      margin-bottom: 16px;
    }
    .progress-bar {
      width: 100%;
      height: 8px;
      background: #1f2937;
      border-radius: 4px;
      margin-top: 12px;
      overflow: hidden;
    }
    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #3b82f6, #2563eb);
      transition: width 0.3s ease;
    }
  </style>
</head>
<body>
<header class="top">
  <h1>📊 Dashboard</h1>
  <div style="display: flex; gap: 12px;">
    <a class="btn" href="/">View All Permits</a>
  </div>
</header>

<section class="grid">
  <!-- Statistics Cards -->
  <div style="grid-column: 1/-1;">
    <h2 class="section-title">Overview</h2>
    <div class="dashboard-grid">
      <div class="stat-card accent">
        <div class="icon">📋</div>
        <div class="label">Total Permits</div>
        <div class="value"><?= number_format($stats['total']) ?></div>
      </div>
      
      <div class="stat-card success">
        <div class="icon">✓</div>
        <div class="label">Active Permits</div>
        <div class="value"><?= number_format($stats['active']) ?></div>
      </div>
      
      <div class="stat-card warning">
        <div class="icon">⏰</div>
        <div class="label">Expiring (7 days)</div>
        <div class="value"><?= number_format($stats['expiring_7days']) ?></div>
      </div>
      
      <div class="stat-card">
        <div class="icon">📝</div>
        <div class="label">Pending Review</div>
        <div class="value"><?= number_format($stats['pending']) ?></div>
      </div>
    </div>
  </div>

  <!-- Status Breakdown -->
  <div style="grid-column: 1/-1;">
    <h2 class="section-title">Status Breakdown</h2>
    <div class="dashboard-grid">
      <div class="stat-card">
        <div class="label">Draft</div>
        <div class="value"><?= number_format($stats['draft']) ?></div>
        <div class="progress-bar">
          <div class="progress-fill" style="width: <?= $stats['total'] > 0 ? ($stats['draft'] / $stats['total'] * 100) : 0 ?>%; background: #6b7280;"></div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="label">Issued</div>
        <div class="value"><?= number_format($stats['issued']) ?></div>
        <div class="progress-bar">
          <div class="progress-fill" style="width: <?= $stats['total'] > 0 ? ($stats['issued'] / $stats['total'] * 100) : 0 ?>%; background: #3b82f6;"></div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="label">Expired</div>
        <div class="value"><?= number_format($stats['expired']) ?></div>
        <div class="progress-bar">
          <div class="progress-fill" style="width: <?= $stats['total'] > 0 ? ($stats['expired'] / $stats['total'] * 100) : 0 ?>%; background: #ef4444;"></div>
        </div>
      </div>
      
      <div class="stat-card">
        <div class="label">Closed</div>
        <div class="value"><?= number_format($stats['closed']) ?></div>
        <div class="progress-bar">
          <div class="progress-fill" style="width: <?= $stats['total'] > 0 ? ($stats['closed'] / $stats['total'] * 100) : 0 ?>%; background: #6b7280;"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick Actions -->
  <div style="grid-column: 1/-1;">
    <h2 class="section-title">Quick Actions</h2>
    <div class="quick-actions">
      <?php foreach($tpls as $t): ?>
        <a href="/new/<?= htmlspecialchars($t['id']) ?>" class="btn btn-accent">
          + Create <?= htmlspecialchars($t['name']) ?>
        </a>
      <?php endforeach; ?>
      <a href="/?status=pending" class="btn">View Pending</a>
      <a href="/?status=active" class="btn">View Active</a>
      <a href="/api/export/csv" class="btn">Export CSV</a>
    </div>
  </div>

  <!-- Recent Activity -->
  <div style="grid-column: 1/-1;">
    <h2 class="section-title">Recent Activity</h2>
    <div class="activity-feed">
      <?php if (empty($recentEvents)): ?>
        <p style="color: #9ca3af; text-align: center; padding: 20px;">No recent activity</p>
      <?php else: ?>
        <?php foreach(array_slice($recentEvents, 0, 10) as $event): ?>
          <div class="activity-item">
            <div class="activity-icon" style="background: <?php
              echo match($event['type']) {
                'created' => '#3b82f6',
                'updated' => '#10b981',
                'status_changed' => '#f59e0b',
                'attachment_added' => '#8b5cf6',
                'attachment_removed' => '#ef4444',
                default => '#6b7280'
              };
            ?>;">
              <?php
              echo match($event['type']) {
                'created' => '➕',
                'updated' => '✏️',
                'status_changed' => '🔄',
                'attachment_added' => '📎',
                'attachment_removed' => '🗑️',
                default => '📋'
              };
              ?>
            </div>
            <div class="activity-content">
              <div class="activity-title">
                <?php
                echo match($event['type']) {
                  'created' => 'Permit created',
                  'updated' => 'Permit updated',
                  'status_changed' => 'Status changed',
                  'attachment_added' => 'Attachment added',
                  'attachment_removed' => 'Attachment removed',
                  default => ucfirst($event['type'])
                };
                ?>
                <?php if(!empty($event['ref'])): ?>
                  - <a href="/form/<?= htmlspecialchars($event['form_id']) ?>" style="color: #3b82f6;"><?= htmlspecialchars($event['ref']) ?></a>
                <?php endif; ?>
              </div>
              <div class="activity-meta">
                <?= date('M d, Y g:i A', strtotime($event['at'])) ?>
                <?php if ($event['by_user']): ?>
                  by <?= htmlspecialchars($event['by_user']) ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Permits -->
  <div style="grid-column: 1/-1;">
    <h2 class="section-title">Recent Permits</h2>
    <div class="activity-feed">
      <?php if (empty($recentForms)): ?>
        <p style="color: #9ca3af; text-align: center; padding: 20px;">No permits yet</p>
      <?php else: ?>
        <?php foreach($recentForms as $form): ?>
          <div class="activity-item">
            <div class="activity-icon" style="background: <?php
              echo match($form['status']) {
                'active' => '#10b981',
                'issued' => '#3b82f6',
                'pending' => '#f59e0b',
                'expired' => '#ef4444',
                'draft' => '#6b7280',
                default => '#6b7280'
              };
            ?>;">
              📋
            </div>
            <div class="activity-content">
              <div class="activity-title">
                <a href="/form/<?= htmlspecialchars($form['id']) ?>" style="color: #f9fafb;">
                  <?= htmlspecialchars($form['ref']) ?>
                </a>
                <span style="padding: 2px 8px; background: rgba(255,255,255,0.1); border-radius: 4px; font-size: 11px; margin-left: 8px;">
                  <?= strtoupper($form['status']) ?>
                </span>
              </div>
              <div class="activity-meta">
                <?= htmlspecialchars($form['site_block']) ?> •
                Updated <?= date('M d, Y', strtotime($form['updated_at'])) ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<script src="/assets/app.js"></script>
</body>
</html>
