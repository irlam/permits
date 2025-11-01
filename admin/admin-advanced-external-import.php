<?php
/**
 * Advanced External Template Importer (Admin)
 *
 * Batch import, advanced parsing, and AI-assisted field extraction for construction templates.
 *
 * Roadmap:
 * 1. Batch import from multiple URLs or file uploads
 * 2. Source-specific HTML parsing (SafetyCulture, OSHA, HSE)
 * 3. AI/LLM field extraction (future)
 * 4. Visual mapping UI (future)
 * 5. Scheduled sync (future)
 */

// DEBUG: Output session and cookie info for troubleshooting, before any redirect
require __DIR__ . '/../vendor/autoload.php';
[$app, $db, $root] = require_once __DIR__ . '/../src/bootstrap.php';
if (isset($_GET['debug'])) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    echo '<pre style="background:#222;color:#fff;padding:12px;">';
    echo 'Session ID: ' . session_id() . "\n";
    echo 'Session Data: ' . print_r($_SESSION, true) . "\n";
    echo 'Cookies: ' . print_r($_COOKIE, true) . "\n";
    echo '</pre>';
    exit;
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
$stmt = $db->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$currentUser || $currentUser['role'] !== 'admin') {
    http_response_code(403);
    echo '<h1>Access denied</h1><p>Administrator role required.</p>';
    exit;
}
$messages = [];
$errors = [];
$previewData = [];
$showPreview = false;
$openAiConfig = load_openai_config($root);
$openAiAvailable = $openAiConfig !== null;
$fieldTypeOptions = [
    'text' => 'Short Text',
    'textarea' => 'Long Text',
    'checkbox' => 'Single Checkbox',
    'checkboxes' => 'Multiple Checkboxes',
    'radio' => 'Radio Buttons',
    'select' => 'Dropdown',
    'number' => 'Number',
    'date' => 'Date',
    'time' => 'Time',
    'section' => 'Section Heading',
    'paragraph' => 'Paragraph',
];
$selectedSource = $_POST['source'] ?? '';
$postedUrls = $_POST['template_urls'] ?? '';
if ($openAiAvailable) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aiRequested = isset($_POST['enable_ai']) && $_POST['enable_ai'] === '1';
    } else {
        $aiRequested = true;
    }
} else {
    $aiRequested = false;
}

function add_field_candidate(array &$fields, array &$seen, string $label, string $type = 'text', bool $required = false, array $meta = []): void
{
    $label = trim(preg_replace('/\s+/', ' ', $label));
    if ($label === '' || strlen($label) < 3) {
        return;
    }

    $key = strtolower($label . '|' . $type);
    if (isset($seen[$key])) {
        return;
    }

    $seen[$key] = true;
    $fields[] = array_merge([
        'label' => $label,
        'type' => $type,
        'required' => $required,
    ], $meta);
}

// Use Simple HTML DOM for robust field extraction
function extract_fields_from_html($html, $source) {
    $fields = [];
    $seen = [];
    $dom = new \simplehtmldom\HtmlDocument();
    $dom->load($html, true, false);

    // Extract list items (common in checklists)
    foreach ($dom->find('li') as $li) {
        add_field_candidate($fields, $seen, $li->plaintext, 'checkbox');
    }

    // Extract table-based checklists (rows and headers)
    foreach ($dom->find('table') as $table) {
        foreach ($table->find('tr') as $row) {
            $cells = array_map(static fn($cell) => trim($cell->plaintext), $row->find('th,td'));
            $cells = array_filter($cells, static fn($value) => $value !== '');
            if (count($cells) === 1) {
                add_field_candidate($fields, $seen, reset($cells), 'text');
            } elseif (!empty($cells)) {
                add_field_candidate($fields, $seen, implode(' | ', $cells), 'text');
            }
        }
    }

    // Extract labels and their associated inputs/selects
    foreach ($dom->find('label') as $labelNode) {
        $labelText = $labelNode->plaintext;
        $forId = $labelNode->for ?? $labelNode->getAttribute('for');
        $required = strpos($labelText, '*') !== false;
        $inputType = 'text';

        if ($forId) {
            $input = $dom->find("#{$forId}", 0);
            if ($input) {
                $tag = strtolower($input->tag);
                if ($tag === 'input') {
                    $inputType = $input->type ?: 'text';
                    $required = $required || ($input->required ?? false);
                } elseif ($tag === 'select') {
                    $inputType = 'select';
                } elseif ($tag === 'textarea') {
                    $inputType = 'textarea';
                }
            }
        }

        add_field_candidate($fields, $seen, $labelText, $inputType, $required);
    }

    // Plain input elements without labels (fallback to placeholder/name)
    foreach ($dom->find('input, textarea, select') as $input) {
        $label = $input->getAttribute('aria-label')
            ?? $input->placeholder
            ?? $input->name
            ?? '';
        $type = $input->tag === 'input' ? ($input->type ?: 'text') : $input->tag;
        $required = $input->required ?? false;
        add_field_candidate($fields, $seen, $label, $type, $required);
    }

    // Headings often describe sections/tasks
    foreach ($dom->find('h1, h2, h3, h4, h5') as $heading) {
        add_field_candidate($fields, $seen, $heading->plaintext, 'section');
    }

    $dom->clear();
    unset($dom);

    return $fields;
}

