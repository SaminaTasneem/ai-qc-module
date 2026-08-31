<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function totalsRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    totalsRespond(405, ['success' => false, 'message' => 'POST requests only.']);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$sessionUser = trim((string) ($_SESSION['user'] ?? ''));
$request = json_decode((string) file_get_contents('php://input'), true);
$qcLogId = is_array($request) ? trim((string) ($request['qc_log_id'] ?? '')) : '';

if ($sessionUser === '') {
    totalsRespond(403, ['success' => false, 'message' => 'Admin session expired.']);
}

if ($qcLogId === '' || !ctype_digit($qcLogId)) {
    totalsRespond(422, ['success' => false, 'message' => 'A valid QC ID was not provided.']);
}

require_once __DIR__ . '/dbconnect_mysqli.php';

if (!isset($link) || !($link instanceof mysqli)) {
    totalsRespond(500, ['success' => false, 'message' => 'Could not connect to the database.']);
}

$accessStatement = mysqli_prepare(
    $link,
    "SELECT q.qc_log_id
       FROM quality_control_queue AS q
       JOIN vicidial_users AS u ON u.user = q.qc_agent
      WHERE q.qc_log_id = ? AND q.qc_agent = ? AND q.qc_status = 'CLAIMED'
        AND u.active = 'Y' AND u.qc_enabled = '1'
      LIMIT 1"
);

if ($accessStatement === false) {
    totalsRespond(500, ['success' => false, 'message' => 'Could not verify QC access.']);
}

mysqli_stmt_bind_param($accessStatement, 'ss', $qcLogId, $sessionUser);
mysqli_stmt_execute($accessStatement);
$accessResult = mysqli_stmt_get_result($accessStatement);
$hasAccess = $accessResult !== false && mysqli_fetch_assoc($accessResult) !== null;
mysqli_stmt_close($accessStatement);

if (!$hasAccess) {
    totalsRespond(403, ['success' => false, 'message' => 'This claimed QC call does not belong to you.']);
}

$totalsStatement = mysqli_prepare(
    $link,
    "SELECT COUNT(*) AS total_checkpoints,
            COALESCE(SUM(checkpoint_points_earned), 0) AS total_points_earned,
            COALESCE(SUM(checkpoint_points), 0) AS total_points_possible
       FROM quality_control_checkpoint_log
      WHERE qc_log_id = ?"
);

if ($totalsStatement === false) {
    totalsRespond(500, ['success' => false, 'message' => 'Could not prepare the totals query.']);
}

mysqli_stmt_bind_param($totalsStatement, 's', $qcLogId);
mysqli_stmt_execute($totalsStatement);
$totalsResult = mysqli_stmt_get_result($totalsStatement);
$totals = $totalsResult !== false ? mysqli_fetch_assoc($totalsResult) : null;
mysqli_stmt_close($totalsStatement);

if (!is_array($totals)) {
    totalsRespond(500, ['success' => false, 'message' => 'Could not calculate QC totals.']);
}

$checkpointCount = (int) $totals['total_checkpoints'];
$pointsEarned = (float) $totals['total_points_earned'];
$pointsPossible = (float) $totals['total_points_possible'];
$scorePercentage = $pointsPossible > 0
    ? round(100 * $pointsEarned / $pointsPossible, 2)
    : null;
$updateStatement = mysqli_prepare(
    $link,
    "UPDATE quality_control_queue
        SET total_checkpoints = ?, total_points_earned = ?,
            total_points_possible = ?, score_percentage = ?
      WHERE qc_log_id = ? AND qc_agent = ? AND qc_status = 'CLAIMED'"
);

if ($updateStatement === false) {
    totalsRespond(500, ['success' => false, 'message' => 'Could not prepare the totals update.']);
}

mysqli_stmt_bind_param(
    $updateStatement,
    'idddss',
    $checkpointCount,
    $pointsEarned,
    $pointsPossible,
    $scorePercentage,
    $qcLogId,
    $sessionUser
);

if (!mysqli_stmt_execute($updateStatement)) {
    mysqli_stmt_close($updateStatement);
    totalsRespond(500, ['success' => false, 'message' => 'Could not save QC totals.']);
}

mysqli_stmt_close($updateStatement);

totalsRespond(200, [
    'success' => true,
    'qc_log_id' => $qcLogId,
    'totals' => [
        'total_checkpoints' => $checkpointCount,
        'total_points_earned' => $pointsEarned,
        'total_points_possible' => $pointsPossible,
        'score_percentage' => $scorePercentage,
    ],
]);
