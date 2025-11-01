<?php
/**
 * Premium Permit Intelligence Showcase - Enhanced Edition
 * 
 * File Path: /presentation-dashboard-enhanced.php
 * Description: Professional presentation dashboard with subsections, flowcharts, and advanced narration
 * Features:
 * - 4 Major subsections (Overview, Workflow, Performance, Insights)
 * - Interactive flowcharts (User Journey & System Architecture)
 * - ChatGPT natural voice narration via OpenAI TTS API
 * - Beautiful animations and transitions
 * - Professional dark theme
 * - Print-ready design
 */

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';

use Permits\SystemSettings;

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

// OpenAI is disabled - using browser voice instead
$hasOpenAI = false;

// Fetch metrics
$metrics = ['total' => 0, 'active' => 0, 'pending' => 0, 'expired' => 0, 'closed' => 0, 'draft' => 0];

try {
    $totalStmt = $db->pdo->query('SELECT COUNT(*) FROM forms');
    $metrics['total'] = (int)$totalStmt->fetchColumn();

    $statusStmt = $db->pdo->query('SELECT status, COUNT(*) as total FROM forms GROUP BY status');
    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        $metrics[$status] = (int)$row['total'];
    }
} catch (Throwable $e) {}

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
    $recentCreated = (int)$countRecentStmt->fetchColumn();

    $countApprovedStmt = $db->pdo->prepare('SELECT COUNT(*) FROM forms WHERE approved_at IS NOT NULL AND approved_at >= ?');
    $countApprovedStmt->execute([$thirtyDaysString]);
    $recentApproved = (int)$countApprovedStmt->fetchColumn();

    $expiringStmt = $db->pdo->prepare("SELECT COUNT(*) FROM forms WHERE status IN ('active','pending_approval') AND valid_to IS NOT NULL AND valid_to <= ?");
    $expiringStmt->execute([$sevenDaysString]);
    $expiringSoon = (int)$expiringStmt->fetchColumn();
} catch (Throwable $e) {}

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
} catch (Throwable $e) {}

if ($approvalDurations) {
    $avgApprovalHours = array_sum($approvalDurations) / count($approvalDurations);
}

if ($onTimeCounters['eligible'] > 0) {
    $onTimeRate = $onTimeCounters['on_time'] / $onTimeCounters['eligible'];
}

// Month trends
$monthLabels = [];
$monthCreated = [];
$monthApproved = [];
$chartLabels = [];
$chartCreated = [];
$chartApproved = [];

$monthCursor = clone $trendStart;
for ($i = 0; $i < 12; $i++) {
    $key = $monthCursor->format('Y-m');
    $label = $monthCursor->format('M');
    $monthLabels[$key] = $label;
    $chartLabels[] = $label;
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
} catch (Throwable $e) {}

$chartCreated = array_values($monthCreated);
$chartApproved = array_values($monthApproved);

// Status breakdown
$statusLabels = [];
$statusValues = [];
$statusMap = [
    'active' => 'Active',
    'pending_approval' => 'Awaiting Approval',
    'expired' => 'Expired',
    'rejected' => 'Rejected',
    'draft' => 'Draft',
    'closed' => 'Closed',
];

foreach ($statusMap as $key => $label) {
    if (isset($metrics[$key]) && $metrics[$key] > 0) {
        $statusLabels[] = $label;
        $statusValues[] = $metrics[$key];
    }
}

