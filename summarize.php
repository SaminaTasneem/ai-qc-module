<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function respond(int $status, array $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'message' => 'POST requests only.']);
}

$request = json_decode((string) file_get_contents('php://input'), true);
$qcScorecardId = is_array($request) ? trim((string) ($request['qc_scorecard_id'] ?? '')) : '';
$recordingId = is_array($request) ? trim((string) ($request['recording_id'] ?? '')) : '';
$qcLogId = is_array($request) ? trim((string) ($request['qc_log_id'] ?? '')) : '';
$analysisMode = is_array($request) ? trim((string) ($request['analysis_mode'] ?? 'checkpoints_sentiment')) : 'checkpoints_sentiment';
$forceRegenerate = is_array($request) && ($request['force_regenerate'] ?? false) === true;
$summaryDirectory = '/var/spool/asterisk/uploads/ai_summary';
$summaryFile = $summaryDirectory . '/' . $recordingId . '_ai_summary.txt';
$analysisFile = $summaryDirectory . '/' . $recordingId . '_ai_analysis.json';

if ($recordingId === '' || !ctype_digit($recordingId)) {
    respond(422, ['success' => false, 'message' => 'A valid recording ID was not provided.']);
}

if ($qcScorecardId === '') {
    respond(422, ['success' => false, 'message' => 'The QC scorecard ID was not provided.']);
}

if ($qcLogId === '' || !ctype_digit($qcLogId)) {
    respond(422, ['success' => false, 'message' => 'A valid QC log ID was not provided.']);
}

if (!in_array($analysisMode, ['checkpoints_sentiment', 'summary_only'], true)) {
    respond(422, ['success' => false, 'message' => 'The requested AI analysis mode is invalid.']);
}

if (empty($_SESSION['user'])) {
    respond(401, ['success' => false, 'message' => 'Your admin session has expired. Please sign in again.']);
}

require_once __DIR__ . '/dbconnect_mysqli.php';

if (!isset($link) || !($link instanceof mysqli)) {
    respond(500, ['success' => false, 'message' => 'Could not connect to the database.']);
}

$queueStatement = mysqli_prepare(
    $link,
    'SELECT qc_log_id, recording_id, qc_scorecard_id FROM quality_control_queue WHERE qc_log_id = ? AND qc_agent = ? LIMIT 1'
);

if ($queueStatement === false) {
    respond(500, ['success' => false, 'message' => 'Could not validate the QC record.']);
}

$sessionUser = (string) $_SESSION['user'];
mysqli_stmt_bind_param($queueStatement, 'is', $qcLogId, $sessionUser);
mysqli_stmt_execute($queueStatement);
$queueResult = mysqli_stmt_get_result($queueStatement);
$queueRow = $queueResult ? mysqli_fetch_assoc($queueResult) : null;
mysqli_stmt_close($queueStatement);

if (!is_array($queueRow)) {
    respond(403, ['success' => false, 'message' => 'This QC record is not assigned to your account.']);
}

if ((string) $queueRow['recording_id'] !== $recordingId || (string) $queueRow['qc_scorecard_id'] !== $qcScorecardId) {
    respond(409, ['success' => false, 'message' => 'The recording or scorecard does not match the QC record.']);
}

// Return the saved per-recording analysis unless the user explicitly requested
// a fresh combined summary and checkpoint evaluation.
if (!$forceRegenerate && is_file($summaryFile)) {
    if (!is_readable($summaryFile)) {
        respond(500, ['success' => false, 'message' => 'The saved AI summary is not readable.']);
    }

    $savedSummary = file_get_contents($summaryFile);

    if ($savedSummary === false) {
        respond(500, ['success' => false, 'message' => 'Could not read the saved AI summary.']);
    }

    $savedCheckpoints = [];

    $savedSentiment = null;

    if (is_file($analysisFile) && is_readable($analysisFile)) {
        $savedAnalysisContents = file_get_contents($analysisFile);
        $savedAnalysis = is_string($savedAnalysisContents)
            ? json_decode($savedAnalysisContents, true)
            : null;

        if (is_array($savedAnalysis) && is_array($savedAnalysis['checkpoints'] ?? null)) {
            $savedCheckpoints = $savedAnalysis['checkpoints'];
        }

        if (is_array($savedAnalysis) && is_array($savedAnalysis['sentiment_analysis'] ?? null)) {
            $savedSentiment = $savedAnalysis['sentiment_analysis'];
        }
    }

    if ($analysisMode === 'summary_only') {
        respond(200, [
            'success' => true,
            'cached' => true,
            'recording_id' => $recordingId,
            'summary' => $savedSummary,
        ]);
    }

    if (is_array($savedSentiment)) {
        respond(200, [
            'success' => true,
            'cached' => true,
            'recording_id' => $recordingId,
            'summary' => $savedSummary,
            'evaluation' => ['checkpoints' => $savedCheckpoints, 'sentiment_analysis' => $savedSentiment],
        ]);
    }
}

