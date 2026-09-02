<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function longCallRespond(int $status, array $payload): void
{
	http_response_code($status);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	longCallRespond(405, ['success' => false, 'message' => 'POST requests only.']);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

$sessionUser = trim((string) ($_SESSION['user'] ?? ''));
if ($sessionUser === '') {
	longCallRespond(401, ['success' => false, 'message' => 'Your admin session has expired. Please sign in again.']);
}

$request = json_decode((string) file_get_contents('php://input'), true);
$agentLogId = is_array($request) ? trim((string) ($request['agent_log_id'] ?? '')) : '';
$recordingId = is_array($request) ? trim((string) ($request['recording_id'] ?? '')) : '';

if ($agentLogId === '' || !ctype_digit($agentLogId)) {
	longCallRespond(422, ['success' => false, 'message' => 'A valid agent log ID was not provided.']);
}
if ($recordingId === '' || !ctype_digit($recordingId)) {
	longCallRespond(422, ['success' => false, 'message' => 'A valid recording ID was not provided.']);
}

require_once __DIR__ . '/dbconnect_mysqli.php';

if (!isset($link) || !($link instanceof mysqli)) {
	longCallRespond(500, ['success' => false, 'message' => 'Could not connect to the database.']);
}

$accessStatement = mysqli_prepare(
	$link,
	"SELECT user FROM vicidial_users WHERE user=? AND active='Y' AND qc_enabled='1' LIMIT 1"
);
if ($accessStatement === false) {
	longCallRespond(500, ['success' => false, 'message' => 'Could not verify QC access.']);
}
mysqli_stmt_bind_param($accessStatement, 's', $sessionUser);
mysqli_stmt_execute($accessStatement);
$accessResult = mysqli_stmt_get_result($accessStatement);
$hasAccess = $accessResult !== false && mysqli_fetch_assoc($accessResult) !== null;
mysqli_stmt_close($accessStatement);
if (!$hasAccess) {
	longCallRespond(403, ['success' => false, 'message' => 'Your account is not enabled for QC analysis.']);
}

$callStatement = mysqli_prepare(
	$link,
	"SELECT lead_id, campaign_id, uniqueid, event_time, comments, talk_sec, user
	   FROM vicidial_agent_log WHERE agent_log_id=? LIMIT 1"
);
if ($callStatement === false) {
	longCallRespond(500, ['success' => false, 'message' => 'Could not validate the selected call.']);
}
mysqli_stmt_bind_param($callStatement, 's', $agentLogId);
mysqli_stmt_execute($callStatement);
$callResult = mysqli_stmt_get_result($callStatement);
$call = $callResult !== false ? mysqli_fetch_assoc($callResult) : null;
mysqli_stmt_close($callStatement);

if (!is_array($call) || (int) ($call['talk_sec'] ?? 0) <= 120) {
	longCallRespond(403, ['success' => false, 'message' => 'The selected call is not an eligible call over two minutes.']);
}

$leadId = (string) $call['lead_id'];
$uniqueId = (string) $call['uniqueid'];
$callDate = (string) $call['event_time'];
$recordingStatement = mysqli_prepare(
	$link,
	"SELECT recording_id FROM recording_log
	  WHERE lead_id=?
	  ORDER BY IF(vicidial_id=?, 1, 0) DESC,
	           ABS(TIMESTAMPDIFF(SECOND, start_time, ?)) ASC
	  LIMIT 1"
);
if ($recordingStatement === false) {
	longCallRespond(500, ['success' => false, 'message' => 'Could not validate the selected recording.']);
}
mysqli_stmt_bind_param($recordingStatement, 'sss', $leadId, $uniqueId, $callDate);
mysqli_stmt_execute($recordingStatement);
$recordingResult = mysqli_stmt_get_result($recordingStatement);
$recordingRow = $recordingResult !== false ? mysqli_fetch_assoc($recordingResult) : null;
mysqli_stmt_close($recordingStatement);

if (!is_array($recordingRow) || (string) $recordingRow['recording_id'] !== $recordingId) {
	longCallRespond(409, ['success' => false, 'message' => 'The recording does not match the selected call.']);
}

$listId = '';
$groupId = '';
$isInbound = in_array((string) ($call['comments'] ?? ''), ['CHAT', 'EMAIL', 'INBOUND'], true);
$logSql = $isInbound
	? "SELECT list_id, campaign_id AS group_id FROM vicidial_closer_log WHERE lead_id=? AND uniqueid=? ORDER BY closecallid DESC LIMIT 1"
	: "SELECT list_id, '' AS group_id FROM vicidial_log WHERE lead_id=? AND uniqueid=? ORDER BY uniqueid DESC LIMIT 1";
$logStatement = mysqli_prepare($link, $logSql);
if ($logStatement !== false) {
	mysqli_stmt_bind_param($logStatement, 'ss', $leadId, $uniqueId);
	mysqli_stmt_execute($logStatement);
	$logResult = mysqli_stmt_get_result($logStatement);
	$logRow = $logResult !== false ? mysqli_fetch_assoc($logResult) : null;
	mysqli_stmt_close($logStatement);
	if (is_array($logRow)) {
		$listId = trim((string) ($logRow['list_id'] ?? ''));
		$groupId = trim((string) ($logRow['group_id'] ?? ''));
	}
}

$scorecardId = '';
$scorecardSource = '';
$candidates = [];
if ($groupId !== '') {
	$candidates[] = ['table' => 'vicidial_inbound_groups', 'key' => 'group_id', 'value' => $groupId, 'source' => 'INGROUP'];
}
if ($listId !== '') {
	$candidates[] = ['table' => 'vicidial_lists', 'key' => 'list_id', 'value' => $listId, 'source' => 'LIST'];
}
$candidates[] = ['table' => 'vicidial_campaigns', 'key' => 'campaign_id', 'value' => (string) $call['campaign_id'], 'source' => 'CAMPAIGN'];

foreach ($candidates as $candidate) {
	$candidateSql = "SELECT qc_scorecard_id FROM {$candidate['table']} WHERE {$candidate['key']}=? AND qc_scorecard_id!='' LIMIT 1";
	$candidateStatement = mysqli_prepare($link, $candidateSql);
	if ($candidateStatement === false) {
		continue;
	}
	mysqli_stmt_bind_param($candidateStatement, 's', $candidate['value']);
	mysqli_stmt_execute($candidateStatement);
	$candidateResult = mysqli_stmt_get_result($candidateStatement);
	$candidateRow = $candidateResult !== false ? mysqli_fetch_assoc($candidateResult) : null;
	mysqli_stmt_close($candidateStatement);
	if (is_array($candidateRow) && trim((string) $candidateRow['qc_scorecard_id']) !== '') {
		$scorecardId = trim((string) $candidateRow['qc_scorecard_id']);
		$scorecardSource = $candidate['source'];
		break;
	}
}

if ($scorecardId === '') {
	longCallRespond(422, ['success' => false, 'message' => 'No QC scorecard is configured for this call.']);
}

$checkpointStatement = mysqli_prepare(
	$link,
	"SELECT checkpoint_row_id, checkpoint_rank, checkpoint_text, checkpoint_points, instant_fail
	   FROM quality_control_checkpoints
	  WHERE qc_scorecard_id=? AND active='Y'
	  ORDER BY checkpoint_rank ASC"
);
if ($checkpointStatement === false) {
	longCallRespond(500, ['success' => false, 'message' => 'Could not load the scorecard checkpoints.']);
}
mysqli_stmt_bind_param($checkpointStatement, 's', $scorecardId);
mysqli_stmt_execute($checkpointStatement);
$checkpointResult = mysqli_stmt_get_result($checkpointStatement);
$checkpoints = [];
if ($checkpointResult !== false) {
	while ($checkpoint = mysqli_fetch_assoc($checkpointResult)) {
		$checkpoints[] = [
			'checkpoint_row_id' => (int) $checkpoint['checkpoint_row_id'],
			'checkpoint_rank' => (int) $checkpoint['checkpoint_rank'],
			'checkpoint_text' => (string) $checkpoint['checkpoint_text'],
			'checkpoint_points' => (int) $checkpoint['checkpoint_points'],
			'instant_fail' => (string) $checkpoint['instant_fail'],
		];
	}
}
mysqli_stmt_close($checkpointStatement);

if ($checkpoints === []) {
	longCallRespond(422, ['success' => false, 'message' => 'The selected scorecard has no active checkpoints.']);
}

$geminiConfigFile = __DIR__ . '/gemini.php';
if (!is_file($geminiConfigFile)) {
	longCallRespond(500, ['success' => false, 'message' => 'Gemini configuration was not found.']);
}
$gemini = require $geminiConfigFile;
if (!is_array($gemini) || empty($gemini['api_key'])) {
	longCallRespond(500, ['success' => false, 'message' => 'Gemini is not configured.']);
}
if (!function_exists('curl_init')) {
	longCallRespond(500, ['success' => false, 'message' => 'The PHP cURL extension is not enabled.']);
}

require_once __DIR__ . '/gemini_audio.php';
require_once __DIR__ . '/recording_audio.php';

$systemInstruction = 'You are a strict call-quality evaluator analyzing a call recording directly. Identify speakers as Agent and Customer and use only audible evidence. First determine the purpose and context of the call.

Evaluate every supplied scorecard checkpoint. Return exactly one checkpoint result for every supplied checkpoint, copying checkpoint_row_id and checkpoint_rank exactly. Never omit a checkpoint. Evidence and reason must never be empty. earned_points must be an integer from zero through checkpoint_points. Use an exact [HH:MM:SS] timestamp when evidence is audible. Use status "Not Applicable" with earned_points 0 when the call context genuinely did not require that checkpoint, and explain why. If a checkpoint was applicable but the required behavior was not observed, use status "Fail", earned_points 0, timestamp "N/A", evidence "The required behavior was not observed in the recording.", and reason "The checkpoint requirement was not met."

Also evaluate customer interest, buying intent, agent empathy, observable agent behavior, customer emotions, likely call outcome, and coaching. Coaching must contain a concise overall assessment and 1 to 4 specific suggestions. Each suggestion must include an audible timestamp, what the agent did, how the situation could have been handled better, and a natural example phrase. Do not invent facts or judge personality.

Return ONLY valid JSON in this exact shape:
{"checkpoints":[{"checkpoint_row_id":1,"checkpoint_rank":1,"earned_points":0,"status":"Pass|Fail|Partial|Not Applicable","is_failed":false,"confidence":0,"timestamp":"[00:00:00]","evidence":"brief audible evidence","reason":"brief reason"}],"sentiment_analysis":{"customer_interest":{"score":0,"reason":"brief reason"},"buying_intent":{"score":0,"reason":"brief reason"},"agent_empathy":{"score":0,"reason":"brief reason"},"agent_behavior":{"score":0,"reason":"brief reason","flags":[{"type":"Rudeness","timestamp":"[00:00:00]","evidence":"brief evidence"}]},"emotions":[{"emotion":"Neutral","confidence":0}],"call_outcome":{"prediction":"brief prediction","confidence":0,"reason":"brief reason"},"agent_coaching":{"overall_assessment":"brief assessment","suggestions":[{"timestamp":"[00:00:00]","observation":"what happened","recommended_approach":"better handling","example_phrase":"what the agent could say"}]}}}';

$prompt = "Analyze this recording against these scorecard checkpoints:\n"
	. json_encode($checkpoints, JSON_UNESCAPED_UNICODE);
$analysis = null;
$generationError = '';
$uploadedFile = null;
$recording = null;

try {
	$recording = resolveRecordingAudio($link, $recordingId);
	$uploadedFile = geminiUploadAudio($gemini, (string) $recording['path']);
	for ($attempt = 1; $attempt <= 3; $attempt++) {
		try {
			$attemptPrompt = $prompt . ($attempt > 1 ? "\nThis is retry $attempt. The previous result was incomplete. Return every checkpoint and valid JSON only." : '');
			$output = geminiGenerateFromUploadedAudio($gemini, $uploadedFile, $systemInstruction, $attemptPrompt, true, null);
			$cleanOutput = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $output) ?? $output;
			$candidate = json_decode(trim($cleanOutput), true);
			$returned = is_array($candidate['checkpoints'] ?? null) ? $candidate['checkpoints'] : [];
			$expectedIds = array_map(static function (array $row): string {
				return (string) $row['checkpoint_row_id'];
			}, $checkpoints);
			$returnedIds = [];
			$completeComments = true;
			foreach ($returned as $result) {
				if (!is_array($result)) {
					$completeComments = false;
					continue;
				}
				$returnedIds[] = (string) ($result['checkpoint_row_id'] ?? '');
				if (trim((string) ($result['evidence'] ?? '')) === '' || trim((string) ($result['reason'] ?? '')) === '') {
					$completeComments = false;
				}
			}
			sort($expectedIds);
			sort($returnedIds);
			$coaching = $candidate['sentiment_analysis']['agent_coaching'] ?? null;
			if (
				is_array($candidate)
				&& $returnedIds === $expectedIds
				&& $completeComments
				&& is_array($candidate['sentiment_analysis'] ?? null)
				&& is_array($coaching)
				&& trim((string) ($coaching['overall_assessment'] ?? '')) !== ''
				&& is_array($coaching['suggestions'] ?? null)
				&& ($coaching['suggestions'] ?? []) !== []
			) {
				$analysis = $candidate;
				break;
			}
			$generationError = 'Gemini returned incomplete analysis.';
		} catch (RuntimeException $error) {
			$generationError = $error->getMessage();
		}
	}
} catch (RuntimeException $error) {
	$generationError = $error->getMessage();
} finally {
	if (is_array($uploadedFile)) {
		geminiDeleteFile($gemini, (string) ($uploadedFile['name'] ?? ''));
	}
	if (is_array($recording)) {
		removeTemporaryRecording($recording);
	}
}

