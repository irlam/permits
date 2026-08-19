<?php
/**
 * API: Record Start Work event for a permit
 *
 * Input (JSON or form):
 * - link: unique public link of the permit (preferred)
 * The unguessable public link is required.
 */

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $auth = new Auth($db);
    $currentUser = $auth->requireJson();

    if (!\Permits\Csrf::validateRequest('permit-start-work')) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Your page token expired. Refresh the permit and try again.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = [];
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) { $data = $decoded; }
    }
    if (empty($data)) { $data = $_POST; }

    $unique_link = is_scalar($data['link'] ?? null) ? trim((string) $data['link']) : '';
    if (!$unique_link || strlen($unique_link) < 32 || strlen($unique_link) > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid public link is required']);
        exit;
    }

    $stmt = $db->pdo->prepare("
        SELECT f.*, ft.form_structure, ft.json_schema
        FROM forms f
        INNER JOIN form_templates ft ON ft.id = f.template_id
        WHERE f.unique_link = ?
        LIMIT 1
    ");
    $stmt->execute([$unique_link]);

    $permit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$permit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Permit not found']);
        exit;
    }

    $activeStatuses = ['active', 'issued', 'approved', 'open'];
    if (!in_array(strtolower((string) $permit['status']), $activeStatuses, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Permit is not active']);
        exit;
    }

    if (!\Permits\PermitAccess::canAccessPermit($currentUser, $permit)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to start work on this permit']);
        exit;
    }

    if (!empty($permit['work_started_at']) && $permit['work_started_at'] !== '0000-00-00 00:00:00') {
        echo json_encode(['success' => true, 'message' => 'Already recorded', 'work_started_at' => $permit['work_started_at']]);
        exit;
    }

    $nowTimestamp = time();
    $validFromTimestamp = !empty($permit['valid_from']) ? strtotime((string) $permit['valid_from']) : false;
    $validToTimestamp = !empty($permit['valid_to']) ? strtotime((string) $permit['valid_to']) : false;
    if ($validFromTimestamp !== false && $validFromTimestamp > $nowTimestamp) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This permit is not valid yet']);
        exit;
    }
    if ($validToTimestamp !== false && $validToTimestamp <= $nowTimestamp) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This permit has expired']);
        exit;
    }

    $structure = json_decode((string) ($permit['form_structure'] ?? ''), true);
    if (!is_array($structure) || $structure === []) {
        $schema = json_decode((string) ($permit['json_schema'] ?? ''), true);
        $structure = is_array($schema)
            ? \Permits\FormTemplateSeeder::buildPublicFormStructure($schema)
            : [];
    }
    $answers = json_decode((string) ($permit['form_data'] ?? ''), true);
    if (!is_array($answers)) {
        $answers = [];
    }

    if (($answers['_applicant_declaration'] ?? '') !== 'confirmed') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'The applicant declaration is missing']);
        exit;
    }

    if ($structure === []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This permit template has no usable fields']);
        exit;
    }

    $validationErrors = \Permits\PermitFormValidator::validate($structure, $answers, true);
    if ($validationErrors !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This permit is incomplete and must be corrected before work starts']);
        exit;
    }

    $yes = 0;
    $no = 0;
    $scoreItems = 0;
    foreach ($structure as $section) {
        foreach (($section['fields'] ?? []) as $field) {
            if (!is_array($field) || empty($field['scoreItem']) || empty($field['name'])) {
                continue;
            }
            $scoreItems++;
            $answer = strtolower(trim((string) ($answers[(string) $field['name']] ?? '')));
            if ($answer === 'yes') {
                $yes++;
            } elseif ($answer === 'no') {
                $no++;
            }
        }
    }
    $scoreTotal = $yes + $no;
    $score = $scoreTotal > 0 ? (int) round(($yes / $scoreTotal) * 100) : null;
    if ($scoreItems > 0 && $scoreTotal === 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'At least one safety check must be applicable before work starts']);
        exit;
    }
    if ($score !== null && $score < 80) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Safety score must be at least 80% before work starts', 'score' => $score]);
        exit;
    }

    // Explicit conflict relationships are a hard start-work interlock. Related
    // and SIMOPS links remain advisory/coordination records; only a manager-set
    // conflict prevents the two permits from being active at the same time.
    $blockingConflicts = \Permits\PermitLinks::blockingConflicts($db->pdo, (string)$permit['id']);
    if ($blockingConflicts !== []) {
        $references = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['ref_number'] ?? '')),
            $blockingConflicts
        )));
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Work cannot start while a conflicting linked permit is active' . ($references !== [] ? ': #' . implode(', #', array_slice($references, 0, 5)) : '.'),
            'conflicting_permits' => $references,
        ]);
        exit;
    }

    $nowExpr = $db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    $upd = $db->pdo->prepare("
        UPDATE forms
        SET work_started_at = $nowExpr, updated_at = $nowExpr
        WHERE id = ?
          AND status IN ('active', 'issued', 'approved', 'open')
          AND (work_started_at IS NULL OR work_started_at = '0000-00-00 00:00:00')
          AND (valid_from IS NULL OR valid_from <= $nowExpr)
          AND (valid_to IS NULL OR valid_to > $nowExpr)
    ");
    $upd->execute([$permit['id']]);

    $get = $db->pdo->prepare("SELECT work_started_at FROM forms WHERE id = ?");
    $get->execute([$permit['id']]);
    $ts = $get->fetchColumn();

    if ($upd->rowCount() !== 1 && empty($ts)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Permit state changed before work could be started']);
        exit;
    }

    if ($upd->rowCount() === 1) {
        try {
            \Permits\PermitWorkflow::recordEvent(
                $db->pdo,
                (string)$permit['id'],
                'work_started',
                (string)($currentUser['id'] ?? ''),
                ['score' => $score]
            );
        } catch (\Throwable $eventError) {
            error_log('Unable to record work-start workflow event: ' . $eventError->getMessage());
        }

        if (function_exists('logActivity')) {
            try {
                logActivity('work_started', 'permit', 'form', $permit['id'], 'Work started recorded via public view');
            } catch (\Throwable $logError) {
                error_log('Unable to log work start: ' . $logError->getMessage());
            }
        }
    }

    echo json_encode(['success' => true, 'work_started_at' => $ts, 'score' => $score]);
} catch (\Throwable $e) {
    error_log('Start-work request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to record work start']);
}