$checkpointStatement = mysqli_prepare(
    $link,
    "SELECT * FROM quality_control_checkpoints WHERE qc_scorecard_id = ? AND active = 'Y' ORDER BY checkpoint_rank ASC"
);

if ($checkpointStatement === false) {
    respond(500, ['success' => false, 'message' => 'Could not prepare the checkpoint query.']);
}

mysqli_stmt_bind_param($checkpointStatement, 's', $qcScorecardId);

if (!mysqli_stmt_execute($checkpointStatement)) {
    mysqli_stmt_close($checkpointStatement);
    respond(500, ['success' => false, 'message' => 'Could not load the scorecard checkpoints.']);
}

$checkpointResult = mysqli_stmt_get_result($checkpointStatement);
$checkpoints = [];

if ($checkpointResult !== false) {
    while ($row = mysqli_fetch_assoc($checkpointResult)) {
        $checkpoints[] = $row;
    }
}

mysqli_stmt_close($checkpointStatement);

$checklistItems = [];

foreach ($checkpoints as $checkpoint) {
    $checkpointText = trim((string) ($checkpoint['checkpoint_text'] ?? ''));

    if ($checkpointText !== '') {
        $checklistItems[] = $checkpointText;
    }
}

if ($checklistItems === []) {
    respond(422, [
        'success' => false,
        'message' => 'No active checklist items were found for scorecard ' . $qcScorecardId . '.',
    ]);
}

$checklistLines = [];

foreach ($checklistItems as $index => $checkpointText) {
    $checklistLines[] = ($index + 1) . '. ' . $checkpointText;
}

$checklistText = implode("\n", $checklistLines);
$checkpointDefinitions = [];

foreach ($checkpoints as $checkpoint) {
    $checkpointDefinitions[] = [
        'checkpoint_row_id' => (int) ($checkpoint['checkpoint_row_id'] ?? 0),
        'checkpoint_rank' => (int) ($checkpoint['checkpoint_rank'] ?? 0),
        'checkpoint_text' => (string) ($checkpoint['checkpoint_text'] ?? ''),
        'checkpoint_points' => (int) ($checkpoint['checkpoint_points'] ?? 0),
        'instant_fail' => (string) ($checkpoint['instant_fail'] ?? ''),
    ];
}

/* OpenRouter configuration (disabled in favor of Gemini).
$configFile = __DIR__ . '/openrouter.php';

if (!is_file($configFile)) {
    respond(500, ['success' => false, 'message' => 'OpenRouter configuration file was not found.']);
}

$openRouter = require $configFile;

if (!is_array($openRouter)) {
    respond(500, ['success' => false, 'message' => 'OpenRouter configuration is invalid.']);
}

if (empty($openRouter['api_key'])) {
    respond(500, ['success' => false, 'message' => 'OpenRouter is not configured.']);
}
*/

$configFile = __DIR__ . '/gemini.php';

if (!is_file($configFile)) {
    respond(500, ['success' => false, 'message' => 'Gemini configuration file was not found.']);
}

$gemini = require $configFile;

if (!is_array($gemini)) {
    respond(500, ['success' => false, 'message' => 'Gemini configuration is invalid.']);
}

if (empty($gemini['api_key'])) {
    respond(500, ['success' => false, 'message' => 'Gemini is not configured.']);
}

