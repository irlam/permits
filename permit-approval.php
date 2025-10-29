<?php
/**
 * Permit Approval Landing Page
 * ---------------------------------
 * Allows approvers to review a permit and record a decision using a
 * single-use token delivered via email.
 */

[$app, $db, $root] = require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/approval-notifications.php';

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$intent = strtolower(trim($_GET['intent'] ?? ''));
if (!in_array($intent, ['approve', 'reject'], true)) {
	$intent = 'approve';
}

$alert = null;
$decisionDetail = null;
$link = null;
$permit = null;
$expired = false;
$used = false;
$allowAction = false;

try {
	if ($token === '') {
		$alert = [
			'type' => 'error',
			'title' => 'Approval link missing',
			'message' => 'The approval link appears to be incomplete. Please tap the link directly from your email.',
		];
	} else {
		$link = fetchApprovalLinkByToken($db, $token);
		if (!$link) {
			$alert = [
				'type' => 'error',
				'title' => 'Link not recognised',
				'message' => 'This approval link is not valid or may have already been replaced by a newer email.',
			];
		} else {
			$permit = fetchPermitForApproval($db, (string)$link['permit_id']);
			if (!$permit) {
				$alert = [
					'type' => 'error',
					'title' => 'Permit unavailable',
					'message' => 'We could not find the permit associated with this link. It may have been removed or archived.',
				];
			} else {
				$expiresRaw = $link['expires_at'] ?? null;
				$expired = $expiresRaw && strtotime((string)$expiresRaw) < time();
				$used = !empty($link['used_at']);
				$status = strtolower((string)$permit['status']);
				$allowAction = !$expired && !$used && $status === 'pending_approval';
			}
		}
	}
} catch (Throwable $e) {
	$alert = [
		'type' => 'error',
		'title' => 'System error',
		'message' => 'We were unable to load this approval link. Please try again, or contact the permits team.',
	];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $link && $permit) {
	$postedToken = trim($_POST['token'] ?? '');
	$action = strtolower(trim($_POST['action'] ?? ''));
	$comment = trim($_POST['comment'] ?? '');

	if (!hash_equals($token, $postedToken)) {
		$alert = [
			'type' => 'error',
			'title' => 'Request could not be verified',
			'message' => 'Please submit your decision using the original email link.',
		];
	} elseif (!in_array($action, ['approve', 'reject'], true)) {
		$alert = [
			'type' => 'error',
			'title' => 'Choose an action',
			'message' => 'Select either approve or reject before submitting.',
		];
	} elseif (!$allowAction) {
		$alert = [
			'type' => 'info',
			'title' => 'Nothing to action',
			'message' => 'This permit is no longer awaiting approval.',
		];
	} else {
		try {
			$result = processApprovalLinkDecision($db, $link, $action, [
				'comment' => $comment,
				'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
				'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
			]);

			$alert = [
				'type' => 'success',
				'title' => $result['title'],
				'message' => 'Thank you. Your decision has been recorded.',
			];
			if (!empty($result['comment'])) {
				$decisionDetail = $result['comment'];
			}

			$allowAction = false;
			$link = fetchApprovalLinkByToken($db, $token);
			$permit = fetchPermitForApproval($db, $result['permit_id']);
		} catch (Throwable $e) {
			$alert = [
				'type' => 'error',
				'title' => 'Could not complete request',
				'message' => $e->getMessage(),
			];
			$allowAction = false;
			$link = fetchApprovalLinkByToken($db, $token);
			if ($link) {
				$permit = fetchPermitForApproval($db, (string)$link['permit_id']);
			}
		}
	}

	if ($link) {
		$expiresRaw = $link['expires_at'] ?? null;
		$expired = $expiresRaw && strtotime((string)$expiresRaw) < time();
		$used = !empty($link['used_at']);
	}
}

$permitRef = $permit['ref_number'] ?? $permit['ref'] ?? $permit['id'] ?? null;
$templateName = $permit['template_name'] ?? 'Permit To Work';
$holderName = $permit['holder_name'] ?? 'Unknown';
$holderEmail = $permit['holder_email'] ?? null;
$submittedAt = $permit && !empty($permit['created_at'])
	? date('d/m/Y H:i', strtotime((string)$permit['created_at']))
	: null;
$permitStatus = $permit['status'] ?? null;

$statusLabels = [
	'pending_approval' => 'Awaiting approval',
	'active' => 'Active',
	'rejected' => 'Rejected',
	'draft' => 'Draft',
];
$statusKey = is_string($permitStatus) ? strtolower($permitStatus) : null;
$statusLabel = $statusLabels[$statusKey] ?? ucfirst($statusKey ?? 'unknown');

$expiresAtDisplay = $link && !empty($link['expires_at'])
	? date('d/m/Y H:i', strtotime((string)$link['expires_at']))
	: null;
