<?php

declare(strict_types=1);

function geminiHttpRequest(
    string $method,
    string $url,
    array $headers,
    $body,
    int $timeout,
    bool $captureHeaders = false
): array {
    $curl = curl_init($url);

    if ($curl === false) {
        throw new RuntimeException('Could not initialize the Gemini request.');
    }

    $responseHeaders = [];
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }

    if ($captureHeaders) {
        $options[CURLOPT_HEADERFUNCTION] = static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
            $length = strlen($headerLine);
            $separator = strpos($headerLine, ':');

            if ($separator !== false) {
                $name = strtolower(trim(substr($headerLine, 0, $separator)));
                $responseHeaders[$name] = trim(substr($headerLine, $separator + 1));
            }

            return $length;
        };
    }

    curl_setopt_array($curl, $options);
    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    return [
        'status' => $status,
        'body' => is_string($responseBody) ? $responseBody : '',
        'error' => $responseBody === false ? $curlError : '',
        'headers' => $responseHeaders,
    ];
}

function geminiResponseError(array $response, string $fallback): string
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    $providerMessage = is_array($decoded) ? trim((string) ($decoded['error']['message'] ?? '')) : '';
    $curlError = trim((string) ($response['error'] ?? ''));

    return $providerMessage !== '' ? $providerMessage : ($curlError !== '' ? $curlError : $fallback);
}