if (!function_exists('curl_init')) {
    respond(500, ['success' => false, 'message' => 'The PHP cURL extension is not enabled.']);
}

require_once __DIR__ . '/gemini_audio.php';
require_once __DIR__ . '/recording_audio.php';

try {
    $recording = resolveRecordingAudio($link, $recordingId);
} catch (RuntimeException $error) {
    respond(404, ['success' => false, 'message' => $error->getMessage()]);
}

/* OpenRouter request (disabled in favor of Gemini).
$payload = [
    'model' => $openRouter['model'] ?? 'openrouter/free',
    'temperature' => 0.2,
    'messages' => [
        [
            'role' => 'system',
            'content' => 'You are an AI Quality Control Assistant.

Evaluate the following transcript against the QC checklist.

For each checklist item return:

- Checklist Item
- Status (Pass / Fail / Partial)
- Confidence (0-100%)
- Timestamp from the transcript (HH:MM:SS)
- Evidence from transcript
- Reason

Checklist:

' . $checklistText . '

Return ONLY a valid HTML table with one row for every checklist item, in the same order as the checklist.',
        ],
        ['role' => 'user', 'content' => "Transcript:\n\n" . $transcript],
    ],
];

$curl = curl_init(rtrim((string) ($openRouter['base_url'] ?? 'https://openrouter.ai/api/v1'), '/') . '/chat/completions');

if ($curl === false) {
    respond(500, ['success' => false, 'message' => 'Could not initialize the OpenRouter request.']);
}
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $openRouter['api_key']],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => (int) ($openRouter['timeout'] ?? 60),
]);

$responseBody = curl_exec($curl);
$curlError = curl_error($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

$response = is_string($responseBody) ? json_decode($responseBody, true) : null;
$summary = is_array($response) ? trim((string) ($response['choices'][0]['message']['content'] ?? '')) : '';

if ($responseBody === false || $status < 200 || $status >= 300 || $summary === '') {
    $providerMessage = is_array($response) ? (string) ($response['error']['message'] ?? '') : '';
    respond(502, ['success' => false, 'message' => $providerMessage ?: ('OpenRouter could not generate a summary: ' . $curlError)]);
}
*/

$systemInstruction = 'You are a strict call-quality evaluator analyzing a call recording directly.
Identify the speakers by conversational role as Agent and Customer. Use only audible evidence from the recording and evaluate every supplied checkpoint.

Return ONLY valid JSON in this shape:
{"checkpoints":[{"checkpoint_row_id":1,"checkpoint_rank":1,"earned_points":0,"status":"Pass|Fail|Partial","is_failed":false,"confidence":0,"timestamp":"[00:00:00]","evidence":"brief audible evidence","reason":"brief reason"}],"sentiment_analysis":{"customer_interest":{"score":0,"reason":"brief evidence-based reason"},"buying_intent":{"score":0,"reason":"brief evidence-based reason"},"agent_empathy":{"score":0,"reason":"brief evidence-based reason"},"agent_behavior":{"score":0,"reason":"brief evidence-based reason","flags":[{"type":"Rudeness","timestamp":"[00:00:00]","evidence":"brief audible evidence"}]},"emotions":[{"emotion":"Curious","confidence":0}],"call_outcome":{"prediction":"brief outcome prediction","confidence":0,"reason":"brief evidence-based reason"},"agent_coaching":{"overall_assessment":"brief constructive assessment","suggestions":[{"timestamp":"[00:00:00]","observation":"what happened","recommended_approach":"how the agent could handle it better","example_phrase":"a concise example the agent could say"}]}}}.

For each checkpoint, earned_points must be an integer from zero through checkpoint_points, confidence must be from 0 through 100, and is_failed must be true when status is Fail and false otherwise.
Use the exact supporting timestamp as [HH:MM:SS]. If several moments support the result, use a comma-separated string. Use N/A only when the recording contains no supporting statement.';

