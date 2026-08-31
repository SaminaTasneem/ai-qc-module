<?php

declare(strict_types=1);

require __DIR__ . '/dbconnect_mysqli.php';
require_once __DIR__ . '/recording_audio.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$sessionUser = trim((string) ($_SESSION['user'] ?? ''));

if ($sessionUser === '') {
    http_response_code(403);
    exit('An authenticated admin session is required.');
}

$escapedUser = mysqli_real_escape_string($link, $sessionUser);
$permissionResult = mysqli_query(
    $link,
    "SELECT qc_enabled FROM vicidial_users WHERE user='$escapedUser' AND active='Y' LIMIT 1"
);
$permissionRow = $permissionResult instanceof mysqli_result
    ? mysqli_fetch_assoc($permissionResult)
    : null;

if (!is_array($permissionRow) || ($permissionRow['qc_enabled'] ?? '0') !== '1') {
    http_response_code(403);
    exit('Quality Control access is required.');
}

$recording = null;

try {
    $recording = resolveRecordingAudio($link, (string) ($_GET['recording_id'] ?? ''));
    $path = (string) $recording['path'];
    $size = filesize($path);

    if ($size === false) {
        throw new RuntimeException('Could not determine the recording size.');
    }

    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $contentType = $extension === 'wav' ? 'audio/wav' : 'audio/mpeg';

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $size);
    header('Content-Disposition: inline; filename="recording.' . ($extension ?: 'mp3') . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
} catch (Throwable $error) {
    http_response_code(404);
    echo 'Recording unavailable.';
} finally {
    if (is_array($recording)) {
        removeTemporaryRecording($recording);
    }
}

