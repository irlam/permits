<?php
// config/openai.php
// Place your OpenAI API key here (never commit real keys to version control)
return [
    'api_key' => getenv('OPENAI_API_KEY') ?: 'YOUR_OPENAI_API_KEY_HERE',
    'endpoint' => 'https://api.openai.com/v1/chat/completions',
    'model' => 'gpt-4',
];