$systemInstruction .= '\nFor sentiment_analysis, every score and confidence must be an integer from 0 through 100. Emotions must use only: Curious, Interested, Skeptical, Confused, Hesitant, Frustrated, Excited, Happy, Impatient, Neutral. Base all reasons on audible behavior, wording, and engagement; do not invent facts. For agent_behavior, 100 means consistently polite and professional and 0 means severely inappropriate. Evaluate only the Agent for rudeness, slang, profanity, insults, interruptions, dismissiveness, aggressive tone, or unprofessional language. Add a flag only when there is clear audible evidence, using one of these exact types: Rudeness, Slang, Profanity, Insult, Interruption, Dismissiveness, Aggressive Tone, Unprofessional Language. Include an exact timestamp and brief evidence for every flag. Return an empty flags array when none are detected.';
$systemInstruction .= '\nFor agent_coaching, give a concise, constructive overall assessment and 1 to 4 specific suggestions showing how the Agent could have handled the conversation more effectively. Anchor each suggestion to an audible moment with an exact timestamp, describe the observed behavior without speculation, recommend a practical alternative, and include a natural example phrase suited to the conversation. Focus on listening, empathy, clarity, objection handling, discovery, professionalism, and next steps as applicable. Do not invent customer needs or events that are not audible.';
$systemInstruction .= '\nYou must return exactly one result for every supplied checkpoint. Copy checkpoint_row_id and checkpoint_rank exactly from the supplied checkpoint and do not omit any checkpoint. Evidence and reason must never be empty. When a requirement was not observed, use status "Fail", earned_points 0, timestamp "N/A", evidence "The required behavior was not observed in the recording.", and reason "The checkpoint requirement was not met."';

$prompt = "Analyze the attached call recording and generate checkpoint and sentiment results.\n\n"
    . "Checkpoints:\n" . json_encode($checkpointDefinitions, JSON_UNESCAPED_UNICODE);
$responseJsonSchema = null;

if ($analysisMode === 'summary_only') {
    $systemInstruction = 'You are a strict call-recording verifier and summarization assistant.

First determine whether the recording contains a connected, intelligible, two-way conversation between a human Agent and a human Customer. Ringing, ringback tones, silence, noise, hold music, voicemail greetings, answering machines, IVR or automated messages, abandoned calls, and audio containing only one human speaker are NOT connected conversations.

Return ONLY valid JSON in exactly this shape:
{"call_connected":false,"conversation_detected":false,"summary_html":"","no_summary_reason":"Only ringing or silence was detected."}

For a connected conversation use the same fields with true booleans, a non-empty summary_html, and an empty no_summary_reason.

Rules:
1. If there is no clear two-way human conversation, set call_connected and conversation_detected to false, leave summary_html empty, and provide a brief factual no_summary_reason.
2. Never infer a conversation from metadata, tones, silence, background noise, an automated message, or a single speaker.
3. Never invent a customer request, product, order, price, objection, commitment, appointment, or next action.
4. Only when a clear two-way Agent and Customer conversation is audible, set both booleans to true and provide a concise, evidence-based summary_html.
5. A connected summary should cover only facts audibly discussed: the customer need, important details, objections or concerns, commitments or appointments, and next actions.
6. summary_html may use only p, strong, ul, ol, li, em, and br tags. Do not include Markdown, scripts, styles, headings, div elements, checkpoint scores, or sentiment scores.';
    $prompt = 'Verify whether this recording contains a connected two-way human conversation. Only if it does, generate a clear manager-friendly summary using audible evidence.';
    $responseJsonSchema = [
        'type' => 'object',
        'properties' => [
            'call_connected' => ['type' => 'boolean'],
            'conversation_detected' => ['type' => 'boolean'],
            'summary_html' => ['type' => 'string'],
            'no_summary_reason' => ['type' => 'string'],
        ],
        'required' => ['call_connected', 'conversation_detected', 'summary_html', 'no_summary_reason'],
        'additionalProperties' => false,
    ];
}

$generationError = '';
$output = '';
$analysis = null;
$maximumAttempts = 3;
$attemptsMade = 0;
$uploadedGeminiFile = null;
$expectedCheckpointIds = array_map(
    static fn(array $definition): string => (string) ($definition['checkpoint_row_id'] ?? ''),
    $checkpointDefinitions
);
sort($expectedCheckpointIds);