$usedAtDisplay = $link && !empty($link['used_at'])
	? date('d/m/Y H:i', strtotime((string)$link['used_at']))
	: null;
$usedAction = $link['used_action'] ?? null;
$usedComment = $link['used_comment'] ?? null;

$viewPermitUrl = null;
if ($permit && !empty($permit['unique_link'])) {
	$viewPermitUrl = $app->url('view-permit-public.php') . '?link=' . urlencode((string)$permit['unique_link']);
}

$managerUrl = $app->url('manager-approvals.php');
$approveHighlight = $intent === 'approve' ? ' is-primary' : '';
$rejectHighlight = $intent === 'reject' ? ' is-primary' : '';
$commentValue = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['comment'] ?? '') : '';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Permit Approval</title>
	<style>
		* { box-sizing: border-box; }
		body {
			margin: 0;
			font-family: "SF Pro Display", "Segoe UI", system-ui, sans-serif;
			background: radial-gradient(circle at 20% 20%, #1f2937 0%, #0f172a 55%, #020617 100%);
			color: #e2e8f0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
		}
		.wrap {
			width: 100%;
			max-width: 720px;
			display: flex;
			flex-direction: column;
			gap: 18px;
		}
		.header h1 {
			margin: 0;
			font-size: 26px;
			letter-spacing: 0.02em;
		}
		.header p {
			margin: 6px 0 0;
			color: #94a3b8;
		}
		.card {
			background: rgba(15, 23, 42, 0.85);
			border: 1px solid rgba(148, 163, 184, 0.12);
			border-radius: 20px;
			padding: 24px;
			backdrop-filter: blur(14px);
			box-shadow: 0 20px 40px rgba(2, 6, 23, 0.4);
		}
		.summary h2 {
			margin: 0 0 12px 0;
			font-size: 22px;
		}
		.status-pill {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			font-size: 13px;
			padding: 6px 12px;
			border-radius: 999px;
			background: rgba(59, 130, 246, 0.15);
			color: #60a5fa;
			margin-left: 12px;
		}
		.status-pill.is-active { background: rgba(16, 185, 129, 0.18); color: #34d399; }
		.status-pill.is-rejected { background: rgba(239, 68, 68, 0.18); color: #f87171; }
		.status-pill.is-pending { background: rgba(245, 158, 11, 0.2); color: #facc15; }
		dl {
			display: grid;
			grid-template-columns: 160px 1fr;
			gap: 10px 16px;
			margin: 0;
		}
		dt {
			font-size: 13px;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #64748b;
		}
		dd {
			margin: 0;
			font-size: 16px;
		}
		.actions { margin-top: 18px; display: flex; flex-wrap: wrap; gap: 12px; }
		.actions a {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 12px 18px;
			border-radius: 12px;
			text-decoration: none;
			font-weight: 600;
			color: #e2e8f0;
			border: 1px solid rgba(148, 163, 184, 0.2);
			background: rgba(51, 65, 85, 0.32);
		}
		.actions a:hover { border-color: rgba(148, 163, 184, 0.4); }
		.alert {
			border-radius: 16px;
			padding: 18px 20px;
			border: 1px solid;
		}
		.alert h2 {
			margin: 0 0 8px 0;
			font-size: 18px;
		}
		.alert p {
			margin: 0;
			font-size: 15px;
			line-height: 1.6;
		}
		.alert-detail { margin-top: 10px; font-size: 14px; color: #cbd5f5; }
		.alert.success { background: rgba(22, 163, 74, 0.18); border-color: rgba(74, 222, 128, 0.35); color: #bbf7d0; }
		.alert.error { background: rgba(239, 68, 68, 0.16); border-color: rgba(252, 165, 165, 0.38); color: #fecaca; }
		.alert.info { background: rgba(59, 130, 246, 0.16); border-color: rgba(96, 165, 250, 0.4); color: #bfdbfe; }
		form { display: grid; gap: 16px; }
		label {
			font-size: 13px;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: #94a3b8;
		}
		textarea {
			min-height: 110px;
			border-radius: 14px;
			border: 1px solid rgba(148, 163, 184, 0.2);
			background: rgba(15, 23, 42, 0.7);
			color: #e2e8f0;
			padding: 14px;
			font-size: 15px;
			resize: vertical;
		}
		textarea:focus {
			outline: none;
			border-color: rgba(96, 165, 250, 0.6);
			box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.25);
		}
		.decision-buttons {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
			gap: 12px;
		}
		.action-btn {
			border: none;
			border-radius: 14px;
			padding: 16px;
			font-size: 16px;
			font-weight: 600;
			cursor: pointer;
			transition: transform 0.15s ease;
		}
		.action-btn:hover { transform: translateY(-1px); }
		.action-btn:disabled { cursor: not-allowed; opacity: 0.6; transform: none; }
		.action-btn.approve { background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); color: #f0fdf4; }
		.action-btn.reject { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: #fee2e2; }
		.action-btn.is-primary { box-shadow: 0 12px 28px rgba(22, 163, 74, 0.28); }
		.action-btn.reject.is-primary { box-shadow: 0 12px 28px rgba(220, 38, 38, 0.32); }
		.meta-note {
			margin-top: 14px;
			font-size: 14px;
			color: #94a3b8;
		}
		.decision-summary {
			margin-top: 18px;
			padding: 14px 16px;
			border-radius: 14px;
			background: rgba(30, 41, 59, 0.65);
			border: 1px solid rgba(148, 163, 184, 0.18);
			font-size: 14px;
			line-height: 1.6;
		}
		.decision-summary span { display: block; color: #cbd5f5; }
		@media (max-width: 640px) {
			body { padding: 16px; }
			.card { padding: 20px; }
			dl { grid-template-columns: 1fr; }
		}
	</style>
</head>
<body>
	<main class="wrap">
		<div class="header">
			<h1>Permit approval</h1>
			<p>Review the permit details and record your decision without signing in.</p>
		</div>

		<?php if ($alert): ?>
			<div class="alert <?= htmlspecialchars($alert['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
				<h2><?= htmlspecialchars($alert['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h2>
				<p><?= htmlspecialchars($alert['message'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
				<?php if ($decisionDetail): ?>
					<p class="alert-detail">Notes saved: <?= htmlspecialchars($decisionDetail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ($permit): ?>
			<section class="card summary">
				<h2>
					<?= htmlspecialchars($permitRef ?? 'Permit', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
					<span class="status-pill <?= $statusKey === 'pending_approval' ? 'is-pending' : ($statusKey === 'active' ? 'is-active' : ($statusKey === 'rejected' ? 'is-rejected' : '')); ?>">
						<?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
					</span>
				</h2>
				<dl>
					<dt>Template</dt>
					<dd><?= htmlspecialchars($templateName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></dd>

					<dt>Permit holder</dt>
					<dd><?= htmlspecialchars($holderName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?><?php if ($holderEmail): ?> &middot; <a href="mailto:<?= htmlspecialchars($holderEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" style="color:#93c5fd;">Email</a><?php endif; ?></dd>

					<?php if ($submittedAt): ?>
						<dt>Submitted</dt>
						<dd><?= htmlspecialchars($submittedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></dd>
					<?php endif; ?>

					<?php if ($expiresAtDisplay && !$used): ?>
						<dt>Link expires</dt>
						<dd><?= htmlspecialchars($expiresAtDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></dd>
					<?php endif; ?>

					<?php if ($usedAtDisplay): ?>
						<dt>Last decision</dt>
						<dd>
							<?= htmlspecialchars(ucfirst((string)$usedAction ?: 'recorded'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> on <?= htmlspecialchars($usedAtDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
						</dd>
					<?php endif; ?>
				</dl>

				<?php if ($usedComment): ?>
					<div class="decision-summary">
						<span>Most recent note</span>
						<?= nl2br(htmlspecialchars($usedComment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?>
					</div>
				<?php endif; ?>

				<div class="actions">
					<?php if ($viewPermitUrl): ?>
						<a href="<?= htmlspecialchars($viewPermitUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank" rel="noopener">View full permit</a>
					<?php endif; ?>
					<a href="<?= htmlspecialchars($managerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" target="_blank" rel="noopener">Manager approvals</a>
				</div>

				<?php if ($expired): ?>
					<p class="meta-note">This link expired on <?= htmlspecialchars($expiresAtDisplay ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>. Request a new approval email if you still need to action this permit.</p>
				<?php elseif ($used && !$alert): ?>
					<p class="meta-note">This permit has already been <?= htmlspecialchars((string)$usedAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>. No further action is required.</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ($allowAction && $permit && $link): ?>
			<section class="card">
				<h3 style="margin-top:0; font-size:18px;">Record your decision</h3>
				<p style="color:#94a3b8; margin-bottom:12px;">Approve or reject this permit. Add an optional note so the issuer understands the outcome.</p>
				<form method="post">
					<input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
					<label for="comment">Notes (optional)</label>
					<textarea id="comment" name="comment" placeholder="Add a short note for the team..."><?= htmlspecialchars($commentValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
					<div class="decision-buttons">
						<button type="submit" name="action" value="approve" class="action-btn approve<?= $approveHighlight; ?>">Approve permit</button>
						<button type="submit" name="action" value="reject" class="action-btn reject<?= $rejectHighlight; ?>">Reject permit</button>
					</div>
				</form>
			</section>
		<?php elseif (!$permit && !$alert): ?>
			<section class="card">
				<p style="margin:0; color:#94a3b8;">We could not load the permit details for this link.</p>
			</section>
		<?php endif; ?>
	</main>
</body>
</html>
