<?php
/**
 * Public Index Page with Status Checker
 *
 * File Path: /index.php
 * Description: Public homepage with permit status checking by email
 * Created: 23/10/2025
 * Last Modified: 27/10/2025
 *
 * Features:
 * - Shows available permit templates
 * - Email-based permit status checker
 * - Public permit creation (no login)
 * - Modern, responsive design
 * - PWA support ready
 */

// Bootstrap
[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

// Opportunistic expiry sweep
require_once __DIR__ . '/src/check-expiry.php';
if (function_exists('check_and_expire_permits')) {
    check_and_expire_permits($db);
}

// Session for login state
session_start();

// Status checker (by email)
$statusEmail = $_GET['check_email'] ?? '';
$userPermits = [];
if (!empty($statusEmail) && filter_var($statusEmail, FILTER_VALIDATE_EMAIL)) {
        try {
                $stmt = $db->pdo->prepare('
                        SELECT 
                                f.id,
                                f.ref_number,
                                f.status,
                                f.valid_to,
                                f.created_at,
                                f.unique_link,
                                ft.name as template_name
                        FROM forms f
                        JOIN form_templates ft ON f.template_id = ft.id
                        WHERE f.holder_email = ?
                        ORDER BY f.created_at DESC
                        LIMIT 10
                ');
                $stmt->execute([$statusEmail]);
                $userPermits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
                error_log('Error fetching user permits: ' . $e->getMessage());
        }
}

                  // Available permit templates
                  try {
                      $templatesStmt = $db->pdo->query('
                          SELECT id, name, version, created_at 
                          FROM form_templates 
                          ORDER BY name ASC
                      ');
                      $templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);
                  } catch (Exception $e) {
                      $templates = [];
                      error_log('Error fetching templates: ' . $e->getMessage());
                  }

                  // Recently approved permits
          try {
            $sql = '
              SELECT 
                f.ref_number,
                f.holder_name,
                f.unique_link,
                f.valid_to,
                f.approved_at,
                f.created_at,
                ft.name AS template_name
              FROM forms f
              JOIN form_templates ft ON f.template_id = ft.id
              WHERE f.status = \'active\'
              ORDER BY COALESCE(f.approved_at, f.created_at) DESC
                                                        LIMIT 3
            ';
                $approvedStmt = $db->pdo->query($sql);
                                                                $approvedPermits = $approvedStmt->fetchAll(PDO::FETCH_ASSOC);
          } catch (Exception $e) {
            $approvedPermits = [];
            error_log('Error fetching approved permits: ' . $e->getMessage());
          }

                  // Template icons mapping
                  $templateIcons = [
                      'hot-works-permit' => '🔥',
                      'permit-to-dig' => '⛏️',
                      'work-at-height-permit' => '🪜',
                      'confined-space-entry-permit' => '🕳️',
                      'electrical-isolation-energisation-permit' => '⚡',
                      'environmental-protection-permit' => '🌿',
                      'hazardous-substances-handling-permit' => '☣️',
                      'lifting-operations-permit' => '🏗️',
                      'noise-vibration-control-permit' => '📢',
                      'roof-access-permit' => '🏠',
                      'temporary-works-permit' => '🛠️',
                      'traffic-management-interface-permit' => '🚦',
                      'default' => '📄',
                  ];

                  function slugifyTemplateName(string $name): string {
                      $slug = strtolower($name);
                      $slug = str_replace('&', 'and', $slug);
                      $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
                      return trim($slug, '-');
                  }

                  function getTemplateIcon($templateName) {
                      global $templateIcons;
                      $slug = slugifyTemplateName($templateName);
                      return $templateIcons[$slug] ?? $templateIcons['default'];
                  }

                  function getStatusBadge($status) {
                      $badges = [
                          'active'            => '<span class="badge badge-success">✅ Active</span>',
                          'pending_approval'  => '<span class="badge badge-warning">⏳ Awaiting Approval</span>',
                          'pending'           => '<span class="badge badge-warning">⏳ Pending</span>',
                          'expired'           => '<span class="badge badge-danger">❌ Expired</span>',
                          'draft'             => '<span class="badge badge-gray">📝 Draft</span>',
                      ];
                      return $badges[$status] ?? '<span class="badge badge-gray">' . htmlspecialchars($status) . '</span>';
                  }

function formatDateUK($date) {
    if (!$date) return 'N/A';
    $timestamp = strtotime($date);
    return date('d/m/Y H:i', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit System - Check Status & Create Permits</title>

    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#0ea5e9">
    <meta name="description" content="Create and manage work permits easily">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icon-192.png">
    <link rel="stylesheet" href="/assets/app.css">
    <style>
      /* Minimal page-specific tweaks for the surface-card dark theme */
      .status-compact { max-height: 260px; overflow: hidden; }
      .permit-list-scroll { max-height: 420px; overflow: auto; }
      .grid-two { align-items: start; }
    </style>
</head>
<body class="theme-dark">
                <div class="wrap">
                        <div class="hero-card home-hero" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                        <div>
                                        <h2 class="home-title">🛡️ Permit System</h2>
                                <p class="muted" style="margin:0">Create permits easily, check status anytime</p>
                        </div>
                        <div class="tab-actions">
                                <?php if ($isLoggedIn): ?>
                                        <span class="chip">👤 <?php echo htmlspecialchars($currentUser['name'] ?? 'User'); ?></span>
                                        <a href="/dashboard.php" class="btn">📊 Dashboard</a>
                                        <a href="/logout.php" class="btn">Logout</a>
                                <?php else: ?>
                                        <button id="installButton" class="btn">📱 Install App</button>
                                        <a href="/login.php" class="btn btn-accent">🔐 Manager Login</a>
                                <?php endif; ?>
                                <button type="button" class="btn mobile-only" id="openPermitPicker">☰ Permits</button>
                        </div>
                </div>

                <div class="surface-section">
                                        <div class="grid-two">
                                                <!-- Status Checker -->
                                                <section class="surface-card" style="grid-column:1/-1">
                                        <div class="card-header"><h3>🔍 Check Your Permit Status</h3></div>
                                        <p class="muted">Enter your email to see all your permits and their current status</p>
                                                        <form action="/" method="GET" class="status-row">
                                                                <input type="email" name="check_email" placeholder="Enter your email address" value="<?php echo htmlspecialchars($statusEmail); ?>" required />
                                                                <button type="submit" class="btn btn-accent" style="min-width:140px">🔍 Check Status</button>
                                                        </form>

                                        <?php if (!empty($statusEmail)): ?>
                                        <?php if (empty($userPermits)): ?>
                                                        <div class="empty-state" style="padding:20px">
                                                                <div style="font-size:36px;margin-bottom:8px">📭</div>
                                                                <div style="font-weight:600;margin-bottom:6px">No permits found</div>
                                                                <p class="muted">We couldn't find any permits for <strong><?php echo htmlspecialchars($statusEmail); ?></strong></p>
                                                                <p style="margin-top: 12px;">
                                                                        <a href="#templates">Create your first permit below</a>
                                                                </p>
                                                        </div>
                                        <?php else: ?>
                                                                        <div class="permit-results" style="margin-top:16px">
                                                                                <h4 style="margin:0 0 10px">Your Permits (<?php echo count($userPermits); ?>)</h4>
                                                        <?php foreach ($userPermits as $permit): ?>
                                                                                        <div class="mini-card">
                                                                        <div class="card-header">
                                                                                <div><strong><?php echo htmlspecialchars($permit['template_name']); ?></strong> #<?php echo htmlspecialchars($permit['ref_number']); ?></div>
                                                                                <?php echo getStatusBadge($permit['status']); ?>
                                                                        </div>
                                                                        <div class="muted"><strong>Submitted:</strong> <?php echo formatDateUK($permit['created_at']); ?></div>
                                                                        <?php if ($permit['status'] === 'active' && $permit['valid_to']): ?>
                                                                                <div class="muted"><strong>Valid Until:</strong> <?php echo formatDateUK($permit['valid_to']); ?></div>
                                                                        <?php endif; ?>
                                                                        <?php if ($permit['status'] === 'pending_approval'): ?>
                                                                                <div class="muted">⏳ Your permit is being reviewed by a manager</div>
                                                                        <?php endif; ?>
                                                                                                                <div class="tab-actions" style="margin-top:8px">
                                                                                <a href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>" class="btn">👁️ View Details</a>
                                                                                <?php if ($permit['status'] === 'active'): ?>
                                                                                        <a href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>&print=1" class="btn btn-secondary">🖨️ Print</a>
                                                                                <?php endif; ?>
                                                                        </div>
                                                                </div>
                                                        <?php endforeach; ?>
                                                </div>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                                </section>
                                        </div>

                                        <!-- Recently Approved: show last 3 only, no ticker -->
                                        <section class="surface-card" id="approved-permits" style="margin-top:12px">
                                                <div class="card-header"><h3>✅ Recently Approved</h3></div>
                                                <?php if (empty($approvedPermits)): ?>
                                                        <div class="muted">No approved permits yet.</div>
                                                <?php else: ?>
                                                        <div class="scroll-row">
                                                                <?php foreach ($approvedPermits as $permit): ?>
                                                                        <div class="mini-card approved-card">
                                                                                <div class="card-header">
                                                                                        <div><strong><?php echo htmlspecialchars($permit['template_name']); ?></strong> #<?php echo htmlspecialchars($permit['ref_number']); ?></div>
                                                                                        <?php echo getStatusBadge('active'); ?>
                                                                                </div>
                                                                                <?php if (!empty($permit['holder_name'])): ?>
                                                                                        <div class="muted"><strong>Permit Holder:</strong> <?php echo htmlspecialchars($permit['holder_name']); ?></div>
                                                                                <?php endif; ?>
                                                                                <div class="muted"><strong>Approved:</strong> <?php echo formatDateUK($permit['approved_at'] ?? $permit['created_at']); ?></div>
                                                                                <?php if (!empty($permit['valid_to'])): ?>
                                                                                        <div class="muted"><strong>Valid Until:</strong> <?php echo formatDateUK($permit['valid_to']); ?></div>
                                                                                <?php endif; ?>
                                                                                <div class="tab-actions" style="margin-top:8px">
                                                                                        <a class="btn" href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>">👁️ View</a>
                                                                                        <a class="btn btn-secondary" href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>&print=1">🖨️ Print</a>
                                                                                </div>
                                                                        </div>
                                                                <?php endforeach; ?>
                                                        </div>
                                                <?php endif; ?>
                                        </section>

                <!-- Available Permit Templates -->
                <section class="surface-card" id="templates" style="margin-top:16px">
                        <div class="card-header"><h3>📋 Create New Permit</h3></div>
                        <p class="muted">Select a permit type to get started. No login required.</p>
                        <?php if (empty($templates)): ?>
                                <div class="empty-state">
                                        <div style="font-size:36px;margin-bottom:8px">📄</div>
                                        <div style="font-weight:600;margin-bottom:6px">No permit templates available</div>
                                        <p class="muted">Contact your administrator to add permit templates.</p>
                                </div>
                        <?php else: ?>
                                                                                <div class="surface-grid">
                                        <?php foreach ($templates as $template): ?>
                                                                                                <a class="template-card template-card--home template-card--xl" href="/create-permit-public.php?template=<?php echo urlencode($template['id']); ?>">
                                                        <span class="icon"><?php echo getTemplateIcon($template['name']); ?></span>
                                                        <span class="name"><?php echo htmlspecialchars($template['name']); ?></span>
                                                </a>
                                        <?php endforeach; ?>
                                </div>
                        <?php endif; ?>
                </section>

                <p class="muted" style="text-align:center;margin:24px 0">© <?php echo date('Y'); ?> Permit System</p>
                </div> <!-- /.surface-section -->
        </div> <!-- /.wrap -->

                <!-- Mobile Permit Picker Sheet -->
                                                                <div class="permit-sheet" id="permitSheet" aria-hidden="true" role="dialog" aria-label="Choose a permit type">
                        <div class="permit-sheet__panel">
                                <div class="permit-sheet__handle"></div>
                                <div class="card-header" style="margin-bottom:12px"><h3>📋 Choose Permit Type</h3><button class="btn btn-secondary" id="closePermitSheet">Close</button></div>
                                <div class="permit-list">
                                        <?php foreach ($templates as $template): ?>
                                                <a class="permit-link" href="/create-permit-public.php?template=<?php echo urlencode($template['id']); ?>">
                                                        <span class="icon"><?php echo getTemplateIcon($template['name']); ?></span>
                                                        <span class="name"><?php echo htmlspecialchars($template['name']); ?></span>
                                                </a>
                                        <?php endforeach; ?>
                                </div>
                        </div>
                </div>
                                                                <!-- Floating mobile Permits button -->
                                                                <button type="button" class="fab-permit mobile-only" id="fabPermit">☰ Permits</button>
                                                                <script src="/assets/app.js"></script>
                                                                <script>
                                                                        // Mobile permit sheet open/close (no optional chaining for compatibility)
                                                                        (function(){
                                                                                var openBtn = document.getElementById('openPermitPicker');
                                                                                var fabBtn = document.getElementById('fabPermit');
                                                                                var sheet = document.getElementById('permitSheet');
                                                                                var closeBtn = document.getElementById('closePermitSheet');
                                                                                function open(){ if (sheet) { sheet.setAttribute('data-open','1'); sheet.setAttribute('aria-hidden','false'); } }
                                                                                function close(){ if (sheet) { sheet.setAttribute('data-open','0'); sheet.setAttribute('aria-hidden','true'); } }
                                                                                if (openBtn) openBtn.addEventListener('click', open);
                                                                                if (fabBtn) fabBtn.addEventListener('click', open);
                                                                                if (closeBtn) closeBtn.addEventListener('click', close);
                                                                                if (sheet) sheet.addEventListener('click', function(e){ if (e.target === sheet) close(); });
                                                                        })();

                                                                        // Center align scroll rows when not overflowing
                                                                        (function(){
                                                                                function updateScrollRows(){
                                                                                        var rows = document.querySelectorAll('.scroll-row');
                                                                                        var each = rows.forEach ? rows.forEach.bind(rows) : function(cb){ Array.prototype.forEach.call(rows, cb); };
                                                                                        each(function(row){
                                                                                                var overflowing = row.scrollWidth > row.clientWidth + 1;
                                                                                                row.setAttribute('data-overflow', overflowing ? '1' : '0');
                                                                                        });
                                                                                }
                                                                                var ro = window.ResizeObserver ? new ResizeObserver(updateScrollRows) : null;
                                                                                window.addEventListener('load', function(){
                                                                                        if (ro) {
                                                                                                var rows = document.querySelectorAll('.scroll-row');
                                                                                                rows.forEach ? rows.forEach(function(r){ ro.observe(r); }) : Array.prototype.forEach.call(rows, function(r){ ro.observe(r); });
                                                                                        }
                                                                                        updateScrollRows();
                                                                                });
                                                                                window.addEventListener('resize', updateScrollRows);
                                                                                window.addEventListener('orientationchange', function(){ setTimeout(updateScrollRows, 150); });
                                                                        })();
                                                                </script>
                                                                                
                    
                  </body>
                  </html>