try {
    $uploadedGeminiFile = geminiUploadAudio($gemini, (string) $recording['path']);

    for ($attempt = 1; $attempt <= $maximumAttempts; $attempt++) {
        $attemptsMade = $attempt;
        try {
            $attemptPrompt = $prompt;

            if ($attempt > 1) {
                $attemptPrompt .= "\n\nThis is retry {$attempt} of {$maximumAttempts}. The previous response was invalid or incomplete. Return one complete JSON object only.";
            }

            $output = geminiGenerateFromUploadedAudio(
                $gemini,
                $uploadedGeminiFile,
                $systemInstruction,
                $attemptPrompt,
                true,
                $responseJsonSchema
            );
            $cleanOutput = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $output) ?? $output;
            $analysis = json_decode(trim($cleanOutput), true);

            $returnedCheckpoints = is_array($analysis['checkpoints'] ?? null)
                ? $analysis['checkpoints']
                : [];
            $returnedCheckpointIds = [];
            $allCheckpointCommentsPresent = true;

            foreach ($returnedCheckpoints as $checkpointResult) {
                if (!is_array($checkpointResult)) {
                    $allCheckpointCommentsPresent = false;
                    continue;
                }

                $returnedCheckpointIds[] = (string) ($checkpointResult['checkpoint_row_id'] ?? '');
                if (
                    trim((string) ($checkpointResult['evidence'] ?? '')) === ''
                    || trim((string) ($checkpointResult['reason'] ?? '')) === ''
                ) {
                    $allCheckpointCommentsPresent = false;
                }
            }

            sort($returnedCheckpointIds);
            $allCheckpointsReturned = count($returnedCheckpointIds) === count($expectedCheckpointIds)
                && $returnedCheckpointIds === $expectedCheckpointIds
                && $allCheckpointCommentsPresent;

            $hasRequiredAnalysis = $analysisMode === 'summary_only'
                ? is_array($analysis)
                    && is_bool($analysis['call_connected'] ?? null)
                    && is_bool($analysis['conversation_detected'] ?? null)
                    && (
                        (($analysis['call_connected'] ?? false) === true
                            && ($analysis['conversation_detected'] ?? false) === true
                            && trim((string) ($analysis['summary_html'] ?? '')) !== '')
                        || (($analysis['call_connected'] ?? true) === false
                            || ($analysis['conversation_detected'] ?? true) === false)
                    )
                : is_array($analysis)
                    && is_array($analysis['checkpoints'] ?? null)
                    && ($analysis['checkpoints'] ?? []) !== []
                    && $allCheckpointsReturned
                    && is_array($analysis['sentiment_analysis'] ?? null)
                    && trim((string) ($analysis['sentiment_analysis']['agent_coaching']['overall_assessment'] ?? '')) !== ''
                    && is_array($analysis['sentiment_analysis']['agent_coaching']['suggestions'] ?? null)
                    && ($analysis['sentiment_analysis']['agent_coaching']['suggestions'] ?? []) !== [];

            if ($hasRequiredAnalysis) {
                break;
            }

            $analysis = null;
            $generationError = 'Gemini returned invalid or incomplete analysis JSON.';
            error_log(
                'AI analysis validation failed for recording ' . $recordingId
                . ', mode ' . $analysisMode
                . ', attempt ' . $attempt
                . ', JSON error: ' . json_last_error_msg()
                . ', response length: ' . strlen($output)
            );
        } catch (RuntimeException $error) {
            $generationError = $error->getMessage();
            error_log(
                'AI generation request failed for recording ' . $recordingId
                . ', mode ' . $analysisMode
                . ', attempt ' . $attempt
                . ': ' . $generationError
            );
        }
    }
} catch (RuntimeException $error) {
    $generationError = $error->getMessage();
    $attemptsMade = max(1, $attemptsMade);
} finally {
    if (is_array($uploadedGeminiFile)) {
        geminiDeleteFile($gemini, (string) ($uploadedGeminiFile['name'] ?? ''));
    }
    removeTemporaryRecording($recording);
}