function extract_fields_from_text(string $text, string $source): array
{
    $fields = [];
    $seen = [];
    $lines = preg_split('/\r?\n/', $text) ?: [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strlen($trimmed) < 3) {
            continue;
        }

        // Checkbox style lines e.g. "[ ] Harness Checked"
        if (preg_match('/^[-*•\u2022]?\s*\[\s*[xX]?\s*\]\s*(.+)$/u', $trimmed, $matches)) {
            add_field_candidate($fields, $seen, $matches[1], 'checkbox');
            continue;
        }

        // Bullet points treated as checkbox tasks
        if (preg_match('/^[-*•\u2022]\s+(.+)$/u', $trimmed, $matches)) {
            add_field_candidate($fields, $seen, $matches[1], 'checkbox');
            continue;
        }

        // Colon separated label/value pairs → text fields
        if (preg_match('/^(.{3,80}?)[\s:：]+(.{0,120})$/u', $trimmed, $matches)) {
            $label = $matches[1];
            $value = trim($matches[2]);
            $type = 'text';
            if (preg_match('/date/i', $label)) {
                $type = 'date';
            } elseif (preg_match('/time/i', $label)) {
                $type = 'time';
            } elseif (preg_match('/number|qty|quantity|count/i', $label)) {
                $type = 'number';
            }
            add_field_candidate($fields, $seen, $label, $type);
            continue;
        }

        // Question style lines → boolean/checkbox by default
        if (preg_match('/\?$/', $trimmed)) {
            add_field_candidate($fields, $seen, $trimmed, 'checkbox');
            continue;
        }

        // Uppercase headings treated as sections
        if (strlen($trimmed) <= 60 && strtoupper($trimmed) === $trimmed && preg_match('/[A-Z]/', $trimmed)) {
            add_field_candidate($fields, $seen, $trimmed, 'section');
            continue;
        }
    }

    return $fields;
}

function extract_text_from_docx(string $path): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP Zip extension is required to process DOCX files');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open DOCX archive');
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        throw new RuntimeException('Missing document.xml in DOCX file');
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadXML($xml)) {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        throw new RuntimeException('Unable to parse DOCX XML content');
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $paragraphs = [];
    foreach ($xpath->query('//w:p') as $paragraph) {
        $texts = [];
        foreach ($xpath->query('.//w:t', $paragraph) as $node) {
            $texts[] = $node->nodeValue;
        }
        $textValue = trim(implode('', $texts));
        if ($textValue !== '') {
            $paragraphs[] = $textValue;
        }
    }

    libxml_use_internal_errors($previous);
    return trim(implode("\n", $paragraphs));
}

