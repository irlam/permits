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
    <div class="home-grid">
      <!-- Left: Status checker -->
      <section class="surface-card">
        <div class="card-header"><h3 class="section-title">📧 Check Permit Status</h3></div>
        <p class="muted">Enter your email to see your recent permits.</p>

        <form action="<?=htmlspecialchars($app->url('index.php'))?>" method="GET" class="status-form" style="display:flex;gap:12px;flex-wrap:wrap">
          <input type="email" name="check_email" placeholder="Enter your email address" value="<?=htmlspecialchars($statusEmail)?>" required style="flex:1;min-width:240px" />
          <button type="submit" class="btn btn-primary">🔍 Check Status</button>
        </form>

        <?php if (!empty($statusEmail)): ?>
          <?php if (empty($userPermits)): ?>
            <div class="alert alert-info">No permits found for <strong><?=htmlspecialchars($statusEmail)?></strong>. Create your first permit below.</div>
          <?php else: ?>
            <div class="permit-list">
              <?php foreach ($userPermits as $permit): ?>
                <div class="surface-card" style="padding:12px">
                  <div class="card-header" style="margin-bottom:8px">
                    <div><strong><?=htmlspecialchars($permit['template_name'])?></strong> #<?=htmlspecialchars($permit['ref_number'])?></div>
                    <?=getStatusBadge($permit['status'])?>
                  </div>
                  <div class="muted" style="margin-bottom:6px"><strong>Submitted:</strong> <?=formatDateUK($permit['created_at'])?></div>
                  <?php if ($permit['status'] === 'active' && $permit['valid_to']): ?>
                    <div class="muted" style="margin-bottom:6px"><strong>Valid Until:</strong> <?=formatDateUK($permit['valid_to'])?></div>
                  <?php endif; ?>
                  <?php if ($permit['status'] === 'pending_approval'): ?>
                    <div class="muted">⏳ Your permit is being reviewed by a manager</div>
                  <?php endif; ?>
                  <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
                    <a class="btn btn-primary" href="<?=htmlspecialchars($app->url('view-permit-public.php') . '?link=' . urlencode($permit['unique_link']))?>">👁️ View Details</a>
                    <?php if ($permit['status'] === 'active'): ?>
                      <a class="btn btn-secondary" href="<?=htmlspecialchars($app->url('view-permit-public.php') . '?link=' . urlencode($permit['unique_link']) . '&print=1')?>">🖨️ Print</a>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php


      // Load bootstrap
      [$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

      require_once __DIR__ . '/src/check-expiry.php';

      if (function_exists('check_and_expire_permits')) {
          check_and_expire_permits($db);
      }

      // Start session
      session_start();

      // Check if user is logged in
      $isLoggedIn = isset($_SESSION['user_id']);
      $currentUser = null;

      if ($isLoggedIn) {
          $user_stmt = $db->pdo->prepare("SELECT * FROM users WHERE id = ?");
          $user_stmt->execute([$_SESSION['user_id']]);
          $currentUser = $user_stmt->fetch(PDO::FETCH_ASSOC);
      }

      // Handle status check request
      $statusEmail = $_GET['check_email'] ?? '';
      $userPermits = [];

      if (!empty($statusEmail) && filter_var($statusEmail, FILTER_VALIDATE_EMAIL)) {
          try {
              $stmt = $db->pdo->prepare("
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
              ");
              $stmt->execute([$statusEmail]);
              $userPermits = $stmt->fetchAll(PDO::FETCH_ASSOC);
          } catch (Exception $e) {
              error_log("Error fetching user permits: " . $e->getMessage());
          }
      }

      // Get available permit templates
      try {
          $templatesStmt = $db->pdo->query("
              SELECT id, name, version, created_at 
              FROM form_templates 
              ORDER BY name ASC
          ");
          $templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);
      } catch (Exception $e) {
          $templates = [];
          error_log("Error fetching templates: " . $e->getMessage());
      }

      // Get recently approved permits
      try {
          $approvedStmt = $db->pdo->query("
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
              WHERE f.status = 'active'
              ORDER BY COALESCE(f.approved_at, f.created_at) DESC
              LIMIT 6
          ");
          $approvedPermits = $approvedStmt->fetchAll(PDO::FETCH_ASSOC);
      } catch (Exception $e) {
          $approvedPermits = [];
          error_log("Error fetching approved permits: " . $e->getMessage());
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
          'default' => '📄'
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
              'active' => '<span class=\"badge badge-success\">✅ Active</span>',
              'pending_approval' => '<span class=\"badge badge-warning\">⏳ Awaiting Approval</span>',
              'pending' => '<span class=\"badge badge-warning\">⏳ Pending</span>',
              'expired' => '<span class=\"badge badge-danger\">❌ Expired</span>',
              'draft' => '<span class=\"badge badge-gray\">📝 Draft</span>'
          ];
          return $badges[$status] ?? '<span class=\"badge badge-gray\">' . htmlspecialchars($status) . '</span>';
      }

      function formatDateUK($date) {
          if (!$date) return 'N/A';
          $timestamp = strtotime($date);
          return date('d/m/Y H:i', $timestamp);
      }
      ?>

      <!DOCTYPE html>
      <html lang=\"en\">
      <head>
          <meta charset=\"UTF-8\">
          <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
          <title>Permit System - Check Status & Create Permits</title>
          <meta name=\"description\" content=\"Create and manage work permits easily\">
          <meta name=\"theme-color\" content=\"#0f172a\">
          <link rel=\"manifest\" href=\"<?=htmlspecialchars($app->url('manifest.webmanifest'))?>\">
          <link rel=\"apple-touch-icon\" sizes=\"192x192\" href=\"<?=htmlspecialchars($app->url('assets/pwa/icon-192.png'))?>\">
          <link rel=\"stylesheet\" href=\"<?=asset('/assets/app.css')?>\">
          <style>
            .home-grid{display:grid;grid-template-columns:1fr;gap:16px}
            @media(min-width:900px){.home-grid{grid-template-columns:1.2fr .8fr}}
            .section-title{font-size:18px;color:#e5e7eb;margin:0 0 8px;font-weight:600}
            .muted{color:#94a3b8}
            .templates-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}
            .permit-list{display:flex;flex-direction:column;gap:12px}
          </style>
      </head>
      <body class=\"theme-dark\">
        <header class=\"site-header\">
          <h1 class=\"site-header__title\">🛡️ Permit System</h1>
          <div class=\"site-header__actions\">
            <?php if ($isLoggedIn && $currentUser): ?>
              <a class=\"btn btn-secondary\" href=\"<?=htmlspecialchars($app->url('dashboard.php'))?>\">📊 Dashboard</a>
              <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                <a class=\"btn btn-secondary\" href=\"<?=htmlspecialchars($app->url('admin.php'))?>\">⚙️ Admin</a>
              <?php endif; ?>
              <a class=\"btn btn-secondary\" href=\"<?=htmlspecialchars($app->url('logout.php'))?>\">🚪 Logout</a>
            <?php else: ?>
              <a class=\"btn btn-secondary\" href=\"<?=htmlspecialchars($app->url('dashboard.php'))?>\">📊 Dashboard</a>
              <a class=\"btn btn-primary\" href=\"<?=htmlspecialchars($app->url('login.php'))?>\">🔐 Manager Login</a>
            <?php endif; ?>
          </div>
        </header>

        <main class=\"site-container\">
          <section class=\"hero-card\">
            <h2>Check status or create a new permit</h2>
            <p class=\"muted\">Fast, simple and mobile-friendly.</p>
          </section>

          <div class=\"home-grid\">
            <!-- Left: Status checker -->
            <section class=\"surface-card\">
              <div class=\"card-header\"><h3 class=\"section-title\">📧 Check Permit Status</h3></div>
              <p class=\"muted\">Enter your email to see your recent permits.</p>

              <form action=\"<?=htmlspecialchars($app->url('index.php'))?>\" method=\"GET\" class=\"status-form\" style=\"display:flex;gap:12px;flex-wrap:wrap\">
                <input type=\"email\" name=\"check_email\" placeholder=\"Enter your email address\" value=\"<?=htmlspecialchars($statusEmail)?>\" required style=\"flex:1;min-width:240px\" />
                <button type=\"submit\" class=\"btn btn-primary\">🔍 Check Status</button>
              </form>

              <?php if (!empty($statusEmail)): ?>
                <?php if (empty($userPermits)): ?>
                  <div class=\"alert alert-info\">No permits found for <strong><?=htmlspecialchars($statusEmail)?></strong>. Create your first permit below.</div>
                <?php else: ?>
                  <div class=\"permit-list\">
                    <?php foreach ($userPermits as $permit): ?>
                      <div class=\"surface-card\" style=\"padding:12px\">
                        <div class=\"card-header\" style=\"margin-bottom:8px\">
                          <div><strong><?=htmlspecialchars($permit['template_name'])?></strong> #<?=htmlspecialchars($permit['ref_number'])?></div>
                          <?=getStatusBadge($permit['status'])?>
                        </div>
                        <div class=\"muted\" style=\"margin-bottom:6px\"><strong>Submitted:</strong> <?=formatDateUK($permit['created_at'])?></div>
                        <?php if ($permit['status'] === 'active' && $permit['valid_to']): ?>
                          <div class=\"muted\" style=\"margin-bottom:6px\"><strong>Valid Until:</strong> <?=formatDateUK($permit['valid_to'])?></div>
                        <?php endif; ?>
                        <?php if ($permit['status'] === 'pending_approval'): ?>
                          <div class=\"muted\">⏳ Your permit is being reviewed by a manager</div>
                        <?php endif; ?>
                        <div style=\"margin-top:10px;display:flex;gap:8px;flex-wrap:wrap\">
                          <a class=\"btn btn-primary\" href=\"<?=htmlspecialchars($app->url('view-permit-public.php') . '?link=' . urlencode($permit['unique_link']))?>\">👁️ View Details</a>
                          <?php if ($permit['status'] === 'active'): ?>
                            <a class=\"btn btn-secondary\" href=\"<?=htmlspecialchars($app->url('view-permit-public.php') . '?link=' . urlencode($permit['unique_link']) . '&print=1')?>\">🖨️ Print</a>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </section>

            <!-- Right: Recently Approved -->
            <section class=\"surface-card\" id=\"approved-permits\">
              <div class=\"card-header\"><h3 class=\"section-title\">✅ Recently Approved</h3></div>
              <?php if (empty($approvedPermits)): ?>
                <div class=\"muted\">No approved permits yet.</div>
              <?php else: ?>
                <div class=\"permit-list\">
                  <?php foreach ($approvedPermits as $permit): ?>
                    <div class=\"surface-card\" style=\"padding:12px\">
                      <div class=\"card-header\" style=\"margin-bottom:8px\">
                        <div><strong><?=htmlspecialchars($permit['template_name'])?></strong> #<?=htmlspecialchars($permit['ref_number'])?></div>
                        <?=getStatusBadge('active')?>
                      </div>
                      <?php if (!empty($permit['holder_name'])): ?>
                        <div class=\"muted\" style=\"margin-bottom:6px\"><strong>Permit Holder:</strong> <?=htmlspecialchars($permit['holder_name'])?></div>
                      <?php endif; ?>
                      <div class=\"muted\" style=\"margin-bottom:6px\"><strong>Approved:</strong> <?=formatDateUK($permit['approved_at'] ?? $permit['created_at'])?></div>
                      <?php if (!empty($permit['valid_to'])): ?>
                        <div class=\"muted\"><strong>Valid Until:</strong> <?=formatDateUK($permit['valid_to'])?></div>
                      <?php endif; ?>
                      <div style=\"margin-top:10px;display:flex;gap:8px;flex-wrap:wrap\">
                        <a class=\"btn btn-primary\" href=\"<?=htmlspecialchars($app->url('view-permit-public.php') . '?link=' . urlencode($permit['unique_link']))?>\">👁️ View Permit</a>
                        <a class=\"btn btn-secondary\" href=\"<?=htmlspecialchars($app->url('view-permit-public.php') . '?link=' . urlencode($permit['unique_link']) . '&print=1')?>\">🖨️ Print</a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>
          </div>

          <!-- Templates -->
          <section class=\"surface-card\" id=\"templates\" style=\"margin-top:16px\">
            <div class=\"card-header\"><h3 class=\"section-title\">📋 Create New Permit</h3></div>
            <p class=\"muted\">Select a permit type to get started. No login required.</p>
            <?php if (empty($templates)): ?>
              <div class=\"alert alert-info\">No permit templates available. Contact your administrator.</div>
            <?php else: ?>
              <div class=\"templates-grid\">
                <?php foreach ($templates as $template): ?>
                  <div class=\"template-card\" onclick=\"window.location.href='<?=htmlspecialchars($app->url('create-permit-public.php') . '?template=' . urlencode($template['id']))?>'\">
                    <div class=\"template-icon\"><?=getTemplateIcon($template['name'])?></div>
                    <div class=\"template-name\"><?=htmlspecialchars($template['name'])?></div>
                    <div class=\"template-version\">Version <?=htmlspecialchars($template['version'])?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <p class=\"muted\" style=\"text-align:center;margin:24px 0\">© <?=date('Y')?> Permit System</p>
        </main>

        <script>
          // Optional PWA install UI hook; hidden unless event fires
          let deferredPrompt;
          window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
          });

          // Register service worker (base-aware)
          if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('<?=json_encode($app->url('sw.js'))?>'.slice(1,-1))
              .catch(err => console.log('SW registration failed', err));
          }

          // Handle push subscription change -> re-subscribe at base-aware endpoint
          (async () => {
            if (!('serviceWorker' in navigator)) return;
            const reg = await navigator.serviceWorker.getRegistration('<?=json_encode($app->url('sw.js'))?>'.slice(1,-1)) || await navigator.serviceWorker.register('<?=json_encode($app->url('sw.js'))?>'.slice(1,-1));
            navigator.serviceWorker.addEventListener('message', async (evt) => {
              if (evt.data?.type === 'PUSH_SUBSCRIPTION_CHANGED') {
                try {
                  const vapidKeyB64 = window.VAPID_PUBLIC_KEY;
                  if (!vapidKeyB64) return;
                  const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapidKeyB64) });
                  await fetch('<?=json_encode($app->url('api/push/subscribe.php'))?>'.slice(1,-1), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub) });
                } catch (e) { console.warn('Push re-subscribe failed', e); }
              }
            });
          })();

          function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = atob(base64);
            const output  = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) output[i] = rawData.charCodeAt(i);
            return output;
          }
        </script>
      </body>
      </html>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit System - Check Status & Create Permits</title>
    <meta name="description" content="Create and manage work permits easily">
    <meta name="theme-color" content="#0f172a">
    <link rel="manifest" href="<?=htmlspecialchars($app->url('manifest.webmanifest'))?>">
    <link rel="apple-touch-icon" sizes="192x192" href="<?=htmlspecialchars($app->url('assets/pwa/icon-192.png'))?>">
    <link rel="stylesheet" href="<?=asset('/assets/app.css')?>">
    <style>
      .home-grid{display:grid;grid-template-columns:1fr;gap:16px}
      @media(min-width:900px){.home-grid{grid-template-columns:1.2fr .8fr}}
      .section-title{font-size:18px;color:#e5e7eb;margin:0 0 8px;font-weight:600}
      .muted{color:#94a3b8}
      .templates-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px}
      .permit-list{display:flex;flex-direction:column;gap:12px}
    </style>
</head>
<body class="theme-dark">
  <header class="site-header">
    <h1 class="site-header__title">🛡️ Permit System</h1>
    <div class="site-header__actions">
      <?php if ($isLoggedIn && $currentUser): ?>
        <a class="btn btn-secondary" href="<?=htmlspecialchars($app->url('dashboard.php'))?>">📊 Dashboard</a>
        <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
          <a class="btn btn-secondary" href="<?=htmlspecialchars($app->url('admin.php'))?>">⚙️ Admin</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?=htmlspecialchars($app->url('logout.php'))?>">🚪 Logout</a>
      <?php else: ?>
        <a class="btn btn-secondary" href="<?=htmlspecialchars($app->url('dashboard.php'))?>">📊 Dashboard</a>
        <a class="btn btn-primary" href="<?=htmlspecialchars($app->url('login.php'))?>">🔐 Manager Login</a>
      <?php endif; ?>
    </div>
  </header>

  <main class="site-container">
    <section class="hero-card">
      <h2>Check status or create a new permit</h2>
      <p class="muted">Fast, simple and mobile-friendly.</p>
    </section>

    <div class="home-grid">
                </div>
            <?php endif; ?>
        </div>

        <!-- Approved Permits -->
        <div class="card" id="approved-permits">
            <h2 class="card-title">✅ Recently Approved Permits</h2>
            <p style="color: #6b7280; margin-bottom: 24px;">
                Latest permits that have been approved and are now active.
            </p>

            <?php if (empty($approvedPermits)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">🗂️</div>
                    <div class="empty-state-title">No approved permits yet</div>
                    <p>Once permits are approved by a manager, they will appear here.</p>
                </div>
            <?php else: ?>
                <div class="permit-results">
                    <?php foreach ($approvedPermits as $permit): ?>
                        <div class="permit-card approved">
                            <div class="permit-header">
                                <div class="permit-ref">
                                    <?php echo htmlspecialchars($permit['template_name']); ?>
                                    #<?php echo htmlspecialchars($permit['ref_number']); ?>
                                </div>
                                <?php echo getStatusBadge('active'); ?>
                            </div>

                            <?php if (!empty($permit['holder_name'])): ?>
                                <div class="permit-info">
                                    <strong>Permit Holder:</strong> <?php echo htmlspecialchars($permit['holder_name']); ?>
                                </div>
                            <?php endif; ?>

                            <div class="permit-info">
                                <strong>Approved:</strong> <?php echo formatDateUK($permit['approved_at'] ?? $permit['created_at']); ?>
                            </div>

                            <?php if (!empty($permit['valid_to'])): ?>
                                <div class="permit-info">
                                    <strong>Valid Until:</strong> <?php echo formatDateUK($permit['valid_to']); ?>
                                </div>
                            <?php endif; ?>

                            <div class="permit-actions">
                                <a href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>" class="btn btn-primary">
                                    👁️ View Permit
                                </a>
                                <a href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>&print=1" class="btn btn-secondary">
                                    🖨️ Print
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>© <?php echo date('Y'); ?> Permit System • Secure & Efficient Permit Management</p>
        </div>
    </div>

    <!-- PWA Installation Script -->
    <script>
        let deferredPrompt;
        const installButton = document.getElementById('installButton');

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            installButton.classList.add('show');
        });

        installButton.addEventListener('click', async () => {
            if (!deferredPrompt) {
                return;
            }
            
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            
            if (outcome === 'accepted') {
                console.log('PWA installed');
            }
            
            deferredPrompt = null;
            installButton.classList.remove('show');
        });

        // Register service worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/service-worker.js')
                .then(registration => console.log('Service Worker registered'))
                .catch(err => console.log('Service Worker registration failed', err));
        }
		// Register SW (once) and listen for "subscription changed"
(async () => {
  if (!('serviceWorker' in navigator)) return;

  const reg = await navigator.serviceWorker.register('/sw.js');

  navigator.serviceWorker.addEventListener('message', async (evt) => {
    if (evt.data?.type === 'PUSH_SUBSCRIPTION_CHANGED') {
      // Recreate the subscription and POST to the server
      const vapidKeyB64 = window.VAPID_PUBLIC_KEY; // expose this in your page
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidKeyB64),
      });
      await fetch('/api/push/subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(sub),
      });
    }
  });
})();

// Helper if you need it:
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  const output  = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) output[i] = rawData.charCodeAt(i);
  return output;
}

    </script>
</body>
</html>