if (!is_array($analysis)) {
    $attemptLabel = $attemptsMade === 1 ? 'time' : 'times';
    $failureMessage = $analysisMode === 'summary_only'
        ? "Unfortunately, AI couldn't generate the AI summary after {$attemptsMade} {$attemptLabel}. Please try again by pressing the Generate AI Summary button."
        : "Unfortunately, AI couldn't generate the checkpoints and sentiment analysis after {$attemptsMade} {$attemptLabel}. Please try again by pressing the Generate AI Checkpoints & Sentiment button.";
    respond(502, [
        'success' => false,
        'attempts' => $attemptsMade,
        'message' => $failureMessage,
    ]);
}

$summary = trim((string) ($analysis['summary_html'] ?? ''));
$evaluatedCheckpoints = is_array($analysis['checkpoints'] ?? null) ? $analysis['checkpoints'] : [];
$sentimentInput = is_array($analysis['sentiment_analysis'] ?? null) ? $analysis['sentiment_analysis'] : [];

if ($analysisMode === 'summary_only') {
    $callConnected = ($analysis['call_connected'] ?? false) === true;
    $conversationDetected = ($analysis['conversation_detected'] ?? false) === true;
    $noSummaryReason = trim((string) ($analysis['no_summary_reason'] ?? ''));

    if (!$callConnected || !$conversationDetected) {
        respond(422, [
            'success' => false,
            'no_connected_conversation' => true,
            'message' => $noSummaryReason !== ''
                ? 'AI summary was not generated: ' . $noSummaryReason
                : 'AI summary was not generated because no connected two-way conversation was detected.',
        ]);
    }

    if ($summary === '') {
        respond(502, ['success' => false, 'message' => 'Gemini did not return an AI summary.']);
    }

    if (!is_dir($summaryDirectory) && !mkdir($summaryDirectory, 0775, true) && !is_dir($summaryDirectory)) {
        respond(500, ['success' => false, 'message' => 'Could not create the AI summary directory.']);
    }

    if (!is_writable($summaryDirectory) || file_put_contents($summaryFile, $summary, LOCK_EX) === false) {
        respond(500, ['success' => false, 'message' => 'Could not save the AI summary.']);
    }

    respond(200, [
        'success' => true,
        'cached' => false,
        'recording_id' => $recordingId,
        'summary' => $summary,
    ]);
}

if ($evaluatedCheckpoints === []) {
    respond(502, ['success' => false, 'message' => 'Gemini did not return checkpoint results.']);
}

foreach ($evaluatedCheckpoints as &$checkpointResult) {
    if (!is_array($checkpointResult)) {
        continue;
    }

    if (trim((string) ($checkpointResult['evidence'] ?? '')) === '') {
        $checkpointResult['evidence'] = 'The required behavior was not observed in the recording.';
    }
    if (trim((string) ($checkpointResult['reason'] ?? '')) === '') {
        $checkpointResult['reason'] = 'The checkpoint requirement was not met.';
    }
    if (trim((string) ($checkpointResult['timestamp'] ?? '')) === '') {
        $checkpointResult['timestamp'] = 'N/A';
    }
}
unset($checkpointResult);

$clampScore = static function ($value): int {
    return max(0, min(100, (int) round((float) $value)));
};
$cleanReason = static function ($value): string {
    return trim((string) $value);
};
$allowedEmotions = ['Curious', 'Interested', 'Skeptical', 'Confused', 'Hesitant', 'Frustrated', 'Excited', 'Happy', 'Impatient', 'Neutral'];
$normalizedEmotions = [];
$allowedBehaviorFlags = ['Rudeness', 'Slang', 'Profanity', 'Insult', 'Interruption', 'Dismissiveness', 'Aggressive Tone', 'Unprofessional Language'];
$normalizedBehaviorFlags = [];
$normalizedCoachingSuggestions = [];

foreach ((array) ($sentimentInput['agent_behavior']['flags'] ?? []) as $flagRow) {
    if (!is_array($flagRow)) {
        continue;
    }
    $flagType = trim((string) ($flagRow['type'] ?? ''));
    if (!in_array($flagType, $allowedBehaviorFlags, true)) {
        continue;
    }
    $normalizedBehaviorFlags[] = [
        'type' => $flagType,
        'timestamp' => trim((string) ($flagRow['timestamp'] ?? 'N/A')),
        'evidence' => $cleanReason($flagRow['evidence'] ?? ''),
    ];
}

