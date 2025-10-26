<?php
/**
 * Immersive Metrics Presentation Dashboard
 */

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$userStmt = $db->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$_SESSION['user_id']]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser || !in_array($currentUser['role'], ['admin', 'manager'], true)) {
    http_response_code(403);
    echo '<h1>Access denied</h1><p>Manager or Administrator role required.</p>';
    exit;
}

$driver = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$metrics = [
    'total' => 0,
    'active' => 0,
    'pending' => 0,
    'expired' => 0,
    'closed' => 0,
    'draft' => 0,
];

try {
    $totalStmt = $db->pdo->query('SELECT COUNT(*) FROM forms');
    $metrics['total'] = (int) $totalStmt->fetchColumn();

    $statusStmt = $db->pdo->query('SELECT status, COUNT(*) as total FROM forms GROUP BY status');
    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        $metrics[$status] = (int) $row['total'];
    }
} catch (Throwable $e) {
    // ignore, keep defaults
}

$recentCreated = 0;
$recentApproved = 0;
$expiringSoon = 0;
$avgApprovalHours = null;
$onTimeRate = null;

$thirtyDaysAgo = (new DateTime('-29 days'))->setTime(0, 0, 0);
$trendStart = (clone $thirtyDaysAgo)->modify('-11 months')->setTime(0, 0, 0);
$trendStartString = $trendStart->format('Y-m-d H:i:s');
$thirtyDaysString = $thirtyDaysAgo->format('Y-m-d H:i:s');
$sevenDaysAhead = (new DateTime('+7 days'))->setTime(23, 59, 59);
$sevenDaysString = $sevenDaysAhead->format('Y-m-d H:i:s');

try {
    $countRecentStmt = $db->pdo->prepare('SELECT COUNT(*) FROM forms WHERE created_at >= ?');
    $countRecentStmt->execute([$thirtyDaysString]);
    $recentCreated = (int) $countRecentStmt->fetchColumn();

    $countApprovedStmt = $db->pdo->prepare('SELECT COUNT(*) FROM forms WHERE approved_at IS NOT NULL AND approved_at >= ?');
    $countApprovedStmt->execute([$thirtyDaysString]);
    $recentApproved = (int) $countApprovedStmt->fetchColumn();

    $expiringStmt = $db->pdo->prepare("SELECT COUNT(*) FROM forms WHERE status IN ('active','pending_approval') AND valid_to IS NOT NULL AND valid_to <= ?");
    $expiringStmt->execute([$sevenDaysString]);
    $expiringSoon = (int) $expiringStmt->fetchColumn();
} catch (Throwable $e) {
    // ignore
}

$approvalDurations = [];
$onTimeCounters = ['eligible' => 0, 'on_time' => 0];

try {
    $timingStmt = $db->pdo->query('SELECT created_at, approved_at, closed_at, valid_to FROM forms');
    while ($row = $timingStmt->fetch(PDO::FETCH_ASSOC)) {
        $createdAt = $row['created_at'];
        $approvedAt = $row['approved_at'];
        $closedAt = $row['closed_at'];
        $validTo = $row['valid_to'];

        if ($createdAt && $approvedAt) {
            $createdTs = strtotime($createdAt);
            $approvedTs = strtotime($approvedAt);
            if ($createdTs && $approvedTs && $approvedTs >= $createdTs) {
                $approvalDurations[] = ($approvedTs - $createdTs) / 3600;
            }
        }

        if ($validTo && $closedAt) {
            $validTs = strtotime($validTo);
            $closedTs = strtotime($closedAt);
            if ($validTs && $closedTs) {
                $onTimeCounters['eligible']++;
                if ($closedTs <= $validTs) {
                    $onTimeCounters['on_time']++;
                }
            }
        }
    }
} catch (Throwable $e) {
    // ignore
}

if ($approvalDurations) {
    $avgApprovalHours = array_sum($approvalDurations) / count($approvalDurations);
}

