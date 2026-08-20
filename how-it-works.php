<?php
declare(strict_types=1);

use Permits\SystemSettings;

[$app, $db] = require __DIR__ . '/src/bootstrap.php';
$branding = SystemSettings::branding($db, 'Permit System');
$companyName = $branding['company_name'];
$companyLogoPath = $branding['company_logo_path'];
$companyLogoUrl = $companyLogoPath ? asset('/' . ltrim($companyLogoPath, '/')) : null;
$brandingCss = SystemSettings::brandingCssVariables($branding);

$steps = [
    ['📱', '1', 'Scan or choose a form', 'Use a site notice-board QR code or browse the current permit and inspection forms from any phone, tablet or computer.'],
    ['📝', '2', 'Complete the details', 'Enter the work location, people, scope, RAMS references and the permit-specific safety controls. Add notes or evidence where required.'],
    ['📨', '3', 'Submit for review', 'The application receives a reference and goes to the controlled approval workflow. Submitting a form does not authorise work.'],
    ['✅', '4', 'Manager reviews', 'An authorised manager reviews the permit, conditions and duration. Approval moves it to Awaiting Holder Acceptance — not Active.'],
    ['✍️', '5', 'Holder accepts', 'The permit holder confirms the conditions and accepts responsibility. The authorised validity clock starts and the permit becomes Active.'],
    ['🔎', '6', 'Verify on site', 'The live permit board and permit QR show the current status. Suspended or expired permits display a clear DO NOT WORK warning.'],
    ['🔄', '7', 'Control changes', 'Use shift handover, linked permits/SIMOPS, suspension and revalidation when site conditions or responsibility change.'],
    ['🏁', '8', 'Complete and close', 'When the work is complete, the permit is closed so it no longer appears live and the audit trail records the outcome and lifecycle history.'],
];
?>
<!doctype html>
<html lang="en" style="<?= htmlspecialchars($brandingCss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="<?= htmlspecialchars($branding['primary_colour'], ENT_QUOTES, 'UTF-8') ?>">
    <title>How the Permit System Works · <?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <meta name="description" content="A simple visual guide to creating, approving, accepting, checking and closing permits to work.">
    <?php if (function_exists('cache_meta_tags')) { cache_meta_tags(); } ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(asset('/assets/app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <style>
        body { margin:0; background:#0f172a; color:#e5e7eb; font-family:system-ui,-apple-system,'Segoe UI',sans-serif; }
        .showcase { width:min(1180px, calc(100% - 28px)); margin:0 auto; padding:28px 0 72px; }
        .showcase-nav { display:flex; justify-content:space-between; gap:16px; align-items:center; margin-bottom:22px; flex-wrap:wrap; }
        .showcase-brand { display:flex; gap:12px; align-items:center; }
        .showcase-brand img { width:48px; height:48px; border-radius:12px; }
        .showcase-brand strong { display:block; font-size:18px; }
        .showcase-brand span { color:#94a3b8; font-size:13px; }
        .showcase-hero { padding:clamp(28px,6vw,64px); border:1px solid rgba(148,163,184,.18); border-radius:28px; background:radial-gradient(circle at 15% 10%,rgba(var(--brand-primary-light-rgb),.28),transparent 42%),linear-gradient(135deg,#172554,#0f172a 72%); box-shadow:0 30px 80px rgba(2,6,23,.35); }
        .eyebrow { display:inline-flex; padding:7px 11px; border-radius:999px; background:rgba(var(--brand-primary-rgb),.18); color:#dbeafe; font-weight:800; font-size:12px; letter-spacing:.06em; text-transform:uppercase; }
        h1 { margin:16px 0 14px; max-width:820px; font-size:clamp(38px,7vw,68px); line-height:.98; letter-spacing:-.04em; }
        .hero-copy { max-width:760px; margin:0; color:#cbd5e1; font-size:clamp(17px,2.4vw,21px); line-height:1.6; }
        .hero-actions { display:flex; gap:12px; flex-wrap:wrap; margin-top:26px; }
        .safety-banner { margin-top:22px; padding:16px 18px; border-radius:16px; border:1px solid rgba(248,113,113,.45); background:rgba(127,29,29,.32); color:#fee2e2; font-weight:700; line-height:1.55; }
        .steps { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-top:28px; }
        .step { position:relative; overflow:hidden; padding:24px; border-radius:22px; border:1px solid rgba(148,163,184,.18); background:linear-gradient(145deg,rgba(15,23,42,.95),rgba(30,41,59,.78)); min-height:210px; }
        .step::after { content:attr(data-step); position:absolute; right:12px; top:-28px; font-size:120px; font-weight:900; color:rgba(148,163,184,.055); line-height:1; }
        .step-icon { font-size:34px; }
        .step h2 { margin:14px 0 8px; font-size:22px; }
        .step p { margin:0; color:#aebdd0; line-height:1.65; }
        .status-strip { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:28px; }
        .status-box { padding:18px; border-radius:18px; text-align:center; font-weight:800; border:1px solid rgba(148,163,184,.18); background:#111827; }
        .status-box small { display:block; margin-top:6px; color:#94a3b8; font-weight:500; }
        .pending { border-color:rgba(245,158,11,.45); color:#fde68a; }
        .accept { border-color:rgba(59,130,246,.45); color:#bfdbfe; }
        .active { border-color:rgba(34,197,94,.45); color:#bbf7d0; }
        .stop { border-color:rgba(239,68,68,.5); color:#fecaca; }
        .final-card { margin-top:28px; padding:28px; border-radius:24px; border:1px solid rgba(var(--brand-primary-light-rgb),.32); background:rgba(var(--brand-primary-rgb),.09); display:grid; gap:10px; }
        .final-card h2 { margin:0; font-size:28px; }
        .final-card p { margin:0; color:#cbd5e1; line-height:1.65; }
        @media(max-width:760px){ .steps,.status-strip{grid-template-columns:1fr}.showcase{width:min(100% - 20px,1180px)}.showcase-nav .btn{width:100%}.hero-actions .btn{width:100%;justify-content:center}.step{min-height:0} }
        @media print { .showcase-nav,.hero-actions{display:none}.showcase{width:100%;padding:0}.showcase-hero,.step,.final-card{box-shadow:none;break-inside:avoid}.steps{grid-template-columns:1fr 1fr} }
    </style>
</head>
<body>
<div class="showcase">
    <nav class="showcase-nav" aria-label="Showcase navigation">
        <div class="showcase-brand brand-mark">
            <?php if ($companyLogoUrl): ?><img src="<?= htmlspecialchars($companyLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> logo" class="brand-mark__logo"><?php endif; ?>
            <div><strong><?= htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><span>Permit System · How it works</span></div>
        </div>
        <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('/'), ENT_QUOTES, 'UTF-8') ?>">Back to Permit System</a>
    </nav>

    <header class="showcase-hero">
        <span class="eyebrow">Simple on site · controlled in the background</span>
        <h1>From QR scan to safe, auditable work.</h1>
        <p class="hero-copy">The system is designed so an operative can start on a phone in seconds while managers retain control of approval, acceptance, live status, suspension, handover and closure.</p>
        <div class="hero-actions">
            <a class="btn btn-accent" href="<?= htmlspecialchars($app->url('/#permit-templates'), ENT_QUOTES, 'UTF-8') ?>">Start a Permit</a>
            <a class="btn btn-secondary" href="<?= htmlspecialchars($app->url('/#status-checker'), ENT_QUOTES, 'UTF-8') ?>">Check Permit Status</a>
        </div>
        <div class="safety-banner">⚠️ Completing or submitting a permit form does not authorise work. Work may only start when the permit has been approved, accepted by the permit holder and is shown as ACTIVE.</div>
    </header>

    <section class="steps" aria-label="Permit workflow">
        <?php foreach ($steps as [$icon, $number, $title, $copy]): ?>
            <article class="step" data-step="<?= htmlspecialchars($number, ENT_QUOTES, 'UTF-8') ?>">
                <div class="step-icon" aria-hidden="true"><?= $icon ?></div>
                <h2><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars($copy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="status-strip" aria-label="Important permit statuses">
        <div class="status-box pending">Pending Approval<small>Not authorised to work</small></div>
        <div class="status-box accept">Awaiting Acceptance<small>Manager approved, holder still required</small></div>
        <div class="status-box active">ACTIVE<small>Work authorised within permit conditions</small></div>
        <div class="status-box stop">SUSPENDED / EXPIRED<small>DO NOT WORK</small></div>
    </section>

    <section class="final-card">
        <h2>Site notice boards can start the process instantly.</h2>
        <p>Administrators can print a permanent QR code for each current permit type and inspection checklist. The QR uses a stable link such as <strong>/start/hot-works</strong>, which automatically resolves to the current active version of that form rather than locking the notice board to an old v1 or v2 template.</p>
    </section>
</div>
</body>
</html>