foreach ((array) ($sentimentInput['emotions'] ?? []) as $emotionRow) {
    if (!is_array($emotionRow)) {
        continue;
    }

    $emotion = trim((string) ($emotionRow['emotion'] ?? ''));

    if (!in_array($emotion, $allowedEmotions, true)) {
        continue;
    }

    $normalizedEmotions[$emotion] = [
        'emotion' => $emotion,
        'confidence' => $clampScore($emotionRow['confidence'] ?? 0),
    ];
}

if ($normalizedEmotions === []) {
    $normalizedEmotions['Neutral'] = ['emotion' => 'Neutral', 'confidence' => 0];
}

foreach ((array) ($sentimentInput['agent_coaching']['suggestions'] ?? []) as $suggestionRow) {
    if (!is_array($suggestionRow)) {
        continue;
    }

    $recommendedApproach = $cleanReason($suggestionRow['recommended_approach'] ?? '');
    if ($recommendedApproach === '') {
        continue;
    }

    $normalizedCoachingSuggestions[] = [
        'timestamp' => trim((string) ($suggestionRow['timestamp'] ?? 'N/A')),
        'observation' => $cleanReason($suggestionRow['observation'] ?? ''),
        'recommended_approach' => $recommendedApproach,
        'example_phrase' => $cleanReason($suggestionRow['example_phrase'] ?? ''),
    ];

    if (count($normalizedCoachingSuggestions) >= 4) {
        break;
    }
}

$normalizedEmotions = array_values($normalizedEmotions);
usort($normalizedEmotions, static function (array $left, array $right): int {
    return $right['confidence'] <=> $left['confidence'];
});

$sentiment = [
    'customer_interest' => [
        'score' => $clampScore($sentimentInput['customer_interest']['score'] ?? 0),
        'reason' => $cleanReason($sentimentInput['customer_interest']['reason'] ?? ''),
    ],
    'buying_intent' => [
        'score' => $clampScore($sentimentInput['buying_intent']['score'] ?? 0),
        'reason' => $cleanReason($sentimentInput['buying_intent']['reason'] ?? ''),
    ],
    'agent_empathy' => [
        'score' => $clampScore($sentimentInput['agent_empathy']['score'] ?? 0),
        'reason' => $cleanReason($sentimentInput['agent_empathy']['reason'] ?? ''),
    ],
    'agent_behavior' => [
        'score' => $clampScore($sentimentInput['agent_behavior']['score'] ?? 0),
        'reason' => $cleanReason($sentimentInput['agent_behavior']['reason'] ?? ''),
        'flags' => $normalizedBehaviorFlags,
    ],
    'emotions' => $normalizedEmotions,
    'call_outcome' => [
        'prediction' => trim((string) ($sentimentInput['call_outcome']['prediction'] ?? '')),
        'confidence' => $clampScore($sentimentInput['call_outcome']['confidence'] ?? 0),
        'reason' => $cleanReason($sentimentInput['call_outcome']['reason'] ?? ''),
    ],
    'agent_coaching' => [
        'overall_assessment' => $cleanReason($sentimentInput['agent_coaching']['overall_assessment'] ?? ''),
        'suggestions' => $normalizedCoachingSuggestions,
    ],
];

$emotionsJson = json_encode($sentiment['emotions'], JSON_UNESCAPED_UNICODE);
$rawAiResponse = (string) $output;
$aiModel = (string) ($gemini['model'] ?? 'gemini');
$dominantEmotion = (string) ($sentiment['emotions'][0]['emotion'] ?? 'Neutral');
$interestScore = $sentiment['customer_interest']['score'];
$interestReason = $sentiment['customer_interest']['reason'];
$buyingScore = $sentiment['buying_intent']['score'];
$buyingReason = $sentiment['buying_intent']['reason'];
$empathyScore = $sentiment['agent_empathy']['score'];
$empathyReason = $sentiment['agent_empathy']['reason'];
$behaviorScore = $sentiment['agent_behavior']['score'];
$behaviorReason = $sentiment['agent_behavior']['reason'];
$behaviorFlagsJson = json_encode($sentiment['agent_behavior']['flags'], JSON_UNESCAPED_UNICODE);
$outcomePrediction = $sentiment['call_outcome']['prediction'];
$outcomeConfidence = $sentiment['call_outcome']['confidence'];
$outcomeReason = $sentiment['call_outcome']['reason'];

