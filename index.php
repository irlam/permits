<?php
/**
 * Public Landing Experience with Status Tracking & Template Picker
 *
 * File Path: /index.php
 * Description: Public homepage that highlights permit activity and provides quick access to templates.
 * Created: 30/10/2025
 */

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

// Opportunistic expiry sweep
require_once __DIR__ . '/src/check-expiry.php';
if (function_exists('check_and_expire_permits')) {
    check_and_expire_permits($db);
}

// Session for login state
session_start();
$isLoggedIn = isset($_SESSION['user_id']);
$currentUser = null;
if ($isLoggedIn) {
    try {
        $stmt = $db->pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Error fetching current user: ' . $e->getMessage());
    }
}

// Fetch available permit templates
try {
    $templatesStmt = $db->pdo->query('SELECT id, name, version, created_at FROM form_templates ORDER BY name ASC');
    $templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $templates = [];
    error_log('Error fetching templates: ' . $e->getMessage());
}

// Platform stats
$systemStats = [
    'total' => 0,
    'active' => 0,
    'awaiting' => 0,
    'templates' => count($templates),
];

try {
    $statsStmt = $db->pdo->query('SELECT status, COUNT(*) AS total FROM forms GROUP BY status');
    $rows = $statsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $status = $row['status'] ?? '';
        $count = (int)($row['total'] ?? 0);
        $systemStats['total'] += $count;
        if ($status === 'active') {
            $systemStats['active'] = $count;
        } elseif (in_array($status, ['pending', 'pending_approval'], true)) {
            $systemStats['awaiting'] += $count;
        }
    }
} catch (Exception $e) {
    error_log('Error fetching permit stats: ' . $e->getMessage());
}

// Fetch recently approved permits (last 3)
try {
    $sql = "SELECT f.ref_number, f.holder_name, f.unique_link, f.valid_to, f.approved_at, f.created_at, ft.name AS template_name, f.id
            FROM forms f
            JOIN form_templates ft ON f.template_id = ft.id
            WHERE f.status = 'active'
            ORDER BY COALESCE(f.approved_at, f.created_at) DESC
            LIMIT 3";
    $approvedStmt = $db->pdo->query($sql);
    $approvedPermits = $approvedStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $approvedPermits = [];
    error_log('Error fetching approved permits: ' . $e->getMessage());
}

// Status checker (by email)
$statusEmail = $_GET['check_email'] ?? '';
$userPermits = [];
if (!empty($statusEmail) && filter_var($statusEmail, FILTER_VALIDATE_EMAIL)) {
    try {
        $stmt = $db->pdo->prepare('SELECT f.id, f.ref_number, f.status, f.valid_to, f.created_at, f.unique_link, ft.name AS template_name
                                   FROM forms f
                                   JOIN form_templates ft ON f.template_id = ft.id
                                   WHERE f.holder_email = ?
                                   ORDER BY f.created_at DESC
                                   LIMIT 10');
        $stmt->execute([$statusEmail]);
        $userPermits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Error fetching user permits: ' . $e->getMessage());
    }
}

// Template icon mapping
$templateIcons = [
    'hot-works-permit' => '🔥',
    'permit-to-dig' => '⛏️',
    'work-at-height-permit' => '🪜',
    'confined-space-entry-permit' => '🕳️',
    'electrical-isolation-energisation-permit' => '⚡️',
    'environmental-protection-permit' => '🌳',
    'hazardous-substances-handling-permit' => '☣️',
    'lifting-operations-permit' => '🏗️',
    'noise-vibration-control-permit' => '🔊',
    'roof-access-permit' => '🏠',
    'temporary-works-permit' => '🛠️',
    'traffic-management-interface-permit' => '🚦',
    'general-permit-to-work' => '📄',
    'default' => '📄',
];

function appUrl(string $path = '/'): string {
    global $app;
    return $app->url($path);
}

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
        'draft' => '<span class="badge badge-gray">📝 Draft</span>',
    ];
    return $badges[$status] ?? '<span class="badge badge-gray">' . htmlspecialchars($status) . '</span>';
}

function formatDateUK($date) {
    if (!$date) {
        return 'N/A';
    }
    $timestamp = strtotime($date);
    return date('d/m/Y H:i', $timestamp);
}