if (!is_array($analysis)) {
	longCallRespond(502, ['success' => false, 'message' => $generationError !== '' ? $generationError : 'AI could not analyze the recording.']);
}

$clamp = static function ($value): int {
	return max(0, min(100, (int) round((float) $value)));
};
$sentimentInput = is_array($analysis['sentiment_analysis'] ?? null) ? $analysis['sentiment_analysis'] : [];
$suggestions = [];
foreach ((array) ($sentimentInput['agent_coaching']['suggestions'] ?? []) as $suggestion) {
	if (!is_array($suggestion)) {
		continue;
	}
	$suggestions[] = [
		'timestamp' => trim((string) ($suggestion['timestamp'] ?? 'N/A')) ?: 'N/A',
		'observation' => trim((string) ($suggestion['observation'] ?? '')),
		'recommended_approach' => trim((string) ($suggestion['recommended_approach'] ?? '')),
		'example_phrase' => trim((string) ($suggestion['example_phrase'] ?? '')),
	];
	if (count($suggestions) >= 4) {
		break;
	}
}

$sentiment = [
	'customer_interest' => [
		'score' => $clamp($sentimentInput['customer_interest']['score'] ?? 0),
		'reason' => trim((string) ($sentimentInput['customer_interest']['reason'] ?? '')),
	],
	'buying_intent' => [
		'score' => $clamp($sentimentInput['buying_intent']['score'] ?? 0),
		'reason' => trim((string) ($sentimentInput['buying_intent']['reason'] ?? '')),
	],
	'agent_empathy' => [
		'score' => $clamp($sentimentInput['agent_empathy']['score'] ?? 0),
		'reason' => trim((string) ($sentimentInput['agent_empathy']['reason'] ?? '')),
	],
	'agent_behavior' => [
		'score' => $clamp($sentimentInput['agent_behavior']['score'] ?? 0),
		'reason' => trim((string) ($sentimentInput['agent_behavior']['reason'] ?? '')),
		'flags' => is_array($sentimentInput['agent_behavior']['flags'] ?? null) ? $sentimentInput['agent_behavior']['flags'] : [],
	],
	'emotions' => is_array($sentimentInput['emotions'] ?? null) ? $sentimentInput['emotions'] : [],
	'call_outcome' => [
		'prediction' => trim((string) ($sentimentInput['call_outcome']['prediction'] ?? '')),
		'confidence' => $clamp($sentimentInput['call_outcome']['confidence'] ?? 0),
		'reason' => trim((string) ($sentimentInput['call_outcome']['reason'] ?? '')),
	],
	'agent_coaching' => [
		'overall_assessment' => trim((string) ($sentimentInput['agent_coaching']['overall_assessment'] ?? '')),
		'suggestions' => $suggestions,
	],
];

longCallRespond(200, [
	'success' => true,
	'agent_log_id' => $agentLogId,
	'recording_id' => $recordingId,
	'qc_scorecard_id' => $scorecardId,
	'scorecard_source' => $scorecardSource,
	'checkpoints' => $checkpoints,
	'evaluation' => [
		'checkpoints' => $analysis['checkpoints'],
		'sentiment_analysis' => $sentiment,
	],
]);