$sentimentStatement = mysqli_prepare($link, "INSERT INTO quality_control_sentiment_analysis
    (qc_log_id, recording_id, customer_interest_score, customer_interest_reason,
     buying_intent_score, buying_intent_reason, agent_empathy_score, agent_empathy_reason,
     agent_behavior_score, agent_behavior_reason, agent_behavior_flags_json,
     dominant_emotion, emotions_json, outcome_prediction, outcome_confidence, outcome_reason,
     ai_model, raw_ai_response, analysis_status, error_message, generated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED', NULL, NOW())
    ON DUPLICATE KEY UPDATE recording_id=VALUES(recording_id),
     customer_interest_score=VALUES(customer_interest_score), customer_interest_reason=VALUES(customer_interest_reason),
     buying_intent_score=VALUES(buying_intent_score), buying_intent_reason=VALUES(buying_intent_reason),
     agent_empathy_score=VALUES(agent_empathy_score), agent_empathy_reason=VALUES(agent_empathy_reason),
     agent_behavior_score=VALUES(agent_behavior_score), agent_behavior_reason=VALUES(agent_behavior_reason),
     agent_behavior_flags_json=VALUES(agent_behavior_flags_json),
     dominant_emotion=VALUES(dominant_emotion), emotions_json=VALUES(emotions_json),
     outcome_prediction=VALUES(outcome_prediction), outcome_confidence=VALUES(outcome_confidence),
     outcome_reason=VALUES(outcome_reason), ai_model=VALUES(ai_model), raw_ai_response=VALUES(raw_ai_response),
     analysis_status='COMPLETED', error_message=NULL, generated_at=NOW()");

if ($sentimentStatement === false) {
    respond(500, ['success' => false, 'message' => 'Could not prepare the sentiment analysis save.']);
}

mysqli_stmt_bind_param(
    $sentimentStatement,
    'iiisisisisssssisss',
    $qcLogId,
    $recordingId,
    $interestScore,
    $interestReason,
    $buyingScore,
    $buyingReason,
    $empathyScore,
    $empathyReason,
    $behaviorScore,
    $behaviorReason,
    $behaviorFlagsJson,
    $dominantEmotion,
    $emotionsJson,
    $outcomePrediction,
    $outcomeConfidence,
    $outcomeReason,
    $aiModel,
    $rawAiResponse
);

if (!mysqli_stmt_execute($sentimentStatement)) {
    $saveError = mysqli_stmt_error($sentimentStatement);
    mysqli_stmt_close($sentimentStatement);
    respond(500, ['success' => false, 'message' => 'Could not save the sentiment analysis: ' . $saveError]);
}

mysqli_stmt_close($sentimentStatement);

if (!is_dir($summaryDirectory) && !mkdir($summaryDirectory, 0775, true) && !is_dir($summaryDirectory)) {
    respond(500, ['success' => false, 'message' => 'Could not create the AI summary directory.']);
}

if (!is_writable($summaryDirectory)) {
    respond(500, ['success' => false, 'message' => 'The AI summary directory is not writable.']);
}

$savedAnalysis = json_encode([
    'recording_id' => $recordingId,
    'qc_scorecard_id' => $qcScorecardId,
    'checkpoints' => $evaluatedCheckpoints,
    'sentiment_analysis' => $sentiment,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

if ($savedAnalysis === false || file_put_contents($analysisFile, $savedAnalysis, LOCK_EX) === false) {
    respond(500, ['success' => false, 'message' => 'Could not save the AI checkpoint analysis.']);
}

respond(200, [
    'success' => true,
    'cached' => false,
    'recording_id' => $recordingId,
    'checkpoints' => $checkpoints,
    'evaluation' => [
        'checkpoints' => $evaluatedCheckpoints,
        'sentiment_analysis' => $sentiment,
    ],
]);
