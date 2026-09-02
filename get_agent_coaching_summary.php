<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ob_start();
register_shutdown_function(static function (): void {
	$error = error_get_last();
	if (!is_array($error) || !in_array((int) ($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
		return;
	}
	error_log('Agent coaching lookup failed: ' . (string) ($error['message'] ?? 'Unknown fatal error'));
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code(500);
	}
	echo json_encode(['success' => false, 'message' => 'A server error occurred while checking saved coaching summaries. Check the PHP error log.']);
});

function savedCoachingRespond(int $status, array $payload): void
{
	if (ob_get_level() > 0) {
		ob_clean();
	}
	http_response_code($status);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	savedCoachingRespond(405, ['success' => false, 'message' => 'POST requests only.']);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}
$sessionUser = trim((string) ($_SESSION['user'] ?? ''));
if ($sessionUser === '') {
	savedCoachingRespond(401, ['success' => false, 'message' => 'Your admin session has expired.']);
}

$request = json_decode((string) file_get_contents('php://input'), true);
$agentId = is_array($request) ? trim((string) ($request['agent_id'] ?? '')) : '';
$beginDate = is_array($request) ? trim((string) ($request['begin_date'] ?? '')) : '';
$endDate = is_array($request) ? trim((string) ($request['end_date'] ?? '')) : '';
$campaignId = is_array($request) ? trim((string) ($request['campaign_id'] ?? '--ALL--')) : '--ALL--';
$requestedIds = is_array($request) && is_array($request['recording_ids'] ?? null) ? $request['recording_ids'] : [];

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match('/^[-_0-9a-zA-Z]{1,20}$/', $agentId)) {
	savedCoachingRespond(422, ['success' => false, 'message' => 'A valid agent ID is required.']);
}
if (!preg_match($datePattern, $beginDate) || !preg_match($datePattern, $endDate) || $beginDate > $endDate) {
	savedCoachingRespond(422, ['success' => false, 'message' => 'A valid coaching report date range is required.']);
}
if ($campaignId !== '--ALL--' && !preg_match('/^[-_0-9a-zA-Z]{1,20}$/', $campaignId)) {
	savedCoachingRespond(422, ['success' => false, 'message' => 'The campaign ID is invalid.']);
}

$normalizeIds = static function (array $ids): array {
	$normalized = [];
	foreach ($ids as $id) {
		$id = trim((string) $id);
		if ($id !== '' && ctype_digit($id)) {
			$normalized[$id] = $id;
		}
	}
	$normalized = array_values($normalized);
	sort($normalized, SORT_STRING);
	return $normalized;
};
$recordingIds = $normalizeIds($requestedIds);
if ($recordingIds === [] || count($recordingIds) > 500) {
	savedCoachingRespond(422, ['success' => false, 'message' => 'At least one valid recording ID is required.']);
}

require_once __DIR__ . '/dbconnect_mysqli.php';
$accessStatement = mysqli_prepare($link, "SELECT user FROM vicidial_users WHERE user=? AND active='Y' AND qc_enabled='1' LIMIT 1");
if ($accessStatement === false) {
	savedCoachingRespond(500, ['success' => false, 'message' => 'Could not verify QC access.']);
}
mysqli_stmt_bind_param($accessStatement, 's', $sessionUser);
mysqli_stmt_execute($accessStatement);
$accessResult = mysqli_stmt_get_result($accessStatement);
$hasAccess = $accessResult !== false && mysqli_fetch_assoc($accessResult) !== null;
mysqli_stmt_close($accessStatement);
if (!$hasAccess) {
	savedCoachingRespond(403, ['success' => false, 'message' => 'Your account is not enabled for QC analysis.']);
}

$statement = mysqli_prepare(
	$link,
	"SELECT coaching_id, recordings_found, calls_analyzed, failed_calls, recording_ids, summary_json, generated_at
	   FROM quality_control_agent_coaching
	  WHERE agent_id=? AND campaign_id=? AND begin_date=? AND end_date=?
	  ORDER BY generated_at DESC, coaching_id DESC
	  LIMIT 25"
);
if ($statement === false) {
	savedCoachingRespond(500, ['success' => false, 'message' => 'Could not check saved coaching summaries.']);
}
mysqli_stmt_bind_param($statement, 'ssss', $agentId, $campaignId, $beginDate, $endDate);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
if ($result !== false) {
	while ($row = mysqli_fetch_assoc($result)) {
		$storedIds = json_decode((string) ($row['recording_ids'] ?? ''), true);
		if (!is_array($storedIds) || $normalizeIds($storedIds) !== $recordingIds) {
			continue;
		}
		$summary = json_decode((string) ($row['summary_json'] ?? ''), true);
		if (!is_array($summary)) {
			continue;
		}
		mysqli_stmt_close($statement);
		savedCoachingRespond(200, [
			'success' => true,
			'found' => true,
			'coaching_id' => (int) $row['coaching_id'],
			'recordings_found' => (int) $row['recordings_found'],
			'calls_analyzed' => (int) $row['calls_analyzed'],
			'failed_calls' => (int) $row['failed_calls'],
			'generated_at' => (string) $row['generated_at'],
			'summary' => $summary,
		]);
	}
}
mysqli_stmt_close($statement);
savedCoachingRespond(200, ['success' => true, 'found' => false]);