if ($onTimeCounters['eligible'] > 0) {
    $onTimeRate = $onTimeCounters['on_time'] / $onTimeCounters['eligible'];
}

$monthLabels = [];
$monthCreated = [];
$monthApproved = [];

$monthCursor = clone $trendStart;
for ($i = 0; $i < 12; $i++) {
    $key = $monthCursor->format('Y-m');
    $monthLabels[$key] = $monthCursor->format('M Y');
    $monthCreated[$key] = 0;
    $monthApproved[$key] = 0;
    $monthCursor->modify('+1 month');
}

try {
    $trendStmt = $db->pdo->prepare('SELECT created_at, approved_at FROM forms WHERE created_at IS NOT NULL AND created_at >= ?');
    $trendStmt->execute([$trendStartString]);
    while ($row = $trendStmt->fetch(PDO::FETCH_ASSOC)) {
        $createdTs = strtotime($row['created_at']);
        if ($createdTs) {
            $monthKey = date('Y-m', $createdTs);
            if (isset($monthCreated[$monthKey])) {
                $monthCreated[$monthKey]++;
            }
        }
        if (!empty($row['approved_at'])) {
            $approvedTs = strtotime($row['approved_at']);
            if ($approvedTs) {
                $approvedKey = date('Y-m', $approvedTs);
                if (isset($monthApproved[$approvedKey])) {
                    $monthApproved[$approvedKey]++;
                }
            }
        }
    }
} catch (Throwable $e) {
    // ignore
}

$topTemplates = [];
try {
    $topStmt = $db->pdo->query('SELECT ft.name AS label, COUNT(*) AS total FROM forms f JOIN form_templates ft ON f.template_id = ft.id GROUP BY ft.name ORDER BY total DESC LIMIT 5');
    $topTemplates = $topStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $topTemplates = [];
}

$statusMap = [
    'active' => 'Active',
    'pending_approval' => 'Awaiting Approval',
    'expired' => 'Expired',
    'rejected' => 'Rejected',
    'draft' => 'Draft',
    'closed' => 'Closed',
];

$voiceLines = [];
$voiceLines[] = sprintf('We are currently tracking %s permits in total, with %s actively in progress.', number_format($metrics['total']), number_format($metrics['active'] ?? 0));
$voiceLines[] = sprintf('%s new permits entered the system in the last thirty days, and %s reached approval in the same period.', number_format($recentCreated), number_format($recentApproved));
if ($avgApprovalHours !== null) {
    $voiceLines[] = sprintf('Average approval time sits at %s hours, keeping turnaround tight.', number_format($avgApprovalHours, 1));
}
if ($onTimeRate !== null) {
    $voiceLines[] = sprintf('On-time closures are running at %s percent, keeping compliance high.', number_format($onTimeRate * 100, 1));
}
if ($expiringSoon > 0) {
    $voiceLines[] = sprintf('%s permits need attention this week before they expire.', number_format($expiringSoon));
}
if ($topTemplates) {
    $firstTemplate = $topTemplates[0];
    $voiceLines[] = sprintf('%s is the most frequently issued permit, covering %s cases recently.', $firstTemplate['label'], number_format($firstTemplate['total']));
}

$voiceLines[] = 'Use these insights to steer today’s decisions and keep the permit pipeline flowing.';

function prettyNumber($value, int $decimals = 0) {
    if ($value === null) {
        return '—';
    }
    $format = number_format((float) $value, $decimals);
    if ($decimals > 0) {
        $format = rtrim(rtrim($format, '0'), '.');
    }
    return $format;
}

$chartLabels = array_values($monthLabels);
$chartCreated = array_values($monthCreated);
$chartApproved = array_values($monthApproved);

$statusLabels = [];
$statusValues = [];
foreach ($statusMap as $key => $label) {
    $statusLabels[] = $label;
    $statusValues[] = (int) ($metrics[$key] ?? 0);
}