function build_preview_from_content(array $item, string $source, bool $aiRequested, ?array $openAiConfig, array &$errors): ?array
{
    $content = $item['content'] ?? '';
    if (!is_string($content) || trim($content) === '') {
        $errors[] = 'Empty content for ' . ($item['label'] ?? 'unknown source');
        return null;
    }

    $label = $item['label'] ?? 'Imported Source';
    $filePath = $item['file_path'] ?? null;
    $originalName = $item['original_name'] ?? $label;
    $contentType = 'html';
    $aiAdded = 0;
    $title = $item['title_hint'] ?? '';
    $fields = [];
    $textForAi = $content;

    $sample = strtolower(substr(ltrim($content), 0, 500));
    $isHtml = str_contains($sample, '<html') || str_contains($sample, '<!doctype');
    $isPdf = !$isHtml && str_contains(substr($content, 0, 20), '%PDF-');
    $extension = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
    $isDocx = !$isHtml && !$isPdf && ($extension === 'docx');
    if (!$isDocx) {
        $binaryHeader = substr($content, 0, 4);
        if ($binaryHeader !== false && $binaryHeader === "PK\x03\x04") {
            if (strpos($content, '[Content_Types].xml') !== false && strpos($content, 'word/') !== false) {
                $isDocx = true;
            }
        }
    }

    if (!$isHtml && !$isPdf && !$isDocx && $extension === 'doc') {
        $errors[] = 'Legacy Word (.doc) files are not supported. Please convert the document to .docx and try again (' . $label . ').';
        return null;
    }

    if ($isHtml) {
        $fields = extract_fields_from_html($content, $source);
        if ($title === '') {
            if (preg_match('/<title>(.*?)<\/title>/si', $content, $matches)) {
                $title = trim(strip_tags($matches[1]));
            }
        }
        $contentType = 'html';
    } elseif ($isDocx) {
        $tempFile = null;
        try {
            $docxPath = is_string($filePath) && is_file($filePath) ? $filePath : null;
            if ($docxPath === null) {
                $tempFile = tempnam(sys_get_temp_dir(), 'docx-import-');
                if ($tempFile === false) {
                    throw new RuntimeException('Unable to create temporary file for DOCX processing');
                }
                if (file_put_contents($tempFile, $content) === false) {
                    throw new RuntimeException('Failed to write DOCX content to temporary file');
                }
                $docxPath = $tempFile;
            }

            $textForAi = extract_text_from_docx($docxPath);
            if (trim($textForAi) === '') {
                throw new RuntimeException('No readable text found inside document');
            }
        } catch (Throwable $exception) {
            $errors[] = 'Failed to parse Word document for ' . $label . ': ' . $exception->getMessage();
            if ($tempFile && is_file($tempFile)) {
                @unlink($tempFile);
            }
            return null;
        }

        if ($tempFile && is_file($tempFile)) {
            @unlink($tempFile);
        }

        $fields = extract_fields_from_text($textForAi, $source);
        $contentType = 'docx';
    } elseif ($isPdf) {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($content);
            $textForAi = $pdf->getText();
            if ($title === '') {
                $details = $pdf->getDetails();
                if (!empty($details['Title'])) {
                    $title = trim((string)$details['Title']);
                }
            }
        } catch (\Throwable $exception) {
            $errors[] = 'Failed to parse PDF for ' . $label . ': ' . $exception->getMessage();
            return null;
        }

        if (!is_string($textForAi) || trim($textForAi) === '') {
            $errors[] = 'No readable text found in PDF for ' . $label;
            return null;
        }

        $fields = extract_fields_from_text($textForAi, $source);
        $contentType = 'pdf';
    } else {
        $textForAi = trim($content);
        if ($textForAi === '') {
            $errors[] = 'Unrecognised content for ' . $label . ' (not HTML/PDF/Word).';
            return null;
        }
        $fields = extract_fields_from_text($textForAi, $source);
        $contentType = 'text';
    }

    if ($title === '') {
        $title = $item['name_hint'] ?? 'Imported Template';
    }
    if ($title === '') {
        $title = 'Imported Template';
    }

    if ($aiRequested && $openAiConfig) {
        $aiResult = enhance_fields_with_openai($textForAi, $fields, $source, $openAiConfig, $errors);
        $fields = $aiResult['fields'];
        $aiAdded = $aiResult['added'] ?? 0;
    }

    if (empty($fields)) {
        $fields[] = [
            'label' => 'Imported Field',
            'type' => 'text',
            'required' => false,
        ];
    }

    $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $title));
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'imported-template';
    }
    $suffix = $contentType === 'pdf' ? 'pdf' : ($contentType === 'text' ? 'txt' : 'adv');
    $id = $slug . '-' . $suffix;

    return [
        'id' => $id,
        'title' => $title,
        'source_label' => $label,
        'description' => 'Imported from ' . $label . ' (' . strtoupper($contentType) . ')',
        'fields' => $fields,
        'ai_added' => $aiAdded,
        'ai_requested' => $aiRequested,
        'content_type' => $contentType,
    ];
}

function load_openai_config(string $root): ?array
{
    static $cached = null;
    static $cachedRoot = null;
    if ($cached !== null && $cachedRoot === $root) {
        return $cached;
    }

    $config = [];
    $configPath = $root . '/config/openai.php';
    if (is_file($configPath)) {
        $config = require $configPath;
    }

    $fileKey = $root . '/config/openai.key';
    $apiKey = null;
    if (is_file($fileKey)) {
        $apiKey = trim((string)file_get_contents($fileKey));
    }
    if (!$apiKey) {
        $apiKey = $config['api_key'] ?? getenv('OPENAI_API_KEY') ?: null;
    }

    if (!$apiKey || stripos($apiKey, 'YOUR_OPENAI_API_KEY_HERE') !== false) {
        $cached = null;
        $cachedRoot = $root;
        return null;
    }

    $cached = [
        'api_key' => $apiKey,
        'endpoint' => $config['endpoint'] ?? 'https://api.openai.com/v1/chat/completions',
        'model' => $config['model'] ?? 'gpt-4o-mini',
    ];
    $cachedRoot = $root;

    return $cached;
}

