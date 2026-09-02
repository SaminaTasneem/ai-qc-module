<?php

require_once __DIR__ . '/dbconnect_mysqli.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/session_auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

if (empty($_SESSION['user'])) {
	$redirect = urlencode((string) ($_SERVER['REQUEST_URI'] ?? 'ai_long_call_recordings.php'));
	header("Location: admin.php?redirect=$redirect");
	exit;
}

$currentUser = (string) $_SESSION['user'];
$escape = static function ($value): string {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$beginDate = (string) ($_GET['begin_date'] ?? date('Y-m-d'));
$endDate = (string) ($_GET['end_date'] ?? date('Y-m-d'));
$campaignId = preg_replace('/[^-_0-9a-zA-Z]/', '', (string) ($_GET['campaign_id'] ?? '--ALL--'));
$fetchRecordings = isset($_GET['fetch_recordings']);
$datePattern = '/^\d{4}-\d{2}-\d{2}$/';
$inputError = '';

if (!preg_match($datePattern, $beginDate) || !preg_match($datePattern, $endDate)) {
	$inputError = 'Please provide valid start and end dates.';
} elseif ($beginDate > $endDate) {
	$inputError = 'The start date cannot be later than the end date.';
}

$campaigns = [];
$campaignResult = mysqli_query(
	$link,
	"SELECT campaign_id, campaign_name FROM vicidial_campaigns ORDER BY campaign_id"
);
if ($campaignResult) {
	while ($campaignRow = mysqli_fetch_assoc($campaignResult)) {
		$campaigns[] = $campaignRow;
	}
}

$recordings = [];
$missingRecordings = 0;
$resultLimit = 500;

if ($fetchRecordings && $inputError === '') {
	$endExclusive = date('Y-m-d', strtotime($endDate . ' +1 day'));
	$callSql = "SELECT val.agent_log_id, val.user AS agent_id,
			COALESCE(NULLIF(vu.full_name, ''), val.user) AS agent_name,
			val.lead_id, val.campaign_id, val.uniqueid,
			val.event_time AS call_date, val.talk_sec,
			COALESCE(vl.phone_number, '') AS phone_number
		FROM vicidial_agent_log AS val
		LEFT JOIN vicidial_users AS vu ON vu.user=val.user
		LEFT JOIN vicidial_list AS vl ON vl.lead_id=val.lead_id
		WHERE val.talk_sec > 120
		  AND val.event_time >= ?
		  AND val.event_time < ?";

	if ($campaignId !== '--ALL--') {
		$callSql .= " AND val.campaign_id = ?";
	}
	$callSql .= " ORDER BY val.event_time DESC LIMIT $resultLimit";

	$callStatement = mysqli_prepare($link, $callSql);
	if ($callStatement === false) {
		$inputError = 'Could not prepare the long-call query.';
	} else {
		if ($campaignId !== '--ALL--') {
			mysqli_stmt_bind_param($callStatement, 'sss', $beginDate, $endExclusive, $campaignId);
		} else {
			mysqli_stmt_bind_param($callStatement, 'ss', $beginDate, $endExclusive);
		}

		if (!mysqli_stmt_execute($callStatement)) {
			$inputError = 'Could not fetch calls from the database.';
		} else {
			$callResult = mysqli_stmt_get_result($callStatement);
			$recordingStatement = mysqli_prepare(
				$link,
				"SELECT recording_id, start_time, length_in_sec, filename, location
				   FROM recording_log
				  WHERE lead_id = ?
				  ORDER BY IF(vicidial_id = ?, 1, 0) DESC,
				           ABS(TIMESTAMPDIFF(SECOND, start_time, ?)) ASC
				  LIMIT 1"
			);

			if ($recordingStatement === false) {
				$inputError = 'Could not prepare the recording lookup.';
			} elseif ($callResult !== false) {
				while ($call = mysqli_fetch_assoc($callResult)) {
					$leadId = (string) $call['lead_id'];
					$uniqueId = (string) $call['uniqueid'];
					$callDate = (string) $call['call_date'];
					mysqli_stmt_bind_param($recordingStatement, 'sss', $leadId, $uniqueId, $callDate);
					mysqli_stmt_execute($recordingStatement);
					$recordingResult = mysqli_stmt_get_result($recordingStatement);
					$recording = $recordingResult !== false ? mysqli_fetch_assoc($recordingResult) : null;

					if (!is_array($recording)) {
						$missingRecordings++;
						continue;
					}

					$recordings[] = array_merge($call, $recording);
				}
				mysqli_stmt_close($recordingStatement);
			}
		}
		mysqli_stmt_close($callStatement);
	}
}

$agentGroups = [];
foreach ($recordings as $recordingRow) {
	$agentKey = (string) $recordingRow['agent_id'];
	if (!isset($agentGroups[$agentKey])) {
		$agentGroups[$agentKey] = [
			'agent_id' => $agentKey,
			'agent_name' => (string) $recordingRow['agent_name'],
			'calls' => [],
		];
	}
	$agentGroups[$agentKey]['calls'][] = [
		'agent_log_id' => (string) $recordingRow['agent_log_id'],
		'recording_id' => (string) $recordingRow['recording_id'],
		'call_date' => (string) $recordingRow['call_date'],
	];
}

?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Long Call Recordings</title>
	<style>
		*{box-sizing:border-box} body{margin:0;padding:28px;background:#07111a;color:#edf7f7;font-family:Arial,sans-serif}
		.page{max-width:1500px;margin:0 auto}.top-link{color:#00f0c0;text-decoration:none}.title{color:#00f0c0;margin:22px 0 8px}
		.note{color:#a9bdc4;margin:0 0 24px}.filters{display:flex;flex-wrap:wrap;gap:14px;align-items:end;padding:20px;background:#0c222c;border:1px solid #126678;border-radius:12px}
		.field{display:flex;flex-direction:column;gap:7px}.field label{font-weight:700}.field input,.field select{min-height:42px;padding:8px 12px;border:1px solid #187183;border-radius:6px;background:#071923;color:#fff}
		.button{min-height:42px;padding:8px 22px;border:0;border-radius:6px;background:#00dfb4;color:#061119;font-weight:800;cursor:pointer}.summary{margin:22px 0 10px;color:#b9cbd0}
		.analyze-button{padding:10px 16px;border:1px solid #00dfb4;border-radius:6px;background:transparent;color:#00dfb4;font-weight:800;cursor:pointer;white-space:nowrap}.analyze-button:disabled{opacity:.55;cursor:wait}
		.error{margin:20px 0;padding:14px;background:#4b1720;border:1px solid #e35d70;border-radius:8px}.table-wrap{overflow-x:auto;border:1px solid #14596a;border-radius:12px}
		table{width:100%;border-collapse:collapse;min-width:1100px}th,td{padding:13px 12px;text-align:left;border-bottom:1px solid #14505d}th{background:#073342;color:#00f0c0;text-transform:uppercase;font-size:13px}tbody tr:nth-child(even){background:#0a2832}
		audio{width:280px;height:38px}.empty{padding:28px;text-align:center;color:#a9bdc4}.agent-section{margin:24px 0}.agent-card{margin:14px 0;border:1px solid #14596a;border-radius:12px;overflow:hidden;background:#081d26}.agent-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px}.agent-name{margin:0;color:#00f0c0}.agent-count{color:#a9bdc4;margin-top:5px}.agent-result{padding:0 18px 18px}.agent-result:empty{display:none}.analysis-status{color:#a9bdc4}.analysis-error{color:#ff8797}.analysis-title{margin:20px 0 10px;color:#00f0c0}.summary-box{padding:16px;border-left:3px solid #00dfb4;background:#0a2530}.summary-list{margin:8px 0;padding-left:22px}.coaching-item{margin:12px 0;padding:14px;border:1px solid #14596a;border-radius:8px;background:#0a2530}.coaching-item p{margin:6px 0}.example{font-style:italic;color:#c8dbdf}.progress-track{height:8px;margin-top:10px;background:#102e39;border-radius:10px;overflow:hidden}.progress-fill{height:100%;width:0;background:#00dfb4;transition:width .2s}
	</style>
</head>
<body>
<main class="page">
	<a class="top-link" href="admin.php">&larr; Back to administration</a>
	<h1 class="title">Calls Over Two Minutes</h1>
	<p class="note">Connected conversation time is taken from <code>vicidial_agent_log.talk_sec</code>. Results are limited to <?php echo $resultLimit; ?> calls.</p>

	<form class="filters" method="get" action="">
		<div class="field">
			<label for="begin_date">Start date</label>
			<input type="date" id="begin_date" name="begin_date" value="<?php echo $escape($beginDate); ?>" required>
		</div>
		<div class="field">
			<label for="end_date">End date</label>
			<input type="date" id="end_date" name="end_date" value="<?php echo $escape($endDate); ?>" required>
		</div>
		<div class="field">
			<label for="campaign_id">Campaign</label>
			<select id="campaign_id" name="campaign_id">
				<option value="--ALL--">-- ALL CAMPAIGNS --</option>
				<?php foreach ($campaigns as $campaign): ?>
					<option value="<?php echo $escape($campaign['campaign_id']); ?>"<?php echo $campaignId === $campaign['campaign_id'] ? ' selected' : ''; ?>><?php echo $escape($campaign['campaign_id'] . ' - ' . $campaign['campaign_name']); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<button class="button" type="submit" name="fetch_recordings" value="1">Fetch recordings</button>
	</form>

	<?php if ($inputError !== ''): ?>
		<div class="error"><?php echo $escape($inputError); ?></div>
	<?php elseif ($fetchRecordings): ?>
		<p class="summary">Found <?php echo count($recordings); ?> recording(s). <?php echo $missingRecordings; ?> eligible call(s) had no matching recording.</p>
		<?php if ($recordings): ?>
			<section class="agent-section">
				<h2 class="title">Agent Coaching Summaries</h2>
				<p class="note">Each button analyzes all displayed recordings for that agent, then combines repeated patterns into one coaching summary.</p>
				<?php $agentIndex = 0; foreach ($agentGroups as $agent): $agentIndex++; ?>
					<div class="agent-card">
						<div class="agent-head">
							<div><h3 class="agent-name"><?php echo $escape($agent['agent_id'] . ' - ' . $agent['agent_name']); ?></h3><div class="agent-count"><?php echo count($agent['calls']); ?> recording(s) over two minutes</div></div>
							<button type="button" class="analyze-button agent-analyze-button" data-agent-id="<?php echo $escape($agent['agent_id']); ?>" data-agent-name="<?php echo $escape($agent['agent_name']); ?>" data-calls-id="agent-calls-<?php echo $agentIndex; ?>" data-result-id="agent-result-<?php echo $agentIndex; ?>">Analyze all recordings</button>
						</div>
						<script type="application/json" id="agent-calls-<?php echo $agentIndex; ?>"><?php echo json_encode($agent['calls'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
						<div class="agent-result" id="agent-result-<?php echo $agentIndex; ?>"></div>
					</div>
				<?php endforeach; ?>
			</section>

			<h2 class="title">Recording Inventory</h2>
			<div class="table-wrap"><table>
				<thead><tr><th>Call date</th><th>Agent</th><th>Campaign</th><th>Lead</th><th>Phone</th><th>Talk time</th><th>Recording</th><th>Audio</th></tr></thead>
				<tbody>
				<?php foreach ($recordings as $row): ?>
					<tr>
						<td><?php echo $escape($row['call_date']); ?></td>
						<td><?php echo $escape($row['agent_id'] . ' - ' . $row['agent_name']); ?></td>
						<td><?php echo $escape($row['campaign_id']); ?></td>
						<td><?php echo $escape($row['lead_id']); ?></td>
						<td><?php echo $escape($row['phone_number']); ?></td>
						<td><?php echo gmdate('H:i:s', (int) $row['talk_sec']); ?></td>
						<td><?php echo $escape($row['recording_id']); ?></td>
						<td><audio controls preload="none"><source src="qc_recording_stream.php?recording_id=<?php echo rawurlencode((string) $row['recording_id']); ?>"></audio></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php else: ?>
			<div class="empty">No matching recordings were found for the selected filters.</div>
		<?php endif; ?>
	<?php endif; ?>
</main>
<script>
(function () {
	function escapeHtml(value) {
		return String(value == null ? '' : value).replace(/[&<>'"]/g, function (character) {
			return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character];
		});
	}

	function compactCallResult(call, data) {
		var evaluation = data.evaluation || {};
		var sentiment = evaluation.sentiment_analysis || {};
		var definitions = {};
		(Array.isArray(data.checkpoints) ? data.checkpoints : []).forEach(function (definition) {
			definitions[String(definition.checkpoint_row_id || '')] = definition;
		});
		var passed = [];
		var improvements = [];
		(Array.isArray(evaluation.checkpoints) ? evaluation.checkpoints : []).forEach(function (result) {
			var status = String(result.status || '').toLowerCase();
			var definition = definitions[String(result.checkpoint_row_id || '')] || {};
			var finding = {
				checkpoint: String(definition.checkpoint_text || ''),
				status: String(result.status || ''),
				timestamp: String(result.timestamp || 'N/A'),
				evidence: String(result.evidence || ''),
				reason: String(result.reason || '')
			};
			if (status === 'pass') passed.push(finding);
			else if (status !== 'not applicable' && status !== 'n/a') improvements.push(finding);
		});
		return {
			agent_log_id: call.agent_log_id,
			recording_id: call.recording_id,
			call_date: call.call_date,
			overall_assessment: String(((sentiment.agent_coaching || {}).overall_assessment) || ''),
			behavior: sentiment.agent_behavior || {},
			empathy: sentiment.agent_empathy || {},
			strengths: passed.slice(0, 6),
			improvements: improvements.slice(0, 8),
			coaching_suggestions: Array.isArray((sentiment.agent_coaching || {}).suggestions) ? sentiment.agent_coaching.suggestions : []
		};
	}

	async function requestJson(url, payload) {
		var response = await fetch(url, {
			method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin',
			body: JSON.stringify(payload)
		});
		var text = await response.text();
		var data;
		try { data = JSON.parse(text); }
		catch (error) { throw new Error('The AI server returned an invalid response.'); }
		if (!response.ok || !data.success) throw new Error(data.message || 'The AI request failed.');
		return data;
	}

	function renderAgentSummary(panel, data, totalCalls) {
		var summary = data.summary || {};
		var strengths = Array.isArray(summary.repeated_strengths) ? summary.repeated_strengths : [];
		var improvements = Array.isArray(summary.improvement_areas) ? summary.improvement_areas : [];
		var plan = Array.isArray(summary.manager_coaching_plan) ? summary.manager_coaching_plan : [];
		var strengthHtml = strengths.map(function (item) {
			return '<li><strong>' + escapeHtml(item.theme || 'Strength') + '</strong> — ' + escapeHtml(item.explanation || '') + ' (' + escapeHtml(item.call_count || 0) + ' calls)</li>';
		}).join('');
		var improvementHtml = improvements.map(function (item) {
			return '<div class="coaching-item"><strong>Priority ' + escapeHtml(item.priority || '') + ': ' + escapeHtml(item.area || '') + '</strong>'
				+ '<p>' + escapeHtml(item.pattern || '') + ' (' + escapeHtml(item.call_count || 0) + ' calls)</p>'
				+ '<p><strong>Coaching action:</strong> ' + escapeHtml(item.action || '') + '</p>'
				+ '<p class="example"><strong>Example:</strong> “' + escapeHtml(item.example_phrase || '') + '”</p></div>';
		}).join('');
		var planHtml = plan.map(function (item) { return '<li>' + escapeHtml(item) + '</li>'; }).join('');
		panel.innerHTML = '<h3 class="analysis-title">Agent-Level Coaching Summary</h3>'
			+ '<p class="agent-count">Recordings found: ' + totalCalls + ' · Analyzed: ' + escapeHtml(data.calls_analyzed || 0) + ' · Failed: ' + escapeHtml(data.failed_calls || 0) + '</p>'
			+ '<div class="summary-box">' + escapeHtml(summary.overall_assessment || '') + '</div>'
			+ '<h3 class="analysis-title">Repeated strengths</h3><ul class="summary-list">' + (strengthHtml || '<li>No repeated strength was identified.</li>') + '</ul>'
			+ '<h3 class="analysis-title">Main areas to improve</h3>' + (improvementHtml || '<p>No repeated improvement pattern was identified.</p>')
			+ '<h3 class="analysis-title">Manager coaching plan</h3><ol class="summary-list">' + planHtml + '</ol>';
	}

	document.addEventListener('click', async function (event) {
		var button = event.target.closest('.agent-analyze-button');
		if (!button) return;
		var callsNode = document.getElementById(button.dataset.callsId);
		var panel = document.getElementById(button.dataset.resultId);
		if (!callsNode || !panel) return;
		var calls = JSON.parse(callsNode.textContent || '[]');
		var completed = [];
		var failed = 0;
		button.disabled = true;
		button.textContent = 'Analyzing...';

		try {
			for (var index = 0; index < calls.length; index++) {
				var percent = Math.round((index / calls.length) * 100);
				panel.innerHTML = '<div class="analysis-status">Analyzing recording ' + (index + 1) + ' of ' + calls.length + '...</div><div class="progress-track"><div class="progress-fill" style="width:' + percent + '%"></div></div>';
				try {
					var callData = await requestJson('analyze_long_call_recording.php', {
						agent_log_id: calls[index].agent_log_id,
						recording_id: calls[index].recording_id
					});
					completed.push(compactCallResult(calls[index], callData));
				} catch (callError) {
					failed++;
				}
			}
			if (completed.length === 0) throw new Error('None of this agent’s recordings could be analyzed.');
			panel.innerHTML = '<div class="analysis-status">Combining ' + completed.length + ' call analyses into one coaching summary...</div><div class="progress-track"><div class="progress-fill" style="width:100%"></div></div>';
			var summaryData = await requestJson('aggregate_agent_coaching.php', {
				agent_id: button.dataset.agentId,
				agent_name: button.dataset.agentName,
				call_results: completed,
				failed_calls: failed
			});
			renderAgentSummary(panel, summaryData, calls.length);
			button.textContent = 'Analyze again';
		} catch (error) {
			panel.innerHTML = '<div class="analysis-error">' + escapeHtml(error.message || 'The agent summary could not be generated.') + '</div>';
			button.textContent = 'Analyze all recordings';
		} finally {
			button.disabled = false;
		}
	});
})();
</script>
</body>
</html>