$templateLabels = array_column($topTemplates, 'label');
$templateValues = array_map('intval', array_column($topTemplates, 'total'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Metrics Showcase</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: dark;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: radial-gradient(circle at 10% 20%, #1f2937 0%, #0b1120 55%, #030712 100%);
            color: #f8fafc;
            min-height: 100vh;
            padding: 0 0 120px;
        }
        .glow {
            position: fixed;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(59,130,246,0.35), transparent 65%);
            top: -120px;
            right: -160px;
            filter: blur(12px);
            pointer-events: none;
        }
        .wrap {
            max-width: 1260px;
            margin: 0 auto;
            padding: 40px 28px 120px;
            position: relative;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
            margin-bottom: 32px;
        }
        header h1 {
            font-size: clamp(28px, 4vw, 44px);
            margin: 0;
            background: linear-gradient(135deg, #60a5fa, #c084fc);
              background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
        }
        header p {
            margin: 6px 0 0;
            font-size: 16px;
            color: #cbd5f5;
            max-width: 680px;
        }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            box-shadow: 0 12px 35px rgba(99, 102, 241, 0.35);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 45px rgba(99, 102, 241, 0.45);
        }
        .btn-ghost {
            background: rgba(148, 163, 184, 0.15);
            color: #e2e8f0;
            border: 1px solid rgba(148, 163, 184, 0.25);
        }
        .btn-ghost:hover {
            transform: translateY(-2px);
            border-color: rgba(148, 163, 184, 0.45);
        }
        .grid {
            display: grid;
            gap: 24px;
        }
        .grid-cols-4 {
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .card {
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(148, 163, 184, 0.16);
            border-radius: 24px;
            padding: 28px;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(16px);
            transition: transform 0.65s cubic-bezier(0.22, 0.61, 0.36, 1),
                        opacity 0.65s cubic-bezier(0.22, 0.61, 0.36, 1);
        }
        .card::before {
            content: '';
            position: absolute;
            inset: 1px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(59,130,246,0.12), rgba(14,116,144,0.08));
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        .card:hover::before {
            opacity: 1;
        }
        [data-animate] {
            opacity: 0;
            transform: translateY(36px) scale(0.98);
        }
        [data-animate].is-visible {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
        .metric-value {
            font-size: clamp(32px, 3vw, 48px);
            font-weight: 700;
            margin: 0 0 6px;
            transition: transform 0.6s ease;
        }
        [data-animate].is-visible .metric-value {
            transform: scale(1.05);
        }
        .metric-label {
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1.1px;
            font-size: 12px;
            margin-bottom: 18px;
        }
        .metric-trend {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 999px;
        }
        .trend-up {
            background: rgba(34, 197, 94, 0.12);
            color: #bbf7d0;
        }
        .trend-flat {
            background: rgba(148, 163, 184, 0.12);
            color: #cbd5f5;
        }
        .section-title {
            font-size: 22px;
            margin: 0 0 16px;
            font-weight: 600;
        }
        canvas {
            width: 100%;
        }
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 24px;
        }
        .top-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 16px;
        }
        .top-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-radius: 14px;
            background: rgba(148, 163, 184, 0.08);
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px;
            background: rgba(59, 130, 246, 0.2);
            color: #dbeafe;
        }
        @keyframes pulseHighlight {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4); }
            50% { transform: scale(1.01); box-shadow: 0 0 0 22px rgba(59, 130, 246, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
        .spotlight {
            animation: pulseHighlight 1.8s ease-in-out infinite;
            border-color: rgba(59, 130, 246, 0.55) !important;
        }
        .voice-indicator {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 16px 20px;
            border-radius: 16px;
            background: rgba(15, 23, 42, 0.88);
            border: 1px solid rgba(59, 130, 246, 0.4);
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.6);
            opacity: 0;
            transform: translateY(12px);
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease;
        }
        .voice-indicator.active {
            opacity: 1;
            transform: translateY(0);
        }
        .voice-indicator svg {
            width: 18px;
            height: 18px;
            color: #60a5fa;
        }
        @media (max-width: 768px) {
            header {
                text-align: center;
            }
            .actions {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="glow"></div>
    <div class="wrap">
        <header>
            <div>
                <h1>Permit Intelligence Showcase</h1>
                <p>Real-time permit performance highlights ready for the boardroom. Switch on presentation mode to narrate the story while the visuals animate.</p>
            </div>
            <div class="actions">
                <a class="btn btn-ghost" href="/dashboard.php">Back to Dashboard</a>
                <button class="btn btn-primary" id="playPresentation">Explain these stats</button>
                <button class="btn btn-ghost" id="stopPresentation">Stop narration</button>
            </div>
        </header>

        <section class="grid grid-cols-4">
            <article class="card" data-animate id="metric-total" data-narration="We are currently tracking <?php echo htmlspecialchars(number_format($metrics['total'])); ?> permits in total.">
                <div class="metric-label">Total permits</div>
                <div class="metric-value"><?php echo htmlspecialchars(number_format($metrics['total'])); ?></div>
                <div class="metric-trend trend-flat">Portfolio scale</div>
            </article>
            <article class="card" data-animate id="metric-active" data-narration="<?php echo htmlspecialchars(number_format($metrics['active'] ?? 0)); ?> permits are active right now.">
                <div class="metric-label">Active</div>
                <div class="metric-value"><?php echo htmlspecialchars(number_format($metrics['active'] ?? 0)); ?></div>
                <div class="metric-trend trend-up">Live operations</div>
            </article>
            <article class="card" data-animate id="metric-approved" data-narration="<?php echo htmlspecialchars(number_format($recentApproved)); ?> approvals landed in the last thirty days.">
                <div class="metric-label">Approvals (30d)</div>
                <div class="metric-value"><?php echo htmlspecialchars(number_format($recentApproved)); ?></div>
                <div class="metric-trend trend-up">Momentum</div>
            </article>
            <article class="card" data-animate id="metric-expiring" data-narration="<?php echo htmlspecialchars(number_format($expiringSoon)); ?> permits will expire within seven days.">
                <div class="metric-label">Expiring (7d)</div>
                <div class="metric-value"><?php echo htmlspecialchars(number_format($expiringSoon)); ?></div>
                <div class="metric-trend trend-flat">Risk radar</div>
            </article>
        </section>

        <section class="card" data-animate id="trend-card" data-narration="Here is the monthly flow of permits created and approved, showing throughput trends.">
            <h2 class="section-title">Throughput trend</h2>
            <canvas id="trendChart" height="300"></canvas>
        </section>

        <section class="charts-grid">
            <article class="card" data-animate id="status-card" data-narration="Permit statuses break down across the lifecycle as shown here.">
                <h2 class="section-title">Lifecycle status mix</h2>
                <canvas id="statusChart" height="260"></canvas>
            </article>
            <article class="card" data-animate id="efficiency-card" data-narration="Efficiency metrics highlight turnaround speed and reliability.">
                <h2 class="section-title">Efficiency pulse</h2>
                <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 18px;">
                    <div>
                        <div class="metric-label">Avg approval hrs</div>
                        <div class="metric-value" style="font-size:32px;"><?php echo htmlspecialchars(prettyNumber($avgApprovalHours, 1)); ?></div>
                    </div>
                    <div>
                        <div class="metric-label">On-time closures</div>
                        <div class="metric-value" style="font-size:32px;"><?php echo htmlspecialchars($onTimeRate !== null ? prettyNumber($onTimeRate * 100, 1) . '%': '—'); ?></div>
                    </div>
                    <div>
                        <div class="metric-label">New permits (30d)</div>
                        <div class="metric-value" style="font-size:32px;"><?php echo htmlspecialchars(number_format($recentCreated)); ?></div>
                    </div>
                </div>
            </article>
        </section>

        <section class="card" data-animate id="top-card" data-narration="Our most popular permit templates are leading the charge here.">
            <h2 class="section-title">Top performing templates</h2>
            <?php if ($topTemplates): ?>
            <div class="top-list">
                <?php foreach ($topTemplates as $index => $template): ?>
                    <div class="top-row">
                        <div>
                            <strong><?php echo htmlspecialchars($template['label']); ?></strong>
                            <span class="badge">Rank #<?php echo $index + 1; ?></span>
                        </div>
                        <div><?php echo htmlspecialchars(number_format($template['total'])); ?> issued</div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p>No permit issuance data yet.</p>
            <?php endif; ?>
        </section>
    </div>

    <div class="voice-indicator" id="voiceIndicator" role="status" aria-live="polite">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3a4 4 0 0 0-4 4v6a4 4 0 0 0 8 0V7a4 4 0 0 0-4-4Zm-7 8a1 1 0 0 0-2 0 9 9 0 0 0 8 8.94V22a1 1 0 0 0 2 0v-2.06A9 9 0 0 0 19 11a1 1 0 0 0-2 0 7 7 0 1 1-14 0Z"/></svg>
        Narrating insights&hellip;
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" integrity="sha384-qiWs4V/mQJu1WDVYj1WFJsbgx5caX5/Cgtiw55GaRLM/J7rnNdw1JkiNeVYwMy3p" crossorigin="anonymous"></script>
    <script>
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const observerSupported = 'IntersectionObserver' in window;
        if (!prefersReducedMotion && observerSupported) {
            const animatedBlocks = document.querySelectorAll('[data-animate]');
            const animateObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        animateObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.2 });
            animatedBlocks.forEach((el) => animateObserver.observe(el));
        } else {
            document.querySelectorAll('[data-animate]').forEach((el) => el.classList.add('is-visible'));
        }

        const trendCtx = document.getElementById('trendChart');
        const statusCtx = document.getElementById('statusChart');

        const trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    {
                        label: 'Created',
                        data: <?php echo json_encode($chartCreated); ?>,
                        fill: true,
                        tension: 0.4,
                        backgroundColor: 'rgba(99,102,241,0.25)',
                        borderColor: 'rgba(99,102,241,1)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Approved',
                        data: <?php echo json_encode($chartApproved); ?>,
                        fill: true,
                        tension: 0.4,
                        backgroundColor: 'rgba(16,185,129,0.25)',
                        borderColor: 'rgba(16,185,129,1)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top', labels: { color: '#cbd5f5' } },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.92)',
                        titleColor: '#e2e8f0',
                        bodyColor: '#cbd5f5',
                        displayColors: false
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(148,163,184,0.1)' },
                        ticks: { color: '#94a3b8' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148,163,184,0.1)' },
                        ticks: { color: '#94a3b8', precision: 0 }
                    }
                }
            }
        });

        const statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($statusLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($statusValues); ?>,
                    backgroundColor: [
                        'rgba(59,130,246,0.75)',
                        'rgba(245,158,11,0.75)',
                        'rgba(239,68,68,0.75)',
                        'rgba(249,115,22,0.75)',
                        'rgba(148,163,184,0.75)',
                        'rgba(16,185,129,0.75)'
                    ],
                    borderColor: 'rgba(15,23,42,0.9)',
                    borderWidth: 4
                }]
            },
            options: {
                cutout: '58%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: '#cbd5f5' }
                    }
                }
            }
        });

        const voiceLines = <?php echo json_encode($voiceLines); ?>;
        const sequence = [
            'metric-total',
            'metric-active',
            'metric-approved',
            'metric-expiring',
            'trend-card',
            'status-card',
            'efficiency-card',
            'top-card'
        ];

        const indicator = document.getElementById('voiceIndicator');
        const playButton = document.getElementById('playPresentation');
        const stopButton = document.getElementById('stopPresentation');
        const synth = window.speechSynthesis;
        let utteranceQueue = [];
        let activeSpotlight = null;
        let selectedVoice = null;
        let voicesReady = false;

        function chooseVoice(list) {
            if (!Array.isArray(list) || list.length === 0) {
                return null;
            }
            const preferred = [
                'Google UK English Female',
                'Google US English Female',
                'Microsoft Hazel Desktop - English (Great Britain)',
                'Microsoft Aria Online (Natural) - English (United States)',
                'Samantha',
                'Karen'
            ];
            const byName = preferred.map((name) => list.find((v) => v.name === name)).filter(Boolean);
            if (byName.length) {
                return byName[0];
            }
            const englishVoices = list.filter((voice) => voice.lang && voice.lang.startsWith('en'));
            return englishVoices[0] || list[0];
        }

        function resolveVoices() {
            return new Promise((resolve) => {
                if (!('speechSynthesis' in window)) {
                    resolve(null);
                    return;
                }
                const available = synth.getVoices();
                if (available.length) {
                    voicesReady = true;
                    selectedVoice = chooseVoice(available);
                    resolve(selectedVoice);
                    return;
                }
                const handle = () => {
                    const fresh = synth.getVoices();
                    if (fresh.length) {
                        window.speechSynthesis.removeEventListener('voiceschanged', handle);
                        clearTimeout(fallbackTimer);
                        voicesReady = true;
                        selectedVoice = chooseVoice(fresh);
                        resolve(selectedVoice);
                    }
                };
                const fallbackTimer = setTimeout(() => {
                    window.speechSynthesis.removeEventListener('voiceschanged', handle);
                    resolve(null);
                }, 1500);
                window.speechSynthesis.addEventListener('voiceschanged', handle);
            });
        }

        const voicePromise = resolveVoices();

        function highlightElement(id) {
            if (activeSpotlight) {
                activeSpotlight.classList.remove('spotlight');
            }
            const el = document.getElementById(id);
            if (el) {
                el.classList.add('spotlight');
                activeSpotlight = el;
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function clearHighlight() {
            if (activeSpotlight) {
                activeSpotlight.classList.remove('spotlight');
                activeSpotlight = null;
            }
        }

        function stopPresentation() {
            utteranceQueue = [];
            if (synth && synth.speaking) {
                synth.cancel();
            }
            clearHighlight();
            indicator.classList.remove('active');
        }

        async function playPresentation() {
            if (!('speechSynthesis' in window)) {
                alert('Speech narration is not supported in this browser.');
                return;
            }
            await voicePromise;

            stopPresentation();

            const combinedLines = [...voiceLines];
            utteranceQueue = [];

            sequence.forEach((id) => {
                const el = document.getElementById(id);
                if (!el) {
                    return;
                }
                const line = el.dataset.narration;
                if (line) {
                    combinedLines.push(line);
                }
            });

            if (!combinedLines.length) {
                alert('No narration content available.');
                return;
            }

            indicator.classList.add('active');

            combinedLines.forEach((line, idx) => {
                const utterance = new SpeechSynthesisUtterance(line);
                utterance.rate = 1.02;
                utterance.pitch = 1.0;
                utterance.volume = 1.0;
                if (selectedVoice) {
                    utterance.voice = selectedVoice;
                    utterance.lang = selectedVoice.lang;
                }
                utteranceQueue.push({ utterance, idx });
            });

            let queueIndex = 0;

            const speakNext = () => {
                if (queueIndex >= utteranceQueue.length) {
                    indicator.classList.remove('active');
                    clearHighlight();
                    return;
                }
                const { utterance, idx } = utteranceQueue[queueIndex];
                const spotlightId = sequence[idx - voiceLines.length];
                if (spotlightId) {
                    highlightElement(spotlightId);
                } else {
                    clearHighlight();
                }
                utterance.onend = () => {
                    queueIndex += 1;
                    speakNext();
                };
                utterance.onerror = () => {
                    queueIndex += 1;
                    speakNext();
                };
                synth.speak(utterance);
            };

            speakNext();
        }

        playButton.addEventListener('click', playPresentation);
        stopButton.addEventListener('click', stopPresentation);
        window.addEventListener('beforeunload', stopPresentation);
    </script>
</body>
</html>
