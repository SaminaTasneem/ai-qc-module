<?php


# AST_agent_rapport_summary.php
# Updated Theme: Missed Call Report Style (Dark/Neon)
# 
# Copyright (C) 2023  Matt Florell <vicidial@gmail.com>    LICENSE: AGPLv2

$startMS = microtime(true);
require("dbconnect_mysqli.php");
require("functions.php");
mysqli_query($link, "SET SESSION group_concat_max_len = 1000000;");
// require("session_auth.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI']);
    header("Location: admin.php?redirect=$redirect");
    exit;
}

$PHP_AUTH_USER = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
$PHP_AUTH_PW   = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';

if (empty($PHP_AUTH_USER) && !empty($_SESSION['user'])) {
    $PHP_AUTH_USER = $_SESSION['user'];

    $stmt = "SELECT pass FROM vicidial_users WHERE user='" . mysqli_real_escape_string($link, $PHP_AUTH_USER) . "' LIMIT 1";
    $rslt = mysqli_query($link, $stmt);
    if ($rslt && mysqli_num_rows($rslt) > 0) {
        $row = mysqli_fetch_row($rslt);
        $PHP_AUTH_PW = $row[0];
        $_SERVER['PHP_AUTH_USER'] = $PHP_AUTH_USER;
        $_SERVER['PHP_AUTH_PW']   = $PHP_AUTH_PW;
    }
}

$PHP_SELF=$_SERVER['PHP_SELF'];
$PHP_SELF = preg_replace('/\.php.*/i','.php',$PHP_SELF);
if (isset($_GET["group"]))				{$group=$_GET["group"];}
	elseif (isset($_POST["group"]))		{$group=$_POST["group"];}
if (isset($_GET["query_date"]))				{$query_date=$_GET["query_date"];}
	elseif (isset($_POST["query_date"]))	{$query_date=$_POST["query_date"];}
if (isset($_GET["end_date"]))			{$end_date=$_GET["end_date"];}
	elseif (isset($_POST["end_date"]))	{$end_date=$_POST["end_date"];}
if (isset($_GET["shift"]))				{$shift=$_GET["shift"];}
	elseif (isset($_POST["shift"]))		{$shift=$_POST["shift"];}
if (isset($_GET["submit"]))				{$submit=$_GET["submit"];}
	elseif (isset($_POST["submit"]))	{$submit=$_POST["submit"];}
if (isset($_GET["SUBMIT"]))				{$SUBMIT=$_GET["SUBMIT"];}
	elseif (isset($_POST["SUBMIT"]))	{$SUBMIT=$_POST["SUBMIT"];}
if (isset($_GET["DID"]))				{$DID=$_GET["DID"];}
	elseif (isset($_POST["DID"]))		{$DID=$_POST["DID"];}
if (isset($_GET["EMAIL"]))				{$EMAIL=$_GET["EMAIL"];}
	elseif (isset($_POST["EMAIL"]))		{$EMAIL=$_POST["EMAIL"];}
if (isset($_GET["DB"]))					{$DB=$_GET["DB"];}
	elseif (isset($_POST["DB"]))		{$DB=$_POST["DB"];}
if (isset($_GET["file_download"]))			{$file_download=$_GET["file_download"];}
	elseif (isset($_POST["file_download"]))	{$file_download=$_POST["file_download"];}
if (isset($_GET["report_display_type"]))			{$report_display_type=$_GET["report_display_type"];}
	elseif (isset($_POST["report_display_type"]))	{$report_display_type=$_POST["report_display_type"];}
if (isset($_GET["search_archived_data"]))			{$search_archived_data=$_GET["search_archived_data"];}
	elseif (isset($_POST["search_archived_data"]))	{$search_archived_data=$_POST["search_archived_data"];}

$DB=preg_replace("/[^0-9a-zA-Z]/","",$DB);

$stmt = "SELECT user_level, view_reports FROM vicidial_users WHERE user='$PHP_AUTH_USER' LIMIT 1;";
$rslt = mysql_to_mysqli($stmt, $link);

