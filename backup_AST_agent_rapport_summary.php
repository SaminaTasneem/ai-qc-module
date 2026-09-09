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
                    IFNULL(NULLIF(val.status, ''), 'NEW')
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
    $MAIN .= "<tr><th>User</th><th>Agent Name</th><th>* Total Calls</th><th>Calls > 2 Min</th><th>% Over 2 Min</th><th>Rapport Verdict</th><th>Phones (Calls > 2 Min)</th><th>Sales</th></tr>";

    $csv_data = [];
    $csv_data[] = ["User", "Agent Name", "Total Calls (human-conversation only)", "Calls > 2 Min", "Percentage", "Rapport Verdict", "Phones with Disposition (Calls > 2 Min)", "Sales"];

    while ($row = mysqli_fetch_assoc($rslt)) {
        $user = $row['user'];
        $full_name = $row['full_name'];
        $total_calls = $row['total_calls'];
        $calls_over_two_minutes = $row['calls_over_two_minutes'];
        $phone_links = [];
        $phone_csv_entries = [];
        if (!empty($row['calls_over_two_minutes_details'])) {
            foreach (explode('###', $row['calls_over_two_minutes_details']) as $call_detail) {
                $parts = explode('|||', $call_detail, 6);
                if (count($parts) !== 6) {
                    continue;
                }

                [$phone_number, $display_status, $agent_log_id, $lead_id, $call_campaign_id, $qc_status] = $parts;
                $phone_label = $phone_number . ' (' . $display_status . ')';
                $phone_csv_entries[] = $phone_label;

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

        $MAIN .= "<tr>";
        $MAIN .= "<td>$user</td><td>$full_name</td><td>$total_calls</td><td>$calls_over_two_minutes</td>";
        $MAIN .= "<td>" . number_format($percentage_over_two_minutes, 2) . "%</td>";
        $MAIN .= "<td>$rapport_verdict</td><td class='phone-links'><small>$phone_numbers_html</small></td><td>$sales_made</td>";
        $MAIN .= "</tr>";

        $csv_data[] = [$user, $full_name, $total_calls, $calls_over_two_minutes, number_format($percentage_over_two_minutes, 2) . "%", $rapport_verdict, $phone_numbers_csv, $sales_made];
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
$MAIN .= "</body></html>";

echo $HEADER;
echo $MAIN;
?>
