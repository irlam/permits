<?php
declare(strict_types=1);

/**
 * Privacy layer for the Phase 4 permit lifecycle screen.
 *
 * The lifecycle implementation is preserved in permit-workflow-legacy.php.
 * The public bearer/QR route must never disclose the holder email merely by
 * rendering the acceptance form, so this wrapper removes the prefilled email
 * value before any HTML reaches the browser. The holder must type the email and
 * PermitWorkflow::accept() verifies it against the permit record server-side.
 */
ob_start();
require __DIR__ . '/permit-workflow-legacy.php';
$html = (string)ob_get_clean();

$html = preg_replace(
    '/(<input\s+type="email"\s+name="accepted_email"[^>]*?)\s+value="[^"]*"([^>]*>)/i',
    '$1 value="" autocomplete="email"$2',
    $html,
    1
) ?? $html;

echo $html;