function getReopenLink($permitId) {
    return appUrl('create-permit-public.php?reopen=' . urlencode((string)$permitId));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit System - Create & Track Work Permits</title>
    <meta name="theme-color" content="#0ea5e9">
    <meta name="description" content="Create permits instantly, notify reviewers, and track status in real time.">
    <link rel="manifest" href="<?php echo htmlspecialchars(appUrl('manifest.webmanifest')); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(appUrl('icon-192.png')); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(appUrl('assets/app.css')); ?>">
</head>
<body class="theme-dark">
    <div class="page-shell">
        <header class="hero-banner">
            <div class="hero-banner__body">
                <span class="hero-kicker">Safety, streamlined</span>
                <h1>Permit System</h1>
                <p class="hero-lead">Lightning-fast permit creation, manager approvals, and live status tracking — available to every crew member on any device.</p>
                <div class="hero-actions">
                    <button type="button" class="btn btn-accent" id="openPermitPicker" aria-haspopup="dialog" aria-expanded="false">Browse Permit Templates</button>
                    <a href="#status-checker" class="btn btn-ghost">Check Status</a>
                </div>
                <div class="hero-actions hero-actions--secondary">
                    <?php if ($isLoggedIn): ?>
                        <span class="hero-user">👋 <?php echo htmlspecialchars($currentUser['name'] ?? 'User'); ?></span>
                        <a href="<?php echo htmlspecialchars(appUrl('dashboard.php')); ?>" class="btn btn-secondary">Dashboard</a>
                        <a href="<?php echo htmlspecialchars(appUrl('logout.php')); ?>" class="btn btn-secondary">Logout</a>
                    <?php else: ?>
                        <button id="installButton" class="btn btn-secondary">Install App</button>
                        <a href="<?php echo htmlspecialchars(appUrl('login.php')); ?>" class="btn btn-secondary">Manager Login</a>
                    <?php endif; ?>
                </div>
            </div>
            <ul class="hero-stats">
                <li class="hero-stat">
                    <span class="hero-stat__label">Active permits</span>
                    <span class="hero-stat__value"><?php echo number_format($systemStats['active']); ?></span>
                    <span class="hero-stat__delta">Across <?php echo number_format($systemStats['total']); ?> total permits</span>
                </li>
                <li class="hero-stat">
                    <span class="hero-stat__label">Awaiting approval</span>
                    <span class="hero-stat__value"><?php echo number_format($systemStats['awaiting']); ?></span>
                    <span class="hero-stat__delta">Managers notified instantly</span>
                </li>
                <li class="hero-stat">
                    <span class="hero-stat__label">Templates ready</span>
                    <span class="hero-stat__value"><?php echo number_format($systemStats['templates']); ?></span>
                    <span class="hero-stat__delta">Customise for every site</span>
                </li>
            </ul>
        </header>

        <main class="content-grid">
            <section class="panel" id="status-checker">
                <div class="panel__header">
                    <span class="panel__eyebrow">Live lookup</span>
                    <h2>Check your permit status</h2>
                    <p class="panel__lead">Enter the email used on your permit application to view recent activity and download documents.</p>
                </div>
                <div class="panel__body">
                    <form action="<?php echo htmlspecialchars(appUrl('index.php')); ?>" method="GET" class="status-form">
                        <input type="email" name="check_email" placeholder="you@company.com" value="<?php echo htmlspecialchars($statusEmail); ?>" required>
                        <button type="submit" class="btn btn-accent">Check Status</button>
                    </form>

                    <?php if (!empty($statusEmail)): ?>
                        <?php if (empty($userPermits)): ?>
                            <div class="empty-panel">
                                <span class="empty-panel__icon">📥</span>
                                <h3>No permits yet</h3>
                                <p>We couldn't find any permits for <strong><?php echo htmlspecialchars($statusEmail); ?></strong>. Start a new permit using the templates below.</p>
                                <a href="#templates" class="btn btn-secondary">Create a permit</a>
                            </div>
                        <?php else: ?>
                            <div class="permit-stack">
                                <?php foreach ($userPermits as $permit): ?>
                                    <article class="status-card">
                                        <header class="status-card__header">
                                            <div>
                                                <span class="status-card__title"><?php echo htmlspecialchars($permit['template_name']); ?></span>
                                                <span class="status-card__meta">Ref #<?php echo htmlspecialchars($permit['ref_number']); ?></span>
                                            </div>
                                            <?php echo getStatusBadge($permit['status']); ?>
                                        </header>
                                        <dl class="status-card__details">
                                            <div>
                                                <dt>Submitted</dt>
                                                <dd><?php echo formatDateUK($permit['created_at']); ?></dd>
                                            </div>
                                            <?php if (!empty($permit['valid_to'])): ?>
                                                <div>
                                                    <dt>Valid until</dt>
                                                    <dd><?php echo formatDateUK($permit['valid_to']); ?></dd>
                                                </div>
                                            <?php endif; ?>
                                        </dl>
                                        <?php if ($permit['status'] === 'pending_approval'): ?>
                                            <p class="status-card__note">Managers have been notified. You'll receive an update as soon as the permit is approved.</p>
                                        <?php endif; ?>
                                        <div class="status-card__actions">
                                            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(appUrl('view-permit-public.php?link=' . urlencode((string)$permit['unique_link']))); ?>">View</a>
                                            <?php if ($permit['status'] === 'active'): ?>
                                                <a class="btn btn-ghost" href="<?php echo htmlspecialchars(appUrl('view-permit-public.php?link=' . urlencode((string)$permit['unique_link']) . '&print=1')); ?>">Print</a>
                                                <a class="btn btn-ghost" href="<?php echo htmlspecialchars(getReopenLink($permit['id'])); ?>">Reopen</a>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel" id="approved-permits">
                <div class="panel__header">
                    <span class="panel__eyebrow">Fresh off the press</span>
                    <h2>Recently approved</h2>
                </div>
                <div class="panel__body">
                    <?php if (empty($approvedPermits)): ?>
                        <div class="empty-panel">
                            <span class="empty-panel__icon">✨</span>
                            <h3>No approvals yet</h3>
                            <p>Approved permits will appear here the moment a manager signs off. Check back soon!</p>
                        </div>
                    <?php else: ?>
                        <div class="card-carousel">
                            <?php foreach ($approvedPermits as $permit): ?>
                                <article class="recent-card">
                                    <header class="recent-card__header">
                                        <div>
                                            <span class="recent-card__title"><?php echo htmlspecialchars($permit['template_name']); ?></span>
                                            <span class="recent-card__meta">Ref #<?php echo htmlspecialchars($permit['ref_number']); ?></span>
                                        </div>
                                        <?php echo getStatusBadge('active'); ?>
                                    </header>
                                    <?php if (!empty($permit['holder_name'])): ?>
                                        <p class="recent-card__line"><strong>Permit holder:</strong> <?php echo htmlspecialchars($permit['holder_name']); ?></p>
                                    <?php endif; ?>
                                    <p class="recent-card__line"><strong>Approved:</strong> <?php echo formatDateUK($permit['approved_at'] ?? $permit['created_at']); ?></p>
                                    <?php if (!empty($permit['valid_to'])): ?>
                                        <p class="recent-card__line"><strong>Valid until:</strong> <?php echo formatDateUK($permit['valid_to']); ?></p>
                                    <?php endif; ?>
                                    <div class="recent-card__actions">
                                        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(appUrl('view-permit-public.php?link=' . urlencode((string)$permit['unique_link']))); ?>">View</a>
                                        <a class="btn btn-ghost" href="<?php echo htmlspecialchars(appUrl('view-permit-public.php?link=' . urlencode((string)$permit['unique_link']) . '&print=1')); ?>">Print</a>
                                        <a class="btn btn-ghost" href="<?php echo htmlspecialchars(appUrl('create-permit-public.php?reopen=' . urlencode((string)($permit['id'] ?? '')))); ?>">Reopen</a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel" id="templates">
                <div class="panel__header">
                    <span class="panel__eyebrow">Get started</span>
                    <h2>Create a new permit</h2>
                    <p class="panel__lead">Pick a template and capture the right safety details in seconds.</p>
                </div>
                <div class="panel__body">
                    <?php if (empty($templates)): ?>
                        <div class="empty-panel">
                            <span class="empty-panel__icon">📄</span>
                            <h3>No permit templates available</h3>
                            <p>Contact your administrator to upload or create permit templates.</p>
                        </div>
                    <?php else: ?>
                        <div class="template-gallery">
                            <?php foreach ($templates as $template): ?>
                                <a class="template-tile" href="<?php echo htmlspecialchars(appUrl('create-permit-public.php?template=' . urlencode((string)$template['id']))); ?>">
                                    <span class="template-tile__icon"><?php echo getTemplateIcon($template['name']); ?></span>
                                    <span class="template-tile__name"><?php echo htmlspecialchars($template['name']); ?></span>
                                    <span class="template-tile__meta">Version <?php echo htmlspecialchars($template['version'] ?? '1'); ?></span>
                                    <span class="template-tile__cta">Start permit →</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="panel panel--info">
                <div class="panel__header">
                    <span class="panel__eyebrow">Why teams love it</span>
                    <h2>Built for everyday permit workflows</h2>
                </div>
                <div class="panel__body panel__body--grid">
                    <div class="info-card">
                        <h3>⚡ Rapid submissions</h3>
                        <p>Kick off jobs in under a minute with reusable templates designed for your site.</p>
                    </div>
                    <div class="info-card">
                        <h3>🔔 Smart notifications</h3>
                        <p>Supervisors and managers receive instant alerts, keeping approvals moving forward.</p>
                    </div>
                    <div class="info-card">
                        <h3>📱 Works everywhere</h3>
                        <p>Optimised for desktop, tablet, and mobile so teams can stay focused on the work.</p>
                    </div>
                </div>
            </section>
        </main>

        <footer class="page-footer">
            <p class="page-footer__note">© <?php echo date('Y'); ?> Permit System — Empowering safe, compliant work every day.</p>
        </footer>
    </div>

    <div class="permit-modal" id="permitModal" data-open="0" aria-hidden="true" hidden>
        <div class="permit-modal__backdrop" data-dismiss></div>
        <div class="permit-modal__dialog" role="dialog" aria-label="Choose a permit type" tabindex="-1">
            <header class="permit-modal__header">
                <div>
                    <span class="permit-modal__eyebrow">Quick start</span>
                    <h3>Choose a permit type</h3>
                </div>
                <button type="button" class="permit-modal__close btn btn-ghost" data-dismiss aria-label="Close permit picker">Close ×</button>
            </header>
            <div class="permit-modal__body">
                <?php if (empty($templates)): ?>
                    <div class="empty-panel">
                        <span class="empty-panel__icon">📄</span>
                        <h3>No permit templates available</h3>
                        <p>Contact your administrator to upload or create permit templates.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($templates as $template): ?>
                        <a class="permit-modal__link" href="<?php echo htmlspecialchars(appUrl('create-permit-public.php?template=' . urlencode((string)$template['id']))); ?>">
                            <span class="permit-modal__icon"><?php echo getTemplateIcon($template['name']); ?></span>
                            <span class="name"><?php echo htmlspecialchars($template['name']); ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="<?php echo htmlspecialchars(appUrl('assets/app.js')); ?>"></script>
    <script>
        (function() {
            var trigger = document.getElementById('openPermitPicker');
            var modal = document.getElementById('permitModal');
            if (!trigger || !modal) return;

            var dialog = modal.querySelector('.permit-modal__dialog');
            var dismissEls = modal.querySelectorAll('[data-dismiss]');
            var active = false;
            var hideTimer = null;

            function openModal(e) {
                if (e) e.preventDefault();
                if (active) {
                    closeModal();
                    return;
                }
                if (hideTimer) {
                    clearTimeout(hideTimer);
                    hideTimer = null;
                }
                active = true;
                modal.removeAttribute('hidden');
                modal.setAttribute('data-open', '1');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                trigger.setAttribute('aria-expanded', 'true');
                setTimeout(function() {
                    if (dialog && dialog.focus) {
                        dialog.focus({ preventScroll: true });
                    }
                }, 0);
            }

            function closeModal(e) {
                if (e) e.preventDefault();
                if (!active) return;
                active = false;
                modal.setAttribute('data-open', '0');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                trigger.setAttribute('aria-expanded', 'false');
                if (document.activeElement && document.activeElement !== trigger) {
                    trigger.focus({ preventScroll: true });
                }
                hideTimer = setTimeout(function() {
                    modal.setAttribute('hidden', '');
                }, 280);
            }

            trigger.addEventListener('click', openModal);
            dismissEls.forEach(function(el) {
                el.addEventListener('click', closeModal);
            });
            modal.addEventListener('click', function(ev) {
                if (ev.target === modal) {
                    closeModal(ev);
                }
            });
            document.addEventListener('keydown', function(ev) {
                if (active && ev.key === 'Escape') {
                    closeModal(ev);
                }
            });
        })();

        (function() {
            function updateCarousels() {
                document.querySelectorAll('.card-carousel').forEach(function(track) {
                    var overflowing = track.scrollWidth > track.clientWidth + 1;
                    track.setAttribute('data-overflow', overflowing ? '1' : '0');
                });
            }

            if (window.ResizeObserver) {
                var observer = new ResizeObserver(updateCarousels);
                window.addEventListener('load', function() {
                    document.querySelectorAll('.card-carousel').forEach(function(track) {
                        observer.observe(track);
                    });
                    updateCarousels();
                });
            } else {
                window.addEventListener('load', updateCarousels);
            }

            window.addEventListener('resize', updateCarousels);
        })();
    </script>
</body>
</html>
