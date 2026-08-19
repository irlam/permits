<?php
declare(strict_types=1);

/**
 * Phase 4 dashboard enhancement.
 *
 * Keep the established analytics/dashboard renderer unchanged and inject an
 * obvious Operational Permit Board entry point for every signed-in team member.
 */
ob_start();
require __DIR__ . '/dashboard-legacy.php';
$html = (string)ob_get_clean();

if (isset($app)) {
    $boardUrl = htmlspecialchars($app->url('permit-board.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $accountUrl = htmlspecialchars($app->url('account.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $accountLink = '<a href="' . $accountUrl . '" class="btn btn-secondary">🔑 My Account</a>';
    $boardLink = '<a href="' . $boardUrl . '" class="btn btn-primary">🪧 Permit Board</a>';
    if (strpos($html, $accountLink) !== false) {
        $html = str_replace($accountLink, $boardLink . "\n                " . $accountLink, $html);
    }
}

echo $html;