function geminiUploadAudio(array $gemini, string $audioFile): array
{
    if (!is_file($audioFile) || !is_readable($audioFile)) {
        throw new RuntimeException('The source recording was not found or is not readable.');
    }

    $fileSize = filesize($audioFile);

    if ($fileSize === false || $fileSize <= 0) {
        throw new RuntimeException('The source recording is empty or could not be measured.');
    }

    $audioData = file_get_contents($audioFile);

    if ($audioData === false || $audioData === '') {
        throw new RuntimeException('The source recording could not be read.');
    }

    $apiKey = trim((string) ($gemini['api_key'] ?? ''));
    $baseUrl = rtrim((string) ($gemini['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
    $timeout = max(60, (int) ($gemini['timeout'] ?? 60));
    $mimeType = 'audio/mpeg';

    if (function_exists('finfo_open')) {
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMimeType = $fileInfo !== false ? finfo_file($fileInfo, $audioFile) : false;

        if ($fileInfo !== false) {
            finfo_close($fileInfo);
        }

        if (is_string($detectedMimeType) && preg_match('#^audio/#', $detectedMimeType)) {
            $mimeType = $detectedMimeType;
        }
    }
    $startUrl = preg_replace('#/v1beta$#', '/upload/v1beta/files', $baseUrl);

    if (!is_string($startUrl) || $startUrl === $baseUrl) {
        $startUrl = 'https://generativelanguage.googleapis.com/upload/v1beta/files';
    }

    $metadata = json_encode([
        'file' => ['display_name' => basename($audioFile)],
    ], JSON_UNESCAPED_SLASHES);

    if ($metadata === false) {
        throw new RuntimeException('Could not create the Gemini upload request.');
    }

    $startResponse = geminiHttpRequest('POST', $startUrl, [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey,
        'X-Goog-Upload-Protocol: resumable',
        'X-Goog-Upload-Command: start',
        'X-Goog-Upload-Header-Content-Length: ' . $fileSize,
        'X-Goog-Upload-Header-Content-Type: ' . $mimeType,
    ], $metadata, $timeout, true);

    $uploadUrl = trim((string) ($startResponse['headers']['x-goog-upload-url'] ?? ''));

    if (($startResponse['status'] ?? 0) < 200 || ($startResponse['status'] ?? 0) >= 300 || $uploadUrl === '') {
        throw new RuntimeException(geminiResponseError($startResponse, 'Gemini could not start the audio upload.'));
    }

    $uploadResponse = geminiHttpRequest('POST', $uploadUrl, [
        'Content-Type: ' . $mimeType,
        'Content-Length: ' . $fileSize,
        'X-Goog-Upload-Offset: 0',
        'X-Goog-Upload-Command: upload, finalize',
    ], $audioData, $timeout);
    unset($audioData);

    $uploadBody = json_decode((string) ($uploadResponse['body'] ?? ''), true);
    $file = is_array($uploadBody) && is_array($uploadBody['file'] ?? null) ? $uploadBody['file'] : null;

    if (($uploadResponse['status'] ?? 0) < 200 || ($uploadResponse['status'] ?? 0) >= 300 || !is_array($file)) {
        throw new RuntimeException(geminiResponseError($uploadResponse, 'Gemini could not upload the recording.'));
    }

    $fileName = trim((string) ($file['name'] ?? ''));

    $processingAttempts = max(30, (int) ceil($timeout / 2));

    for ($attempt = 0; $attempt < $processingAttempts && strtoupper((string) ($file['state'] ?? 'ACTIVE')) === 'PROCESSING'; $attempt++) {
        sleep(2);
        $statusResponse = geminiHttpRequest(
            'GET',
            $baseUrl . '/' . ltrim($fileName, '/'),
            ['x-goog-api-key: ' . $apiKey],
            null,
            $timeout
        );

        if (($statusResponse['status'] ?? 0) < 200 || ($statusResponse['status'] ?? 0) >= 300) {
            throw new RuntimeException(geminiResponseError($statusResponse, 'Could not check the Gemini audio status.'));
        }

        $statusBody = json_decode((string) ($statusResponse['body'] ?? ''), true);
        $file = is_array($statusBody) ? $statusBody : $file;
    }

    if (strtoupper((string) ($file['state'] ?? '')) !== 'ACTIVE') {
        throw new RuntimeException('Gemini did not finish processing the recording in time.');
    }

    return [
        'name' => trim((string) ($file['name'] ?? $fileName)),
        'uri' => trim((string) ($file['uri'] ?? '')),
        'mime_type' => trim((string) ($file['mimeType'] ?? $mimeType)),
    ];
}

function geminiDeleteFile(array $gemini, string $fileName): void
{
    if ($fileName === '') {
        return;
    }

    $baseUrl = rtrim((string) ($gemini['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
    geminiHttpRequest(
        'DELETE',
        $baseUrl . '/' . ltrim($fileName, '/'),
        ['x-goog-api-key: ' . (string) ($gemini['api_key'] ?? '')],
        null,
        max(30, (int) ($gemini['timeout'] ?? 60))
    );
}

function geminiGenerateFromAudio(
    array $gemini,
    string $audioFile,
    string $systemInstruction,
    string $prompt,
    bool $jsonOutput = false,
    ?array $responseJsonSchema = null
): string {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is not enabled.');
    }

    $uploadedFile = geminiUploadAudio($gemini, $audioFile);

    if ($uploadedFile['uri'] === '') {
        geminiDeleteFile($gemini, $uploadedFile['name']);
        throw new RuntimeException('Gemini did not return a URI for the uploaded recording.');
    }

    try {
        return geminiGenerateFromUploadedAudio(
            $gemini,
            $uploadedFile,
            $systemInstruction,
            $prompt,
            $jsonOutput,
            $responseJsonSchema
        );
    } finally {
        geminiDeleteFile($gemini, $uploadedFile['name']);
    }
}

function geminiGenerateFromUploadedAudio(
    array $gemini,
    array $uploadedFile,
    string $systemInstruction,
    string $prompt,
    bool $jsonOutput = false,
    ?array $responseJsonSchema = null
): string {
    if (trim((string) ($uploadedFile['uri'] ?? '')) === '') {
        throw new RuntimeException('Gemini did not return a URI for the uploaded recording.');
    }

    $generationConfig = ['temperature' => 0.2];

    if ($jsonOutput) {
        $generationConfig = [
            'temperature' => 0,
            'responseMimeType' => 'application/json',
        ];
    }

    if ($responseJsonSchema !== null) {
        $generationConfig['responseJsonSchema'] = $responseJsonSchema;
    }

    $payload = json_encode([
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'fileData' => [
                                'fileUri' => $uploadedFile['uri'],
                                'mimeType' => $uploadedFile['mime_type'],
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => $generationConfig,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        throw new RuntimeException('Could not create the Gemini generation request.');
    }

    $baseUrl = rtrim((string) ($gemini['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
    $model = trim((string) ($gemini['model'] ?? 'gemini-3.1-flash-lite'));
    $response = geminiHttpRequest(
            'POST',
            $baseUrl . '/models/' . rawurlencode($model) . ':generateContent',
            [
                'Content-Type: application/json',
                'x-goog-api-key: ' . (string) ($gemini['api_key'] ?? ''),
            ],
            $payload,
            max(60, (int) ($gemini['timeout'] ?? 60))
        );

    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    $parts = is_array($decoded) ? ($decoded['candidates'][0]['content']['parts'] ?? []) : [];
    $textParts = [];

    if (is_array($parts)) {
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text'])) {
                $textParts[] = (string) $part['text'];
            }
        }
    }

    $output = trim(implode('', $textParts));

    if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300 || $output === '') {
        throw new RuntimeException(geminiResponseError($response, 'Gemini could not analyze the recording.'));
    }

    return $output;
}
