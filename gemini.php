<?php

/**
 * Gemini API settings. Keep the API key outside version control.
 */
$apiKey = trim((string) getenv('GEMINI_API_KEY'));

if ($apiKey === '') {
    $envFile = __DIR__ . '/.env';

    if (is_readable($envFile)) {
        $env = parse_ini_file($envFile, false, INI_SCANNER_RAW);

        if (is_array($env)) {
            $apiKey = trim((string) ($env['GEMINI_API_KEY'] ?? ''));
        }
    }
}

return [
    'api_key' => $apiKey,
    'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    'model' => 'gemini-3.1-flash-lite',
    'timeout' => 300,
];
