<?php
/**
 * Public Landing Page entry point.
 */

use Permits\DatabaseMaintenance;
use Permits\FormTemplateSeeder;
use Permits\SystemSettings;

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

require_once __DIR__ . '/src/check-expiry.php';
if (function_exists('check_and_expire_permits')) {
	check_and_expire_permits($db);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

$isLoggedIn = isset($_SESSION['user_id']);
$currentUser = null;
if ($isLoggedIn) {
	try {
		$stmt = $db->pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
		$stmt->execute([$_SESSION['user_id']]);
		$currentUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	} catch (Throwable $e) {
		error_log('Error fetching current user: ' . $e->getMessage());
	}
}

$templates = [];
try {
	$templatesStmt = $db->pdo->query('SELECT id, name, version, created_at FROM form_templates ORDER BY name ASC');
	$templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
	error_log('Error fetching templates: ' . $e->getMessage());
}

if (empty($templates)) {
	try {
		$columns = DatabaseMaintenance::ensureFormTemplateColumns($db);
		foreach ($columns['errors'] ?? [] as $error) {
			error_log('Template column error: ' . $error);
		}

		$seedResult = FormTemplateSeeder::importFromDirectory($db, $root . '/templates/form-presets');
		foreach ($seedResult['errors'] ?? [] as $error) {
			error_log('Template seed error: ' . $error);
		}

		if (!empty($seedResult['imported'])) {
			$templatesStmt = $db->pdo->query('SELECT id, name, version, created_at FROM form_templates ORDER BY name ASC');
			$templates = $templatesStmt->fetchAll(PDO::FETCH_ASSOC);
		}
	} catch (Throwable $e) {
		error_log('Template auto-import failed: ' . $e->getMessage());
	}
}

$systemStats = [
	'total' => 0,
	'active' => 0,
	'awaiting' => 0,
	'templates' => count($templates),
];

try {
	$statsStmt = $db->pdo->query('SELECT status, COUNT(*) AS total FROM forms GROUP BY status');
	$rows = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

	$activeStatuses = ['active', 'issued', 'approved', 'open'];
	$awaitingStatuses = ['pending', 'pending_approval', 'awaiting', 'awaiting_approval', 'submitted'];

	foreach ($rows as $row) {
		$status = strtolower((string)($row['status'] ?? ''));
		$count = (int)($row['total'] ?? 0);

		$systemStats['total'] += $count;
		if (in_array($status, $activeStatuses, true)) {
			$systemStats['active'] += $count;
		}
		if (in_array($status, $awaitingStatuses, true)) {
			$systemStats['awaiting'] += $count;
		}
	}
} catch (Throwable $e) {
	error_log('Error fetching permit stats: ' . $e->getMessage());
}

$recentPermits = [];
try {
	$sql = "SELECT f.id, f.ref_number, f.holder_name, f.unique_link, f.valid_to, f.approved_at, f.created_at, f.status, ft.name AS template_name\n            FROM forms f\n            INNER JOIN form_templates ft ON f.template_id = ft.id\n            WHERE f.status IN ('active', 'issued', 'approved')\n            ORDER BY COALESCE(f.approved_at, f.updated_at, f.created_at) DESC\n            LIMIT 4";
	$recentStmt = $db->pdo->query($sql);
	$recentPermits = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
	error_log('Error fetching recent permits: ' . $e->getMessage());
}

$statusEmail = trim((string)($_GET['check_email'] ?? ''));
$userPermits = [];
if ($statusEmail !== '' && filter_var($statusEmail, FILTER_VALIDATE_EMAIL)) {
	try {
		$lookup = $db->pdo->prepare('SELECT f.id, f.ref_number, f.status, f.valid_to, f.created_at, f.unique_link, ft.name AS template_name\n                                      FROM forms f\n                                      INNER JOIN form_templates ft ON f.template_id = ft.id\n                                      WHERE f.holder_email = ?\n                                      ORDER BY f.created_at DESC\n                                      LIMIT 10');
		$lookup->execute([$statusEmail]);
		$userPermits = $lookup->fetchAll(PDO::FETCH_ASSOC);
	} catch (Throwable $e) {
		error_log('Error fetching user permits: ' . $e->getMessage());
	}
}

$companyName = SystemSettings::companyName($db) ?? 'Permit System';
$companyLogoPath = SystemSettings::companyLogoPath($db);
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;

$templateIcons = [
	'hot-works-permit' => '&#128293;',
	'permit-to-dig' => '&#9935;',
	'work-at-height-permit' => '&#128692;',
	'confined-space-entry-permit' => '&#128371;',
	'electrical-isolation-energisation-permit' => '&#9889;',
	'environmental-protection-permit' => '&#127795;',
	'hazardous-substances-handling-permit' => '&#9762;',
	'lifting-operations-permit' => '&#128679;',
	'noise-vibration-control-permit' => '&#128266;',
	'roof-access-permit' => '&#127968;',
	'temporary-works-permit' => '&#9881;',
	'traffic-management-interface-permit' => '&#128678;',
	'general-permit-to-work' => '&#128221;',
	'default' => '&#128196;',
];

function slugify_template_name(string $name): string
{
	$slug = strtolower($name);
	$slug = str_replace('&', 'and', $slug);
	$slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
	return trim((string)$slug, '-');
}

function template_icon(string $templateName, array $icons): string
{
	$slug = slugify_template_name($templateName);
	return $icons[$slug] ?? $icons['default'];
}

function status_badge(string $status): string
{
	$statusLower = strtolower($status);
	$map = [
		'active' => ['label' => 'Active', 'class' => 'status-badge--success'],
		'issued' => ['label' => 'Issued', 'class' => 'status-badge--success'],
		'approved' => ['label' => 'Approved', 'class' => 'status-badge--success'],
		'pending' => ['label' => 'Pending Review', 'class' => 'status-badge--warning'],
		'pending_approval' => ['label' => 'Awaiting Approval', 'class' => 'status-badge--warning'],
		'awaiting' => ['label' => 'Awaiting Action', 'class' => 'status-badge--warning'],
		'awaiting_approval' => ['label' => 'Awaiting Approval', 'class' => 'status-badge--warning'],
		'draft' => ['label' => 'Draft', 'class' => 'status-badge--neutral'],
		'closed' => ['label' => 'Closed', 'class' => 'status-badge--neutral'],
		'expired' => ['label' => 'Expired', 'class' => 'status-badge--danger'],
		'rejected' => ['label' => 'Rejected', 'class' => 'status-badge--danger'],
	];

	$meta = $map[$statusLower] ?? ['label' => ucfirst($statusLower ?: 'Unknown'), 'class' => 'status-badge--neutral'];

	return '<span class="status-badge ' . htmlspecialchars($meta['class'], ENT_QUOTES, 'UTF-8') . '">' .
		'<span>' . htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') . '</span>' .
		'</span>';
}

function format_date_local(?string $date): string
{
	if (empty($date)) {
		return 'N/A';
	}

	try {
		$dt = new DateTimeImmutable($date);
	} catch (Throwable $e) {
		return htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
	}

	return $dt->format('d/m/Y H:i');
}

function reopen_link($app, $permitId): string
{
	return $app->url('/create-permit-public.php?reopen=' . urlencode((string)$permitId));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Permit System · Safe Work Starts Here</title>
	<meta name="description" content="Create permits, track approvals, and stay compliant from any device.">
	<meta name="theme-color" content="#0ea5e9">
	<?php if (function_exists('cache_meta_tags')) { cache_meta_tags(); } ?>
	<link rel="manifest" href="<?php echo htmlspecialchars(asset('manifest.webmanifest'), ENT_QUOTES, 'UTF-8'); ?>">
	<link rel="apple-touch-icon" href="<?php echo htmlspecialchars(asset('icon-192.png'), ENT_QUOTES, 'UTF-8'); ?>">
	<link rel="stylesheet" href="<?php echo htmlspecialchars(asset('assets/app.css'), ENT_QUOTES, 'UTF-8'); ?>">
	<style>
		body.landing-body {
			margin: 0;
			background: #0f172a;
			color: #e5e7eb;
			font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
		}
		.site-shell {
			max-width: 1200px;
			margin: 0 auto;
			padding: clamp(32px, 6vw, 72px) clamp(16px, 5vw, 56px) 96px;
			display: grid;
			gap: clamp(32px, 6vw, 64px);
		}
		.hero {
			background: radial-gradient(circle at top left, rgba(56, 189, 248, 0.3), rgba(14, 165, 233, 0.1) 42%),
						linear-gradient(135deg, rgba(30, 58, 138, 0.85), rgba(15, 23, 42, 0.92));
			border-radius: 28px;
			padding: clamp(32px, 6vw, 56px);
			display: grid;
			gap: clamp(24px, 4vw, 40px);
			position: relative;
			overflow: hidden;
		}
		.hero::after {
			content: '';
			position: absolute;
			inset: 0;
			background: radial-gradient(circle at 80% 20%, rgba(59, 130, 246, 0.22), transparent 60%);
			pointer-events: none;
		}
		.hero__content {
			position: relative;
			z-index: 1;
			display: grid;
			gap: 16px;
		}
		.hero__headline {
			font-size: clamp(38px, 8vw, 64px);
			margin: 0;
			letter-spacing: -0.02em;
		}
		.hero__lead {
			margin: 0;
			max-width: 640px;
			font-size: clamp(18px, 2.4vw, 22px);
			line-height: 1.6;
			color: rgba(226, 232, 240, 0.92);
		}
		.hero__actions {
			display: flex;
			flex-wrap: wrap;
			gap: 12px;
		}
	.hero__brand {
		display: inline-flex;
		align-items: center;
		gap: 16px;
		margin-bottom: 20px;
	}
	.hero__brand .brand-mark__logo {
		width: 64px;
		height: 64px;
	}
	.hero__brand .brand-mark__name {
		font-size: 28px;
	}
	.hero__brand .brand-mark__sub {
		font-size: 15px;
		color: rgba(148, 163, 184, 0.88);
	}
		.btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 8px;
			padding: 12px 18px;
			border-radius: 999px;
			border: none;
			cursor: pointer;
			font-size: 15px;
			font-weight: 600;
			text-decoration: none;
			transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
		}
		.btn:focus-visible {
			outline: 2px solid rgba(59, 130, 246, 0.6);
			outline-offset: 3px;
		}
		.btn-accent {
			background: linear-gradient(135deg, #0ea5e9, #38bdf8);
			color: #0f172a;
			box-shadow: 0 10px 30px rgba(14, 165, 233, 0.35);
		}
		.btn-accent:hover {
			transform: translateY(-2px);
		}
		.btn-secondary {
			background: rgba(15, 23, 42, 0.72);
			color: #e2e8f0;
			border: 1px solid rgba(148, 163, 184, 0.4);
		}
		.btn-secondary:hover {
			background: rgba(15, 23, 42, 0.9);
		}
		.btn-ghost {
			background: transparent;
			color: #e2e8f0;
			border: 1px solid rgba(148, 163, 184, 0.35);
		}
		.nav-actions {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			align-items: center;
			position: relative;
			z-index: 1;
		}
		.nav-actions__welcome {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			background: rgba(15, 23, 42, 0.4);
			border-radius: 999px;
			padding: 10px 16px;
			font-size: 14px;
		}
		.stats-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 16px;
			position: relative;
			z-index: 1;
		}
		.stat-card {
			background: rgba(15, 23, 42, 0.88);
			border: 1px solid rgba(148, 163, 184, 0.2);
			border-radius: 18px;
			padding: 18px 20px;
			display: grid;
			gap: 6px;
		}
		.stat-card__label {
			font-size: 12px;
			letter-spacing: 0.12em;
			text-transform: uppercase;
			color: rgba(148, 163, 184, 0.75);
		}
		.stat-card__value {
			font-size: clamp(28px, 3.5vw, 40px);
			font-weight: 700;
		}
		.stat-card__meta {
			color: rgba(203, 213, 225, 0.8);
			font-size: 14px;
		}
		.section {
			background: rgba(15, 23, 42, 0.92);
			border-radius: 26px;
			border: 1px solid rgba(148, 163, 184, 0.16);
			padding: clamp(28px, 4vw, 44px);
			display: grid;
			gap: clamp(18px, 3vw, 28px);
		}
		.section__header {
			display: grid;
			gap: 8px;
		}
		.section__title {
			margin: 0;
			font-size: clamp(22px, 3vw, 30px);
		}
		.section__lead {
			margin: 0;
			color: rgba(203, 213, 225, 0.85);
			max-width: 640px;
		}
		.status-form {
			display: grid;
			gap: 12px;
			max-width: 520px;
		}
		.status-form input[type=email] {
			min-height: 48px;
			border-radius: 12px;
			border: 1px solid rgba(148, 163, 184, 0.28);
			background: rgba(15, 23, 42, 0.8);
			padding: 12px 16px;
			color: #e5e7eb;
			font-size: 16px;
		}
		.status-form input[type=email]:focus {
			outline: 2px solid rgba(14, 165, 233, 0.65);
			outline-offset: 2px;
		}
		.permit-list {
			display: grid;
			gap: 16px;
		}
		.permit-card {
			border-radius: 18px;
			border: 1px solid rgba(148, 163, 184, 0.18);
			background: rgba(15, 23, 42, 0.86);
			padding: 20px;
			display: grid;
			gap: 12px;
		}
		.permit-card__header {
			display: flex;
			justify-content: space-between;
			gap: 12px;
			flex-wrap: wrap;
		}
		.permit-card__title {
			font-size: 18px;
			font-weight: 600;
			margin: 0;
		}
		.permit-card__meta {
			font-size: 14px;
			color: rgba(148, 163, 184, 0.85);
		}
		.permit-card__details {
			display: grid;
			gap: 8px;
			font-size: 15px;
			color: rgba(226, 232, 240, 0.92);
		}
		.permit-card__actions {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
		}
		.empty-state {
			border-radius: 18px;
			border: 1px dashed rgba(148, 163, 184, 0.35);
			padding: 24px;
			text-align: center;
			display: grid;
			gap: 12px;
			justify-items: center;
			background: rgba(15, 23, 42, 0.78);
		}
		.empty-state__icon {
			font-size: 32px;
		}
		.template-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
			gap: 16px;
		}
		.template-tile {
			display: grid;
			gap: 10px;
			padding: 18px;
			border-radius: 18px;
			border: 1px solid rgba(148, 163, 184, 0.18);
			background: rgba(15, 23, 42, 0.88);
			text-decoration: none;
			color: inherit;
			transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease;
		}
		.template-tile:hover,
		.template-tile:focus-visible {
			transform: translateY(-4px);
			border-color: rgba(56, 189, 248, 0.45);
			background: rgba(15, 23, 42, 0.94);
			outline: none;
		}
		.template-tile__icon {
			font-size: 30px;
		}
		.template-tile__name {
			font-size: 18px;
			font-weight: 600;
		}
		.template-tile__meta {
			font-size: 14px;
			color: rgba(148, 163, 184, 0.85);
		}
		.status-badge {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 6px 12px;
			border-radius: 999px;
			font-size: 13px;
			font-weight: 600;
			letter-spacing: 0.01em;
			background: rgba(148, 163, 184, 0.2);
			color: #e5e7eb;
		}
		.status-badge--success { background: rgba(34, 197, 94, 0.2); color: #bbf7d0; }
		.status-badge--warning { background: rgba(234, 179, 8, 0.2); color: #fde68a; }
		.status-badge--danger { background: rgba(248, 113, 113, 0.22); color: #fecaca; }
		.status-badge--neutral { background: rgba(148, 163, 184, 0.28); color: #e5e7eb; }
		.recent-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
			gap: 16px;
		}
		.recent-card {
			border-radius: 18px;
			border: 1px solid rgba(148, 163, 184, 0.18);
			background: rgba(15, 23, 42, 0.88);
			padding: 20px;
			display: grid;
			gap: 12px;
		}
		.recent-card__header {
			display: flex;
			justify-content: space-between;
			gap: 12px;
			flex-wrap: wrap;
		}
		.recent-card__title {
			margin: 0;
			font-size: 18px;
			font-weight: 600;
		}
		.recent-card__meta {
			font-size: 14px;
			color: rgba(148, 163, 184, 0.85);
		}
		.recent-card__line {
			margin: 0;
			font-size: 15px;
			color: rgba(226, 232, 240, 0.9);
		}
		.site-footer {
			text-align: center;
			color: rgba(148, 163, 184, 0.8);
			font-size: 14px;
		}
		.template-modal {
			position: fixed;
			inset: 0;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
			background: rgba(15, 23, 42, 0.7);
			backdrop-filter: blur(6px);
			z-index: 2000;
		}
		.template-modal[hidden] { display: none; }
		.template-modal__dialog {
			background: rgba(15, 23, 42, 0.97);
			border-radius: 24px;
			border: 1px solid rgba(148, 163, 184, 0.2);
			width: min(600px, 100%);
			max-height: min(80vh, 620px);
			padding: clamp(24px, 4vw, 36px);
			display: grid;
			gap: 18px;
			overflow: hidden;
			outline: none;
		}
		.template-modal__body {
			overflow-y: auto;
			display: grid;
			gap: 12px;
			padding-right: 6px;
		}
		.modal-open {
			overflow: hidden;
		}
		@media (max-width: 720px) {
			.hero {
				border-radius: 22px;
			}
			.hero__actions,
			.nav-actions {
				flex-direction: column;
				align-items: stretch;
			}
			.hero__actions .btn,
			.nav-actions .btn,
			.nav-actions__welcome {
				width: 100%;
				justify-content: center;
			}
			.template-modal__dialog {
				max-height: 90vh;
			}
		}
	</style>
</head>
<body class="landing-body">
	<div class="site-shell">
		<header class="hero">
			<div class="hero__content">
				<div class="brand-mark hero__brand">
					<?php if ($companyLogoUrl): ?>
						<img src="<?= $companyLogoUrl ?>" alt="<?= htmlspecialchars($companyName) ?> logo" class="brand-mark__logo">
					<?php endif; ?>
					<div>
						<div class="brand-mark__name"><?= htmlspecialchars($companyName) ?></div>
						<div class="brand-mark__sub">Permit System</div>
					</div>
				</div>
				<p class="nav-actions">
					<?php if ($isLoggedIn && !empty($currentUser)): ?>
						<span class="nav-actions__welcome">Welcome <?php echo htmlspecialchars($currentUser['name'] ?? 'Manager', ENT_QUOTES, 'UTF-8'); ?></span>
						<a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('/dashboard.php'), ENT_QUOTES, 'UTF-8'); ?>">Dashboard</a>
						<a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('/logout.php'), ENT_QUOTES, 'UTF-8'); ?>">Logout</a>
					<?php else: ?>
						<button class="btn btn-secondary" type="button" id="installButton">Install App</button>
						<a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('/login.php'), ENT_QUOTES, 'UTF-8'); ?>">Manager Login</a>
					<?php endif; ?>
				</p>
				<h1 class="hero__headline">Permit System</h1>
				<p class="hero__lead">Create permits in minutes, notify reviewers instantly, and keep every job compliant from mobile, desktop, or site kiosk.</p>
				<div class="hero__actions">
					<button class="btn btn-accent" type="button" data-template-modal="open">Browse Permit Templates</button>
					<a class="btn btn-ghost" href="#status-checker">Check Application Status</a>
				</div>
			</div>
			<div class="stats-grid">
				<article class="stat-card">
					<span class="stat-card__label">Active permits</span>
					<span class="stat-card__value"><?php echo number_format($systemStats['active']); ?></span>
					<span class="stat-card__meta">Live jobs in circulation</span>
				</article>
				<article class="stat-card">
					<span class="stat-card__label">Awaiting approval</span>
					<span class="stat-card__value"><?php echo number_format($systemStats['awaiting']); ?></span>
					<span class="stat-card__meta">Managers notified</span>
				</article>
				<article class="stat-card">
					<span class="stat-card__label">Total permits</span>
					<span class="stat-card__value"><?php echo number_format($systemStats['total']); ?></span>
					<span class="stat-card__meta">Tracked in the system</span>
				</article>
				<article class="stat-card">
					<span class="stat-card__label">Templates ready</span>
					<span class="stat-card__value"><?php echo number_format($systemStats['templates']); ?></span>
					<span class="stat-card__meta">Tailored for your workflows</span>
				</article>
			</div>
		</header>

		<main class="main-content" aria-live="polite">
			<section class="section" id="status-checker">
				<header class="section__header">
					<h2 class="section__title">Check a permit status</h2>
					<p class="section__lead">Enter the email used on your permit application to see the latest updates, download paperwork, or reopen an active permit.</p>
				</header>

				<form class="status-form" action="<?php echo htmlspecialchars($app->url('/'), ENT_QUOTES, 'UTF-8'); ?>" method="get" novalidate>
					<label class="sr-only" for="check_email">Permit email address</label>
					<input type="email" id="check_email" name="check_email" placeholder="you@company.com" value="<?php echo htmlspecialchars($statusEmail, ENT_QUOTES, 'UTF-8'); ?>" required>
					<button class="btn btn-accent" type="submit">Look up permit</button>
				</form>

				<?php if ($statusEmail !== ''): ?>
					<?php if (empty($userPermits)): ?>
						<div class="empty-state">
							<span class="empty-state__icon">&#128233;</span>
							<h3>No permits yet</h3>
							<p>We could not find any permits associated with <strong><?php echo htmlspecialchars($statusEmail, ENT_QUOTES, 'UTF-8'); ?></strong>. Start a new permit below.</p>
							<button class="btn btn-secondary" type="button" data-template-modal="open">Create a permit</button>
						</div>
					<?php else: ?>
						<div class="permit-list" role="list">
							<?php foreach ($userPermits as $permit): ?>
								<article class="permit-card" role="listitem">
									<header class="permit-card__header">
										<div>
											<h3 class="permit-card__title"><?php echo htmlspecialchars($permit['template_name'] ?? 'Permit', ENT_QUOTES, 'UTF-8'); ?></h3>
											<span class="permit-card__meta">Ref #<?php echo htmlspecialchars($permit['ref_number'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
										</div>
										<?php echo status_badge($permit['status'] ?? ''); ?>
									</header>
									<div class="permit-card__details">
										<span><strong>Submitted:</strong> <?php echo format_date_local($permit['created_at'] ?? null); ?></span>
										<?php if (!empty($permit['valid_to'])): ?>
											<span><strong>Valid until:</strong> <?php echo format_date_local($permit['valid_to']); ?></span>
										<?php endif; ?>
									</div>
									<?php if (strtolower((string)($permit['status'] ?? '')) === 'pending_approval'): ?>
										<p class="permit-card__meta">Managers have been notified. You will receive an email when a decision is made.</p>
									<?php endif; ?>
									<div class="permit-card__actions">
										<a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('/view-permit-public.php?link=' . urlencode((string)($permit['unique_link'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>">View</a>
										<?php $statusLower = strtolower((string)($permit['status'] ?? '')); ?>
										<?php if (in_array($statusLower, ['active', 'issued', 'approved'], true)): ?>
											<a class="btn btn-ghost" href="<?php echo htmlspecialchars($app->url('/view-permit-public.php?link=' . urlencode((string)($permit['unique_link'] ?? '')) . '&print=1'), ENT_QUOTES, 'UTF-8'); ?>">Print</a>
											<a class="btn btn-ghost" href="<?php echo htmlspecialchars(reopen_link($app, $permit['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">Reopen</a>
										<?php endif; ?>
									</div>
								</article>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<section class="section" id="recent-activity">
				<header class="section__header">
					<h2 class="section__title">Recently approved permits</h2>
					<p class="section__lead">The latest permits signed off by managers. Reopen a job or download paperwork instantly.</p>
				</header>

				<?php if (empty($recentPermits)): ?>
					<div class="empty-state">
						<span class="empty-state__icon">&#10024;</span>
						<h3>All quiet for now</h3>
						<p>Approved permits will appear here as soon as managers sign them off.</p>
					</div>
				<?php else: ?>
					<div class="recent-grid" role="list">
						<?php foreach ($recentPermits as $permit): ?>
							<article class="recent-card" role="listitem">
								<header class="recent-card__header">
									<div>
										<h3 class="recent-card__title"><?php echo htmlspecialchars($permit['template_name'] ?? 'Permit', ENT_QUOTES, 'UTF-8'); ?></h3>
										<span class="recent-card__meta">Ref #<?php echo htmlspecialchars($permit['ref_number'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span>
									</div>
									<?php echo status_badge($permit['status'] ?? ''); ?>
								</header>
								<?php if (!empty($permit['holder_name'])): ?>
									<p class="recent-card__line"><strong>Permit holder:</strong> <?php echo htmlspecialchars($permit['holder_name'], ENT_QUOTES, 'UTF-8'); ?></p>
								<?php endif; ?>
								<p class="recent-card__line"><strong>Approved:</strong> <?php echo format_date_local($permit['approved_at'] ?? $permit['created_at'] ?? null); ?></p>
								<?php if (!empty($permit['valid_to'])): ?>
									<p class="recent-card__line"><strong>Valid until:</strong> <?php echo format_date_local($permit['valid_to']); ?></p>
								<?php endif; ?>
								<div class="permit-card__actions">
									<a class="btn btn-secondary" href="<?php echo htmlspecialchars($app->url('/view-permit-public.php?link=' . urlencode((string)($permit['unique_link'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>">View</a>
									<a class="btn btn-ghost" href="<?php echo htmlspecialchars($app->url('/view-permit-public.php?link=' . urlencode((string)($permit['unique_link'] ?? '')) . '&print=1'), ENT_QUOTES, 'UTF-8'); ?>">Print</a>
									<a class="btn btn-ghost" href="<?php echo htmlspecialchars(reopen_link($app, $permit['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">Reopen</a>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<section class="section" id="templates">
				<header class="section__header">
					<h2 class="section__title">Create a new permit</h2>
					<p class="section__lead">Pick a template to launch the correct checklist, controls, and approvals for your next task.</p>
				</header>

				<?php if (empty($templates)): ?>
					<div class="empty-state">
						<span class="empty-state__icon">&#128196;</span>
						<h3>No templates available</h3>
						<p>Your administrator can add templates from the dashboard. Once published, they will appear here automatically.</p>
					</div>
				<?php else: ?>
					<div class="template-grid" role="list">
						<?php foreach ($templates as $template): ?>
							<a class="template-tile" role="listitem" href="<?php echo htmlspecialchars($app->url('/create-permit-public.php?template=' . urlencode((string)($template['id'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>">
								<span class="template-tile__icon"><?php echo template_icon($template['name'] ?? '', $templateIcons); ?></span>
								<span class="template-tile__name"><?php echo htmlspecialchars($template['name'] ?? 'Permit template', ENT_QUOTES, 'UTF-8'); ?></span>
								<span class="template-tile__meta">Version <?php echo htmlspecialchars((string)($template['version'] ?? '1'), ENT_QUOTES, 'UTF-8'); ?></span>
								<span class="template-tile__meta">Tap to start</span>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</main>

		<footer class="site-footer">
			© <?php echo date('Y'); ?> Permit System · Empowering safe, compliant work every day.
		</footer>
	</div>

	<div class="template-modal" id="templateModal" role="dialog" aria-modal="true" aria-labelledby="templateModalTitle" hidden>
		<div class="template-modal__dialog" tabindex="-1">
			<header class="section__header" style="margin:0;">
				<div>
					<h2 id="templateModalTitle" class="section__title" style="margin:0; font-size:24px;">Choose a permit template</h2>
					<p class="section__lead" style="margin:8px 0 0;">Select the permit you want to raise. Templates open in a step-by-step form.</p>
				</div>
				<button class="btn btn-ghost" type="button" data-template-modal="close" aria-label="Close template picker">Close</button>
			</header>
			<div class="template-modal__body">
				<?php if (empty($templates)): ?>
					<div class="empty-state" style="margin:0;">
						<span class="empty-state__icon">&#128196;</span>
						<h3>No templates available</h3>
						<p>Templates published by your administrator will show here automatically.</p>
					</div>
				<?php else: ?>
					<?php foreach ($templates as $template): ?>
						<a class="template-tile" href="<?php echo htmlspecialchars($app->url('/create-permit-public.php?template=' . urlencode((string)($template['id'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>">
							<span class="template-tile__icon"><?php echo template_icon($template['name'] ?? '', $templateIcons); ?></span>
							<span class="template-tile__name"><?php echo htmlspecialchars($template['name'] ?? 'Permit template', ENT_QUOTES, 'UTF-8'); ?></span>
							<span class="template-tile__meta">Version <?php echo htmlspecialchars((string)($template['version'] ?? '1'), ENT_QUOTES, 'UTF-8'); ?></span>
						</a>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<div style="display:flex; justify-content:flex-end;">
				<button class="btn btn-secondary" type="button" data-template-modal="close">Close</button>
			</div>
		</div>
	</div>

	<script src="<?php echo htmlspecialchars(asset('assets/app.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var modal = document.getElementById('templateModal');
			if (!modal) {
				return;
			}

			var openers = document.querySelectorAll('[data-template-modal="open"]');
			var closers = modal.querySelectorAll('[data-template-modal="close"]');

			var lockScroll = function () {
				document.body.classList.add('modal-open');
			};

			var unlockScroll = function () {
				document.body.classList.remove('modal-open');
			};

			var closeModal = function (event) {
				if (event) {
					event.preventDefault();
				}
				modal.setAttribute('hidden', 'hidden');
				modal.setAttribute('aria-hidden', 'true');
				unlockScroll();
				if (openers.length > 0) {
					openers[0].focus({ preventScroll: true });
				}
			};

			var openModal = function (event) {
				if (event) {
					event.preventDefault();
				}
				modal.removeAttribute('hidden');
				modal.setAttribute('aria-hidden', 'false');
				lockScroll();
				var dialog = modal.querySelector('.template-modal__dialog');
				if (dialog && dialog.focus) {
					dialog.focus({ preventScroll: true });
				}
			};

			openers.forEach(function (button) {
				button.addEventListener('click', openModal);
			});

			closers.forEach(function (button) {
				button.addEventListener('click', closeModal);
			});

			modal.addEventListener('click', function (event) {
				if (event.target === modal) {
					closeModal(event);
				}
			});

			document.addEventListener('keydown', function (event) {
				if (event.key === 'Escape' && modal.getAttribute('aria-hidden') !== 'true') {
					closeModal(event);
				}
			});
		});
	</script>
</body>
</html>