// Top templates
$topTemplates = [];
try {
    $topStmt = $db->pdo->query('SELECT ft.name AS label, COUNT(*) AS total FROM forms f JOIN form_templates ft ON f.template_id = ft.id GROUP BY ft.name ORDER BY total DESC LIMIT 5');
    $topTemplates = $topStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

function prettyNumber($value, $decimals = 0) {
    if ($value === null) return '—';
    $format = number_format((float)$value, $decimals);
    if ($decimals > 0) $format = rtrim(rtrim($format, '0'), '.');
    return $format;
}

// Comprehensive narration sections
$narrationSections = [
    'intro' => [
        'title' => 'Executive Overview',
        'lines' => [
            'Welcome to the Permit Intelligence Showcase. This presentation gives you a real-time snapshot of your permit ecosystem.',
            'We are tracking ' . number_format($metrics['total']) . ' permits in total, with ' . number_format($metrics['active']) . ' actively in progress.',
            'This data-driven view lets you understand volume, velocity, and compliance in seconds.'
        ]
    ],
    'workflow' => [
        'title' => 'Operational Workflow',
        'lines' => [
            'Every permit follows a predictable lifecycle. It starts in draft, moves through approval, and concludes when closed.',
            'Our system automates tracking at each stage, eliminating manual overhead.',
            'Real-time status updates keep everyone synchronized and alert to bottlenecks.'
        ]
    ],
    'performance' => [
        'title' => 'Performance Metrics',
        'lines' => [
            'In the last thirty days, ' . number_format($recentCreated) . ' new permits entered the system.',
            number_format($recentApproved) . ' reached approval in the same period.',
            'Average approval time sits at ' . prettyNumber($avgApprovalHours, 1) . ' hours, keeping turnaround tight.',
            ($onTimeRate !== null ? 'On-time closures are running at ' . prettyNumber($onTimeRate * 100, 1) . ' percent.' : 'Closure tracking is underway.')
        ]
    ],
    'insights' => [
        'title' => 'Strategic Insights',
        'lines' => [
            ($expiringSoon > 0 ? number_format($expiringSoon) . ' permits need attention this week before they expire.' : 'No critical expirations this week.'),
            ($topTemplates ? 'Our most popular template is ' . htmlspecialchars($topTemplates[0]['label']) . ', covering ' . number_format($topTemplates[0]['total']) . ' cases recently.' : 'Template usage patterns are emerging.'),
            'Use these insights to steer today\'s decisions and keep the permit pipeline flowing smoothly.',
            'Ready to dive deeper? Access the full dashboard for granular controls and detailed analysis.'
        ]
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permit Intelligence Showcase - Enhanced</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" referrerpolicy="no-referrer"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #111a2e 100%);
            color: #e2e8f0;
            line-height: 1.6;
            min-height: 100vh;
        }

        .wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 60px;
            gap: 40px;
        }

        header div:first-child h1 {
            font-size: 48px;
            font-weight: 700;
            background: linear-gradient(135deg, #06b6d4, #0ea5e9);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 12px;
        }

        header div:first-child p {
            color: #94a3b8;
            font-size: 16px;
        }

        .actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #06b6d4, #0ea5e9);
            color: #0f172a;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(6, 182, 212, 0.3);
        }

        .btn-ghost {
            background: rgba(148, 163, 184, 0.1);
            color: #94a3b8;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        .btn-ghost:hover {
            background: rgba(148, 163, 184, 0.2);
        }

        /* Subsection Structure */
        section {
            margin-bottom: 80px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 40px;
            padding-bottom: 24px;
            border-bottom: 2px solid rgba(6, 182, 212, 0.2);
        }

        .section-header-icon {
            font-size: 40px;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(6, 182, 212, 0.1);
            border-radius: 12px;
        }

        .section-header h2 {
            font-size: 36px;
            font-weight: 700;
            color: #e2e8f0;
        }

        .section-header p {
            color: #94a3b8;
            font-size: 14px;
            margin-top: 4px;
        }

        /* Card Grid */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }

        .card {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(6, 182, 212, 0.1);
            border-radius: 12px;
            padding: 24px;
            transition: all 0.3s ease;
        }

        .card:hover {
            border-color: rgba(6, 182, 212, 0.3);
            box-shadow: 0 12px 24px rgba(6, 182, 212, 0.1);
            transform: translateY(-4px);
        }

        .card.spotlight {
            border-color: #06b6d4;
            background: rgba(6, 182, 212, 0.05);
            box-shadow: 0 0 24px rgba(6, 182, 212, 0.2);
        }

        .metric-value {
            font-size: 48px;
            font-weight: 700;
            color: #06b6d4;
            margin: 16px 0;
        }

        .metric-label {
            font-size: 13px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metric-trend {
            font-size: 12px;
            color: #64748b;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(6, 182, 212, 0.1);
        }

        /* Charts */
        .chart-container {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(6, 182, 212, 0.1);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: #e2e8f0;
            margin-bottom: 20px;
        }

        /* Flowchart */
        .flowchart {
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(6, 182, 212, 0.1);
            border-radius: 12px;
            padding: 40px 24px;
            margin-bottom: 40px;
            overflow-x: auto;
        }

        .flowchart svg {
            display: block;
            margin: 0 auto;
            min-width: 100%;
        }

        /* Narration Indicator */
        .voice-indicator {
            position: fixed;
            bottom: 30px;
            right: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(6, 182, 212, 0.1);
            border: 1px solid rgba(6, 182, 212, 0.3);
            padding: 16px 24px;
            border-radius: 50px;
            color: #06b6d4;
            font-size: 14px;
            font-weight: 600;
            opacity: 0;
            pointer-events: none;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .voice-indicator.active {
            opacity: 1;
            pointer-events: auto;
        }

        .voice-indicator svg {
            width: 20px;
            height: 20px;
            animation: pulse 1.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        /* Animations */
        [data-animate] {
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        [data-animate].is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Responsive */
        @media (max-width: 768px) {
            header {
                flex-direction: column;
            }

            header div:first-child h1 {
                font-size: 32px;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
            }

            .voice-indicator {
                bottom: 20px;
                right: 20px;
                left: 20px;
            }
        }

        @media print {
            body { background: white; }
            .actions, .voice-indicator { display: none; }
            .card, .chart-container, .flowchart { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <div>
                <h1>Permit Intelligence Showcase</h1>
                <p>Professional presentation with subsections, flowcharts, and interactive narration</p>
            </div>
            <div class="actions">
                <a href="/dashboard.php" class="btn btn-ghost">← Back to Dashboard</a>
                <button class="btn btn-primary" id="playPresentation">🎙️ Play Narration</button>
                <button class="btn btn-ghost" id="stopPresentation">⏹️ Stop</button>
            </div>
        </header>

        <!-- SECTION 1: OVERVIEW -->
        <section data-section="overview">
            <div class="section-header">
                <div class="section-header-icon">📊</div>
                <div>
                    <h2>Executive Overview</h2>
                    <p>Real-time permit portfolio snapshot and key metrics</p>
                </div>
            </div>

            <div class="grid">
                <div class="card" data-id="metric-total" data-narration="We are tracking <?php echo number_format($metrics['total']); ?> permits in total. This represents the complete portfolio across all statuses and lifecycles.">
                    <div class="metric-label">Total Permits</div>
                    <div class="metric-value"><?php echo number_format($metrics['total']); ?></div>
                    <div class="metric-trend">Portfolio Scale</div>
                </div>

                <div class="card" data-id="metric-active" data-narration="Currently, <?php echo number_format($metrics['active']); ?> permits are active and in progress. These are permits actively being worked on.">
                    <div class="metric-label">Active Now</div>
                    <div class="metric-value"><?php echo number_format($metrics['active']); ?></div>
                    <div class="metric-trend">Live Operations</div>
                </div>

                <div class="card" data-id="metric-pending" data-narration="<?php echo number_format($metrics['pending']); ?> permits are awaiting approval. This is our approval pipeline.">
                    <div class="metric-label">Pending Approval</div>
                    <div class="metric-value"><?php echo number_format($metrics['pending']); ?></div>
                    <div class="metric-trend">Approval Queue</div>
                </div>

                <div class="card" data-id="metric-closed" data-narration="<?php echo number_format($metrics['closed']); ?> permits have been successfully closed. This demonstrates our completion rate and efficiency.">
                    <div class="metric-label">Closed</div>
                    <div class="metric-value"><?php echo number_format($metrics['closed']); ?></div>
                    <div class="metric-trend">Completion Rate</div>
                </div>
            </div>
        </section>

        <!-- SECTION 2: WORKFLOW -->
        <section data-section="workflow">
            <div class="section-header">
                <div class="section-header-icon">🔄</div>
                <div>
                    <h2>Operational Workflow</h2>
                    <p>How permits move through the system - from creation to closure</p>
                </div>
            </div>

            <div class="flowchart" data-id="flowchart-user" data-narration="This flowchart shows how an end user creates and manages permits. Starting from permit creation, users submit requests that enter our approval queue. Once approved, permits become active and can be worked on. Finally, users close permits when work is complete.">
                <svg viewBox="0 0 1000 300" preserveAspectRatio="xMidYMid meet">
                    <!-- Step 1: Create -->
                    <rect x="50" y="100" width="140" height="100" rx="8" fill="rgba(6, 182, 212, 0.2)" stroke="#06b6d4" stroke-width="2"/>
                    <text x="120" y="135" font-size="18" font-weight="bold" fill="#e2e8f0" text-anchor="middle">📝 Create</text>
                    <text x="120" y="160" font-size="12" fill="#94a3b8" text-anchor="middle">User submits</text>
                    <text x="120" y="175" font-size="12" fill="#94a3b8" text-anchor="middle">new permit</text>

                    <!-- Arrow 1 -->
                    <path d="M 190 150 L 240 150" stroke="#06b6d4" stroke-width="2" fill="none" marker-end="url(#arrowhead)"/>

                    <!-- Step 2: Pending -->
                    <rect x="240" y="100" width="140" height="100" rx="8" fill="rgba(245, 158, 11, 0.2)" stroke="#f59e0b" stroke-width="2"/>
                    <text x="310" y="135" font-size="18" font-weight="bold" fill="#e2e8f0" text-anchor="middle">⏳ Pending</text>
                    <text x="310" y="160" font-size="12" fill="#94a3b8" text-anchor="middle">Awaiting</text>
                    <text x="310" y="175" font-size="12" fill="#94a3b8" text-anchor="middle">approval</text>

                    <!-- Arrow 2 -->
                    <path d="M 380 150 L 430 150" stroke="#06b6d4" stroke-width="2" fill="none" marker-end="url(#arrowhead)"/>

                    <!-- Step 3: Active -->
                    <rect x="430" y="100" width="140" height="100" rx="8" fill="rgba(34, 197, 94, 0.2)" stroke="#22c55e" stroke-width="2"/>
                    <text x="500" y="135" font-size="18" font-weight="bold" fill="#e2e8f0" text-anchor="middle">✓ Active</text>
                    <text x="500" y="160" font-size="12" fill="#94a3b8" text-anchor="middle">Approved &amp;</text>
                    <text x="500" y="175" font-size="12" fill="#94a3b8" text-anchor="middle">in progress</text>

                    <!-- Arrow 3 -->
                    <path d="M 570 150 L 620 150" stroke="#06b6d4" stroke-width="2" fill="none" marker-end="url(#arrowhead)"/>

                    <!-- Step 4: Closed -->
                    <rect x="620" y="100" width="140" height="100" rx="8" fill="rgba(99, 102, 241, 0.2)" stroke="#6366f1" stroke-width="2"/>
                    <text x="690" y="135" font-size="18" font-weight="bold" fill="#e2e8f0" text-anchor="middle">🏁 Closed</text>
                    <text x="690" y="160" font-size="12" fill="#94a3b8" text-anchor="middle">Completed</text>
                    <text x="690" y="175" font-size="12" fill="#94a3b8" text-anchor="middle">successfully</text>

                    <!-- Arrow marker definition -->
                    <defs>
                        <marker id="arrowhead" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto">
                            <polygon points="0 0, 10 3, 0 6" fill="#06b6d4"/>
                        </marker>
                    </defs>

                    <!-- Timeline labels -->
                    <text x="120" y="270" font-size="11" fill="#64748b" text-anchor="middle">Day 0</text>
                    <text x="310" y="270" font-size="11" fill="#64748b" text-anchor="middle">Day 1-3</text>
                    <text x="500" y="270" font-size="11" fill="#64748b" text-anchor="middle">Day 4-N</text>
                    <text x="690" y="270" font-size="11" fill="#64748b" text-anchor="middle">Completion</text>
                </svg>
            </div>

            <div class="grid">
                <div class="chart-container" data-id="lifecycle-explained">
                    <div class="chart-title">📋 Lifecycle Breakdown</div>
                    <div style="padding: 20px;">
                        <div style="margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid rgba(6, 182, 212, 0.1);">
                            <div style="color: #06b6d4; font-weight: 600; margin-bottom: 4px;">Draft (<?php echo number_format($metrics['draft']); ?>)</div>
                            <div style="font-size: 13px; color: #94a3b8;">Permits created but not yet submitted</div>
                        </div>
                        <div style="margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid rgba(6, 182, 212, 0.1);">
                            <div style="color: #f59e0b; font-weight: 600; margin-bottom: 4px;">Pending Approval (<?php echo number_format($metrics['pending']); ?>)</div>
                            <div style="font-size: 13px; color: #94a3b8;">Awaiting manager or admin review</div>
                        </div>
                        <div style="margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid rgba(6, 182, 212, 0.1);">
                            <div style="color: #22c55e; font-weight: 600; margin-bottom: 4px;">Active (<?php echo number_format($metrics['active']); ?>)</div>
                            <div style="font-size: 13px; color: #94a3b8;">Approved and ready for execution</div>
                        </div>
                        <div style="margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid rgba(6, 182, 212, 0.1);">
                            <div style="color: #6366f1; font-weight: 600; margin-bottom: 4px;">Closed (<?php echo number_format($metrics['closed']); ?>)</div>
                            <div style="font-size: 13px; color: #94a3b8;">Completed and archived</div>
                        </div>
                        <div>
                            <div style="color: #ef4444; font-weight: 600; margin-bottom: 4px;">Expired (<?php echo number_format($metrics['expired']); ?>)</div>
                            <div style="font-size: 13px; color: #94a3b8;">Exceeded validity period</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- SECTION 3: PERFORMANCE -->
        <section data-section="performance">
            <div class="section-header">
                <div class="section-header-icon">📈</div>
                <div>
                    <h2>Performance Metrics</h2>
                    <p>Throughput, efficiency, and quality indicators</p>
                </div>
            </div>

            <div class="grid">
                <div class="card" data-id="metric-recent" data-narration="In the last thirty days, <?php echo number_format($recentCreated); ?> new permits entered the system. This shows the ongoing demand and throughput volume.">
                    <div class="metric-label">Created (30d)</div>
                    <div class="metric-value"><?php echo number_format($recentCreated); ?></div>
                    <div class="metric-trend">Monthly Volume</div>
                </div>

                <div class="card" data-id="metric-approved" data-narration="<?php echo number_format($recentApproved); ?> permits reached approval in the last thirty days. This indicates our approval velocity and pipeline health.">
                    <div class="metric-label">Approved (30d)</div>
                    <div class="metric-value"><?php echo number_format($recentApproved); ?></div>
                    <div class="metric-trend">Approval Velocity</div>
                </div>

                <div class="card" data-id="metric-turnaround" data-narration="Average approval time sits at <?php echo prettyNumber($avgApprovalHours, 1); ?> hours. This is our key efficiency metric for turnaround speed.">
                    <div class="metric-label">Avg Approval</div>
                    <div class="metric-value"><?php echo prettyNumber($avgApprovalHours, 1); ?></div>
                    <div class="metric-trend">Hours</div>
                </div>

                <div class="card" data-id="metric-ontime" data-narration="On-time closures are running at <?php echo ($onTimeRate !== null ? prettyNumber($onTimeRate * 100, 1) : '—'); ?> percent. This demonstrates our commitment to meeting deadlines and compliance.">
                    <div class="metric-label">On-Time Closures</div>
                    <div class="metric-value"><?php echo ($onTimeRate !== null ? prettyNumber($onTimeRate * 100, 1) . '%' : '—'); ?></div>
                    <div class="metric-trend">Quality Metric</div>
                </div>
            </div>

            <div class="chart-container" data-id="trend-chart" data-narration="This trend line shows our creation and approval patterns over the past twelve months. It reveals seasonality and helps forecast future capacity needs.">
                <div class="chart-title">📊 12-Month Trend</div>
                <canvas id="trendChart" height="80"></canvas>
            </div>

            <div class="chart-container" data-id="status-chart" data-narration="This breakdown shows how permits are distributed across all statuses. The majority are in active or closed states, indicating a healthy pipeline.">
                <div class="chart-title">🍰 Status Distribution</div>
                <canvas id="statusChart" height="80"></canvas>
            </div>
        </section>

        <!-- SECTION 4: INSIGHTS -->
        <section data-section="insights">
            <div class="section-header">
                <div class="section-header-icon">💡</div>
                <div>
                    <h2>Strategic Insights</h2>
                    <p>Key takeaways and recommendations</p>
                </div>
            </div>

            <div class="flowchart" data-id="flowchart-system" data-narration="This is our system architecture from a high level. Permits flow from creation through our approval system, into active operations, and finally to closure. Data is captured at each stage for reporting and compliance.">
                <svg viewBox="0 0 1000 400" preserveAspectRatio="xMidYMid meet">
                    <!-- Top Layer: Input -->
                    <rect x="350" y="20" width="300" height="80" rx="8" fill="rgba(6, 182, 212, 0.2)" stroke="#06b6d4" stroke-width="2"/>
                    <text x="500" y="55" font-size="18" font-weight="bold" fill="#e2e8f0" text-anchor="middle">📥 Input Layer</text>
                    <text x="500" y="80" font-size="12" fill="#94a3b8" text-anchor="middle">User Submissions &amp; Templates</text>

                    <!-- Arrow Down -->
                    <path d="M 500 100 L 500 140" stroke="#06b6d4" stroke-width="2" fill="none" marker-end="url(#arrowhead2)"/>

                    <!-- Middle Layer: Processing -->
                    <rect x="350" y="140" width="300" height="80" rx="8" fill="rgba(245, 158, 11, 0.2)" stroke="#f59e0b" stroke-width="2"/>
                    <text x="500" y="175" font-size="18" font-weight="bold" fill="#e2e8f0" text-anchor="middle">⚙️ Processing Layer</text>
                    <text x="500" y="200" font-size="12" fill="#94a3b8" text-anchor="middle">Validation, Approval Workflow &amp; Routing</text>

                    <!-- Arrow Down -->
                    <path d="M 500 220 L 500 260" stroke="#06b6d4" stroke-width="2" fill="none" marker-end="url(#arrowhead2)"/>

                    <!-- Bottom Layer: Output -->
                    <rect x="350" y="260" width="300" height="80" rx="8" fill="rgba(34, 197, 94, 0.2)" stroke="#22c55e" stroke-width="2"/>
                    <text x="500" y="295" font-size="18" font-weight="bold" fill="#e2e8f0" text-anchor="middle">📤 Output Layer</text>
                    <text x="500" y="320" font-size="12" fill="#94a3b8" text-anchor="middle">Reporting, Compliance &amp; Analytics</text>

                    <!-- Side boxes: Data Flow -->
                    <g>
                        <!-- Left: QR Codes -->
                        <rect x="40" y="140" width="120" height="80" rx="8" fill="rgba(6, 182, 212, 0.1)" stroke="rgba(6, 182, 212, 0.4)" stroke-width="1" stroke-dasharray="4"/>
                        <text x="100" y="170" font-size="14" font-weight="bold" fill="#06b6d4" text-anchor="middle">🔲 QR Codes</text>
                        <text x="100" y="190" font-size="11" fill="#94a3b8" text-anchor="middle">Public Access</text>

                        <!-- Arrow to QR -->
                        <path d="M 350 180 L 160 180" stroke="rgba(6, 182, 212, 0.3)" stroke-width="1" stroke-dasharray="4" fill="none"/>
                    </g>

                    <g>
                        <!-- Right: Activity Log -->
                        <rect x="840" y="140" width="120" height="80" rx="8" fill="rgba(6, 182, 212, 0.1)" stroke="rgba(6, 182, 212, 0.4)" stroke-width="1" stroke-dasharray="4"/>
                        <text x="900" y="170" font-size="14" font-weight="bold" fill="#06b6d4" text-anchor="middle">📊 Audit Log</text>
                        <text x="900" y="190" font-size="11" fill="#94a3b8" text-anchor="middle">Compliance</text>

                        <!-- Arrow to Audit -->
                        <path d="M 650 180 L 840 180" stroke="rgba(6, 182, 212, 0.3)" stroke-width="1" stroke-dasharray="4" fill="none"/>
                    </g>

                    <!-- Arrow marker definition -->
                    <defs>
                        <marker id="arrowhead2" markerWidth="10" markerHeight="10" refX="9" refY="3" orient="auto">
                            <polygon points="0 0, 10 3, 0 6" fill="#06b6d4"/>
                        </marker>
                    </defs>
                </svg>
            </div>

            <div class="grid">
                <div class="card" data-id="insight-expiring" data-narration="<?php echo ($expiringSoon > 0 ? number_format($expiringSoon) . ' permits need attention this week before they expire. This is a critical alert for compliance.' : 'No critical expirations this week. Our compliance is on track.'); ?>">
                    <div class="metric-label">Expiring Soon (7d)</div>
                    <div class="metric-value"><?php echo number_format($expiringSoon); ?></div>
                    <div class="metric-trend">Risk Alert</div>
                </div>

                <div class="card" data-id="insight-pipeline" data-narration="The ratio of pending to active permits shows our approval pipeline health. A balanced flow indicates good operational rhythm.">
                    <div class="metric-label">Pipeline Health</div>
                    <div class="metric-value" style="font-size: 32px;"><?php echo ($metrics['active'] > 0 ? number_format(round(($metrics['pending'] / $metrics['active']) * 100)) . '%' : '—'); ?></div>
                    <div class="metric-trend">Pending/Active Ratio</div>
                </div>

                <?php if ($topTemplates): ?>
                <div class="card" data-id="insight-template" data-narration="Our most popular template is <?php echo htmlspecialchars($topTemplates[0]['label']); ?>, covering <?php echo number_format($topTemplates[0]['total']); ?> cases recently. This shows strong standardization and efficiency.">
                    <div class="metric-label">Top Template</div>
                    <div class="metric-value" style="font-size: 24px; margin: 12px 0;"><?php echo htmlspecialchars(substr($topTemplates[0]['label'], 0, 20)); ?></div>
                    <div class="metric-trend"><?php echo number_format($topTemplates[0]['total']); ?> issued</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="chart-container" data-id="recommendations">
                <div class="chart-title">🎯 Key Recommendations</div>
                <div style="padding: 20px;">
                    <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid rgba(6, 182, 212, 0.1);">
                        <div style="color: #06b6d4; font-weight: 600; margin-bottom: 8px;">✓ Maintain Momentum</div>
                        <div style="font-size: 13px; color: #94a3b8;">Continue focusing on your strong approval velocity. The <?php echo prettyNumber($avgApprovalHours, 1); ?>-hour average is excellent.</div>
                    </div>
                    <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid rgba(6, 182, 212, 0.1);">
                        <div style="color: #06b6d4; font-weight: 600; margin-bottom: 8px;">📋 Monitor Expirations</div>
                        <div style="font-size: 13px; color: #94a3b8;">Review the <?php echo number_format($expiringSoon); ?> permits expiring within 7 days to prevent compliance gaps.</div>
                    </div>
                    <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid rgba(6, 182, 212, 0.1);">
                        <div style="color: #06b6d4; font-weight: 600; margin-bottom: 8px;">📈 Optimize Pipeline</div>
                        <div style="font-size: 13px; color: #94a3b8;">The current pipeline ratio suggests room for growth. Consider scaling approval resources if needed.</div>
                    </div>
                    <div>
                        <div style="color: #06b6d4; font-weight: 600; margin-bottom: 8px;">🎓 Leverage Data</div>
                        <div style="font-size: 13px; color: #94a3b8;">Use these insights to drive strategic decisions. Access the full dashboard for granular analysis and controls.</div>
                    </div>
                </div>
            </div>
        </section>

        <footer style="text-align: center; padding: 60px 0 20px; color: #64748b; font-size: 13px; border-top: 1px solid rgba(6, 182, 212, 0.1);">
            <p>Permit Intelligence Showcase • Real-time Data • Comprehensive Analytics</p>
            <p style="margin-top: 8px;"><a href="/admin/activity.php" style="color: #06b6d4; text-decoration: none; font-weight: 600;">View Activity Log →</a></p>
        </footer>
    </div>

    <div class="voice-indicator" id="voiceIndicator" role="status" aria-live="polite">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 3a4 4 0 0 0-4 4v6a4 4 0 0 0 8 0V7a4 4 0 0 0-4-4Zm-7 8a1 1 0 0 0-2 0 9 9 0 0 0 8 8.94V22a1 1 0 0 0 2 0v-2.06A9 9 0 0 0 19 11a1 1 0 0 0-2 0 7 7 0 1 1-14 0Z"/>
        </svg>
        <span id="narrationText">Narrating insights…</span>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        // Initialize charts
        const trendCtx = document.getElementById('trendChart');
        const statusCtx = document.getElementById('statusChart');

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    {
                        label: 'Created',
                        data: <?php echo json_encode($chartCreated); ?>,
                        fill: true,
                        tension: 0.4,
                        backgroundColor: 'rgba(6, 182, 212, 0.1)',
                        borderColor: '#06b6d4',
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#06b6d4'
                    },
                    {
                        label: 'Approved',
                        data: <?php echo json_encode($chartApproved); ?>,
                        fill: true,
                        tension: 0.4,
                        backgroundColor: 'rgba(34, 197, 94, 0.1)',
                        borderColor: '#22c55e',
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#22c55e'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top', labels: { color: '#cbd5f5', usePointStyle: true } },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.92)',
                        titleColor: '#e2e8f0',
                        bodyColor: '#cbd5f5'
                    }
                },
                scales: {
                    x: { grid: { color: 'rgba(148, 163, 184, 0.1)' }, ticks: { color: '#94a3b8' } },
                    y: { beginAtZero: true, grid: { color: 'rgba(148, 163, 184, 0.1)' }, ticks: { color: '#94a3b8' } }
                }
            }
        });

        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($statusLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($statusValues); ?>,
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.75)',
                        'rgba(245, 158, 11, 0.75)',
                        'rgba(239, 68, 68, 0.75)',
                        'rgba(249, 115, 22, 0.75)',
                        'rgba(148, 163, 184, 0.75)',
                        'rgba(16, 185, 129, 0.75)'
                    ],
                    borderColor: 'rgba(15, 23, 42, 0.9)',
                    borderWidth: 3
                }]
            },
            options: {
                cutout: '60%',
                plugins: { legend: { position: 'bottom', labels: { color: '#cbd5f5', usePointStyle: true } } }
            }
        });

        // Scroll animations
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });

            document.querySelectorAll('[data-animate], [data-id], section').forEach(el => observer.observe(el));
        } else {
            document.querySelectorAll('[data-animate], [data-id], section').forEach(el => el.classList.add('is-visible'));
        }

        // Voice narration
        const synth = window.speechSynthesis;
        const playBtn = document.getElementById('playPresentation');
        const stopBtn = document.getElementById('stopPresentation');
        const indicator = document.getElementById('voiceIndicator');
        const narrationText = document.getElementById('narrationText');
        let activeSpotlight = null;
        let selectedVoice = null;
        let currentAudio = null;

        async function resolveVoice() {
            return new Promise(resolve => {
                if (!synth) { resolve(null); return; }
                const available = synth.getVoices();
                if (available.length) {
                    // Prefer natural male voices (ChatGPT-style)
                    selectedVoice = available.find(v => 
                        v.name.includes('Google UK English Male')
                    ) || available.find(v => 
                        v.lang.startsWith('en-GB') && v.name.includes('Male')
                    ) || available.find(v => 
                        v.name.includes('Samantha')
                    ) || available.find(v => 
                        v.lang.startsWith('en-GB') && v.name.includes('Male')
                    ) || available.find(v => 
                        v.name.includes('Male')
                    ) || available.find(v => 
                        v.lang.startsWith('en-GB')
                    ) || available[0];
                    if (selectedVoice) selectedVoice.lang = 'en-GB';
                    resolve(selectedVoice);
                    return;
                }
                const handle = () => {
                    const fresh = synth.getVoices();
                    if (fresh.length) {
                        synth.removeEventListener('voiceschanged', handle);
                        // Prefer natural male voices (ChatGPT-style)
                        selectedVoice = fresh.find(v => 
                            v.name.includes('Google UK English Male')
                        ) || fresh.find(v => 
                            v.lang.startsWith('en-GB') && v.name.includes('Male')
                        ) || fresh.find(v => 
                            v.name.includes('Samantha')
                        ) || fresh.find(v => 
                            v.lang.startsWith('en-GB') && v.name.includes('Male')
                        ) || fresh.find(v => 
                            v.name.includes('Male')
                        ) || fresh.find(v => 
                            v.lang.startsWith('en-GB')
                        ) || fresh[0];
                        if (selectedVoice) selectedVoice.lang = 'en-GB';
                        resolve(selectedVoice);
                    }
                };
                synth.addEventListener('voiceschanged', handle);
                setTimeout(() => resolve(null), 2000);
            });
        }

        function highlightElement(id) {
            if (activeSpotlight) activeSpotlight.classList.remove('spotlight');
            const el = document.querySelector(`[data-id="${id}"]`);
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

        function stopNarration() {
            if (currentAudio) {
                currentAudio.pause();
                currentAudio = null;
            }
            if (synth && synth.speaking) synth.cancel();
            indicator.classList.remove('active');
            clearHighlight();
        }

        async function playNarration() {
            const useOpenAI = <?php echo $hasOpenAI ? 'true' : 'false'; ?>;
            console.log('Using OpenAI:', useOpenAI);
            
            if (useOpenAI) {
                await playNarrationWithOpenAI();
            } else {
                console.log('Falling back to browser voice');
                await playNarrationWithBrowserVoice();
            }
        }

        async function playNarrationWithOpenAI() {
            stopNarration();
            indicator.classList.add('active');

            const allElements = document.querySelectorAll('[data-narration]');
            let index = 0;

            async function speakNextWithOpenAI() {
                if (index >= allElements.length) {
                    stopNarration();
                    return;
                }

                const el = allElements[index];
                const text = el.getAttribute('data-narration');
                const id = el.getAttribute('data-id');

                narrationText.textContent = text;
                highlightElement(id);

                try {
                    console.log('Calling TTS API for text:', text.substring(0, 50) + '...');
                    const response = await fetch('/api/openai-tts.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            text: text,
                            voice: 'onyx',
                            model: 'tts-1'
                        })
                    });

                    console.log('TTS Response status:', response.status);

                    if (!response.ok) {
                        const errorData = await response.json();
                        console.error('TTS error:', errorData);
                        throw new Error('TTS request failed: ' + (errorData.error || response.statusText));
                    }
                    
                    const blob = await response.blob();
                    console.log('Received audio blob:', blob.size, 'bytes');
                    
                    const url = URL.createObjectURL(blob);
                    currentAudio = new Audio(url);
                    
                    currentAudio.onended = () => {
                        console.log('Audio ended, moving to next');
                        index++;
                        setTimeout(speakNextWithOpenAI, 500);
                    };

                    currentAudio.onerror = (err) => {
                        console.error('Audio playback error:', err);
                        index++;
                        setTimeout(speakNextWithOpenAI, 500);
                    };

                    console.log('Starting playback');
                    currentAudio.play().catch(err => {
                        console.error('Play error:', err);
                        index++;
                        setTimeout(speakNextWithOpenAI, 500);
                    });
                } catch (err) {
                    console.error('OpenAI TTS error:', err);
                    index++;
                    setTimeout(speakNextWithOpenAI, 500);
                }
            }

            await speakNextWithOpenAI();
        }

        async function playNarrationWithBrowserVoice() {
            await resolveVoice();
            if (!('speechSynthesis' in window)) {
                alert('Speech not supported');
                return;
            }

            stopNarration();
            indicator.classList.add('active');

            const allElements = document.querySelectorAll('[data-narration]');
            let index = 0;

            function speakNext() {
                if (index >= allElements.length) {
                    stopNarration();
                    return;
                }

                const el = allElements[index];
                const text = el.getAttribute('data-narration');
                const id = el.getAttribute('data-id');

                narrationText.textContent = text;
                highlightElement(id);

                const utterance = new SpeechSynthesisUtterance(text);
                utterance.rate = 0.95;
                utterance.pitch = 1.0;
                if (selectedVoice) {
                    utterance.voice = selectedVoice;
                    utterance.lang = selectedVoice.lang;
                }

                utterance.onend = () => {
                    index++;
                    setTimeout(speakNext, 500);
                };

                synth.speak(utterance);
            }

            speakNext();
        }

        playBtn.addEventListener('click', playNarration);
        stopBtn.addEventListener('click', stopNarration);
    </script>
</body>
</html>