function normalise_ai_field(array $field): ?array
{
    $label = trim(preg_replace('/\s+/', ' ', (string)($field['label'] ?? '')));
    if ($label === '' || strlen($label) < 3) {
        return null;
    }

    $type = strtolower((string)($field['type'] ?? 'text'));
    $allowedTypes = ['text','checkbox','checkboxes','radio','select','dropdown','textarea','number','date','time','section','paragraph','boolean'];
    if (!in_array($type, $allowedTypes, true)) {
        $type = 'text';
    }

    $required = filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $options = [];
    if (!empty($field['options']) && is_array($field['options'])) {
        foreach ($field['options'] as $option) {
            $option = trim((string)$option);
            if ($option !== '') {
                $options[] = $option;
            }
        }
        $options = array_values(array_unique($options));
    }

    $result = [
        'label' => $label,
        'type' => $type,
        'required' => $required,
        'source' => $field['source'] ?? 'ai',
    ];

    if (!empty($options)) {
        $result['options'] = $options;
    }

    if (!empty($field['help_text'])) {
        $result['help_text'] = trim((string)$field['help_text']);
    }

    return $result;
}

function merge_fields_with_ai(array $existing, array $aiFields): array
{
    $merged = $existing;
    $seen = [];
    foreach ($existing as $field) {
        $label = strtolower(trim((string)($field['label'] ?? '')));
        $type = strtolower((string)($field['type'] ?? 'text'));
        if ($label === '') {
            continue;
        }
        $seen[$label . '|' . $type] = true;
    }

    $added = 0;
    foreach ($aiFields as $field) {
        $normalised = normalise_ai_field($field);
        if ($normalised === null) {
            continue;
        }
        $key = strtolower($normalised['label'] . '|' . $normalised['type']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $merged[] = $normalised;
        $added++;
    }

    return [$merged, $added];
}

function enhance_fields_with_openai(string $html, array $fields, string $source, array $config, array &$errors): array
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, 6000);
    } else {
        $text = substr($text, 0, 6000);
    }

    $fieldSummary = [];
    foreach (array_slice($fields, 0, 40) as $field) {
        $label = trim((string)($field['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $type = $field['type'] ?? 'text';
        $fieldSummary[] = "- {$label} ({$type})";
    }
    if (empty($fieldSummary)) {
        $fieldSummary[] = '- None detected yet';
    }

    $sourceLabel = $source !== '' ? $source : 'unspecified';

    $payload = [
        'model' => $config['model'],
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are an assistant that extracts structured checklist or permit fields from raw construction safety templates. Always respond with pure JSON in the shape {"fields": [{"label": string, "type": string, "required": boolean, "options": [string], "help_text": string}]}. Use lowercase snake_case for type names (text, checkbox, select, radio, textarea, section, number, date, time). Only include "options" when multiple choices exist. Do not include explanations or prose.',
            ],
            [
                'role' => 'user',
                'content' => implode("\n", [
                    'Source: ' . $sourceLabel,
                    '',
                    'Existing extracted fields:',
                    implode("\n", $fieldSummary),
                    '',
                    'Raw template content snippet:',
                    $text,
                ]),
            ],
        ],
        'temperature' => 0.1,
    ];

    $ch = curl_init($config['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 45,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $errors[] = 'OpenAI request failed: ' . curl_error($ch);
        curl_close($ch);
        return ['fields' => $fields, 'added' => 0];
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 400) {
        $errors[] = 'OpenAI API error (' . $status . '): ' . substr($response, 0, 200);
        return ['fields' => $fields, 'added' => 0];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        $errors[] = 'OpenAI response was not valid JSON.';
        return ['fields' => $fields, 'added' => 0];
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || trim($content) === '') {
        $errors[] = 'OpenAI returned an empty response.';
        return ['fields' => $fields, 'added' => 0];
    }

    $contentTrimmed = trim($content);
    $jsonStart = strpos($contentTrimmed, '{');
    $jsonEnd = strrpos($contentTrimmed, '}');
    if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd >= $jsonStart) {
        $contentTrimmed = substr($contentTrimmed, $jsonStart, $jsonEnd - $jsonStart + 1);
    }

    $aiPayload = json_decode($contentTrimmed, true);
    if (!is_array($aiPayload)) {
        $errors[] = 'Unable to decode AI response JSON.';
        return ['fields' => $fields, 'added' => 0];
    }

    $aiFields = $aiPayload['fields'] ?? $aiPayload;
    if (!is_array($aiFields)) {
        $errors[] = 'AI response did not include a "fields" array.';
        return ['fields' => $fields, 'added' => 0];
    }

    [$merged, $added] = merge_fields_with_ai($fields, $aiFields);
    return ['fields' => $merged, 'added' => $added];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cancel_preview'])) {
        $showPreview = false;
        $previewData = [];
    } elseif (isset($_POST['confirm_import']) && $_POST['confirm_import'] === '1') {
        $templates = $_POST['templates'] ?? [];
        if (empty($templates)) {
            $errors[] = 'Nothing to import. Please run the preview again.';
        }
        foreach ($templates as $template) {
            $title = trim((string)($template['title'] ?? 'Imported Template'));
            if ($title === '') {
                $title = 'Imported Template';
            }
            $rawId = strtolower((string)($template['id'] ?? ''));
            if ($rawId === '') {
                $rawId = strtolower(preg_replace('/[^a-z0-9]+/', '-', $title));
            }
            $id = preg_replace('/[^a-z0-9\-]+/', '-', $rawId);
            if ($id === '') {
                $id = 'imported-template-' . uniqid();
            }
            $fields = [];
            foreach (($template['fields'] ?? []) as $field) {
                $include = isset($field['include']) && $field['include'] === '1';
                if (!$include) {
                    continue;
                }
                $label = trim((string)($field['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $type = strtolower(trim((string)($field['type'] ?? 'text')));
                if ($type === '') {
                    $type = 'text';
                }
                $required = isset($field['required']) && $field['required'] === '1';
                $helpText = trim((string)($field['help_text'] ?? ''));
                $optionsText = (string)($field['options_text'] ?? '');
                $options = [];
                if ($optionsText !== '') {
                    $options = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $optionsText)), static fn($v) => $v !== ''));
                }
                $payload = [
                    'label' => $label,
                    'type' => $type,
                    'required' => $required,
                ];
                if (!empty($options)) {
                    $payload['options'] = $options;
                }
                if ($helpText !== '') {
                    $payload['help_text'] = $helpText;
                }
                $fields[] = $payload;
            }
            if (empty($fields)) {
                $errors[] = 'Skipped saving "' . htmlspecialchars($title) . '" because no fields were selected.';
                continue;
            }
            $templateData = [
                'id' => $id,
                'title' => $title,
                'name' => $title,
                'description' => trim((string)($template['description'] ?? 'Imported template')),
                'fields' => array_values($fields),
            ];
            $jsonPath = $root . '/templates/forms/' . $id . '.json';
            file_put_contents($jsonPath, json_encode($templateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $messages[] = 'Saved template "' . htmlspecialchars($title) . '" → ' . basename($jsonPath) . ' (' . count($fields) . ' fields)';
        }
        $previewData = [];
        $showPreview = false;
        $postedUrls = '';
    } else {
        $urls = array_values(array_filter(array_map('trim', explode("\n", $_POST['template_urls'] ?? ''))));
        $source = $_POST['source'] ?? '';
        $hasInput = !empty($urls);
        $uploadedSources = [];

        if (!empty($_FILES['template_files']) && is_array($_FILES['template_files']['name'])) {
            $fileCount = count($_FILES['template_files']['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                $errorCode = $_FILES['template_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
                if ($errorCode === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($errorCode !== UPLOAD_ERR_OK) {
                    $errors[] = 'Upload failed for ' . ($_FILES['template_files']['name'][$i] ?? 'unknown file') . ' (error code ' . $errorCode . ').';
                    continue;
                }
                $tmpPath = $_FILES['template_files']['tmp_name'][$i] ?? null;
                if (!is_string($tmpPath) || !is_file($tmpPath)) {
                    $errors[] = 'Temporary file missing for ' . ($_FILES['template_files']['name'][$i] ?? 'unknown file') . '.';
                    continue;
                }
                $fileContents = @file_get_contents($tmpPath);
                if ($fileContents === false || $fileContents === '') {
                    $errors[] = 'Unable to read uploaded file ' . ($_FILES['template_files']['name'][$i] ?? 'unknown file') . '.';
                    continue;
                }
                $originalName = $_FILES['template_files']['name'][$i] ?? 'uploaded-template';
                $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                $uploadedSources[] = [
                    'content' => $fileContents,
                    'label' => 'Uploaded file: ' . $originalName,
                    'name_hint' => $baseName,
                    'title_hint' => $baseName,
                    'file_path' => $tmpPath,
                    'original_name' => $originalName,
                ];
                $hasInput = true;
            }
        }

        if (!$hasInput) {
            $errors[] = 'Please provide at least one template URL or upload a file.';
        }

        $sources = [];
        foreach ($urls as $templateUrl) {
            $raw = @file_get_contents($templateUrl);
            if ($raw === false || $raw === '') {
                $errors[] = 'Failed to fetch: ' . $templateUrl;
                continue;
            }
            $nameHint = $templateUrl;
            $path = parse_url($templateUrl, PHP_URL_PATH);
            if (is_string($path)) {
                $filename = pathinfo($path, PATHINFO_FILENAME);
                if ($filename !== '') {
                    $nameHint = $filename;
                }
            }
            $sources[] = [
                'content' => $raw,
                'label' => $templateUrl,
                'name_hint' => $nameHint,
                'title_hint' => $nameHint,
                'original_name' => is_string($path) ? basename($path) : $nameHint,
            ];
        }
        $sources = array_merge($sources, $uploadedSources);

        foreach ($sources as $sourceItem) {
            $preview = build_preview_from_content($sourceItem, $source, $aiRequested, $openAiConfig, $errors);
            if ($preview !== null) {
                $previewData[] = $preview;
            }
        }
        if (!empty($previewData)) {
            $showPreview = true;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Advanced External Template Importer</title>
    <style>
        body { background: #0f172a; color: #e2e8f0; font-family: system-ui, -apple-system, sans-serif; min-height: 100vh; margin: 0; }
        .wrap { max-width: 900px; margin: 0 auto; padding: 32px 16px 80px; }
        a.back { color: #60a5fa; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 24px; }
        a.back:hover { text-decoration: underline; }
        h1 { font-size: 28px; margin-bottom: 12px; }
        p.lead { color: #94a3b8; margin-bottom: 24px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 24px; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.35); margin-bottom: 24px; }
        .card h2 { font-size: 22px; margin-bottom: 8px; }
        .card p { color: #94a3b8; margin-bottom: 16px; }
        .btn { background: #3b82f6; border: none; color: white; padding: 14px 28px; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 15px; }
        .btn:hover { background: #2563eb; }
        .btn-secondary { background: transparent; border: 1px solid #475569; color: #e2e8f0; }
        .btn-secondary:hover { background: #334155; }
        .alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.45); color: #bbf7d0; }
        .alert-error { background: rgba(248, 113, 113, 0.12); border: 1px solid rgba(248, 113, 113, 0.4); color: #fecaca; }
        ul { margin: 0; padding-left: 20px; }
        li { margin-bottom: 6px; }
        .muted { font-size: 13px; color: #64748b; margin-top: 12px; }
        form { margin-top: 20px; }
        textarea { width: 100%; min-height: 80px; font-size: 15px; border-radius: 8px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; padding: 8px; }
        .preview-card { margin-top: 24px; }
        .preview-template { border: 1px solid #334155; border-radius: 12px; padding: 16px; margin-bottom: 18px; background: #0f172a; }
        .preview-template h3 { margin: 0 0 8px 0; font-size: 18px; }
        .preview-template .source { font-size: 13px; color: #64748b; margin-bottom: 12px; }
        .field-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
        .field-card { border: 1px solid #334155; border-radius: 10px; padding: 12px; background: rgba(15, 23, 42, 0.65); position: relative; }
        .field-card label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.05em; }
        .field-card input[type="text"],
        .field-card textarea,
        .field-card select { width: 100%; padding: 6px 8px; border-radius: 6px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; font-size: 14px; }
        .field-card textarea { min-height: 60px; }
        .field-meta { display: flex; align-items: center; gap: 12px; margin-top: 10px; font-size: 13px; color: #cbd5f5; }
        .field-meta label { text-transform: none; letter-spacing: normal; font-size: 13px; color: #cbd5f5; margin: 0; }
        .field-meta input[type="checkbox"] { margin-right: 6px; }
        .field-meta span.help { color: #64748b; font-size: 12px; }
        .template-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .template-meta input { width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; font-size: 14px; }
        .empty-indicator { padding: 12px; border-radius: 8px; background: rgba(248, 113, 113, 0.12); border: 1px dashed rgba(248, 113, 113, 0.4); color: #fecaca; font-size: 14px; margin-bottom: 18px; }
    .ai-chip { display: inline-flex; align-items: center; gap: 6px; background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.35); color: #bae6fd; font-size: 12px; padding: 4px 8px; border-radius: 999px; margin-left: 8px; }
    .content-chip { display: inline-flex; align-items: center; gap: 6px; background: rgba(148, 163, 184, 0.18); border: 1px solid rgba(148, 163, 184, 0.35); color: #e2e8f0; font-size: 12px; padding: 4px 8px; border-radius: 999px; margin-left: 8px; text-transform: uppercase; letter-spacing: 0.05em; }
        @media (max-width: 640px) {
            .field-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <a class="back" href="/admin.php">⬅ Back to Admin</a>
        <h1>🚀 Advanced External Template Importer</h1>
        <p class="lead"><strong>Batch import and advanced parsing for construction templates.</strong><br>
        Paste one or more public template URLs (one per line) from SafetyCulture, OSHA, HSE, or other supported sites. This tool will fetch, parse, and create ready-to-edit permit templates.<br><br>
        <span style="color:#38bdf8">Tip:</span> For best results, use direct links to checklist/template pages. More parsing logic and AI field extraction coming soon!
        </p>

        <?php if (!empty($messages)): ?>
            <div class="alert alert-success">
                <ul>
                    <?php foreach ($messages as $message): ?>
                        <li><?= htmlspecialchars($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Batch Import & Parse</h2>
            <form method="post" enctype="multipart/form-data">
                <label><strong>Source:</strong><br>
                    <select name="source" required style="margin-top:4px;">
                        <option value="">Select Source</option>
                        <option value="safetyculture" <?= $selectedSource === 'safetyculture' ? 'selected' : '' ?>>SafetyCulture</option>
                        <option value="osha" <?= $selectedSource === 'osha' ? 'selected' : '' ?>>OSHA</option>
                        <option value="hse" <?= $selectedSource === 'hse' ? 'selected' : '' ?>>HSE (UK)</option>
                        <option value="other" <?= $selectedSource === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </label>
                <br><br>
                <label><strong>Template URLs (one per line):</strong><br>
                    <textarea name="template_urls" placeholder="https://...\nhttps://...\n"><?= htmlspecialchars($postedUrls) ?></textarea>
                </label>
                <br><br>
                <label><strong>Or upload template files:</strong><br>
                    <input type="file" name="template_files[]" accept="text/html,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword" multiple style="margin-top:6px;">
                </label>
                <p class="muted" style="margin:6px 0 18px 0;">Upload exported HTML checklists, PDF safety forms, or Word (.docx) documents. We’ll extract the text and run the same AI-assisted field mapping.</p>
                <?php if ($openAiAvailable): ?>
                    <label style="display:flex;align-items:flex-start;gap:10px;margin-bottom:18px;">
                        <input type="checkbox" name="enable_ai" value="1" <?= $aiRequested ? 'checked' : '' ?>>
                        <span>
                            <strong>Enhance with OpenAI suggestions</strong><br>
                            <span class="muted">A short snippet of the template is sent securely to OpenAI to suggest refined field labels, types, and options.</span>
                        </span>
                    </label>
                <?php else: ?>
                    <p class="muted" style="margin:0 0 18px 0;">Add an OpenAI API key in <a href="/admin/admin-openai-settings.php">OpenAI Settings</a> to enable AI-assisted field extraction.</p>
                <?php endif; ?>
                <button type="submit" class="btn">Preview Templates</button>
            </form>
            <div style="font-size:14px;color:#94a3b8;line-height:1.6;">
                <strong>Instructions:</strong>
                <ol style="margin:8px 0 8px 20px;padding:0;">
                                      <li>Choose a source and paste one or more public template/checklist URLs (one per line) or upload PDF/HTML/Word (.docx) files.</li>
                                    <li>Click <b>Preview Templates</b> to review and refine extracted fields.</li>
                  <li>Confirm the mapping to save JSON templates into your system.</li>
                  <li>Re-run the <b>Permit Template Importer</b> to sync them once you’re happy.</li>
                </ol>
                                <span style="color:#38bdf8">Note:</span> When OpenAI is enabled, field suggestions are refined using your secure API key. Use the preview below to map the final fields before saving, even for PDFs.<br>
                <span style="color:#fbbf24">Feedback and suggestions welcome!</span>
            </div>
        </div>

        <?php if ($showPreview): ?>
            <div class="card preview-card">
                <h2>Preview &amp; Map Fields</h2>
                <p style="color:#94a3b8;">Review the extracted fields, tweak their labels/types, and uncheck any that you don’t want to import. Options accept one value per line.</p>
                <form method="post">
                    <input type="hidden" name="confirm_import" value="1">
                    <input type="hidden" name="source" value="<?= htmlspecialchars($selectedSource) ?>">
                    <input type="hidden" name="enable_ai" value="<?= $aiRequested ? '1' : '0' ?>">
                    <?php foreach ($previewData as $index => $template): ?>
                        <div class="preview-template">
                            <h3>
                                <?= htmlspecialchars($template['title']) ?>
                                <?php if (!empty($template['content_type'])): ?>
                                    <span class="content-chip"><?= htmlspecialchars(strtoupper($template['content_type'])) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($template['ai_requested'])): ?>
                                    <span class="ai-chip">AI Enhanced<?= $template['ai_added'] > 0 ? ' +' . (int)$template['ai_added'] : '' ?></span>
                                <?php endif; ?>
                            </h3>
                            <p class="source">Source: <?= htmlspecialchars($template['source_label']) ?></p>
                            <div class="template-meta">
                                <div>
                                    <label for="tpl-title-<?= $index ?>">Template title</label>
                                    <input id="tpl-title-<?= $index ?>" type="text" name="templates[<?= $index ?>][title]" value="<?= htmlspecialchars($template['title']) ?>" required>
                                </div>
                                <div>
                                    <label for="tpl-id-<?= $index ?>">Template ID (filename)</label>
                                    <input id="tpl-id-<?= $index ?>" type="text" name="templates[<?= $index ?>][id]" value="<?= htmlspecialchars($template['id']) ?>" required>
                                </div>
                            </div>
                            <input type="hidden" name="templates[<?= $index ?>][description]" value="<?= htmlspecialchars($template['description']) ?>">
                            <input type="hidden" name="templates[<?= $index ?>][source_label]" value="<?= htmlspecialchars($template['source_label']) ?>">
                            <?php if (!empty($template['fields'])): ?>
                                <div class="field-grid">
                                    <?php foreach ($template['fields'] as $fieldIndex => $field):
                                        $labelValue = $field['label'] ?? '';
                                        $typeValue = strtolower($field['type'] ?? 'text');
                                        $requiredValue = !empty($field['required']);
                                        $helpValue = $field['help_text'] ?? '';
                                        $optionsValue = '';
                                        if (!empty($field['options']) && is_array($field['options'])) {
                                            $optionsValue = implode("\n", $field['options']);
                                        }
                                    ?>
                                        <div class="field-card">
                                            <input type="hidden" name="templates[<?= $index ?>][fields][<?= $fieldIndex ?>][include]" value="0">
                                            <label>
                                                <input type="checkbox" name="templates[<?= $index ?>][fields][<?= $fieldIndex ?>][include]" value="1" checked>
                                                Include field
                                            </label>
                                            <label>Label</label>
                                            <input type="text" name="templates[<?= $index ?>][fields][<?= $fieldIndex ?>][label]" value="<?= htmlspecialchars($labelValue) ?>" required>
                                            <label>Type</label>
                                            <select name="templates[<?= $index ?>][fields][<?= $fieldIndex ?>][type]">
                                                <?php foreach ($fieldTypeOptions as $value => $label): ?>
                                                    <option value="<?= $value ?>" <?= $typeValue === $value ? 'selected' : '' ?>><?= $label ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="field-meta">
                                                <label>
                                                    <input type="hidden" name="templates[<?= $index ?>][fields][<?= $fieldIndex ?>][required]" value="0">
                                                    <input type="checkbox" name="templates[<?= $index ?>][fields][<?= $fieldIndex ?>][required]" value="1" <?= $requiredValue ? 'checked' : '' ?>>
                                                    Required
                                                </label>
                                                <span class="help">Type tweaks update downstream forms automatically.</span>
                                            </div>
                                            <label style="margin-top:10px;">Options (one per line)</label>
                                            <textarea name="templates[<?= $index ?>][fields][<?= $fieldIndex ?>][options_text]" placeholder="Option A&#10;Option B"><?= htmlspecialchars($optionsValue) ?></textarea>
                                            <label style="margin-top:10px;">Help text</label>
                                            <textarea name="templates[<?= $index ?>][fields][<?= $fieldIndex ?>][help_text]" placeholder="Optional helper copy..."><?= htmlspecialchars($helpValue) ?></textarea>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-indicator">No fields were detected for this template. Try enabling AI suggestions or review the source HTML.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <button type="submit" class="btn">Save Templates</button>
                        <button type="submit" name="cancel_preview" value="1" class="btn btn-secondary">Back to Import Form</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
