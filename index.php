<?php
/**
 * Public Index Page with Status Checker
 * 
 * File Path: /index.php
 * Description: Public homepage with permit status checking by email
 * Created: 23/10/2025
 * Last Modified: 23/10/2025
 * 
 * Features:
 * - Shows available permit templates
 * - Email-based permit status checker
 * - Public permit creation (no login)
 * - Modern, responsive design
 * - PWA support ready
 */

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
        'active' => '<span class="badge badge-success">✅ Active</span>',
        'pending_approval' => '<span class="badge badge-warning">⏳ Awaiting Approval</span>',
        'pending' => '<span class="badge badge-warning">⏳ Pending</span>',
        'expired' => '<span class="badge badge-danger">❌ Expired</span>',
        'draft' => '<span class="badge badge-gray">📝 Draft</span>'
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
    <meta name="theme-color" content="#667eea">
    <meta name="description" content="Create and manage work permits easily">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icon-192.png">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-content h1 {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }

        .header-content p {
            color: #6b7280;
            font-size: 16px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }

        .btn-secondary:hover {
            background: #667eea;
            color: white;
        }

        /* Cards */
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .card-title {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 16px;
        }

        /* Status Checker */
        .status-checker {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border: 2px solid #667eea;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .status-checker h3 {
            font-size: 20px;
            color: #111827;
            margin-bottom: 16px;
        }

        .status-form {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .status-form input {
            flex: 1;
            min-width: 250px;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 15px;
        }

        .status-form input:focus {
            outline: none;
            border-color: #667eea;
        }

        .status-form button {
            padding: 12px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        /* Permit Results */
        .permit-results {
            margin-top: 24px;
        }

        .permit-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
            border-left: 4px solid #667eea;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }

        .permit-card:hover {
            transform: translateX(4px);
        }

        .permit-card.pending {
            border-left-color: #f59e0b;
        }

        .permit-card.approved {
            border-left-color: #10b981;
        }

        .permit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .permit-ref {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }

        .permit-info {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 8px;
        }

        .permit-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .permit-actions .btn {
            font-size: 13px;
            padding: 8px 16px;
        }

        /* Templates Grid */
        .templates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 24px;
        }

        .template-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .template-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transform: scaleX(0);
            transition: transform 0.3s;
        }

        .template-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
            border-color: #667eea;
        }

        .template-card:hover::before {
            transform: scaleX(1);
        }

        .template-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .template-name {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 8px;
        }

        .template-version {
            font-size: 14px;
            color: #6b7280;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 13px;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: #6b7280;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }

        .empty-state-title {
            font-size: 20px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }

        /* User Welcome */
        .user-welcome {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            background: #f3f4f6;
            border-radius: 8px;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
            }

            .header-content h1 {
                font-size: 24px;
            }

            .templates-grid {
                grid-template-columns: 1fr;
            }

            .status-form {
                flex-direction: column;
            }

            .status-form input {
                min-width: 100%;
            }
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 24px;
            color: rgba(255, 255, 255, 0.8);
            margin-top: 48px;
        }

        /* PWA Install Button */
        #installButton {
            display: none;
        }

        #installButton.show {
            display: inline-flex;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1>🛡️ Permit System</h1>
                <p>Create permits easily, check status anytime</p>
            </div>
            <div class="header-actions">
                <?php if ($isLoggedIn): ?>
                    <div class="user-welcome">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($currentUser['name'] ?? 'U', 0, 2)); ?>
                        </div>
                        <span><?php echo htmlspecialchars($currentUser['name'] ?? 'User'); ?></span>
                    </div>
                    <a href="/dashboard.php" class="btn btn-primary">
                        📊 Dashboard
                    </a>
                    <a href="/logout.php" class="btn btn-secondary">
                        Logout
                    </a>
                <?php else: ?>
                    <button id="installButton" class="btn btn-secondary">
                        📱 Install App
                    </button>
                    <a href="/login.php" class="btn btn-primary">
                        🔐 Manager Login
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status Checker -->
        <div class="card">
            <div class="status-checker">
                <h3>🔍 Check Your Permit Status</h3>
                <p style="color: #6b7280; margin-bottom: 16px;">
                    Enter your email to see all your permits and their current status
                </p>
                
                <form action="/" method="GET" class="status-form">
                    <input 
                        type="email" 
                        name="check_email" 
                        placeholder="Enter your email address"
                        value="<?php echo htmlspecialchars($statusEmail); ?>"
                        required
                    >
                    <button type="submit">🔍 Check Status</button>
                </form>

                <?php if (!empty($statusEmail)): ?>
                    <?php if (empty($userPermits)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <div class="empty-state-title">No permits found</div>
                            <p>We couldn't find any permits for <strong><?php echo htmlspecialchars($statusEmail); ?></strong></p>
                            <p style="margin-top: 12px;">
                                <a href="#templates" style="color: #667eea;">Create your first permit below</a>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="permit-results">
                            <h4 style="margin-bottom: 16px; color: #111827;">
                                Your Permits (<?php echo count($userPermits); ?>)
                            </h4>
                            
                            <?php foreach ($userPermits as $permit): ?>
                                <div class="permit-card <?php echo $permit['status'] === 'active' ? 'approved' : 'pending'; ?>">
                                    <div class="permit-header">
                                        <div class="permit-ref">
                                            <?php echo htmlspecialchars($permit['template_name']); ?>
                                            #<?php echo htmlspecialchars($permit['ref_number']); ?>
                                        </div>
                                        <?php echo getStatusBadge($permit['status']); ?>
                                    </div>
                                    
                                    <div class="permit-info">
                                        <strong>Submitted:</strong> <?php echo formatDateUK($permit['created_at']); ?>
                                    </div>
                                    
                                    <?php if ($permit['status'] === 'active' && $permit['valid_to']): ?>
                                        <div class="permit-info">
                                            <strong>Valid Until:</strong> <?php echo formatDateUK($permit['valid_to']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($permit['status'] === 'pending_approval'): ?>
                                        <div class="permit-info" style="color: #f59e0b;">
                                            ⏳ Your permit is being reviewed by a manager
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="permit-actions">
                                        <a href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>" class="btn btn-primary">
                                            👁️ View Details
                                        </a>
                                        <?php if ($permit['status'] === 'active'): ?>
                                            <a href="/view-permit-public.php?link=<?php echo urlencode($permit['unique_link']); ?>&print=1" class="btn btn-secondary">
                                                🖨️ Print
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Available Permit Templates -->
        <div class="card" id="templates">
            <h2 class="card-title">📋 Create New Permit</h2>
            <p style="color: #6b7280; margin-bottom: 24px;">
                Select a permit type to get started. No login required!
            </p>

            <?php if (empty($templates)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📄</div>
                    <div class="empty-state-title">No permit templates available</div>
                    <p>Contact your administrator to add permit templates.</p>
                </div>
            <?php else: ?>
                <div class="templates-grid">
                    <?php foreach ($templates as $template): ?>
                        <div class="template-card" onclick="window.location.href='/create-permit-public.php?template=<?php echo urlencode($template['id']); ?>'">
                            <div class="template-icon">
                                <?php echo getTemplateIcon($template['name']); ?>
                            </div>
                            <div class="template-name">
                                <?php echo htmlspecialchars($template['name']); ?>
                            </div>
                            <div class="template-version">
                                Version <?php echo htmlspecialchars($template['version']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
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