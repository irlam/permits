<?php
/**
 * OpenAI Text-to-Speech API Endpoint
 * 
 * File Path: /api/openai-tts.php
 * Description: Converts text to natural speech using OpenAI's TTS model
 * Voice: "onyx" (natural male voice similar to ChatGPT default)
 */

[$app, $db, $root] = require __DIR__ . '/../src/bootstrap.php';

use Permits\SystemSettings;

session_start();

// Auth check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Verify user is admin or manager
$userStmt = $db->pdo->prepare('SELECT role FROM users WHERE id = ?');
$userStmt->execute([$_SESSION['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !in_array($user['role'], ['admin', 'manager'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['text'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter: text']);
    exit;
}

$text = trim($input['text']);
$voice = $input['voice'] ?? 'onyx';
$model = $input['model'] ?? 'tts-1';

if (empty($text) || strlen($text) > 4096) {
    http_response_code(400);
    echo json_encode(['error' => 'Text must be between 1 and 4096 characters']);
    exit;
}

// Get OpenAI API key from config file
$openaiApiKey = null;
$root = dirname(__DIR__);
$configFile = $root . '/config/ai-settings.json';
try {
    if (file_exists($configFile)) {
        $configData = json_decode(file_get_contents($configFile), true);
        if (isset($configData['providers']['openai']['api_key'])) {
            $openaiApiKey = trim($configData['providers']['openai']['api_key']);
            $openaiApiKey = !empty($openaiApiKey) ? $openaiApiKey : null;
        }
    }
} catch (Throwable $e) {
    $openaiApiKey = null;
}

if (!$openaiApiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API key not configured']);
    exit;
}

// Call OpenAI TTS API
$ch = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $openaiApiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'model' => $model,
        'input' => $text,
        'voice' => $voice,
    ]),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(500);
    echo json_encode(['error' => 'Request failed: ' . $error]);
    exit;
}

if ($httpCode !== 200) {
    $errorBody = @json_decode($response, true);
    http_response_code($httpCode);
    echo json_encode(['error' => $errorBody['error']['message'] ?? 'OpenAI API error']);
    exit;
}

// Return audio as MP3
header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($response));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $response;
exit;
