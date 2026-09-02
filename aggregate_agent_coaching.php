<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function agentCoachingRespond(int $status, array $payload): void
{
	http_response_code($status);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	agentCoachingRespond(405, ['success' => false, 'message' => 'POST requests only.']);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

$sessionUser = trim((string) ($_SESSION['user'] ?? ''));
if ($sessionUser === '') {
	agentCoachingRespond(401, ['success' => false, 'message' => 'Your admin session has expired.']);
}

$request = json_decode((string) file_get_contents('php://input'), true);
$agentId = is_array($request) ? trim((string) ($request['agent_id'] ?? '')) : '';
$agentName = is_array($request) ? trim((string) ($request['agent_name'] ?? '')) : '';
$callResults = is_array($request) && is_array($request['call_results'] ?? null) ? $request['call_results'] : [];
$failedCalls = is_array($request) ? max(0, (int) ($request['failed_calls'] ?? 0)) : 0;
$beginDate = is_array($request) ? trim((string) ($request['begin_date'] ?? '')) : '';
$endDate = is_array($request) ? trim((string) ($request['end_date'] ?? '')) : '';
$campaignId = is_array($request) ? trim((string) ($request['campaign_id'] ?? '--ALL--')) : '--ALL--';
$recordingsFound = is_array($request) ? max(0, min(500, (int) ($request['recordings_found'] ?? count($callResults)))) : 0;
$requestedRecordingIds = is_array($request) && is_array($request['recording_ids'] ?? null) ? $request['recording_ids'] : [];
$reanalyze = is_array($request) && filter_var($request['reanalyze'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($agentId === '' || !preg_match('/^[-_0-9a-zA-Z]{1,20}$/', $agentId)) {
	agentCoachingRespond(422, ['success' => false, 'message' => 'A valid agent ID was not provided.']);
}
if ($callResults === [] || count($callResults) > 500) {
	agentCoachingRespond(422, ['success' => false, 'message' => 'Between 1 and 500 completed call analyses are required.']);
}

require_once __DIR__ . '/dbconnect_mysqli.php';
$accessStatement = mysqli_prepare(
	$link,
	"SELECT user FROM vicidial_users WHERE user=? AND active='Y' AND qc_enabled='1' LIMIT 1"
);
if ($accessStatement === false) {
	agentCoachingRespond(500, ['success' => false, 'message' => 'Could not verify QC access.']);
}
mysqli_stmt_bind_param($accessStatement, 's', $sessionUser);
mysqli_stmt_execute($accessStatement);
$accessResult = mysqli_stmt_get_result($accessStatement);
$hasAccess = $accessResult !== false && mysqli_fetch_assoc($accessResult) !== null;
mysqli_stmt_close($accessStatement);
if (!$hasAccess) {
	agentCoachingRespond(403, ['success' => false, 'message' => 'Your account is not enabled for QC analysis.']);
}

$normalizedResults = [];
foreach ($callResults as $result) {
	if (!is_array($result)) {
		continue;
	}
	$encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
	if ($encoded === false || strlen($encoded) > 30000) {
		continue;
	}
	$normalizedResults[] = $result;
}
if ($normalizedResults === []) {
	agentCoachingRespond(422, ['success' => false, 'message' => 'No usable call analyses were supplied.']);
}

$recordingIds = [];
foreach ($requestedRecordingIds as $recordingId) {
	$recordingId = trim((string) $recordingId);
	if ($recordingId !== '' && ctype_digit($recordingId)) {
		$recordingIds[$recordingId] = $recordingId;
	}
}
if ($recordingIds === []) {
	foreach ($normalizedResults as $result) {
		$recordingId = trim((string) ($result['recording_id'] ?? ''));
		if ($recordingId !== '' && ctype_digit($recordingId)) {
			$recordingIds[$recordingId] = $recordingId;
		}
	}
}
$recordingIds = array_values($recordingIds);
if ($recordingsFound < count($recordingIds)) {
	$recordingsFound = count($recordingIds);
}

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
if (!preg_match($datePattern, $beginDate) || !preg_match($datePattern, $endDate)) {
	$callDates = [];
	foreach ($normalizedResults as $result) {
		$callDate = substr(trim((string) ($result['call_date'] ?? '')), 0, 10);
		if (preg_match($datePattern, $callDate)) {
			$callDates[] = $callDate;
		}
	}
	if ($callDates !== []) {
		sort($callDates);
		$beginDate = $callDates[0];
		$endDate = $callDates[count($callDates) - 1];
	}
}
if (!preg_match($datePattern, $beginDate) || !preg_match($datePattern, $endDate) || $beginDate > $endDate) {
	agentCoachingRespond(422, ['success' => false, 'message' => 'A valid coaching report date range is required.']);
}
if ($campaignId !== '--ALL--' && !preg_match('/^[-_0-9a-zA-Z]{1,20}$/', $campaignId)) {
	agentCoachingRespond(422, ['success' => false, 'message' => 'The campaign ID is invalid.']);
}

$geminiFile = __DIR__ . '/gemini.php';
if (!is_file($geminiFile)) {
	agentCoachingRespond(500, ['success' => false, 'message' => 'Gemini configuration was not found.']);
}
$gemini = require $geminiFile;
if (!is_array($gemini) || empty($gemini['api_key'])) {
	agentCoachingRespond(500, ['success' => false, 'message' => 'Gemini is not configured.']);
}
require_once __DIR__ . '/gemini_audio.php';

$systemInstruction = 'You are a coaching analyst. Combine multiple evidence-based call evaluations into one concise agent-level coaching summary. Treat an issue as repeated only when it appears in at least two calls. Do not invent facts, do not count Not Applicable scorecard items as failures, and distinguish recurring patterns from isolated events. Prioritize practical improvements a manager can coach.

Return ONLY valid JSON in this shape:
{"overall_assessment":"concise assessment","repeated_strengths":[{"theme":"strength","call_count":2,"explanation":"brief evidence-based explanation"}],"improvement_areas":[{"priority":1,"area":"skill area","call_count":2,"pattern":"what repeatedly happened","action":"specific coaching action","example_phrase":"natural example"}],"manager_coaching_plan":["action 1","action 2","action 3"]}.';
$prompt = "Create an agent-level coaching summary for agent $agentId ($agentName).\n"
	. 'Successfully analyzed calls: ' . count($normalizedResults) . ". Failed calls: $failedCalls.\n"
	. "Call-level evidence:\n" . json_encode($normalizedResults, JSON_UNESCAPED_UNICODE);

$payload = json_encode([
	'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
	'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
	'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($payload === false) {
	agentCoachingRespond(500, ['success' => false, 'message' => 'Could not create the aggregation request.']);
}

$baseUrl = rtrim((string) ($gemini['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
$model = trim((string) ($gemini['model'] ?? 'gemini-3.1-flash-lite'));
$response = geminiHttpRequest(
	'POST',
	$baseUrl . '/models/' . rawurlencode($model) . ':generateContent',
	['Content-Type: application/json', 'x-goog-api-key: ' . (string) $gemini['api_key']],
	$payload,
	max(60, (int) ($gemini['timeout'] ?? 60))
);
$decoded = json_decode((string) ($response['body'] ?? ''), true);
$parts = is_array($decoded) ? ($decoded['candidates'][0]['content']['parts'] ?? []) : [];
$text = '';
if (is_array($parts)) {
	foreach ($parts as $part) {
		if (is_array($part) && isset($part['text'])) {
			$text .= (string) $part['text'];
		}
	}
}
if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300 || trim($text) === '') {
	agentCoachingRespond(502, ['success' => false, 'message' => geminiResponseError($response, 'Gemini could not combine the coaching results.')]);
}
$cleanText = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', trim($text)) ?? trim($text);
$summary = json_decode($cleanText, true);
if (
	!is_array($summary)
	|| trim((string) ($summary['overall_assessment'] ?? '')) === ''
	|| !is_array($summary['improvement_areas'] ?? null)
	|| !is_array($summary['manager_coaching_plan'] ?? null)
) {
	agentCoachingRespond(502, ['success' => false, 'message' => 'Gemini returned an incomplete agent coaching summary.']);
}

$summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$recordingIdsJson = json_encode($recordingIds, JSON_UNESCAPED_SLASHES);
if ($summaryJson === false || $recordingIdsJson === false) {
	agentCoachingRespond(500, ['success' => false, 'message' => 'Could not encode the coaching summary for storage.']);
}

$callsAnalyzed = count($normalizedResults);
$coachingId = 0;
$saveAction = 'inserted';
if ($reanalyze) {
	$targetIds = $recordingIds;
	sort($targetIds, SORT_STRING);
	$lookupStatement = mysqli_prepare(
		$link,
		"SELECT coaching_id, recording_ids FROM quality_control_agent_coaching
		  WHERE agent_id=? AND campaign_id=? AND begin_date=? AND end_date=?
		  ORDER BY generated_at DESC, coaching_id DESC LIMIT 25"
	);
	if ($lookupStatement !== false) {
		mysqli_stmt_bind_param($lookupStatement, 'ssss', $agentId, $campaignId, $beginDate, $endDate);
		mysqli_stmt_execute($lookupStatement);
		$lookupResult = mysqli_stmt_get_result($lookupStatement);
		if ($lookupResult !== false) {
			while ($lookupRow = mysqli_fetch_assoc($lookupResult)) {
				$storedIds = json_decode((string) ($lookupRow['recording_ids'] ?? ''), true);
				if (!is_array($storedIds)) continue;
				$storedIds = array_values(array_unique(array_filter(array_map('strval', $storedIds), 'ctype_digit')));
				sort($storedIds, SORT_STRING);
				if ($storedIds === $targetIds) {
					$coachingId = (int) $lookupRow['coaching_id'];
					break;
				}
			}
		}
		mysqli_stmt_close($lookupStatement);
	}
}

if ($coachingId > 0) {
	$saveStatement = mysqli_prepare(
		$link,
		"UPDATE quality_control_agent_coaching
		    SET recordings_found=?, calls_analyzed=?, failed_calls=?, recording_ids=?, summary_json=?, generated_by=?, generated_at=NOW()
		  WHERE coaching_id=? LIMIT 1"
	);
	if ($saveStatement !== false) {
		mysqli_stmt_bind_param($saveStatement, 'iiisssi', $recordingsFound, $callsAnalyzed, $failedCalls, $recordingIdsJson, $summaryJson, $sessionUser, $coachingId);
	}
	$saveAction = 'updated';
} else {
	$saveStatement = mysqli_prepare(
		$link,
		"INSERT INTO quality_control_agent_coaching
			(agent_id, campaign_id, begin_date, end_date, recordings_found, calls_analyzed, failed_calls, recording_ids, summary_json, generated_by, generated_at)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
	);
	if ($saveStatement !== false) {
		mysqli_stmt_bind_param($saveStatement, 'ssssiiisss', $agentId, $campaignId, $beginDate, $endDate, $recordingsFound, $callsAnalyzed, $failedCalls, $recordingIdsJson, $summaryJson, $sessionUser);
	}
}
if ($saveStatement === false) {
	agentCoachingRespond(500, ['success' => false, 'message' => 'The summary was generated, but the database save could not be prepared.']);
}
if (!mysqli_stmt_execute($saveStatement)) {
	mysqli_stmt_close($saveStatement);
	agentCoachingRespond(500, ['success' => false, 'message' => 'The summary was generated, but it could not be saved to the database.']);
}
if ($coachingId === 0) {
	$coachingId = (int) mysqli_insert_id($link);
}
mysqli_stmt_close($saveStatement);

agentCoachingRespond(200, [
	'success' => true,
	'coaching_id' => $coachingId,
	'save_action' => $saveAction,
	'agent_id' => $agentId,
	'agent_name' => $agentName,
	'calls_analyzed' => $callsAnalyzed,
	'failed_calls' => $failedCalls,
	'summary' => $summary,
]);
