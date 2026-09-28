<?php

declare(strict_types=1);

function resolveRecordingAudio(mysqli $link, string $recordingId): array
{
    if ($recordingId === '' || !ctype_digit($recordingId)) {
        throw new RuntimeException('A valid recording ID was not provided.');
    }

    $statement = mysqli_prepare(
        $link,
        'SELECT filename, location FROM recording_log WHERE recording_id = ? LIMIT 1'
    );

    if ($statement === false) {
        throw new RuntimeException('Could not prepare the recording lookup.');
    }

    mysqli_stmt_bind_param($statement, 's', $recordingId);

    if (!mysqli_stmt_execute($statement)) {
        mysqli_stmt_close($statement);
        throw new RuntimeException('Could not look up the recording.');
    }

    $result = mysqli_stmt_get_result($statement);
    $row = $result !== false ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($statement);

    if (!is_array($row)) {
        throw new RuntimeException('No recording was found for recording ID ' . $recordingId . '.');
    }

    $filename = trim((string) ($row['filename'] ?? ''));
    $location = trim((string) ($row['location'] ?? ''));
    $recordingDirectory = '/var/spool/asterisk/monitorDONE/MP3';
    $candidates = [];

    if ($filename !== '') {
        $candidates[] = $filename;
        $candidates[] = $recordingDirectory . '/' . basename($filename);

        if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
            $candidates[] = $recordingDirectory . '/' . basename($filename) . '.mp3';
            $candidates[] = $recordingDirectory . '/' . basename($filename) . '.wav';
        }
    }

    $locationPath = parse_url($location, PHP_URL_PATH);

    if (is_string($locationPath) && $locationPath !== '') {
        $candidates[] = $recordingDirectory . '/' . basename($locationPath);
    }

    $candidates[] = $recordingDirectory . '/' . $recordingId . '.mp3';
    $candidates[] = $recordingDirectory . '/' . $recordingId . '.wav';

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return ['path' => $candidate, 'temporary' => false];
        }
    }

    throw new RuntimeException('The recording does not exist.');

}

function removeTemporaryRecording(array $recording): void
{
    if (($recording['temporary'] ?? false) === true) {
        $path = (string) ($recording['path'] ?? '');

        if ($path !== '' && is_file($path)) {
            unlink($path);
        }
    }
}