$LOGuser_level = 0;
$LOGview_reports = 0;

if ($rslt && mysqli_num_rows($rslt) > 0) {
    $row = mysqli_fetch_row($rslt);
    $LOGuser_level = (int)$row[0];
    $LOGview_reports = (int)$row[1];
}



$report_name = 'Conversation Score';
$db_source = 'M';

// Input sanitization
$begin_date = isset($_GET["begin_date"]) ? preg_replace('/[^- \:\_0-9a-zA-Z]/', '', $_GET["begin_date"]) : date("Y-m-d");
$end_date = isset($_GET["end_date"]) ? preg_replace('/[^- \:\_0-9a-zA-Z]/', '', $_GET["end_date"]) : date("Y-m-d");
$campaign_id = isset($_GET["campaign_id"]) ? preg_replace('/[^-_0-9a-zA-Z]/', '', $_GET["campaign_id"]) : "--ALL--";

#############################################
##### HTML HEADER (Missed Call Style) #####
#############################################
$HEADER = "<html>\n<head>\n";
$HEADER .= "<META HTTP-EQUIV=\"Content-Type\" CONTENT=\"text/html; charset=utf-8\">\n";
$HEADER .= "<title>$report_name</title>\n";
$HEADER .= "<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { 
        background-color: #0a0e27; 
        background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
        text-align: center; 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        padding: 20px; 
        color: #fff;
        min-height: 100vh;
    }
    h1 { 
        color: #00ffcc; 
        margin-bottom: 20px; 
        font-size: 2.5em;
        text-transform: uppercase;
        letter-spacing: 2px;
        text-shadow: 0 0 20px rgba(0, 255, 204, 0.5);
    }
    h3 { color: #00ffcc; margin: 20px 0; font-size: 1.2em; }
    
    .note-box { font-size: 14px; color: rgba(255, 255, 255, 0.7); margin-bottom: 20px; }

    form { 
        background: rgba(26, 31, 58, 0.8); 
        backdrop-filter: blur(10px);
        padding: 20px 30px; 
        border-radius: 12px; 
        box-shadow: 0 8px 32px rgba(0, 255, 204, 0.1);
        border: 1px solid rgba(0, 255, 204, 0.2);
        display: inline-block; 
        margin-bottom: 30px; 
    }
    select, input[type='date'],input[type='text'] { 
        padding: 10px 15px; 
        border: 1px solid rgba(0, 255, 204, 0.3); 
        border-radius: 6px; 
        margin: 5px; 
        background: rgba(10, 14, 39, 0.6);
        color: #00ffcc;
        font-size: 14px;
        transition: all 0.3s ease;
        appearance: none; /* Removes default browser styling */
        -webkit-appearance: none;
    }
    select:focus, input[type='text']:focus {
        outline: none;
        border-color: #00ffcc;
        box-shadow: 0 0 15px rgba(0, 255, 204, 0.3);
    }
    select option {
        background: #1a1f3a; /* Matches the form background */
        color: #00ffcc;
        padding: 10px;
    }
    select::-webkit-scrollbar {
        width: 8px;
    }
    select::-webkit-scrollbar-thumb {
        background: #00ffcc;
        border-radius: 10px;
    }
    input[type='submit'] { 
        background: linear-gradient(135deg, #00ffcc 0%, #00d4aa 100%); 
        color: #0a0e27; 
        border: none; padding: 12px 30px; 
        border-radius: 6px; cursor: pointer; 
        font-weight: bold; margin: 5px;
        text-transform: uppercase; letter-spacing: 1px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(0, 255, 204, 0.3);
    }
    input[type='submit']:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0, 255, 204, 0.5); }

    table { 
        border-collapse: collapse; 
        width: 95%; margin: 20px auto; 
        background: rgba(26, 31, 58, 0.6); 
        backdrop-filter: blur(10px);
        border-radius: 12px; overflow: hidden;
        border: 1px solid rgba(0, 255, 204, 0.2);
    }
    th { 
        background: linear-gradient(135deg, rgba(0, 255, 204, 0.2) 0%, rgba(0, 212, 170, 0.2) 100%);
        color: #00ffcc; 
        padding: 15px 10px; 
        text-transform: uppercase; 
        font-size: 14px; /* Increased from 12px */
        font-weight: bold; /* Added for visibility */
        border-bottom: 2px solid rgba(0, 255, 204, 0.3);
    }
    td { 
        padding: 14px 10px; 
        border-bottom: 1px solid rgba(0, 255, 204, 0.1); 
        color: #ffffff; /* Changed from grey to pure white for visibility */
        font-size: 15px; /* Increased from 13px */
        font-weight: 500; 
    }
    /* This makes the phone numbers inside the table legible too */
    td small {
        font-size: 14px;
        color: #00ffcc; /* Makes the phone list stand out in neon teal */
    }
    td.phone-links a,
    td.phone-links a:visited {
        color: #00ffcc;
        text-decoration-color: rgba(0, 255, 204, 0.55);
        text-underline-offset: 3px;
    }
    td.phone-links a:hover,
    td.phone-links a:focus {
        color: #7fffe6;
        text-decoration-color: #7fffe6;
    }
    tr:hover { background-color: rgba(0, 255, 204, 0.2); }

    .btn-back { 
        display: inline-block; margin-bottom: 20px; text-decoration: none; 
        background: linear-gradient(135deg, #00ffcc 0%, #00d4aa 100%); 
        color: #0a0e27; padding: 10px 20px; border-radius: 6px; 
        font-size: 14px; font-weight: bold; text-transform: uppercase;
        box-shadow: 0 4px 15px rgba(0, 255, 204, 0.3);
    }
    small { color: rgba(255, 255, 255, 0.5); font-size: 12px; }
    .coaching-count, .coaching-status { color: rgba(255,255,255,.7); margin-top: 5px; }
    .coaching-button { padding: 10px 16px; border: 1px solid #00ffcc; border-radius: 6px; background: transparent; color: #00ffcc; font-weight: 800; cursor: pointer; white-space: nowrap; }
    .coaching-button:disabled { opacity: .55; cursor: wait; }
    .coaching-reanalyze { margin-top: 18px; }
    .coaching-unavailable { color: rgba(255,255,255,.5); font-size: 13px; }
    .coaching-detail-row { display: none; background: rgba(5,27,36,.95); }
    .coaching-detail-row:hover { background: rgba(5,27,36,.95); }
    .coaching-detail-row > td { padding: 0; text-align: left; }
    .coaching-result { padding: 18px 24px 24px; }
    .coaching-result:empty { display: none; }
    .coaching-error { color: #ff8797; }
    .coaching-title { margin: 20px 0 10px; color: #00ffcc; }
    .coaching-summary { padding: 16px; border-left: 3px solid #00ffcc; background: rgba(8,45,54,.8); }
    .coaching-list { margin: 8px 0; padding-left: 24px; }
    .coaching-item { margin: 12px 0; padding: 14px; border: 1px solid rgba(0,255,204,.3); border-radius: 8px; background: rgba(8,45,54,.8); }
    .coaching-item p { margin: 6px 0; }
    .coaching-example { font-style: italic; color: #c8dbdf; }
    .coaching-progress { height: 8px; margin-top: 10px; background: #102e39; border-radius: 10px; overflow: hidden; }
    .coaching-progress-fill { height: 100%; background: #00ffcc; transition: width .2s; }
</style>\n";
$HEADER .= "</head>\n<body>\n";

// Back button
$MAIN = "<div style='text-align:left; max-width: 95%; margin: 0 auto;'><a href='admin.php' class='btn-back'>&laquo; Back to Reports</a></div>\n";
$MAIN .= "<h1>$report_name</h1>\n";

// Search Form
$MAIN .= "<form action='' method='GET' name='vicidial_report' id='vicidial_report'>\n";
$MAIN .= "Start Date: <input type='text' name='begin_date' value='$begin_date' size='10'>\n";
$MAIN .= "End Date: <input type='text' name='end_date' value='$end_date' size='10'>\n";
$MAIN .= " Campaign: <select name='campaign_id'>\n";
$MAIN .= "<option value='--ALL--'>-- ALL CAMPAIGNS --</option>\n";

$stmt = "SELECT campaign_id, campaign_name FROM vicidial_campaigns;";
$rslt = mysql_to_mysqli($stmt, $link);
while ($row = mysqli_fetch_assoc($rslt)) {
    $selected = ($campaign_id == $row['campaign_id']) ? "selected" : "";
    $MAIN .= "<option value='" . $row['campaign_id'] . "' $selected>" . $row['campaign_id'] . " - " . $row['campaign_name'] . "</option>\n";
}
$MAIN .= "</select>\n";
$MAIN .= "<input type='submit' name='submit' value='Submit'>\n";
$MAIN .= "</form>\n";

if (isset($_GET["submit"])) {
    $query_condition = ($campaign_id != "--ALL--") ? "AND val.campaign_id = '$campaign_id'" : "";

    $stmt = "
    SELECT 
        val.user, 
        vu.full_name, 
        SUM(CASE WHEN val.talk_sec > 2 THEN 1 ELSE 0 END) as total_calls, 
        SUM(CASE WHEN val.talk_sec > 120 THEN 1 ELSE 0 END) as calls_over_two_minutes,
        GROUP_CONCAT(
                CASE WHEN val.talk_sec > 120
                THEN CONCAT_WS('|||',
                    IFNULL(vls.phone_number, 'NoNum'),
                    IFNULL(NULLIF(vls.status, ''), IFNULL(NULLIF(val.status, ''), 'NEW')),
                    IFNULL(val.agent_log_id, ''),
                    IFNULL(val.lead_id, ''),
                    IFNULL(val.campaign_id, ''),
                    IFNULL(NULLIF(val.status, ''), 'NEW'),
                    IFNULL(val.uniqueid, ''),
                    IFNULL(val.event_time, '')
                )
                ELSE NULL END SEPARATOR '###'
            ) as calls_over_two_minutes_details,
        SUM(CASE WHEN val.talk_sec > 120 AND (val.status = 'SALE' OR vls.status = 'SALE') THEN 1 ELSE 0 END) as sales_made
    FROM 
        vicidial_agent_log val
    LEFT JOIN 
        vicidial_users vu ON val.user = vu.user
    LEFT JOIN
        vicidial_list vls ON val.lead_id = vls.lead_id
    WHERE 
        val.event_time >= '$begin_date 00:00:01' 
        AND val.event_time <= '$end_date 23:59:59' 
        $query_condition
    GROUP BY 
        val.user;
    ";

    $rslt = mysql_to_mysqli($stmt, $link);

    $MAIN .= "<h3>Rapport Summary for Campaign: $campaign_id</h3>";
    $MAIN .= "<div class='note-box'><em><strong>* Note: The Total Calls count represents only calls with human conversation.</strong></em></div>";
    $MAIN .= "<div class='note-box'><em><strong>* Note: Clicking on a phone number will take you to the Quality control page.</strong></em></div>";

    $MAIN .= "<table>";
    $MAIN .= "<tr><th>User</th><th>Agent Name</th><th>* Total Calls</th><th>Calls > 2 Min</th><th>% Over 2 Min</th><th>Rapport Verdict</th><th>Phones (Calls > 2 Min)</th><th>Sales</th><th>Agent Coaching</th></tr>";

    $csv_data = [];
    $csv_data[] = ["User", "Agent Name", "Total Calls (human-conversation only)", "Calls > 2 Min", "Percentage", "Rapport Verdict", "Phones with Disposition (Calls > 2 Min)", "Sales"];
    $coaching_agents = [];
    $coaching_index = 0;
    $recording_lookup = mysqli_prepare($link, "SELECT recording_id FROM recording_log WHERE lead_id=? ORDER BY IF(vicidial_id=?,1,0) DESC, ABS(TIMESTAMPDIFF(SECOND,start_time,?)) ASC LIMIT 1");

    while ($row = mysqli_fetch_assoc($rslt)) {
        $user = $row['user'];
        $full_name = $row['full_name'];
        $total_calls = $row['total_calls'];
        $calls_over_two_minutes = $row['calls_over_two_minutes'];
        $phone_links = [];
        $phone_csv_entries = [];
        if (!empty($row['calls_over_two_minutes_details'])) {
            foreach (explode('###', $row['calls_over_two_minutes_details']) as $call_detail) {
                $parts = explode('|||', $call_detail, 8);
                if (count($parts) !== 8) {
                    continue;
                }

                [$phone_number, $display_status, $agent_log_id, $lead_id, $call_campaign_id, $qc_status, $unique_id, $call_date] = $parts;
                $phone_label = $phone_number . ' (' . $display_status . ')';
                $phone_csv_entries[] = $phone_label;

                if ($recording_lookup !== false && $agent_log_id !== '' && $lead_id !== '' && $call_date !== '') {
                    mysqli_stmt_bind_param($recording_lookup, 'sss', $lead_id, $unique_id, $call_date);
                    if (mysqli_stmt_execute($recording_lookup)) {
                        $recording_result = mysqli_stmt_get_result($recording_lookup);
                        $recording_row = $recording_result !== false ? mysqli_fetch_assoc($recording_result) : null;
                        if (is_array($recording_row) && !empty($recording_row['recording_id'])) {
                            $coaching_agents[$user]['agent_id'] = (string)$user;
                            $coaching_agents[$user]['agent_name'] = (string)$full_name;
                            $coaching_agents[$user]['calls'][] = [
                                'agent_log_id' => (string)$agent_log_id,
                                'recording_id' => (string)$recording_row['recording_id'],
                                'call_date' => (string)$call_date,
                            ];
                        }
                    }
                }

                if ($phone_number === 'NoNum' || $agent_log_id === '' || $lead_id === '' || $call_campaign_id === '') {
                    $phone_links[] = htmlspecialchars($phone_label, ENT_QUOTES, 'UTF-8');
                    continue;
                }

                $qc_url = 'qc_modify_lead.php?' . http_build_query([
                    'claim_QC' => 'CLAIM',
                    'qc_display_method' => 'CALL',
                    'qc_display_group_type' => 'CAMPAIGN',
                    'agent_log_id' => $agent_log_id,
                    'qc_status' => $qc_status,
                    'lead_id' => $lead_id,
                    'campaign_id' => $call_campaign_id,
                    'referring_section' => 'CAMPAIGN',
                    'referring_element' => $call_campaign_id,
                ]);
                $phone_links[] = '<a href="' . htmlspecialchars($qc_url, ENT_QUOTES, 'UTF-8') . '">'
                    . htmlspecialchars($phone_label, ENT_QUOTES, 'UTF-8') . '</a>';
            }
        }
        $phone_numbers_html = $phone_links ? implode(', ', $phone_links) : 'None';
        $phone_numbers_csv = $phone_csv_entries ? implode(', ', $phone_csv_entries) : 'None';
        $percentage_over_two_minutes = ($total_calls > 0) ? ($calls_over_two_minutes / $total_calls) * 100 : 0;
        $rapport_verdict = ($calls_over_two_minutes < 3) ? "Needs Improvement" : "Decent Rapport Builder";
        $sales_made = $row['sales_made'];
		$agent_calls = $coaching_agents[$user]['calls'] ?? [];
		$coaching_button = "<span class='coaching-unavailable'>No recording</span>";
		$coaching_detail = '';
		if (!empty($agent_calls)) {
			$coaching_index++;
			$calls_id = "rapport-coaching-calls-$coaching_index";
			$result_id = "rapport-coaching-result-$coaching_index";
			$row_id = "rapport-coaching-row-$coaching_index";
			$button_id = "rapport-coaching-button-$coaching_index";
			$agent_id_html = htmlspecialchars((string)$user, ENT_QUOTES, 'UTF-8');
			$agent_name_html = htmlspecialchars((string)$full_name, ENT_QUOTES, 'UTF-8');
			$calls_json = json_encode($agent_calls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
			$coaching_button = "<button type='button' id='$button_id' class='coaching-button rapport-coaching-button' data-agent-id='$agent_id_html' data-agent-name='$agent_name_html' data-calls-id='$calls_id' data-result-id='$result_id' data-row-id='$row_id'>Analyze</button>";
			$coaching_detail = "<tr class='coaching-detail-row' id='$row_id'><td colspan='9'><script type='application/json' id='$calls_id'>$calls_json</script><div class='coaching-result' id='$result_id'></div></td></tr>";
		}

        $MAIN .= "<tr>";
        $MAIN .= "<td>$user</td><td>$full_name</td><td>$total_calls</td><td>$calls_over_two_minutes</td>";
        $MAIN .= "<td>" . number_format($percentage_over_two_minutes, 2) . "%</td>";
        $MAIN .= "<td>$rapport_verdict</td><td class='phone-links'><small>$phone_numbers_html</small></td><td>$sales_made</td><td>$coaching_button</td>";
        $MAIN .= "</tr>";
		$MAIN .= $coaching_detail;

        $csv_data[] = [$user, $full_name, $total_calls, $calls_over_two_minutes, number_format($percentage_over_two_minutes, 2) . "%", $rapport_verdict, $phone_numbers_csv, $sales_made];
    }
    if ($recording_lookup !== false) {
        mysqli_stmt_close($recording_lookup);
    }
    $MAIN .= "</table>";

    $MAIN .= "<br><form method='POST' action='' style='background:none; border:none; box-shadow:none;'>
                <input type='hidden' name='csv_data' value='" . base64_encode(serialize($csv_data)) . "'>
                <input type='submit' name='download_csv' value='Download CSV Report' >
              </form>";
}

#############################################
##### HANDLE CSV DOWNLOAD REQUEST #####
#############################################
if (isset($_POST['download_csv'])) {
    $csv_data = unserialize(base64_decode($_POST['csv_data']));
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Rapport_Summary.csv"');
    $output = fopen('php://output', 'w');
    foreach ($csv_data as $row) { fputcsv($output, $row); }
    fclose($output);
    exit;
}

$RUNtime = round(microtime(true) - $startMS, 2);
$MAIN .= "<br><br><small>Script runtime: $RUNtime seconds</small>";
$coaching_filters_json = json_encode([
    'begin_date' => $begin_date,
    'end_date' => $end_date,
    'campaign_id' => $campaign_id,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$MAIN .= "<script>window.rapportCoachingFilters=" . ($coaching_filters_json ?: '{}') . ";</script>";
$MAIN .= <<<'COACHING_SCRIPT'
<script>
(function () {
    function escapeCoachingHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>'"]/g, function (character) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character];
        });
    }
    async function coachingRequest(url, payload) {
        var response = await fetch(url, {
            method: 'POST', headers: {'Content-Type':'application/json'}, credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        var text = await response.text();
        var data;
        try { data = JSON.parse(text); }
        catch (error) {
            var preview = text.trim().replace(/\s+/g, ' ').slice(0, 160);
            throw new Error(url + ' returned HTTP ' + response.status + ' instead of JSON.' + (preview ? ' Response: ' + preview : ' The response was empty.'));
        }
        if (!response.ok || !data.success) throw new Error(data.message || 'The AI request failed.');
        return data;
    }
    function compactCoachingCall(call, data) {
        var evaluation = data.evaluation || {};
        var sentiment = evaluation.sentiment_analysis || {};
        var definitions = {};
        (Array.isArray(data.checkpoints) ? data.checkpoints : []).forEach(function (item) {
            definitions[String(item.checkpoint_row_id || '')] = item;
        });
        var strengths = [], improvements = [];
        (Array.isArray(evaluation.checkpoints) ? evaluation.checkpoints : []).forEach(function (item) {
            var status = String(item.status || '').toLowerCase();
            var definition = definitions[String(item.checkpoint_row_id || '')] || {};
            var finding = {
                checkpoint: String(definition.checkpoint_text || ''), status: String(item.status || ''),
                timestamp: String(item.timestamp || 'N/A'), evidence: String(item.evidence || ''), reason: String(item.reason || '')
            };
            if (status === 'pass') strengths.push(finding);
            else if (status !== 'not applicable' && status !== 'n/a') improvements.push(finding);
        });
        return {
            agent_log_id: call.agent_log_id, recording_id: call.recording_id, call_date: call.call_date,
            overall_assessment: String(((sentiment.agent_coaching || {}).overall_assessment) || ''),
            behavior: sentiment.agent_behavior || {}, empathy: sentiment.agent_empathy || {},
            strengths: strengths.slice(0, 6), improvements: improvements.slice(0, 8),
            coaching_suggestions: Array.isArray((sentiment.agent_coaching || {}).suggestions) ? sentiment.agent_coaching.suggestions : []
        };
    }
    function renderCoachingSummary(panel, data, total, analyzeButton) {
        var summary = data.summary || {};
        var strengths = Array.isArray(summary.repeated_strengths) ? summary.repeated_strengths : [];
        var improvements = Array.isArray(summary.improvement_areas) ? summary.improvement_areas : [];
        var plan = Array.isArray(summary.manager_coaching_plan) ? summary.manager_coaching_plan : [];
        var strengthHtml = strengths.map(function (item) {
            return '<li><strong>' + escapeCoachingHtml(item.theme || 'Strength') + '</strong> — ' + escapeCoachingHtml(item.explanation || '') + ' (' + escapeCoachingHtml(item.call_count || 0) + ' calls)</li>';
        }).join('');
        var improvementHtml = improvements.map(function (item) {
            return '<div class="coaching-item"><strong>Priority ' + escapeCoachingHtml(item.priority || '') + ': ' + escapeCoachingHtml(item.area || '') + '</strong>'
                + '<p>' + escapeCoachingHtml(item.pattern || '') + ' (' + escapeCoachingHtml(item.call_count || 0) + ' calls)</p>'
                + '<p><strong>Coaching action:</strong> ' + escapeCoachingHtml(item.action || '') + '</p>'
                + '<p class="coaching-example"><strong>Example:</strong> “' + escapeCoachingHtml(item.example_phrase || '') + '”</p></div>';
        }).join('');
        var planHtml = plan.map(function (item) { return '<li>' + escapeCoachingHtml(item) + '</li>'; }).join('');
        panel.innerHTML = '<h3 class="coaching-title">Agent-Level Coaching Summary</h3>'
            + '<p class="coaching-count">Recordings found: ' + total + ' · Analyzed: ' + escapeCoachingHtml(data.calls_analyzed || 0) + ' · Failed: ' + escapeCoachingHtml(data.failed_calls || 0) + ' · Saved report #' + escapeCoachingHtml(data.coaching_id || '') + '</p>'
            + '<div class="coaching-summary">' + escapeCoachingHtml(summary.overall_assessment || '') + '</div>'
            + '<h3 class="coaching-title">Repeated strengths</h3><ul class="coaching-list">' + (strengthHtml || '<li>No repeated strength was identified.</li>') + '</ul>'
            + '<h3 class="coaching-title">Main areas to improve</h3>' + (improvementHtml || '<p>No repeated improvement pattern was identified.</p>')
            + '<h3 class="coaching-title">Manager coaching plan</h3><ol class="coaching-list">' + planHtml + '</ol>'
            + '<button type="button" class="coaching-button coaching-reanalyze rapport-reanalyze-button" data-analyze-button-id="' + escapeCoachingHtml(analyzeButton.id) + '">Reanalyze</button>';
    }
    document.addEventListener('click', async function (event) {
        var reanalyzeButton = event.target.closest('.rapport-reanalyze-button');
        if (reanalyzeButton) {
            var originalButton = document.getElementById(reanalyzeButton.dataset.analyzeButtonId);
            if (!originalButton) return;
            var originalPanel = document.getElementById(originalButton.dataset.resultId);
            if (originalPanel) originalPanel.dataset.complete = '';
            originalButton.dataset.forceReanalyze = '1';
            originalButton.click();
            return;
        }
        var button = event.target.closest('.rapport-coaching-button');
        if (!button) return;
        var callsNode = document.getElementById(button.dataset.callsId);
        var panel = document.getElementById(button.dataset.resultId);
        var detailRow = document.getElementById(button.dataset.rowId);
        if (!callsNode || !panel || !detailRow) return;
        if (panel.dataset.complete === '1') {
            var isVisible = window.getComputedStyle(detailRow).display !== 'none';
            detailRow.style.display = isVisible ? 'none' : 'table-row';
            button.textContent = isVisible ? 'Show coaching' : 'Hide coaching';
            return;
        }
        detailRow.style.display = 'table-row';
        var calls = JSON.parse(callsNode.textContent || '[]');
        var completed = [], failed = 0;
		var forceReanalyze = button.dataset.forceReanalyze === '1';
		button.dataset.forceReanalyze = '';
        button.disabled = true;
        button.textContent = forceReanalyze ? 'Reanalyzing...' : 'Checking...';
        try {
			if (!forceReanalyze) {
                panel.innerHTML = '<div class="coaching-status">Checking for an existing coaching summary...</div>';
                var savedResult = await coachingRequest('get_agent_coaching_summary.php', {
                    agent_id: button.dataset.agentId,
                    begin_date: window.rapportCoachingFilters.begin_date,
                    end_date: window.rapportCoachingFilters.end_date,
                    campaign_id: window.rapportCoachingFilters.campaign_id,
                    recording_ids: calls.map(function (call) { return call.recording_id; })
                });
                if (savedResult.found) {
                    renderCoachingSummary(panel, savedResult, calls.length, button);
                    panel.dataset.complete = '1';
                    button.textContent = 'Hide coaching';
                    return;
                }
            }
            button.textContent = 'Analyzing...';
            for (var index = 0; index < calls.length; index++) {
                var percent = Math.round((index / calls.length) * 100);
                panel.innerHTML = '<div class="coaching-status">Analyzing recording ' + (index + 1) + ' of ' + calls.length + '...</div><div class="coaching-progress"><div class="coaching-progress-fill" style="width:' + percent + '%"></div></div>';
                try {
                    var callData = await coachingRequest('analyze_long_call_recording.php', {
                        agent_log_id: calls[index].agent_log_id, recording_id: calls[index].recording_id
                    });
                    completed.push(compactCoachingCall(calls[index], callData));
                } catch (callError) { failed++; }
            }
            if (!completed.length) throw new Error('None of this agent’s recordings could be analyzed.');
            panel.innerHTML = '<div class="coaching-status">Combining ' + completed.length + ' call analyses...</div><div class="coaching-progress"><div class="coaching-progress-fill" style="width:100%"></div></div>';
            var result = await coachingRequest('aggregate_agent_coaching.php', {
                agent_id: button.dataset.agentId, agent_name: button.dataset.agentName,
                begin_date: window.rapportCoachingFilters.begin_date,
                end_date: window.rapportCoachingFilters.end_date,
                campaign_id: window.rapportCoachingFilters.campaign_id,
                recordings_found: calls.length,
                recording_ids: calls.map(function (call) { return call.recording_id; }),
                reanalyze: forceReanalyze,
                call_results: completed, failed_calls: failed
            });
            renderCoachingSummary(panel, result, calls.length, button);
            panel.dataset.complete = '1';
            button.textContent = 'Hide coaching';
        } catch (error) {
            panel.innerHTML = '<div class="coaching-error">' + escapeCoachingHtml(error.message || 'The agent summary could not be generated.') + '</div>';
            button.textContent = 'Try again';
        } finally { button.disabled = false; }
    });
})();
</script>
COACHING_SCRIPT;
$MAIN .= "</body></html>";

echo $HEADER;
echo $MAIN;
?>
