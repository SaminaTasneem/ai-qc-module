<?php
# qc_modify_lead.php modified from: (by poundteam)
//admin_modify_lead.php   version 2.14
#
# ViciDial database administration modify lead in vicidial_list
# qc_modify_lead.php
# 
# Copyright (C) 2012  poundteam.com    LICENSE: AGPLv2
# Copyright (C) 2022  Matt Florell <vicidial@gmail.com>    LICENSE: AGPLv2
#
# This script is designed to allow QC review and modification of leads, contributed by poundteam.com
#
# changes:
# 121116-1324 - First build, added to vicidial codebase
# 121130-1034 - Changed scheduled callback user ID field to be 20 characters, issue #467
# 130621-2328 - Finalized changing of all ereg instances to preg
#             - Added filtering of input to prevent SQL injection attacks and new user auth
# 130902-0904 - Changed to mysqli PHP functions, fixes for issue #699
# 140706-0837 - Incorporated into standard admin code
# 141007-2152 - Finalized adding QXZ translation to all admin files
# 141128-0902 - Code cleanup for QXZ functions
# 141229-1742 - Added code for on-the-fly language translations display
# 150808-1437 - Added compatibility for custom fields data options
# 150908-1531 - Fixed input lengths for several standard fields to match DB
# 150917-1311 - Added dynamic default field maxlengths based on DB schema
# 160611-1217 - Fixed for external server IP recording link issue
# 170409-1542 - Added IP List validation code
# 170513-2256 - Added QC Webform, issue #1010
# 170527-2252 - Fix for rare inbound logging issue #1017
# 171001-1110 - Added in-browser audio control, if recording access control is disabled
# 210304-1650 - Added modify_leads=5 read-only option
# 210306-0854 - Redesign of QC module
# 210827-1818 - Fix for security issue
# 220224-2143 - Added allow_web_debug system setting
#

require("dbconnect_mysqli.php");
require("functions.php");

// admin.php authenticates users with this session. Reuse it here so opening a
// QC record from the admin interface does not trigger a second Basic Auth login.
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

$has_admin_session = !empty($_SESSION['user']);

// QC pages are part of the custom admin application and must be entered through
// its authenticated session. Direct visits should use the normal admin login
// instead of falling back to VICIdial's browser-level Basic Authentication.
if (!$has_admin_session) {
	header('Location: admin.php');
	exit;
}

$PHP_AUTH_USER = isset($_SESSION['user'])
	? (string) $_SESSION['user']
	: (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
$PHP_AUTH_PW = isset($_SESSION['pass'])
	? (string) $_SESSION['pass']
	: (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

// Save the overall QC comment without leaving or finishing the QC record.
if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& ($_GET['qc_action'] ?? '') === 'save_agent_comment'
) {
	header('Content-Type: application/json; charset=utf-8');

	$comment_response = static function (int $status, array $payload): void {
		http_response_code($status);
		echo json_encode($payload, JSON_UNESCAPED_UNICODE);
		exit;
	};

	$request = json_decode((string) file_get_contents('php://input'), true);
	$qc_comment_log_id = is_array($request) ? trim((string) ($request['qc_log_id'] ?? '')) : '';
	$qc_comment_text = is_array($request) ? trim((string) ($request['qc_agent_comment'] ?? '')) : '';

	if ($qc_comment_log_id === '' || !ctype_digit($qc_comment_log_id)) {
		$comment_response(422, ['success' => false, 'message' => 'A valid QC ID was not provided.']);
	}

	$comment_length = function_exists('mb_strlen')
		? mb_strlen($qc_comment_text, 'UTF-8')
		: strlen($qc_comment_text);
	if ($comment_length > 10000) {
		$comment_response(422, ['success' => false, 'message' => 'The QC comment is too long.']);
	}

	$comment_access_stmt = mysqli_prepare(
		$link,
		"SELECT q.qc_log_id
		   FROM quality_control_queue AS q
		   JOIN vicidial_users AS u ON u.user = q.qc_agent
		--   WHERE q.qc_log_id = ? AND q.qc_agent = ? AND q.qc_status = 'CLAIMED'
			WHERE q.qc_log_id = ? AND q.qc_status = 'CLAIMED'
		    AND u.active = 'Y' AND u.qc_enabled = '1'
		  LIMIT 1"
	);
	if ($comment_access_stmt === false) {
		$comment_response(500, ['success' => false, 'message' => 'Could not verify QC access.']);
	}

	// mysqli_stmt_bind_param($comment_access_stmt, 'ss', $qc_comment_log_id, $PHP_AUTH_USER);
	mysqli_stmt_bind_param($comment_access_stmt, 's', $qc_comment_log_id);
	mysqli_stmt_execute($comment_access_stmt);
	$comment_access_result = mysqli_stmt_get_result($comment_access_stmt);
	$has_comment_access = $comment_access_result !== false && mysqli_fetch_assoc($comment_access_result) !== null;
	mysqli_stmt_close($comment_access_stmt);

	if (!$has_comment_access) {
		$comment_response(403, ['success' => false, 'message' => 'This QC record is not available for comments.']);
	}

	$comment_update_stmt = mysqli_prepare(
		$link,
		"UPDATE quality_control_queue
		    SET qc_agent_comment = ?
			WHERE qc_log_id = ? AND qc_status = 'CLAIMED'"
	);

	if ($comment_update_stmt === false) {
		$comment_response(500, ['success' => false, 'message' => 'Could not prepare the comment update.']);
	}

	mysqli_stmt_bind_param(
		$comment_update_stmt,
		'ss',
		$qc_comment_text,
		$qc_comment_log_id,
		// $PHP_AUTH_USER
	);
	if (!mysqli_stmt_execute($comment_update_stmt)) {
		mysqli_stmt_close($comment_update_stmt);
		$comment_response(500, ['success' => false, 'message' => 'Could not save the QC comment.']);
	}

	mysqli_stmt_close($comment_update_stmt);
	$comment_response(200, ['success' => true, 'message' => 'Comment saved.']);
}

$PHP_SELF = $_SERVER['PHP_SELF'];
$PHP_SELF = preg_replace('/\.php.*/i', '.php', $PHP_SELF);
if (isset($_GET["vendor_id"])) {
	$vendor_id = $_GET["vendor_id"];
} elseif (isset($_POST["vendor_id"])) {
	$vendor_id = $_POST["vendor_id"];
}
if (isset($_GET["phone"])) {
	$phone = $_GET["phone"];
} elseif (isset($_POST["phone"])) {
	$phone = $_POST["phone"];
}
if (isset($_GET["old_phone"])) {
	$old_phone = $_GET["old_phone"];
} elseif (isset($_POST["old_phone"])) {
	$old_phone = $_POST["old_phone"];
}
if (isset($_GET["lead_id"])) {
	$lead_id = $_GET["lead_id"];
} elseif (isset($_POST["lead_id"])) {
	$lead_id = $_POST["lead_id"];
}
if (isset($_GET["title"])) {
	$title = $_GET["title"];
} elseif (isset($_POST["title"])) {
	$title = $_POST["title"];
}
if (isset($_GET["first_name"])) {
	$first_name = $_GET["first_name"];
} elseif (isset($_POST["first_name"])) {
	$first_name = $_POST["first_name"];
}
if (isset($_GET["middle_initial"])) {
	$middle_initial = $_GET["middle_initial"];
} elseif (isset($_POST["middle_initial"])) {
	$middle_initial = $_POST["middle_initial"];
}
if (isset($_GET["last_name"])) {
	$last_name = $_GET["last_name"];
} elseif (isset($_POST["last_name"])) {
	$last_name = $_POST["last_name"];
}
if (isset($_GET["lead_name"])) {
	$lead_name = $_GET["lead_name"];
} elseif (isset($_POST["lead_name"])) {
	$lead_name = $_POST["lead_name"];
}
if (isset($_GET["phone_number"])) {
	$phone_number = $_GET["phone_number"];
} elseif (isset($_POST["phone_number"])) {
	$phone_number = $_POST["phone_number"];
}
if (isset($_GET["end_call"])) {
	$end_call = $_GET["end_call"];
} elseif (isset($_POST["end_call"])) {
	$end_call = $_POST["end_call"];
}
if (isset($_GET["DB"])) {
	$DB = $_GET["DB"];
} elseif (isset($_POST["DB"])) {
	$DB = $_POST["DB"];
}
if (isset($_GET["dispo"])) {
	$dispo = $_GET["dispo"];
} elseif (isset($_POST["dispo"])) {
	$dispo = $_POST["dispo"];
}
if (isset($_GET["list_id"])) {
	$list_id = $_GET["list_id"];
} elseif (isset($_POST["list_id"])) {
	$list_id = $_POST["list_id"];
}
if (isset($_GET["campaign_id"])) {
	$campaign_id = $_GET["campaign_id"];
} elseif (isset($_POST["campaign_id"])) {
	$campaign_id = $_POST["campaign_id"];
}
if (isset($_GET["group_id"])) {
	$group_id = $_GET["group_id"];
} elseif (isset($_POST["group_id"])) {
	$group_id = $_POST["group_id"];
}
if (isset($_GET["phone_code"])) {
	$phone_code = $_GET["phone_code"];
} elseif (isset($_POST["phone_code"])) {
	$phone_code = $_POST["phone_code"];
}
if (isset($_GET["server_ip"])) {
	$server_ip = $_GET["server_ip"];
} elseif (isset($_POST["server_ip"])) {
	$server_ip = $_POST["server_ip"];
}
if (isset($_GET["extension"])) {
	$extension = $_GET["extension"];
} elseif (isset($_POST["extension"])) {
	$extension = $_POST["extension"];
}
if (isset($_GET["channel"])) {
	$channel = $_GET["channel"];
} elseif (isset($_POST["channel"])) {
	$channel = $_POST["channel"];
}
if (isset($_GET["call_began"])) {
	$call_began = $_GET["call_began"];
} elseif (isset($_POST["call_began"])) {
	$call_began = $_POST["call_began"];
}
if (isset($_GET["parked_time"])) {
	$parked_time = $_GET["parked_time"];
} elseif (isset($_POST["parked_time"])) {
	$parked_time = $_POST["parked_time"];
}
if (isset($_GET["tsr"])) {
	$tsr = $_GET["tsr"];
} elseif (isset($_POST["tsr"])) {
	$tsr = $_POST["tsr"];
}
if (isset($_GET["address1"])) {
	$address1 = $_GET["address1"];
} elseif (isset($_POST["address1"])) {
	$address1 = $_POST["address1"];
}
if (isset($_GET["address2"])) {
	$address2 = $_GET["address2"];
} elseif (isset($_POST["address2"])) {
	$address2 = $_POST["address2"];
}
if (isset($_GET["address3"])) {
	$address3 = $_GET["address3"];
} elseif (isset($_POST["address3"])) {
	$address3 = $_POST["address3"];
}
if (isset($_GET["city"])) {
	$city = $_GET["city"];
} elseif (isset($_POST["city"])) {
	$city = $_POST["city"];
}
if (isset($_GET["state"])) {
	$state = $_GET["state"];
} elseif (isset($_POST["state"])) {
	$state = $_POST["state"];
}
if (isset($_GET["postal_code"])) {
	$postal_code = $_GET["postal_code"];
} elseif (isset($_POST["postal_code"])) {
	$postal_code = $_POST["postal_code"];
}
if (isset($_GET["province"])) {
	$province = $_GET["province"];
} elseif (isset($_POST["province"])) {
	$province = $_POST["province"];
}
if (isset($_GET["country_code"])) {
	$country_code = $_GET["country_code"];
} elseif (isset($_POST["country_code"])) {
	$country_code = $_POST["country_code"];
}
if (isset($_GET["alt_phone"])) {
	$alt_phone = $_GET["alt_phone"];
} elseif (isset($_POST["alt_phone"])) {
	$alt_phone = $_POST["alt_phone"];
}
if (isset($_GET["email"])) {
	$email = $_GET["email"];
} elseif (isset($_POST["email"])) {
	$email = $_POST["email"];
}
if (isset($_GET["security"])) {
	$security = $_GET["security"];
} elseif (isset($_POST["security"])) {
	$security = $_POST["security"];
}
if (isset($_GET["comments"])) {
	$comments = $_GET["comments"];
} elseif (isset($_POST["comments"])) {
	$comments = $_POST["comments"];
}
if (isset($_GET["status"])) {
	$status = $_GET["status"];
} elseif (isset($_POST["status"])) {
	$status = $_POST["status"];
}
if (isset($_GET["rank"])) {
	$rank = $_GET["rank"];
} elseif (isset($_POST["rank"])) {
	$rank = $_POST["rank"];
}
if (isset($_GET["owner"])) {
	$owner = $_GET["owner"];
} elseif (isset($_POST["owner"])) {
	$owner = $_POST["owner"];
}
if (isset($_GET["submit"])) {
	$submit = $_GET["submit"];
} elseif (isset($_POST["submit"])) {
	$submit = $_POST["submit"];
}
if (isset($_GET["SUBMIT"])) {
	$SUBMIT = $_GET["SUBMIT"];
} elseif (isset($_POST["SUBMIT"])) {
	$SUBMIT = $_POST["SUBMIT"];
}
if (isset($_GET["CBchangeUSERtoANY"])) {
	$CBchangeUSERtoANY = $_GET["CBchangeUSERtoANY"];
} elseif (isset($_POST["CBchangeUSERtoANY"])) {
	$CBchangeUSERtoANY = $_POST["CBchangeUSERtoANY"];
}
if (isset($_GET["CBchangeUSERtoUSER"])) {
	$CBchangeUSERtoUSER = $_GET["CBchangeUSERtoUSER"];
} elseif (isset($_POST["CBchangeUSERtoUSER"])) {
	$CBchangeUSERtoUSER = $_POST["CBchangeUSERtoUSER"];
}
if (isset($_GET["CBchangeANYtoUSER"])) {
	$CBchangeANYtoUSER = $_GET["CBchangeANYtoUSER"];
} elseif (isset($_POST["CBchangeANYtoUSER"])) {
	$CBchangeANYtoUSER = $_POST["CBchangeANYtoUSER"];
}
if (isset($_GET["CBchangeDATE"])) {
	$CBchangeDATE = $_GET["CBchangeDATE"];
} elseif (isset($_POST["CBchangeDATE"])) {
	$CBchangeDATE = $_POST["CBchangeDATE"];
}
if (isset($_GET["callback_id"])) {
	$callback_id = $_GET["callback_id"];
} elseif (isset($_POST["callback_id"])) {
	$callback_id = $_POST["callback_id"];
}
if (isset($_GET["CBuser"])) {
	$CBuser = $_GET["CBuser"];
} elseif (isset($_POST["CBuser"])) {
	$CBuser = $_POST["CBuser"];
}
if (isset($_GET["modify_logs"])) {
	$modify_logs = $_GET["modify_logs"];
} elseif (isset($_POST["modify_logs"])) {
	$modify_logs = $_POST["modify_logs"];
}
if (isset($_GET["modify_closer_logs"])) {
	$modify_closer_logs = $_GET["modify_closer_logs"];
} elseif (isset($_POST["modify_closer_logs"])) {
	$modify_closer_logs = $_POST["modify_closer_logs"];
}
if (isset($_GET["modify_agent_logs"])) {
	$modify_agent_logs = $_GET["modify_agent_logs"];
} elseif (isset($_POST["modify_agent_logs"])) {
	$modify_agent_logs = $_POST["modify_agent_logs"];
}
if (isset($_GET["add_closer_record"])) {
	$add_closer_record = $_GET["add_closer_record"];
} elseif (isset($_POST["add_closer_record"])) {
	$add_closer_record = $_POST["add_closer_record"];
}
if (isset($_POST["appointment_date"])) {
	$appointment_date = $_POST["appointment_date"];
} elseif (isset($_GET["appointment_date"])) {
	$appointment_date = $_GET["appointment_date"];
}
if (isset($_POST["appointment_time"])) {
	$appointment_time = $_POST["appointment_time"];
} elseif (isset($_GET["appointment_time"])) {
	$appointment_time = $_GET["appointment_time"];
}
if (isset($_POST["claim_QC"])) {
	$claim_QC = $_POST["claim_QC"];
} elseif (isset($_GET["claim_QC"])) {
	$claim_QC = $_GET["claim_QC"];
}
if (isset($_POST["qc_agent"])) {
	$qc_agent = $_POST["qc_agent"];
} elseif (isset($_GET["qc_agent"])) {
	$qc_agent = $_GET["qc_agent"];
}
if (isset($_POST["qc_log_id"])) {
	$qc_log_id = $_POST["qc_log_id"];
} elseif (isset($_GET["qc_log_id"])) {
	$qc_log_id = $_GET["qc_log_id"];
}
if (isset($_POST["qc_process_status"])) {
	$qc_process_status = $_POST["qc_process_status"];
} elseif (isset($_GET["qc_process_status"])) {
	$qc_process_status = $_GET["qc_process_status"];
}
if (isset($_POST["qc_agent_comment"])) {
	$qc_agent_comment = $_POST["qc_agent_comment"];
} elseif (isset($_GET["qc_agent_comment"])) {
	$qc_agent_comment = $_GET["qc_agent_comment"];
} else {
	$qc_agent_comment = '';
}
if (isset($_POST["finish_qc"])) {
	$finish_qc = $_POST["finish_qc"];
} elseif (isset($_GET["finish_qc"])) {
	$finish_qc = $_GET["finish_qc"];
}
if (isset($_POST["qc_display_method"])) {
	$qc_display_method = $_POST["qc_display_method"];
} elseif (isset($_GET["qc_display_method"])) {
	$qc_display_method = $_GET["qc_display_method"];
}
if (isset($_POST["qc_display_group_type"])) {
	$qc_display_group_type = $_POST["qc_display_group_type"];
} elseif (isset($_GET["qc_display_group_type"])) {
	$qc_display_group_type = $_GET["qc_display_group_type"];
}
if (isset($_POST["qc_status"])) {
	$qc_status = $_POST["qc_status"];
} elseif (isset($_GET["qc_status"])) {
	$qc_status = $_GET["qc_status"];
}
if (isset($_POST["agent_log_id"])) {
	$agent_log_id = $_POST["agent_log_id"];
} elseif (isset($_GET["agent_log_id"])) {
	$agent_log_id = $_GET["agent_log_id"];
}
if (isset($_POST["referring_section"])) {
	$referring_section = $_POST["referring_section"];
} elseif (isset($_GET["referring_section"])) {
	$referring_section = $_GET["referring_section"];
}
if (isset($_POST["referring_element"])) {
	$referring_element = $_POST["referring_element"];
} elseif (isset($_GET["referring_element"])) {
	$referring_element = $_GET["referring_element"];
}

$DB = preg_replace('/[^0-9]/', '', $DB);

#############################################
##### START SYSTEM_SETTINGS LOOKUP #####
$stmt = "SELECT use_non_latin,custom_fields_enabled,enable_languages,language_method,active_modules,log_recording_access,allow_web_debug FROM system_settings;";
$rslt = mysql_to_mysqli($stmt, $link);
#if ($DB) {echo "$stmt\n";}
$qm_conf_ct = mysqli_num_rows($rslt);
if ($qm_conf_ct > 0) {
	$row = mysqli_fetch_row($rslt);
	$non_latin =				$row[0];
	$custom_fields_enabled =	$row[1];
	$SSenable_languages =		$row[2];
	$SSlanguage_method =		$row[3];
	$active_modules =			$row[4];
	$log_recording_access =		$row[5];
	$SSallow_web_debug =		$row[6];
}
if ($SSallow_web_debug < 1) {
	$DB = 0;
}
##### END SETTINGS LOOKUP #####
###########################################

$CBchangeANYtoUSER = preg_replace('/[^A-Z]/', '', $CBchangeANYtoUSER);
$CBchangeDATE = preg_replace('/[^A-Z]/', '', $CBchangeDATE);
$CBchangeUSERtoANY = preg_replace('/[^A-Z]/', '', $CBchangeUSERtoANY);
$CBchangeUSERtoUSER = preg_replace('/[^A-Z]/', '', $CBchangeUSERtoUSER);
$claim_QC = preg_replace('/[^A-Z]/', '', $claim_QC);
$referring_section = preg_replace('/[^A-Z]/', '', $referring_section);
$call_began = preg_replace("/\<|\>|\'|\"|\\\\|;/", "", $call_began);

if ($non_latin < 1) {
	$PHP_AUTH_USER = preg_replace('/[^-_0-9a-zA-Z]/', '', $PHP_AUTH_USER);
	$PHP_AUTH_PW = preg_replace('/[^-_0-9a-zA-Z]/', '', $PHP_AUTH_PW);
	$user = preg_replace('/[^-\_0-9a-zA-Z]/', '', $user);
	$tsr = preg_replace('/[^-\_0-9a-zA-Z]/', '', $tsr);
	$CBuser = preg_replace('/[^-\_0-9a-zA-Z]/', '', $CBuser);
	$qc_agent = preg_replace('/[^-\_0-9a-zA-Z]/', '', $qc_agent);
	$title = preg_replace('/[^- \'\_\.0-9a-zA-Z]/', '', $title);
	$first_name = preg_replace('/[^- \'\+\_\.0-9a-zA-Z]/', '', $first_name);
	$middle_initial = preg_replace('/[^0-9a-zA-Z]/', '', $middle_initial);
	$last_name = preg_replace('/[^- \'\+\_\.0-9a-zA-Z]/', '', $last_name);
	$lead_name = preg_replace('/[^- \'\+\_\.0-9a-zA-Z]/', '', $lead_name);
	$address1 = preg_replace('/[^- \'\+\.\:\/\@\_0-9a-zA-Z]/', '', $address1);
	$address2 = preg_replace('/[^- \'\+\.\:\/\@\_0-9a-zA-Z]/', '', $address2);
	$address3 = preg_replace('/[^- \'\+\.\:\/\@\_0-9a-zA-Z]/', '', $address3);
	$city = preg_replace('/[^- \'\+\.\:\/\@\_0-9a-zA-Z]/', '', $city);
	$state = preg_replace('/[^- 0-9a-zA-Z]/', '', $state);
	$province = preg_replace('/[^- \'\+\.\_0-9a-zA-Z]/', '', $province);
	$postal_code = preg_replace('/[^- \'\+0-9a-zA-Z]/', '', $postal_code);
	$alt_phone = preg_replace('/[^- \'\+\_\.0-9a-zA-Z]/', '', $alt_phone);
	$email = preg_replace('/[^- \'\+\.\:\/\@\%\_0-9a-zA-Z]/', '', $email);
	$security = preg_replace('/[^- \'\+\.\:\/\@\_0-9a-zA-Z]/', '', $security);
	$campaign_id = preg_replace('/[^-\_0-9a-zA-Z]/', '', $campaign_id);
	$owner = preg_replace('/[^- \'\+\.\:\/\@\_0-9a-zA-Z]/', '', $owner);
	$country_code = preg_replace('/[^A-Z]/', '', $country_code);
	$gender = preg_replace('/[^A-Z]/', '', $gender);
	$qc_display_group_type = preg_replace('/[^A-Z]/', '', $qc_display_group_type);
	$qc_display_method = preg_replace('/[^A-Z]/', '', $qc_display_method);
	$qc_process_status = preg_replace('/[^A-Z]/', '', $qc_process_status);
	$channel = preg_replace('/[^- \'\+\.\:\/\@\_0-9a-zA-Z]/', '', $channel);
	$dispo = preg_replace('/[^-\_0-9a-zA-Z]/', '', $dispo);
	$status = preg_replace('/[^-\_0-9a-zA-Z]/', '', $status);
	$qc_status = preg_replace('/[^-\_0-9a-zA-Z]/', '', $qc_status);
	$referring_element = preg_replace('/[^-\_0-9a-zA-Z]/', '', $referring_element);
	$submit = preg_replace('/[^- 0-9a-zA-Z]/', '', $submit);
	$SUBMIT = preg_replace('/[^- 0-9a-zA-Z]/', '', $SUBMIT);
} else {
	$PHP_AUTH_USER = preg_replace('/[^-_0-9\p{L}]/u', '', $PHP_AUTH_USER);
	$PHP_AUTH_PW = preg_replace('/[^-_0-9\p{L}]/u', '', $PHP_AUTH_PW);
	$user = preg_replace('/[^-_0-9\p{L}]/u', '', $user);
	$tsr = preg_replace('/[^-_0-9\p{L}]/u', '', $tsr);
	$CBuser = preg_replace('/[^-_0-9\p{L}]/u', '', $CBuser);
	$qc_agent = preg_replace('/[^-_0-9\p{L}]/u', '', $qc_agent);
	$title = preg_replace('/[^- \'\_\.0-9\p{L}]/u', '', $title);
	$first_name = preg_replace('/[^- \'\+\_\.0-9\p{L}]/u', '', $first_name);
	$middle_initial = preg_replace('/[^0-9\p{L}]/u', '', $middle_initial);
	$last_name = preg_replace('/[^- \'\+\_\.0-9\p{L}]/u', '', $last_name);
	$lead_name = preg_replace('/[^- \'\+\_\.0-9\p{L}]/u', '', $lead_name);
	$address1 = preg_replace('/[^- \'\+\.\:\/\@\_0-9\p{L}]/u', '', $address1);
	$address2 = preg_replace('/[^- \'\+\.\:\/\@\_0-9\p{L}]/u', '', $address2);
	$address3 = preg_replace('/[^- \'\+\.\:\/\@\_0-9\p{L}]/u', '', $address3);
	$city = preg_replace('/[^- \'\+\.\:\/\@\_0-9\p{L}]/u', '', $city);
	$state = preg_replace('/[^- 0-9\p{L}]/u', '', $state);
	$province = preg_replace('/[^- \'\+\.\_0-9\p{L}]/u', '', $province);
	$postal_code = preg_replace('/[^- \'\+0-9\p{L}]/u', '', $postal_code);
	$alt_phone = preg_replace('/[^- \'\+\_\.0-9\p{L}]/u', '', $alt_phone);
	$email = preg_replace('/[^- \'\+\.\:\/\@\%\_0-9\p{L}]/u', '', $email);
	$security = preg_replace('/[^- \'\+\.\:\/\@\_0-9\p{L}]/u', '', $security);
	$campaign_id = preg_replace('/[^-\_0-9\p{L}]/u', '', $campaign_id);
	$owner = preg_replace('/[^- \'\+\.\:\/\@\_0-9\p{L}]/u', '', $owner);
	$country_code = preg_replace('/[^\p{L}]/u', '', $country_code);
	$gender = preg_replace('/[^\p{L}]/u', '', $gender);
	$qc_display_group_type = preg_replace('/[^\p{L}]/u', '', $qc_display_group_type);
	$qc_display_method = preg_replace('/[^\p{L}]/u', '', $qc_display_method);
	$qc_process_status = preg_replace('/[^\p{L}]/u', '', $qc_process_status);
	$channel = preg_replace('/[^- \'\+\.\:\/\@\_0-9\p{L}]/u', '', $channel);
	$dispo = preg_replace('/[^-_0-9\p{L}]/u', '', $dispo);
	$status = preg_replace('/[^-_0-9\p{L}]/u', '', $status);
	$qc_status = preg_replace('/[^-_0-9\p{L}]/u', '', $qc_status);
	$referring_element = preg_replace('/[^-\_0-9\p{L}]/u', '', $referring_element);
	$submit = preg_replace('/[^- 0-9\p{L}]/u', '', $submit);
	$SUBMIT = preg_replace('/[^- 0-9\p{L}]/u', '', $SUBMIT);
}

$first_name = preg_replace('/\+/', ' ', $first_name);
$last_name = preg_replace('/\+/', ' ', $last_name);
$address1 = preg_replace('/\+/', ' ', $address1);
$address2 = preg_replace('/\+/', ' ', $address2);
$address3 = preg_replace('/\+/', ' ', $address3);
$city = preg_replace('/\+/', ' ', $city);
$province = preg_replace('/\+/', ' ', $province);
$postal_code = preg_replace('/\+/', ' ', $postal_code);
$alt_phone = preg_replace('/\+/', ' ', $alt_phone);
$email = preg_replace('/\+/', ' ', $email);
$security_phrase = preg_replace('/\+/', ' ', $security_phrase);
$owner = preg_replace('/\+/', ' ', $owner);

$list_id = preg_replace('/[^0-9]/', '', $list_id);
$lead_id = preg_replace('/[^0-9a-zA-Z]/', '', $lead_id);
$entry_list_id = preg_replace('/[^0-9]/', '', $entry_list_id);
$phone_code = preg_replace('/[^0-9]/', '', $phone_code);
$update_phone_number = preg_replace('/[^A-Z]/', '', $update_phone_number);
$phone_number = preg_replace('/[^0-9]/', '', $phone_number);
$old_phone = preg_replace('/[^0-9]/', '', $old_phone);
$phone = preg_replace('/[^0-9]/', '', $phone);
$vendor_lead_code = preg_replace('/;|#/', '', $vendor_lead_code);
$vendor_lead_code = preg_replace('/\+/', ' ', $vendor_lead_code);
$vendor_id = preg_replace('/;|#/', '', $vendor_id);
$vendor_id = preg_replace('/\+/', ' ', $vendor_id);
$source_id = preg_replace('/;|#/', '', $source_id);
$source_id = preg_replace('/\+/', ' ', $source_id);
$gmt_offset_now = preg_replace('/[^-\_\.0-9]/', '', $gmt_offset_now);
$comments = preg_replace('/;|#/', '', $comments);
$comments = preg_replace('/\+/', ' ', $comments);
$rank = preg_replace('/[^0-9]/', '', $rank);
$no_update = preg_replace('/[^A-Z]/', '', $no_update);
$called_count = preg_replace('/[^0-9]/', '', $called_count);
$local_gmt = preg_replace('/[^-\.0-9]/', '', $local_gmt);

$callback = preg_replace('/[^A-Z]/', '', $callback);
$callback_type = preg_replace('/[^A-Z]/', '', $callback_type);
$add_closer_record = preg_replace('/[^0-9]/', '', $add_closer_record);
$agent_log_id = preg_replace('/[^0-9]/', '', $agent_log_id);
$appointment_date = preg_replace('/[^-0-9]/', '', $appointment_date);
$appointment_time = preg_replace('/[^0-9\:]/', '', $appointment_time);
$parked_time = preg_replace('/[^0-9\:\-]/', '', $parked_time);
$callback_id = preg_replace('/[^0-9]/', '', $callback_id);
$server_ip = preg_replace("/[^0-9\.]/", "", $server_ip);
$end_call = preg_replace('/[^0-9]/', '', $end_call);
$finish_qc = preg_replace('/[^0-9]/', '', $finish_qc);
$group_id = preg_replace('/[^-_0-9\p{L}]/u', '', $group_id);
$extension = preg_replace('/[^0-9]/', '', $extension);
$qc_log_id = preg_replace('/[^0-9\:\-]/', '', $qc_log_id);
$modify_agent_logs = preg_replace('/[^0-9]/', '', $modify_agent_logs);
$modify_closer_logs = preg_replace('/[^0-9]/', '', $modify_closer_logs);
$modify_logs = preg_replace('/[^0-9]/', '', $modify_logs);


$STARTtime = date("U");
$defaultappointment = date("Y-m-d");
$NOW_TIME = date("Y-m-d H:i:s");
$back_link = "./admin.php?ADD=881&qc_display_group_type=$referring_section";
switch ($referring_section) # was $scorecard_source
{
	case "CAMPAIGN":
		$back_link .= "&campaign_id=$referring_element";
		break;
	case "LIST":
		$back_link .= "&list_id=$referring_element";
		break;
	case "INGROUP":
		$back_link .= "&group_id=$referring_element";
		break;
	case "PHONE":
		$back_link = "./qc_calls_by_phone.php?phone_number=" . urlencode($referring_element);
		break;
}

$rights_stmt = "SELECT modify_leads,qc_enabled,full_name,modify_leads,user_group,selected_language from vicidial_users where user='$PHP_AUTH_USER';";
if ($DB) {
	echo "|$stmt|\n";
}
$rights_rslt = mysql_to_mysqli($rights_stmt, $link);
$rights_row = mysqli_fetch_row($rights_rslt);
$modify_leads =			$rights_row[0];
$qc_enabled =			$rights_row[1];
$LOGfullname =			$rights_row[2];
$LOGmodify_leads =		$rights_row[3];
$LOGuser_group =		$rights_row[4];
$VUselected_language =	$rights_row[5];

if (strlen($phone_number) < 6) {
	$phone_number = $old_phone;
}

require("screen_colors.php");

$Mhead_color =	$SSstd_row5_background;
$Mmain_bgcolor = $SSmenu_background;
$Mhead_color =	$SSstd_row5_background;

$auth = 0;
$auth_message = '';

// A valid admin session has already passed the login and optional 2FA flow.
// Confirm that the session user still exists and has QC access before trusting it.
if ($rights_row && ($qc_enabled == '1')) {
	$auth = 1;
}

if ($auth < 1) {
	$VDdisplayMESSAGE = _QXZ("Login incorrect, please try again");
	Header("Content-type: text/html; charset=utf-8");
	Header("HTTP/1.1 403 Forbidden");
	echo _QXZ("Your account does not have permission to access Quality Control") . ". <a href=\"admin.php\">" . _QXZ("Return to Administration") . "</a>.\n";
	exit;
}


# check their permissions
#if ( $modify_leads < 1 )
#	{
#	header ("Content-type: text/html; charset=utf-8");
#	echo "You do not have permissions to modify leads\n";
#	exit;
#	}
if ($qc_enabled < 1) {
	header("Content-type: text/html; charset=utf-8");
	echo _QXZ("QC is not enabled for your user account") . "\n";
	exit;
}


if ($submit == "SUBMIT" && $auth == 1) {
	$stmt = "UPDATE vicidial_list set status='" . mysqli_real_escape_string($link, $status) . "',title='" . mysqli_real_escape_string($link, $title) . "',first_name='" . mysqli_real_escape_string($link, $first_name) . "',middle_initial='" . mysqli_real_escape_string($link, $middle_initial) . "',last_name='" . mysqli_real_escape_string($link, $last_name) . "',address1='" . mysqli_real_escape_string($link, $address1) . "',address2='" . mysqli_real_escape_string($link, $address2) . "',address3='" . mysqli_real_escape_string($link, $address3) . "',city='" . mysqli_real_escape_string($link, $city) . "',state='" . mysqli_real_escape_string($link, $state) . "',province='" . mysqli_real_escape_string($link, $province) . "',postal_code='" . mysqli_real_escape_string($link, $postal_code) . "',country_code='" . mysqli_real_escape_string($link, $country_code) . "',alt_phone='" . mysqli_real_escape_string($link, $alt_phone) . "',phone_number='$phone_number',phone_code='$phone_code',email='" . mysqli_real_escape_string($link, $email) . "',security_phrase='" . mysqli_real_escape_string($link, $security) . "',comments='" . mysqli_real_escape_string($link, $comments) . "',rank='" . mysqli_real_escape_string($link, $rank) . "',owner='" . mysqli_real_escape_string($link, $owner) . "' where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "'";
	if ($DB) {
		echo "|$stmt|\n";
	}
	$rslt = mysql_to_mysqli($stmt, $link);
}


if ($finish_qc && $qc_log_id && $qc_process_status && $qc_agent == $PHP_AUTH_USER) {
	$qc_queue_stmt = "select * from quality_control_queue where qc_log_id='$qc_log_id'";
	$qc_queue_rslt = mysql_to_mysqli($qc_queue_stmt, $link);
	$q = 0;
	while ($queue_row = mysqli_fetch_array($qc_queue_rslt)) {
		$scorecard_source = $queue_row["scorecard_source"];
		$campaign_id = $queue_row["campaign_id"];
		$group_id = $queue_row["group_id"];
		$list_id = $queue_row["list_id"];

		$redirect_URL_str = "&qc_display_group_type=$referring_section"; # was $scorecard_source
		switch ($referring_section) # was $scorecard_source
		{
			case "CAMPAIGN":
				$redirect_URL_str .= "&campaign_id=$referring_element";
				$display_ID = $campaign_id;
				break;
			case "LIST":
				$redirect_URL_str .= "&list_id=$referring_element";
				$display_ID = $list_id;
				break;
			case "INGROUP":
				$redirect_URL_str .= "&group_id=$referring_element";
				$display_ID = $group_id;
				break;
			case "PHONE":
				$redirect_URL_str = "";
				$display_ID = $referring_element;
				break;
		}
	}

	if ($qc_process_status == "RELEASE") {
		$del_stmt = "delete from quality_control_queue where qc_log_id='$qc_log_id'";
		$del_rslt = mysqli_query($link, $del_stmt);
		$del_stmt2 = "delete from quality_control_checkpoint_log where qc_log_id='$qc_log_id'";
		$del_rslt2 = mysqli_query($link, $del_stmt2);
	} else {
		if ($lead_id) {
			$upd_lead_stmt = "UPDATE vicidial_list set status='" . mysqli_real_escape_string($link, $status) . "' where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "'";
			$upd_lead_rslt = mysqli_query($link, $upd_lead_stmt);
		} else {
			echo _QXZ("Error.  No lead ID");
			exit;
		}
		$qc_agent_comment_sql = mysqli_real_escape_string($link, trim((string) $qc_agent_comment));
		$upd_stmt = "UPDATE quality_control_queue AS q
			LEFT JOIN (
				SELECT qc_log_id,
					COUNT(*) AS total_checkpoints,
					COALESCE(SUM(checkpoint_points_earned), 0) AS total_points_earned,
					COALESCE(SUM(checkpoint_points), 0) AS total_points_possible
				FROM quality_control_checkpoint_log
				WHERE qc_log_id='$qc_log_id'
				GROUP BY qc_log_id
			) AS totals ON totals.qc_log_id=q.qc_log_id
			SET q.qc_status='$qc_process_status',
				q.qc_agent_comment='$qc_agent_comment_sql',
				q.date_completed=now(),
				q.total_checkpoints=COALESCE(totals.total_checkpoints, 0),
				q.total_points_earned=COALESCE(totals.total_points_earned, 0),
				q.total_points_possible=COALESCE(totals.total_points_possible, 0),
				q.score_percentage=ROUND(100 * totals.total_points_earned / NULLIF(totals.total_points_possible, 0), 2)
			WHERE q.qc_log_id='$qc_log_id'";
		if ($DB) {
			echo "|$upd_stmt|\n";
		}
		$upd_rslt = mysqli_query($link, $upd_stmt);
	}
	if ($referring_section == 'PHONE') {
		header("Location: ./qc_calls_by_phone.php?phone_number=" . urlencode($referring_element));
	} else {
		header("Location: ./admin.php?ADD=881$redirect_URL_str");
	}
}

if ($claim_QC && $auth == 1) {
	if ((!$qc_display_group_type) || ($qc_display_group_type == "CAMPAIGN" && !$campaign_id) || ($qc_display_group_type == "LIST" && !$list_id) || ($qc_display_group_type == "INGROUP" && !$group_id)) {
		echo _QXZ("Error.  QC type is not defined or missing info") . " ($qc_display_group_type => $campaign_id|$list_id|$group_id)";
		exit;
	}
	if ($qc_display_method) {
		$list_id = 0;
		if ($agent_log_id) {
			$alid_clause = "and agent_log_id='$agent_log_id'";
		}
		# Get specific agent log record related to selected call to ascertain the appropriate prioritization of the QC scorecards based on the call direction and list ID
		$agent_log_stmt = "select agent_log_id, user, user_group, campaign_id, uniqueid, event_time+INTERVAL(pause_sec+wait_sec) SECOND as call_time, if(comments='CHAT' or comments='EMAIL' or comments='INBOUND', 'INBOUND', 'OUTBOUND') as direction from vicidial_agent_log where lead_id='$lead_id' and status='$qc_status' $alid_clause order by agent_log_id desc limit 1";
		if ($DB) {
			echo $agent_log_stmt . "<BR>";
		}
		# http://svn.eflo.net:40080/vicidial/qc_modify_lead.php?claim_QC=CLAIM&qc_display_method=LEAD&agent_log_id=&qc_status=NP&lead_id=813114&campaign_id=TESTCAMP&lead_name=Test+Lead+17917
		$agent_log_rslt = mysql_to_mysqli($agent_log_stmt, $link);
		if (mysqli_num_rows($agent_log_rslt) > 0) {
			$alog_row = mysqli_fetch_array($agent_log_rslt);
			$agent_log_id = $alog_row["agent_log_id"];
			$user = $alog_row["user"];
			$user_group = $alog_row["user_group"];
			$campaign_id = $alog_row["campaign_id"];
			$uniqueid = $alog_row["uniqueid"];
			$direction = $alog_row["direction"];
			$call_time = $alog_row["call_time"];
		}

		switch ($direction) {
			case "OUTBOUND":
				$log_stmt = "select list_id from vicidial_log where uniqueid='$uniqueid'";
				break;
			case "INBOUND":
				$log_stmt = "select list_id, campaign_id from vicidial_closer_log where lead_id='$lead_id' and uniqueid='$uniqueid'" . ($group_id ? "and campaign_id='$group_id'" : "");
				break;
		}
		$list_scorecard_clause = "";
		$ingroup_scorecard_clause = "";
		$log_rslt = mysql_to_mysqli($log_stmt, $link);
		while ($log_row = mysqli_fetch_array($log_rslt)) {
			# Both outbound and inbound calls have a list ID, list ID takes priority over outbound campaign, but not ingroup
			$list_id = $log_row["list_id"];
			$list_scorecard_clause = "UNION select qc_scorecard_id, 'LIST' as scorecard_source, qc_web_form_address, if(qc_scorecard_id!='', 2, 200) as priority from vicidial_lists where list_id='$list_id'";

			# Inbound calls' QC scorecard takes priority over list ID and campaign ID.
			if (!$group_id) {
				$group_id = $log_row["campaign_id"];
			}
			if ($group_id) {
				$ingroup_scorecard_clause = "UNION select qc_scorecard_id, 'INGROUP' as scorecard_source, qc_web_form_address, if(qc_scorecard_id!='', 1, 100) as priority from vicidial_inbound_groups where group_id='$group_id'";
			}
		}

		# Get scorecard ID
		$qc_scorecard_id = "";
		$scorecard_stmt = "select qc_scorecard_id, 'CAMPAIGN' as scorecard_source, qc_web_form_address, if(qc_scorecard_id!='', 3, 300) as priority from vicidial_campaigns where campaign_id='$campaign_id' $list_scorecard_clause $ingroup_scorecard_clause order by priority asc limit 1";

		# echo $scorecard_stmt."<BR>";

		$scorecard_rslt = mysql_to_mysqli($scorecard_stmt, $link);
		while ($scorecard_row = mysqli_fetch_array($scorecard_rslt)) {
			$qc_scorecard_id = $scorecard_row["qc_scorecard_id"];
			$scorecard_source = $scorecard_row["scorecard_source"];
			$qc_web_form_address = $scorecard_row["qc_web_form_address"];
		}
		if (!$qc_scorecard_id) {
			echo _QXZ("Cannot claim this - no QC scorecard found") . "\n";
			exit;
		}

		# select the nearest recording_id based on timestamp.  'Nearest' can be in either direction from the call time (i.e. before or after).
		$recording_id = '';
		$rec_log_stmt = "select recording_id, if(vicidial_id='$uniqueid', 1, 0) as priority, ABS(TIMESTAMPDIFF(SECOND, start_time, '$call_time')) as priority2 from recording_log where lead_id='$lead_id' order by priority desc, priority2 asc limit 1";
		$rec_log_rslt = mysql_to_mysqli($rec_log_stmt, $link);
		while ($rec_log_row = mysqli_fetch_array($rec_log_rslt)) {
			$recording_id = $rec_log_row["recording_id"];
		}

		# Reopening a call from a report must not create a second active QC claim.
		# Reuse this user's existing claim, or stop if another QC user owns it.
		$new_qc_claim = true;
		$existing_claim_stmt = "select qc_log_id, qc_agent from quality_control_queue where agent_log_id='" . mysqli_real_escape_string($link, $agent_log_id) . "' and lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' and qc_status='CLAIMED' order by qc_log_id desc limit 1";
		$existing_claim_rslt = mysql_to_mysqli($existing_claim_stmt, $link);
		if ($existing_claim_rslt && mysqli_num_rows($existing_claim_rslt) > 0) {
			$existing_claim_row = mysqli_fetch_assoc($existing_claim_rslt);
			if ($existing_claim_row['qc_agent'] != $PHP_AUTH_USER) {
				echo _QXZ("This call is already claimed by another QC user.");
				exit;
			}
			$qc_log_id = $existing_claim_row['qc_log_id'];
			$new_qc_claim = false;
		} else {
			$ins_stmt = "insert into quality_control_queue(qc_display_method, lead_id, agent_log_id, user, user_group, campaign_id, group_id, list_id, vicidial_id, recording_id, qc_scorecard_id, qc_agent, qc_user_group, qc_status, date_claimed, scorecard_source, status, call_date, qc_web_form_address) VALUES('$qc_display_method', '$lead_id', '$agent_log_id', '$user', '$user_group', '$campaign_id', '$group_id', '$list_id', '$uniqueid', '$recording_id', '$qc_scorecard_id', '$PHP_AUTH_USER', '$LOGuser_group', 'CLAIMED', now(), '$scorecard_source', '$qc_status', '$call_time', '$qc_web_form_address')";
			#		echo $ins_stmt."<BR>";
			$ins_rslt = mysql_to_mysqli($ins_stmt, $link);
			$qc_log_id = mysqli_insert_id($link);
			if (!$qc_log_id) {
				echo _QXZ("ERROR - INSERT INTO QUALITY CONTROL QUEUE FAILED!");
				exit;
			}
		}
		if ($scorecard_source != $qc_display_group_type) {
			$alert = "<BR><font style='font-family:courier; color:white; background-color:red' size='3'>WARNING: $scorecard_source setting overrides $qc_display_group_type - using scorecard $qc_scorecard_id</font>";
		}


		if ($new_qc_claim) {
			$checkpoint_log_stmt = "select * from quality_control_checkpoints where qc_scorecard_id='$qc_scorecard_id' order by checkpoint_rank asc";
			$checkpoint_log_rslt = mysql_to_mysqli($checkpoint_log_stmt, $link);
			while ($checkpoint_row = mysqli_fetch_array($checkpoint_log_rslt)) {
			$checkpoint_row_id = mysqli_real_escape_string($link, (string) $checkpoint_row['checkpoint_row_id']);
			$checkpoint_text = mysqli_real_escape_string($link, (string) $checkpoint_row['checkpoint_text']);
			$checkpoint_text_presets = mysqli_real_escape_string($link, (string) $checkpoint_row['checkpoint_text_presets']);
			$checkpoint_rank = mysqli_real_escape_string($link, (string) $checkpoint_row['checkpoint_rank']);
			$checkpoint_points = mysqli_real_escape_string($link, (string) $checkpoint_row['checkpoint_points']);
			$instant_fail = mysqli_real_escape_string($link, (string) $checkpoint_row['instant_fail']);
			$checkpoint_ins_stmt = "insert into quality_control_checkpoint_log(qc_log_id, campaign_id, group_id, list_id, qc_scorecard_id, checkpoint_row_id, checkpoint_text, checkpoint_text_presets, checkpoint_rank, checkpoint_points, checkpoint_points_earned, instant_fail) VALUES ('$qc_log_id', '$campaign_id', '$group_id', '$list_id', '$qc_scorecard_id', '$checkpoint_row_id', '$checkpoint_text', '$checkpoint_text_presets', '$checkpoint_rank', '$checkpoint_points', '0', '$instant_fail')";
			$checkpoint_ins_rslt = mysql_to_mysqli($checkpoint_ins_stmt, $link);
			#			echo $checkpoint_ins_stmt."<BR>";
			}
			$initial_totals_stmt = "UPDATE quality_control_queue AS q
			JOIN (
				SELECT qc_log_id, COUNT(*) AS total_checkpoints,
					COALESCE(SUM(checkpoint_points_earned), 0) AS total_points_earned,
					COALESCE(SUM(checkpoint_points), 0) AS total_points_possible
				FROM quality_control_checkpoint_log
				WHERE qc_log_id='$qc_log_id'
				GROUP BY qc_log_id
			) AS totals ON totals.qc_log_id=q.qc_log_id
			SET q.total_checkpoints=totals.total_checkpoints,
				q.total_points_earned=totals.total_points_earned,
				q.total_points_possible=totals.total_points_possible,
				q.score_percentage=ROUND(100 * totals.total_points_earned / NULLIF(totals.total_points_possible, 0), 2)
			WHERE q.qc_log_id='$qc_log_id'";
			// The claim and checkpoints are already saved. A cached totals failure
			// must not prevent opening them; the scorecard calculates its displayed
			// totals from the checkpoint rows. Log both mysqli error modes.
			try {
				$initial_totals_rslt = mysqli_query($link, $initial_totals_stmt);
				if ($initial_totals_rslt === false) {
					error_log('QC initial totals update failed for qc_log_id=' . $qc_log_id . ': ' . mysqli_error($link));
				}
			} catch (mysqli_sql_exception $error) {
				error_log('QC initial totals update failed for qc_log_id=' . $qc_log_id . ': ' . $error->getMessage());
			}
		}
		#exit;
	} else {
		echo _QXZ("Cannot claim this - there is no QC display method") . "\n";
		exit;
	}
}

if (!$qc_log_id) {
	echo _QXZ("No QC record detected - exiting.");
	exit;
} else {
	$qc_queue_stmt = "select * from quality_control_queue where qc_log_id='$qc_log_id'";
	$qc_queue_rslt = mysql_to_mysqli($qc_queue_stmt, $link);
	$q = 0;
	while ($queue_row = mysqli_fetch_array($qc_queue_rslt)) {
		$qc_id = $queue_row["qc_log_id"];
		$qc_agent = $queue_row["qc_agent"];
		$lead_id = $queue_row["lead_id"];
		$date_claimed = $queue_row["date_claimed"];
		$date_modified = $queue_row["date_modified"];
		$scorecard_source = $queue_row["scorecard_source"];
		$campaign_id = $queue_row["campaign_id"];
		$group_id = $queue_row["group_id"];
		$list_id = $queue_row["list_id"];
		$agent_log_id = $queue_row["agent_log_id"];
		$qc_recording_id = $queue_row["recording_id"];
		$qc_scorecard_id = $queue_row["qc_scorecard_id"];
		$qc_webform = $queue_row['qc_web_form_address'];
		$qc_agent_comment = $queue_row['qc_agent_comment'] ?? '';

		$redirect_URL_str = "&qc_display_group_type=$scorecard_source";
		switch ($scorecard_source) {
			case "CAMPAIGN":
				$redirect_URL_str .= "&campaign_id=$campaign_id";
				$display_ID = $campaign_id;
				break;
			case "LIST":
				$redirect_URL_str .= "&list_id=$list_id";
				$display_ID = $list_id;
				break;
			case "INGROUP":
				$redirect_URL_str .= "&group_id=$group_id";
				$display_ID = $group_id;
				break;
		}
	}
}

//if ($qc_log_id && $qc_agent != $PHP_AUTH_USER) {
 // echo "Access denied - you are not the agent who claimed this QC record.";
 // exit;
//}

if ($qc_log_id && !empty($qc_agent) && $qc_agent != $PHP_AUTH_USER) {
    if (isset($LOGuser_level) && $LOGuser_level < 8 && isset($LOGqc_user_level) && $LOGqc_user_level < 7) {
        echo "Access denied - you are not the agent who claimed this QC record.";
        exit;
    }
}
// The selected call's connected conversation time. VICIdial stores this in
// vicidial_agent_log.talk_sec, separate from wait and disposition time.
$conversation_seconds = 0;
if (!empty($agent_log_id)) {
	$conversation_stmt = "SELECT COALESCE(talk_sec, 0) AS talk_sec
		FROM vicidial_agent_log
		WHERE agent_log_id='" . mysqli_real_escape_string($link, $agent_log_id) . "'
		LIMIT 1";
	$conversation_rslt = mysql_to_mysqli($conversation_stmt, $link);
	if ($conversation_rslt && mysqli_num_rows($conversation_rslt) > 0) {
		$conversation_row = mysqli_fetch_assoc($conversation_rslt);
		$conversation_seconds = max(0, (int) ($conversation_row['talk_sec'] ?? 0));
	}
}
$conversation_time = sprintf(
	'%02d:%02d:%02d',
	intdiv($conversation_seconds, 3600),
	intdiv($conversation_seconds % 3600, 60),
	$conversation_seconds % 60
);

$label_title =				_QXZ("Title");
$label_first_name =			_QXZ("First");
$label_middle_initial =		_QXZ("MI");
$label_last_name =			_QXZ("Last");
$label_address1 =			_QXZ("Address1");
$label_address2 =			_QXZ("Address2");
$label_address3 =			_QXZ("Address3");
$label_city =				_QXZ("City");
$label_state =				_QXZ("State");
$label_province =			_QXZ("Province");
$label_postal_code =		_QXZ("Postal Code");
$label_vendor_lead_code =	_QXZ("Vendor ID");
$label_gender =				_QXZ("Gender");
$label_phone_number =		_QXZ("Phone");
$label_phone_code =			_QXZ("DialCode");
$label_alt_phone =			_QXZ("Alt. Phone");
$label_security_phrase =	_QXZ("Show");
$label_email =				_QXZ("Email");
$label_comments =			_QXZ("Comments");

### find any custom field labels
$stmt = "SELECT label_title,label_first_name,label_middle_initial,label_last_name,label_address1,label_address2,label_address3,label_city,label_state,label_province,label_postal_code,label_vendor_lead_code,label_gender,label_phone_number,label_phone_code,label_alt_phone,label_security_phrase,label_email,label_comments from system_settings;";
$rslt = mysql_to_mysqli($stmt, $link);
$row = mysqli_fetch_row($rslt);
if (strlen($row[0]) > 0) {
	$label_title =				$row[0];
}
if (strlen($row[1]) > 0) {
	$label_first_name =		$row[1];
}
if (strlen($row[2]) > 0) {
	$label_middle_initial =	$row[2];
}
if (strlen($row[3]) > 0) {
	$label_last_name =			$row[3];
}
if (strlen($row[4]) > 0) {
	$label_address1 =			$row[4];
}
if (strlen($row[5]) > 0) {
	$label_address2 =			$row[5];
}
if (strlen($row[6]) > 0) {
	$label_address3 =			$row[6];
}
if (strlen($row[7]) > 0) {
	$label_city =				$row[7];
}
if (strlen($row[8]) > 0) {
	$label_state =				$row[8];
}
if (strlen($row[9]) > 0) {
	$label_province =			$row[9];
}
if (strlen($row[10]) > 0) {
	$label_postal_code =		$row[10];
}
if (strlen($row[11]) > 0) {
	$label_vendor_lead_code =	$row[11];
}
if (strlen($row[12]) > 0) {
	$label_gender =			$row[12];
}
if (strlen($row[13]) > 0) {
	$label_phone_number =		$row[13];
}
if (strlen($row[14]) > 0) {
	$label_phone_code =		$row[14];
}
if (strlen($row[15]) > 0) {
	$label_alt_phone =			$row[15];
}
if (strlen($row[16]) > 0) {
	$label_security_phrase =	$row[16];
}
if (strlen($row[17]) > 0) {
	$label_email =				$row[17];
}
if (strlen($row[18]) > 0) {
	$label_comments =			$row[18];
}

##### BEGIN vicidial_list FIELD LENGTH LOOKUP #####
$MAXvendor_lead_code =		'20';
$MAXphone_code =			'10';
$MAXphone_number =			'18';
$MAXtitle =					'4';
$MAXfirst_name =			'30';
$MAXmiddle_initial =		'1';
$MAXlast_name =				'30';
$MAXaddress1 =				'100';
$MAXaddress2 =				'100';
$MAXaddress3 =				'100';
$MAXcity =					'50';
$MAXstate =					'2';
$MAXprovince =				'50';
$MAXpostal_code =			'10';
$MAXalt_phone =				'12';
$MAXemail =					'70';
$MAXsecurity_phrase =		'100';
$MAXcountry_code =			'3';
$MAXowner =					'20';

$stmt = "SHOW COLUMNS FROM vicidial_list;";
$rslt = mysql_to_mysqli($stmt, $link);
if ($DB) {
	echo "$stmt\n";
}
$scvl_ct = mysqli_num_rows($rslt);
$s = 0;
while ($scvl_ct > $s) {
	$row = mysqli_fetch_row($rslt);
	$vl_field =	$row[0];
	$vl_type = preg_replace("/[^0-9]/", '', $row[1]);
	if (strlen($vl_type) > 0) {
		if (($vl_field == 'vendor_lead_code') and ($MAXvendor_lead_code != $vl_type)) {
			$MAXvendor_lead_code = $vl_type;
		}
		if (($vl_field == 'phone_code') and ($MAXphone_code != $vl_type)) {
			$MAXphone_code = $vl_type;
		}
		if (($vl_field == 'phone_number') and ($MAXphone_number != $vl_type)) {
			$MAXphone_number = $vl_type;
		}
		if (($vl_field == 'title') and ($MAXtitle != $vl_type)) {
			$MAXtitle = $vl_type;
		}
		if (($vl_field == 'first_name') and ($MAXfirst_name != $vl_type)) {
			$MAXfirst_name = $vl_type;
		}
		if (($vl_field == 'middle_initial') and ($MAXmiddle_initial != $vl_type)) {
			$MAXmiddle_initial = $vl_type;
		}
		if (($vl_field == 'last_name') and ($MAXlast_name != $vl_type)) {
			$MAXlast_name = $vl_type;
		}
		if (($vl_field == 'address1') and ($MAXaddress1 != $vl_type)) {
			$MAXaddress1 = $vl_type;
		}
		if (($vl_field == 'address2') and ($MAXaddress2 != $vl_type)) {
			$MAXaddress2 = $vl_type;
		}
		if (($vl_field == 'address3') and ($MAXaddress3 != $vl_type)) {
			$MAXaddress3 = $vl_type;
		}
		if (($vl_field == 'city') and ($MAXcity != $vl_type)) {
			$MAXcity = $vl_type;
		}
		if (($vl_field == 'state') and ($MAXstate != $vl_type)) {
			$MAXstate = $vl_type;
		}
		if (($vl_field == 'province') and ($MAXprovince != $vl_type)) {
			$MAXprovince = $vl_type;
		}
		if (($vl_field == 'postal_code') and ($MAXpostal_code != $vl_type)) {
			$MAXpostal_code = $vl_type;
		}
		if (($vl_field == 'alt_phone') and ($MAXalt_phone != $vl_type)) {
			$MAXalt_phone = $vl_type;
		}
		if (($vl_field == 'email') and ($MAXemail != $vl_type)) {
			$MAXemail = $vl_type;
		}
		if (($vl_field == 'security_phrase') and ($MAXsecurity_phrase != $vl_type)) {
			$MAXsecurity_phrase = $vl_type;
		}
		if (($vl_field == 'country_code') and ($MAXcountry_code != $vl_type)) {
			$MAXcountry_code = $vl_type;
		}
		if (($vl_field == 'owner') and ($MAXowner != $vl_type)) {
			$MAXowner = $vl_type;
		}
	}
	$s++;
}
##### END vicidial_list FIELD LENGTH LOOKUP #####


//Added by Poundteam for QC. Gather record data to display on page and prepopulate title and hrefs, etc.
#$stmt="SELECT * from vicidial_list A inner join vicidial_lists B on A.list_id=B.list_id inner join vicidial_campaigns C on B.campaign_id=C.campaign_id left outer join vicidial_statuses D on A.status=D.status left outer join vicidial_qc_codes E on A.status=E.code where A.lead_id='$lead_id'";
#$rslt=mysql_to_mysqli($stmt, $link);
#if (mysqli_num_rows ($rslt) < '1' )
#	{
#   if($DB) { echo "$stmt\n"; }
#    exit();
#    }
#$row=mysqli_fetch_assoc($rslt);
#$original_record =		$row;
#$campaign_id =			$row['campaign_id'];
#$campaign_name =		$row['campaign_name'];
#$phone_number =			$row['phone_number'];
#$phone_code =			$row['phone_code'];
#$scheduled_callback =	$row['scheduled_callback'];

##### grab vicidial_list data for lead #####
$lead_stmt = "SELECT lead_id,entry_date,modify_date,status,user,vendor_lead_code,source_id,list_id,gmt_offset_now,called_since_last_reset,phone_code,phone_number,title,first_name,middle_initial,last_name,address1,address2,address3,city,state,province,postal_code,country_code,gender,date_of_birth,alt_phone,email,security_phrase,comments,called_count,last_local_call_time,rank,owner,entry_list_id from vicidial_list where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "'";
$lead_rslt = mysql_to_mysqli($lead_stmt, $link);
if ($DB) {
	echo "$stmt\n";
}
$row = mysqli_fetch_array($lead_rslt);
if (strlen($row[0]) > 0) {
	$lead_id		= $row[0];
}
$modify_date		= $row[2];
$dispo				= $row[3];
$tsr				= $row[4];
$vendor_id			= $row[5];
$list_id			= $row[7];
$gmt_offset_now		= $row[8];
$phone_code			= $row[10];
$phone_number		= $row[11];
$title				= $row[12];
$first_name			= $row[13];
$middle_initial		= $row[14];
$last_name			= $row[15];
$address1			= $row[16];
$address2			= $row[17];
$address3			= $row[18];
$city				= $row[19];
$state				= $row[20];
$province			= $row[21];
$postal_code		= $row[22];
$country_code		= $row[23];
$gender				= $row[24];
$date_of_birth		= $row[25];
$alt_phone			= $row[26];
$email				= $row[27];
$security			= $row[28];
$comments			= $row[29];
$called_count		= $row[30];
$last_local_call_time = $row[31];
$rank				= $row[32];
$owner				= $row[33];
$entry_list_id		= $row[34];
$lead_name =			trim(trim($row['first_name'] . ' ' . $row['middle_initial']) . ' ' . $row['last_name']);

### BEGIN Replace variables with values for qc_webform ###
$qc_webform = str_replace('--A--lead_id--B--', $row['lead_id'], $qc_webform);
$qc_webform = str_replace('--A--vendor_lead_code--B--', $row['vendor_lead_code'], $qc_webform);
$qc_webform = str_replace('--A--list_id--B--', $row['list_id'], $qc_webform);
$qc_webform = str_replace('--A--gmt_offset_now--B--', $row['gmt_offset_now'], $qc_webform);
$qc_webform = str_replace('--A--phone_code--B--', $row['phone_code'], $qc_webform);
$qc_webform = str_replace('--A--phone_number--B--', $row['phone_number'], $qc_webform);
$qc_webform = str_replace('--A--title--B--', $row['title'], $qc_webform);
$qc_webform = str_replace('--A--first_name--B--', $row['first_name'], $qc_webform);
$qc_webform = str_replace('--A--middle_initial--B--', $row['middle_initial'], $qc_webform);
$qc_webform = str_replace('--A--last_name--B--', $row['last_name'], $qc_webform);
$qc_webform = str_replace('--A--address1--B--', $row['address1'], $qc_webform);
$qc_webform = str_replace('--A--address2--B--', $row['address2'], $qc_webform);
$qc_webform = str_replace('--A--address3--B--', $row['address3'], $qc_webform);
$qc_webform = str_replace('--A--city--B--', $row['city'], $qc_webform);
$qc_webform = str_replace('--A--state--B--', $row['state'], $qc_webform);
$qc_webform = str_replace('--A--province--B--', $row['province'], $qc_webform);
$qc_webform = str_replace('--A--postal_code--B--', $row['postal_code'], $qc_webform);
$qc_webform = str_replace('--A--country_code--B--', $row['country_code'], $qc_webform);
$qc_webform = str_replace('--A--gender--B--', $row['gender'], $qc_webform);
$qc_webform = str_replace('--A--date_of_birth--B--', $row['date_of_birth'], $qc_webform);
$qc_webform = str_replace('--A--alt_phone--B--', $row['alt_phone'], $qc_webform);
$qc_webform = str_replace('--A--email--B--', $row['email'], $qc_webform);
$qc_webform = str_replace('--A--security_phrase--B--', $row['security_phrase'], $qc_webform);
$qc_webform = str_replace('--A--comments--B--', $row['comments'], $qc_webform);
$qc_webform = str_replace('--A--user--B--', $row['user'], $qc_webform);
$qc_webform = str_replace('--A--campaign--B--', $row['campaign'], $qc_webform);
$qc_webform = str_replace('--A--phone_login--B--', $row['phone_login'], $qc_webform);
$qc_webform = str_replace('--A--rank--B--', $row['rank'], $qc_webform);
$qc_webform = str_replace('--A--owner--B--', $row['owner'], $qc_webform);
$qc_webform = str_replace('--A--security_phrase--B--', $row['security_phrase'], $qc_webform);
$qc_webform = str_replace('--A--current_user--B--', $PHP_AUTH_USER, $qc_webform);
$qc_webform = str_replace('--A--called_count--B--', $row['called_count'], $qc_webform);
### END of replace variables code ###


$vdc_form_display = 'vdc_form_display.php';
if (preg_match("/cf_encrypt/", $active_modules)) {
	$vdc_form_display = 'vdc_form_display_encrypt.php';
}

?>
<html>

<head>
	<META HTTP-EQUIV="Content-Type" CONTENT="text/html; charset=utf-8">
	<title><?php echo _QXZ("QC Modify Lead"); ?>: <?php echo _QXZ("QC $scorecard_source") . " $display_ID, $lead_id - " . urldecode($lead_name); ?></title>
	<script language="JavaScript" src="calendar_db.js"></script>
	<script language="Javascript">
		var qc_log_id = <?php echo $qc_log_id; ?>;
		var timestamp = <?php echo $STARTtime; ?>;
		var qcPhpAuthUser = <?php echo json_encode($PHP_AUTH_USER, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
		// console.log('qc_modify_lead.php $PHP_AUTH_USER:', qcPhpAuthUser);

		function StartRefresh() {
			setInterval("CheckIfLeadUpdated()", 3000);
		}

		function CheckIfLeadUpdated() {

			var lead_id = document.getElementById('leadinfo_lead_id').value;
			var modify_date = document.getElementById('modify_date').value;
			var encoded_modify_date = encodeURIComponent(modify_date);

			var xmlhttp = false;
			try {
				xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
			} catch (e) {
				try {
					xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
				} catch (E) {
					xmlhttp = false;
				}
			}
			if (!xmlhttp && typeof XMLHttpRequest != 'undefined') {
				xmlhttp = new XMLHttpRequest();
				var lead_query = "&qc_action=check_lead_status&lead_id=" + lead_id + "&modify_date=" + modify_date;
				// alert(update_query); return false;
				xmlhttp.open('POST', 'qc_session_actions.php');
				xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xmlhttp.send(lead_query);
				xmlhttp.onreadystatechange = function() {
					if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
						QueryText = xmlhttp.responseText;
						var QueryText_array = QueryText.split("\n");
						if (QueryText_array[0] == 1) {
							// alert("LEAD MODIFIED");
							LeadDataArray = QueryText_array[1].split("|");
							document.getElementById('modify_date').value = LeadDataArray[0];
							document.getElementById('dispo').value = LeadDataArray[1];
							document.getElementById('dispo_display').innerHTML = LeadDataArray[1];
							document.getElementById('vendor_id_display').innerHTML = LeadDataArray[2];
							document.getElementById('tsr_display').innerHTML = "<A HREF='user_stats.php?user=" + LeadDataArray[3] + "' target='_blank'>" + LeadDataArray[3] + "</A>";
							document.getElementById('called_count_display').innerHTML = LeadDataArray[4];
							document.getElementById('title').value = LeadDataArray[5];
							document.getElementById('first_name').value = LeadDataArray[6];
							document.getElementById('middle_initial').value = LeadDataArray[7];
							document.getElementById('last_name').value = LeadDataArray[8];
							document.getElementById('address1').value = LeadDataArray[9];
							document.getElementById('address2').value = LeadDataArray[10];
							document.getElementById('address3').value = LeadDataArray[11];
							document.getElementById('city').value = LeadDataArray[12];
							document.getElementById('state').value = LeadDataArray[13];
							document.getElementById('postal_code').value = LeadDataArray[14];
							document.getElementById('province').value = LeadDataArray[15];
							document.getElementById('country_code').value = LeadDataArray[16];
							document.getElementById('phone_number').value = LeadDataArray[17];
							document.getElementById('phone_code').value = LeadDataArray[18];
							document.getElementById('alt_phone').value = LeadDataArray[19];
							document.getElementById('email').value = LeadDataArray[20];
							document.getElementById('security').value = LeadDataArray[21];
							document.getElementById('rank').value = LeadDataArray[22];
							document.getElementById('owner').value = LeadDataArray[23];
							document.getElementById('comments').innerHTML = LeadDataArray[24];
						}
					}
				}
				delete xmlhttp;
			}
		}

		function ToggleQCSpans(SpanToShow) {
			var panelIds = [
				'qc_master_span',
				'qc_summary_span',
				'lead_information_span',
				'qc_call_information',
				'call_logs_span'
			];

			for (var i = 0; i < panelIds.length; i++) {
				var panel = document.getElementById(panelIds[i]);
				if (panel) panel.style.display = panelIds[i] === SpanToShow ? 'block' : 'none';
			}

			if (SpanToShow === 'qc_master_span') {
				var scoreValueIds = ['qc_total_earned_points', 'qc_total_score', 'qc_total_max_points'];
				for (var scoreIndex = 0; scoreIndex < scoreValueIds.length; scoreIndex++) {
					var scoreValue = document.getElementById(scoreValueIds[scoreIndex]);
					if (scoreValue) scoreValue.style.display = 'inline';
				}
				RefreshQcScoreTotals();
			}

			// Legacy VICIdial panels may use HTTP Basic authentication. Loading their
			// iframes while the panels are hidden causes Chrome to show a sign-in
			// dialog over an otherwise authenticated QC page. Defer each request until
			// the user actually opens the corresponding panel.
			var activeSpan = document.getElementById(SpanToShow);
			if (activeSpan) {
				var deferredFrames = activeSpan.querySelectorAll('iframe[data-src]');
				for (var frameIndex = 0; frameIndex < deferredFrames.length; frameIndex++) {
					var deferredFrame = deferredFrames[frameIndex];
					if (!deferredFrame.getAttribute('src')) {
						deferredFrame.setAttribute('src', deferredFrame.getAttribute('data-src'));
					}
				}
			}
		}

		function FinishQCRecord(qc_row_id, qc_process_status, active_form) {
			var unfilled_fields = 0;

			// Intake questions crap
			var elements = document.getElementById("qc_form").elements;
			var intake_str = "";
			for (var i = 0, element; element = elements[i++];) {
				if (element.name.match(/checkpoint_points_earned/)) {
					var checkpoint_points_text = "";
					var checkpoint_points_text = element.value.replace(/[^0-9]/g, "");
					if (checkpoint_points_text == "") {
						checkpoint_points_text = "0";
					}
					element.value = checkpoint_points_text;
				} else if (element.name.match(/checkpoint_comment_agent/)) {
					var checkpoint_comments_text = "";
					var checkpoint_comments_text = element.value.replace(/[\r\n]+/g, " -- ");
					element.value = checkpoint_comments_text;
				}
			}
			document.qc_form.qc_process_status.value = qc_process_status;
			active_form.submit();
		}

		function RefreshQcScoreTotals() {
			var pointFields = document.querySelectorAll('[id^="checkpoint_points_earned"][data-max-points]');
			var earnedPoints = 0;
			var maxPoints = 0;

			for (var i = 0; i < pointFields.length; i++) {
				var field = pointFields[i];
				var maximum = Number(field.dataset.maxPoints || 0);
				var earned = Number(field.value || 0);

				if (!Number.isFinite(maximum) || maximum < 0) maximum = 0;
				if (!Number.isFinite(earned) || earned < 0) earned = 0;
				if (earned > maximum) earned = maximum;

				earnedPoints += earned;
				maxPoints += maximum;
			}

			var percentage = maxPoints > 0 ? (100 * earnedPoints / maxPoints) : 0;
			var earnedDisplay = document.getElementById('qc_total_earned_points');
			var maxDisplay = document.getElementById('qc_total_max_points');
			var scoreDisplay = document.getElementById('qc_total_score');

			if (earnedDisplay) earnedDisplay.textContent = earnedPoints.toFixed(2);
			if (maxDisplay) maxDisplay.textContent = maxPoints.toFixed(2);
			if (scoreDisplay) scoreDisplay.textContent = percentage.toFixed(2) + '%';
		}

		function SyncQcStoredTotals() {
			return fetch('qc_update_totals.php', {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				credentials: 'same-origin',
				body: JSON.stringify({qc_log_id: String(qc_log_id)})
			})
				.then(function(response) {
					return response.json().then(function(data) {
						return response.ok && data.success;
					});
				})
				.catch(function() { return false; });
		}

		document.addEventListener('DOMContentLoaded', function() {
			if (qc_log_id) SyncQcStoredTotals();
		});

		function AddToComments(qc_log_row_id, level) {
			var checkpoint_comment_field_ID = "checkpoint_comment_agent" + qc_log_row_id;
			if (document.getElementById(checkpoint_comment_field_ID).value.length > 0) {
				document.getElementById(checkpoint_comment_field_ID).value += "\n";
			}
			if (level > 0) {
				var RecordingPos = document.getElementById("main_QC_recording").currentTime;
				var strPos = 11;
				var strLen = 8;
				if (RecordingPos < 600) {
					strPos = 15;
					strLen = 4;
				} else if (RecordingPos < 3600) {
					strPos = 14;
					strLen = 5;
				} else if (RecordingPos < 36000) {
					strPos = 12;
					strLen = 7;
				}

				var currentTimestamp = new Date(RecordingPos * 1000).toISOString().substr(strPos, strLen);
				if (document.getElementById(checkpoint_comment_field_ID)) {
					document.getElementById(checkpoint_comment_field_ID).value += currentTimestamp + " - ";
				}
			}
			if (level % 2 == 0) {
				var checkpoint_comment_preset_ID = "checkpoint_text_presets" + qc_log_row_id;
				if (document.getElementById(checkpoint_comment_field_ID)) {
					var preset_field = document.getElementById(checkpoint_comment_preset_ID);
					var preset_field_value = preset_field.options[preset_field.selectedIndex].value;
					document.getElementById(checkpoint_comment_field_ID).value += preset_field_value;
				}
			}
			LogQCData(qc_log_row_id);

		}

		function LogQCData(qc_log_row_id, skipTotalsSync) {
			var xmlhttp = false;
			var checkpoint_comment_field_ID = "checkpoint_comment_agent" + qc_log_row_id;
			var instant_fail_field_ID = "instant_fail_value" + qc_log_row_id;
			var checkpoint_points_field_ID = "checkpoint_points_earned" + qc_log_row_id;

			if (document.getElementById(checkpoint_comment_field_ID)) {
				var qc_ac = document.getElementById(checkpoint_comment_field_ID).value;
				var qc_agent_comments = qc_ac.replace("&", "and");
			} else {
				var qc_agent_comments = "";
			}
			var encoded_qc_agent_comments = encodeURIComponent(qc_agent_comments);

			if (document.getElementById(instant_fail_field_ID)) {
				if (document.getElementById(instant_fail_field_ID).checked) {
					var instant_fail_value = document.getElementById(instant_fail_field_ID).value;
				} else {
					var instant_fail_value = "N";
				}
			} else {
				var instant_fail_value = "N";
			}

			if (document.getElementById(checkpoint_points_field_ID)) {
				var checkpoint_points_earned = document.getElementById(checkpoint_points_field_ID).value;
			} else {
				var checkpoint_points_earned = 0;
			}


			try {
				xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
			} catch (e) {
				try {
					xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
				} catch (E) {
					xmlhttp = false;
				}
			}
			if (!xmlhttp && typeof XMLHttpRequest != 'undefined') {
				xmlhttp = new XMLHttpRequest();
				var update_query = "&view_epoch=" + timestamp + "&qc_log_id=" + qc_log_id + "&qc_checkpoint_log_id=" + qc_log_row_id + "&checkpoint_comment_agent=" + encoded_qc_agent_comments + "&instant_fail_value=" + instant_fail_value + "&checkpoint_points_earned=" + checkpoint_points_earned + "&qc_action=upd_customer";
				xmlhttp.open('POST', 'qc_session_actions.php');
				xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

				return new Promise(function(resolve) {
					xmlhttp.onreadystatechange = function() {
						if (xmlhttp.readyState == 4) {
							if (xmlhttp.status >= 200 && xmlhttp.status < 300) {
								QASpanText = xmlhttp.responseText;
								if (skipTotalsSync) {
									resolve(true);
								} else {
									SyncQcStoredTotals().then(function(success) { resolve(success); });
								}
							} else {
								resolve(false);
							}
						}
					};
					xmlhttp.onerror = function() {
						resolve(false);
					};
					xmlhttp.send(update_query);
				});
			}

			return Promise.resolve(false);
		}

		function SaveQcAgentComment(button) {
			var commentField = document.getElementById('qc_agent_comment');
			var statusField = document.getElementById('qc-comment-save-status');

			if (!commentField || !qc_log_id) {
				if (statusField) statusField.textContent = 'Unable to identify this QC record.';
				return;
			}

			button.disabled = true;
			button.value = 'SAVING...';
			if (statusField) statusField.textContent = '';

			fetch('qc_modify_lead.php?qc_action=save_agent_comment', {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				credentials: 'same-origin',
				body: JSON.stringify({
					qc_log_id: String(qc_log_id),
					qc_agent_comment: commentField.value
				})
			})
				.then(function(response) {
					return response.json().then(function(data) {
						if (!response.ok || !data.success) {
							throw new Error(data.message || 'Could not save comment.');
						}
						if (statusField) statusField.textContent = 'Comment saved.';
					});
				})
				.catch(function(error) {
					if (statusField) statusField.textContent = error.message || 'Comment could not be saved.';
				})
				.then(function() {
					button.disabled = false;
					button.value = 'SAVE QC COMMENT';
				});
		}

		function UpdateCustomerInfo(lead_id, field_name, field_value) {
			var xmlhttp = false;
			try {
				xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
			} catch (e) {
				try {
					xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
				} catch (E) {
					xmlhttp = false;
				}
			}
			if (!xmlhttp && typeof XMLHttpRequest != 'undefined') {
				xmlhttp = new XMLHttpRequest();
				var update_query = "&view_epoch=" + timestamp + "&qc_log_id=" + qc_log_id + "&qc_action=update_vl_field&lead_id=" + lead_id + "&field_name=" + field_name + "&field_value=" + field_value;
				xmlhttp.open('POST', 'qc_session_actions.php');
				xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xmlhttp.send(update_query);
				xmlhttp.onreadystatechange = function() {
					if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
						QASpanText = xmlhttp.responseText;
						// alert(QASpanText);
						// if (QASpanText==1) {alert("CUSTOMER INFO UPDATED");} else {alert("UPDATE FAILED");}
					}
				}
				delete xmlhttp;
			}
		}

		function UpdateCallback(callback_id, recipient, CBuser) {
			var xmlhttp = false;
			try {
				xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
			} catch (e) {
				try {
					xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
				} catch (E) {
					xmlhttp = false;
				}
			}
			if (!xmlhttp && typeof XMLHttpRequest != 'undefined') {
				xmlhttp = new XMLHttpRequest();
				var update_query = "&view_epoch=" + timestamp + "&qc_action=update_callback&qc_log_id=" + qc_log_id + "&callback_id=" + callback_id + "&recipient=" + recipient + "&CBuser=" + CBuser;
				/// alert(update_query); // return false;
				xmlhttp.open('POST', 'qc_session_actions.php');
				xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xmlhttp.send(update_query);
				xmlhttp.onreadystatechange = function() {
					if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
						QASpanText = xmlhttp.responseText;
						if (QASpanText == 1) {
							alert("The callback owner was updated successfully.");
						} else {
							alert("The callback owner could not be updated. Please try again.");
						}
					}
				}
				delete xmlhttp;
			}
		}

		function ChangeCallbackDateTime(callback_id) {
			var callback_date = document.getElementById('appointment_date').value;

			var appointment_hourFORM = document.getElementById('appointment_hour');
			var appointment_hourVALUE = appointment_hourFORM[appointment_hourFORM.selectedIndex].text;
			var appointment_minFORM = document.getElementById('appointment_min');
			var appointment_minVALUE = appointment_minFORM[appointment_minFORM.selectedIndex].text;
			var callback_time = callback_date + " " + appointment_hourVALUE + ":" + appointment_minVALUE + ":00";

			var qc_cb_comments_field = document.getElementById('callback_comments');
			var qc_cb = qc_cb_comments_field.value;
			var qc_callback_comments = qc_cb.replace("&", "and");
			var encoded_qc_callback_comments = encodeURIComponent(qc_callback_comments);

			var xmlhttp = false;
			try {
				xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
			} catch (e) {
				try {
					xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
				} catch (E) {
					xmlhttp = false;
				}
			}
			if (!xmlhttp && typeof XMLHttpRequest != 'undefined') {
				xmlhttp = new XMLHttpRequest();
				var update_query = "&view_epoch=" + timestamp + "&qc_action=update_callback&qc_log_id=" + qc_log_id + "&callback_id=" + callback_id + "&callback_time=" + callback_time + "&callback_comments=" + encoded_qc_callback_comments;
				// alert(update_query); return false;
					xmlhttp.open('POST', 'qc_session_actions.php');
				xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
				xmlhttp.send(update_query);
				xmlhttp.onreadystatechange = function() {
					if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
						QASpanText = xmlhttp.responseText;
						if (QASpanText == 1) {
							alert("The callback time was updated successfully.");
						} else {
							alert("The callback time could not be updated. Please try again.");
						}
					}
				}
				delete xmlhttp;
			}

		}

		function LogAudioRecordingAccess(log_active, recording_id, lead_id, recording_object_ID) {
			if (log_active) {
				var xmlhttp = false;
				try {
					xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
				} catch (e) {
					try {
						xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
					} catch (E) {
						xmlhttp = false;
					}
				}
				if (!xmlhttp && typeof XMLHttpRequest != 'undefined') {
					xmlhttp = new XMLHttpRequest();
				}
				if (xmlhttp) {
					var log_query = "&no_redirect=1&recording_id=" + recording_id + "&lead_id=" + lead_id;
					xmlhttp.open('POST', 'recording_log_redirect.php');
					xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
					xmlhttp.send(log_query);
					xmlhttp.onreadystatechange = function() {
						if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
							var response = xmlhttp.responseText;
							if (response != "OK") {
								document.getElementById(recording_object_ID).pause();
								alert("This recording cannot be played right now. Please try again or contact your supervisor.");
							}
						}
					}
					delete xmlhttp;
				}
			}
		}
	</script>
	<link rel="stylesheet" href="calendar.css">
	<link rel="stylesheet" type="text/css" href="vicidial_stylesheet.php">
	<style>
		:root {
			--qc-bg: #020c12;
			--qc-surface: #06151b;
			--qc-surface-alt: #0a1d24;
			--qc-border: #13515a;
			--qc-accent: #00e6bd;
			--qc-accent-hover: #42f3d1;
			--qc-text: #e8f1f2;
			--qc-muted: #91a4a8;
			--qc-danger: #ff6577;
			--qc-input: #031118;
			--qc-header: #031016;
			--qc-button-text: #00100d;
			--qc-danger-text: #140307;
			--qc-shadow: rgba(0, 0, 0, 0.3);
		}

		html.qc-light-theme {
			--qc-bg: #ffffff;
			--qc-surface: #f1f3f3;
			--qc-surface-alt: #f8f9f9;
			--qc-border: #c4d7d8;
			--qc-accent: #007d69;
			--qc-accent-hover: #006653;
			--qc-text: #15252b;
			--qc-muted: #5d7074;
			--qc-danger: #b8404b;
			--qc-input: #ffffff;
			--qc-header: #e5ebeb;
			--qc-button-text: #ffffff;
			--qc-danger-text: #ffffff;
			--qc-shadow: rgba(0, 33, 48, 0.12);
		}

		html,
		body {
			min-height: 100%;
			background: var(--qc-bg) !important;
			color: var(--qc-text) !important;
			font-family: Arial, Helvetica, sans-serif !important;
		}

		body {
			padding: 22px 0 40px;
			font-size: 16px !important;
		}

		body table {
			background-color: var(--qc-surface) !important;
			color: var(--qc-text) !important;
			border-color: var(--qc-border) !important;
			border-collapse: collapse;
		}

		body table[width="1000"] {
			width: min(1180px, 96vw) !important;
			border: 1px solid var(--qc-border);
			box-shadow: 0 16px 50px var(--qc-shadow);
			border-radius: 14px;
			overflow: hidden;
		}

		body #lead_information_span table {
			border-collapse: separate !important;
			border-spacing: 0 10px !important;
		}

		body #lead_information_span table td {
			padding: 6px 10px !important;
			line-height: 1.5 !important;
		}

		body #call_logs_span table {
			border-collapse: separate !important;
			border-spacing: 0 6px !important;
		}

		body #call_logs_span table td,
		body #call_logs_span table th {
			padding: 9px 10px !important;
			line-height: 1.5 !important;
		}

		body tr[bgcolor],
		body td[bgcolor],
		body th[bgcolor] {
			background: var(--qc-surface) !important;
		}

		body tr[bgcolor]:nth-child(even) {
			background: var(--qc-surface-alt) !important;
		}

		body tr[bgcolor="#000"],
		body tr[bgcolor="#000000"] {
			background: var(--qc-header) !important;
		}

		body td,
		body th {
			color: var(--qc-text) !important;
			border-color: var(--qc-border) !important;
		}

		body tr[bgcolor] > td,
		body tr[bgcolor] > th {
			border-bottom: 1px solid var(--qc-border);
			padding: 9px 10px;
		}

		body font,
		body .standard,
		body .standard_bold,
		body .small_standard,
		body .white_text,
		body font[color="BLACK"],
		body font[color="black"] {
			color: var(--qc-text) !important;
			font-size: 15px !important;
		}

		body th,
		body th font,
		body .standard_bold {
			font-size: 16px !important;
		}

		body .small_standard,
		body .small_standard_bold {
			font-size: 14px !important;
		}

		body a,
		body a:visited {
			color: var(--qc-accent) !important;
			text-decoration-color: var(--qc-accent) !important;
		}

		body a:hover {
			color: var(--qc-accent-hover) !important;
		}

		body a.qc-ai-action,
		body a.qc-ai-action:visited {
			background: var(--qc-accent) !important;
			color: var(--qc-button-text) !important;
			border: 1px solid var(--qc-accent) !important;
			font-size: 14px !important;
			box-shadow: 0 5px 18px rgba(0, 230, 189, 0.16);
		}

		body a.qc-ai-action:hover {
			background: var(--qc-accent-hover) !important;
			border-color: var(--qc-accent-hover) !important;
		}

		body #qc_master_span .qc-ai-steps {
			display: flex;
			flex-direction: column;
			gap: 10px;
			width: min(400px, 100%);
			margin: 6px auto;
			position: relative;
		}

		body #qc_master_span .qc-ai-steps::before {
			content: "";
			position: absolute;
			top: 28px;
			bottom: 28px;
			left: 15px;
			width: 2px;
			background: var(--qc-border);
		}

		body #qc_master_span .qc-ai-step {
			display: grid;
			grid-template-columns: 32px minmax(0, 1fr);
			align-items: center;
			gap: 10px;
			padding: 0;
			background: transparent;
			border: 0;
		}

		body #qc_master_span .qc-ai-step-number {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 30px;
			height: 30px;
			background: var(--qc-accent);
			color: var(--qc-button-text);
			border: 2px solid var(--qc-surface);
			border-radius: 50%;
			font: 700 13px/1 Arial, sans-serif;
			position: relative;
			z-index: 1;
		}

		body #qc_master_span .qc-ai-step-content {
			display: flex;
			flex-direction: column;
			align-items: stretch;
			min-width: 0;
		}

		body #qc_master_span .qc-ai-step .qc-ai-action {
			width: 100% !important;
			max-width: 100%;
			min-height: 36px !important;
			padding: 7px 14px !important;
			font-size: 12px !important;
			box-shadow: none !important;
		}

		body input[type="text"],
		body input[type="password"],
		body input[type="email"],
		body input[type="number"],
		body select,
		body textarea,
		body .cust_form {
			background: var(--qc-input) !important;
			color: var(--qc-text) !important;
			border: 1px solid var(--qc-border) !important;
			border-radius: 5px !important;
			font-size: 15px !important;
			box-sizing: border-box;
			outline: none;
		}

		body input[type="text"],
		body input[type="password"],
		body input[type="email"],
		body input[type="number"],
		body select {
			min-height: 30px;
			padding: 4px 8px;
		}

		body textarea {
			padding: 8px 10px;
			resize: vertical;
		}

		body #qc_master_span textarea[id^="checkpoint_comment_agent"] {
			width: clamp(460px, 34vw, 680px) !important;
			max-width: 100% !important;
			min-height: 150px;
			font-size: 14px;
			line-height: 1.4;
		}

		body #qc_master_span .qc-comment-action {
			width: auto !important;
			min-width: 170px;
			height: auto !important;
			min-height: 38px;
			padding: 7px 14px !important;
			font-size: 13px !important;
			line-height: 1.2 !important;
			white-space: nowrap;
		}

		body #qc_master_span .qc-score-summary {
			display: grid;
			grid-template-columns: repeat(4, minmax(0, 1fr));
			width: 100%;
			margin: 0 0 16px;
			border: 1px solid var(--qc-border);
			border-radius: 10px;
			overflow: hidden;
			background: var(--qc-header) !important;
			color: var(--qc-text) !important;
			box-sizing: border-box;
		}

		body #qc_master_span .qc-score-summary-item {
			padding: 16px 18px;
			border-right: 1px solid var(--qc-border);
			font-size: 16px !important;
			font-weight: 700;
			text-align: center;
			display: flex;
			align-items: center;
			justify-content: center;
			gap: 6px;
			white-space: nowrap;
		}

		body #qc_master_span .qc-score-summary-item:last-child { border-right: 0; }

		body #qc_master_span .qc-score-summary-value {
			color: var(--qc-accent) !important;
			font-size: 18px;
		}
		body #qc_master_span .qc-ai-summary-panel {
			margin: 10px;
			padding: 16px;
			background: var(--qc-surface, #06151b);
			color: var(--qc-text, #e8f1f2);
			border: 1px solid var(--qc-border, #13515a);
			border-radius: 8px;
			font: 14px/1.6 Arial, sans-serif;
			text-align: left;
			white-space: pre-wrap;
		}
		body #qc_master_span .qc-ai-summary-panel[hidden] { display: none; }
		body #qc_master_span .qc-ai-summary-panel::before {
			content: "AI Call Summary";
			display: block;
			margin-bottom: 10px;
			color: var(--qc-accent, #00e6bd);
			font-size: 16px;
			font-weight: 700;
		}
		body #qc_master_span .qc-ai-summary-panel strong { color: var(--qc-accent, #00e6bd); }
		body #qc_master_span .qc-ai-summary-panel ul,
		body #qc_master_span .qc-ai-summary-panel ol { margin: 8px 0; padding-left: 24px; }
		body #qc_master_span .qc-ai-summary-panel.is-no-conversation {
			color: var(--qc-muted, #91a4a8);
			border-color: #a77a2f;
			background: rgba(167, 122, 47, .10);
		}

		body #qc-ai-sentiment-panel {
			display: block;
			width: 100%;
			text-align: left;
		}

		body .qc-sentiment-panel {
			width: 100%;
			padding: 22px;
			background: var(--qc-surface);
			border: 1px solid var(--qc-border);
			border-radius: 12px;
			box-sizing: border-box;
		}

		body .qc-sentiment-heading {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 14px;
			margin-bottom: 18px;
		}

		body .qc-sentiment-heading h2 { margin: 0; color: var(--qc-accent); font-size: 21px; }
		body .qc-sentiment-demo { padding: 5px 10px; color: var(--qc-muted); border: 1px solid var(--qc-border); border-radius: 999px; font-size: 12px; font-weight: 700; }
		body .qc-sentiment-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px; }
		body .qc-sentiment-card { padding: 18px; background: var(--qc-header); border: 1px solid var(--qc-border); border-radius: 10px; }
		body .qc-sentiment-card-wide { grid-column: 1 / -1; }
		body .qc-sentiment-card-title { margin-bottom: 10px; color: var(--qc-text); font-size: 16px; font-weight: 700; }
		body .qc-sentiment-score-row { display: flex; align-items: center; gap: 14px; margin-bottom: 11px; }
		body .qc-sentiment-score { min-width: 70px; color: var(--qc-accent); font-size: 25px; font-weight: 800; }
		body .qc-sentiment-meter { flex: 1; height: 9px; overflow: hidden; background: var(--qc-border); border-radius: 999px; }
		body .qc-sentiment-meter-fill { height: 100%; background: var(--qc-accent); border-radius: inherit; }
		body .qc-sentiment-reason { margin: 0; color: var(--qc-muted); font-size: 14px; line-height: 1.55; }
		body .qc-sentiment-reason strong { color: var(--qc-text); }
		body .qc-emotion-list { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 12px; }
		body .qc-emotion-chip { padding: 7px 11px; color: var(--qc-accent); background: var(--qc-surface-alt); border: 1px solid var(--qc-accent); border-radius: 999px; font-size: 13px; font-weight: 700; }
		body .qc-behavior-flags { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
		body .qc-behavior-flag { padding: 9px 11px; color: var(--qc-text); background: var(--qc-surface-alt); border-left: 3px solid #ff6b6b; border-radius: 6px; font-size: 13px; line-height: 1.45; }
		body .qc-behavior-flag strong { color: #ff8d8d; }
		body .qc-behavior-clear { margin-top: 10px; color: var(--qc-accent); font-size: 13px; font-weight: 700; }
		body .qc-outcome-value { margin: 3px 0 10px; color: var(--qc-accent); font-size: 23px; font-weight: 800; }
		body .qc-coaching-card { border-left: 4px solid var(--qc-accent); }
		body .qc-coaching-list { display: grid; gap: 12px; margin-top: 14px; }
		body .qc-coaching-item { padding: 13px; background: var(--qc-surface-alt); border-radius: 8px; }
		body .qc-coaching-item-title { margin-bottom: 7px; color: var(--qc-accent); font-size: 14px; font-weight: 800; }
		body .qc-coaching-example { margin-top: 7px; padding-left: 10px; color: var(--qc-text); border-left: 2px solid var(--qc-accent); font-style: italic; }

		@media (max-width: 1100px) {
			body #qc_master_span .qc-score-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
			body #qc_master_span .qc-score-summary-item { border-bottom: 1px solid var(--qc-border); }
			body #qc_master_span .qc-score-summary-item:nth-child(2n) { border-right: 0; }
			body #qc_master_span .qc-score-summary-item:nth-last-child(-n+2) { border-bottom: 0; }
		}

		@media (max-width: 760px) {
			body #qc_master_span .qc-score-summary { grid-template-columns: 1fr; }
			body #qc_master_span .qc-score-summary-item { border-right: 0; border-bottom: 1px solid var(--qc-border); }
			body #qc_master_span .qc-score-summary-item:last-child { border-bottom: 0; }
			body .qc-sentiment-grid { grid-template-columns: 1fr; }
			body .qc-sentiment-card-wide { grid-column: auto; }
		}

		body input:focus,
		body select:focus,
		body textarea:focus {
			border-color: var(--qc-accent) !important;
			box-shadow: 0 0 0 2px rgba(0, 230, 189, 0.16);
		}

		body input[type="checkbox"],
		body input[type="radio"] {
			accent-color: var(--qc-accent);
		}

		body input[type="button"],
		body input[type="submit"],
		body button {
			min-height: 32px;
			padding: 5px 12px !important;
			background: transparent !important;
			color: var(--qc-accent) !important;
			border: 1px solid var(--qc-accent) !important;
			border-radius: 5px !important;
			font-family: Arial, Helvetica, sans-serif !important;
			font-size: 14px !important;
			font-weight: 700 !important;
			cursor: pointer;
		}

		body input[type="button"]:hover,
		body input[type="submit"]:hover,
		body button:hover {
			background: var(--qc-accent) !important;
			color: var(--qc-button-text) !important;
		}

		body .green_btn {
			background: var(--qc-accent) !important;
			color: var(--qc-button-text) !important;
		}

		body .red_btn {
			color: var(--qc-danger) !important;
			border-color: var(--qc-danger) !important;
		}

		body .red_btn:hover {
			background: var(--qc-danger) !important;
			color: var(--qc-danger-text) !important;
		}

		body audio {
			max-width: 100%;
			filter: saturate(0.8) hue-rotate(105deg);
		}

		body iframe {
			border-color: var(--qc-border) !important;
		}

		body hr {
			border-color: var(--qc-border);
		}

		::selection {
			background: var(--qc-accent);
			color: var(--qc-button-text);
		}

		html.qc-light-theme body table[width="1000"] {
			box-shadow: 0 14px 38px var(--qc-shadow);
		}

		html.qc-light-theme body tr[bgcolor]:nth-child(even),
		html.qc-light-theme body tr[bgcolor]:nth-child(even) > td {
			background: var(--qc-surface-alt) !important;
		}

		html.qc-light-theme body th,
		html.qc-light-theme body .white_text {
			color: var(--qc-accent) !important;
		}

		html.qc-light-theme body input[type="button"],
		html.qc-light-theme body input[type="submit"],
		html.qc-light-theme body button {
			background: #ffffff !important;
		}

		html.qc-light-theme body input[type="button"]:hover,
		html.qc-light-theme body input[type="submit"]:hover,
		html.qc-light-theme body button:hover {
			background: var(--qc-accent) !important;
			color: var(--qc-button-text) !important;
		}

		html.qc-light-theme body input.green_btn {
			background: #ffffff !important;
			color: var(--qc-accent) !important;
			border-color: var(--qc-accent) !important;
		}

		html.qc-light-theme body input.green_btn:hover {
			background: var(--qc-accent) !important;
			color: #ffffff !important;
		}

		body .qc-theme-toggle {
			position: fixed;
			top: 14px;
			right: 16px;
			z-index: 10000;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			min-height: 38px;
			padding: 8px 14px;
			background: var(--qc-surface-alt) !important;
			color: var(--qc-accent) !important;
			border: 1px solid var(--qc-accent) !important;
			border-radius: 999px;
			box-shadow: 0 7px 22px var(--qc-shadow);
			font-size: 13px;
			font-weight: 700;
			cursor: pointer;
		}

		body .qc-theme-toggle:hover {
			background: var(--qc-accent) !important;
			color: var(--qc-button-text) !important;
		}

		html.qc-light-theme body .qc-theme-toggle {
			background: #ffffff !important;
			color: var(--qc-accent) !important;
		}

		html.qc-light-theme body .qc-theme-toggle:hover {
			background: var(--qc-accent) !important;
			color: #ffffff !important;
		}

		@media (max-width: 1050px) {
			body table[width="1000"] { width: 96vw !important; }
			body { overflow-x: auto; }
		}
	</style>
	<script>
	(function () {
		function storedTheme() {
			var requestedTheme = String(new URLSearchParams(window.location.search).get('qc_theme') || '').toLowerCase();
			if (requestedTheme === 'light') return true;
			if (requestedTheme === 'dark') return false;
			var sharedTheme = String(localStorage.getItem('qc-theme') || '').toLowerCase();
			if (sharedTheme === 'light') return true;
			if (sharedTheme === 'dark') return false;
			var light = null;
			for (var i = 0; i < localStorage.length; i++) {
				var key = localStorage.key(i) || '';
				if (!/(theme|mode|dark|light)/i.test(key)) continue;
				var value = String(localStorage.getItem(key) || '').toLowerCase();
				if (/light/.test(value)) return true;
				if (/dark/.test(value)) return false;
				if (/dark/i.test(key) && /^(false|0|off|no)$/.test(value)) light = true;
				if (/dark/i.test(key) && /^(true|1|on|yes)$/.test(value)) light = false;
			}
			return light;
		}

		function openerTheme() {
			try {
				if (!window.opener || window.opener.closed) return null;
				var doc = window.opener.document;
				if (doc.documentElement.classList.contains('qc-light-theme')) return true;
				var target = doc.querySelector('.inner_main_right') || doc.body;
				var values = window.opener.getComputedStyle(target).backgroundColor.match(/[\d.]+/g);
				if (!values || values.length < 3 || (values.length > 3 && Number(values[3]) === 0)) return null;
				return ((values[0] * 299 + values[1] * 587 + values[2] * 114) / 1000) > 170;
			} catch (error) { return null; }
		}

		function applyQcTheme() {
			var light = storedTheme();
			// if (light === null) light = openerTheme();
			// if (light === null) light = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
			if (light === null) light = false;
			document.documentElement.classList.toggle('qc-light-theme', !!light);
			updateThemeButton();
		}

		function updateThemeButton() {
			var button = document.getElementById('qc-theme-toggle');
			if (!button) return;
			var light = document.documentElement.classList.contains('qc-light-theme');
			button.textContent = light ? '☾ Dark theme' : '☀ Light theme';
			button.setAttribute('aria-label', light ? 'Switch to dark theme' : 'Switch to light theme');
		}

		window.toggleQcTheme = function () {
			var useLightTheme = !document.documentElement.classList.contains('qc-light-theme');
			localStorage.setItem('qc-theme', useLightTheme ? 'light' : 'dark');
			document.documentElement.classList.toggle('qc-light-theme', useLightTheme);

			var url = new URL(window.location.href);
			url.searchParams.set('qc_theme', useLightTheme ? 'light' : 'dark');
			window.history.replaceState({}, '', url.toString());

			document.querySelectorAll('a[href*="ai_summary_page.php"]').forEach(function (link) {
				var summaryUrl = new URL(link.href, window.location.href);
				var themeName = useLightTheme ? 'light' : 'dark';
				summaryUrl.searchParams.set('qc_theme', themeName);
				var returnUrl = summaryUrl.searchParams.get('return_url');
				if (returnUrl) {
					var parsedReturnUrl = new URL(returnUrl, window.location.origin);
					parsedReturnUrl.searchParams.set('qc_theme', themeName);
					summaryUrl.searchParams.set('return_url', parsedReturnUrl.pathname + parsedReturnUrl.search);
				}
				link.href = summaryUrl.toString();
			});
			updateThemeButton();
		};

		applyQcTheme();
		document.addEventListener('DOMContentLoaded', updateThemeButton);
		window.addEventListener('storage', applyQcTheme);
		if (window.matchMedia) {
			var scheme = window.matchMedia('(prefers-color-scheme: light)');
			if (scheme.addEventListener) scheme.addEventListener('change', applyQcTheme);
			else if (scheme.addListener) scheme.addListener(applyQcTheme);
		}
	})();
	</script>
</head>

<BODY onLoad="StartRefresh()" BGCOLOR="#020c12" marginheight=0 marginwidth=0 leftmargin=0 topmargin=0>
	<button type="button" id="qc-theme-toggle" class="qc-theme-toggle" onclick="toggleQcTheme()" aria-label="Switch color theme">☀ Light theme</button>
	<!-- 'FORM_LOADED' is in here because vdc_form_display calls it. //-->
	<input type='hidden' id='FORM_LOADED'>

	<tr>
		<td align='center' colspan='5'>

			<?php
			if ($DB) {
				echo __LINE__ . "\n";
			}


			echo "<table border=0 cellpadding=0 cellspacing=0 bgcolor='#" . $SSstd_row1_background . "' align='center' width='1000'>\n";
			echo "<tr><td align='center' colspan='5'><FONT FACE='Courier' COLOR=BLACK SIZE=3><B>" . _QXZ("QC $scorecard_source") . " $display_ID: " . _QXZ("Lead") . " $lead_id - " . urldecode($lead_name) . "&nbsp;&nbsp;&nbsp;<a href='$back_link'>GO BACK</a></B></FONT>";

			echo $alert;

			echo "</td></tr>\n";

			echo "<tr><td align='center' colspan='5'>&nbsp;</td></tr>\n";

			echo "\t<tr>\n";
			echo "\t\t<th><input type='button' value='" . _QXZ("SCORECARD") . "' style='background-color:#" . $SSbutton_color . ";font-size:12px;font-weight:bold;width:180px' onClick=\"ToggleQCSpans('qc_master_span')\"></th>\n";
			echo "\t\t<th><input type='button' value='" . _QXZ("LEAD INFO") . "' style='background-color:#" . $SSbutton_color . ";font-size:12px;font-weight:bold;width:180px' onClick=\"ToggleQCSpans('lead_information_span')\"></th>\n";
			echo "\t\t<th><input type='button' value='" . _QXZ("CALLBACK INFO") . "' style='background-color:#" . $SSbutton_color . ";font-size:12px;font-weight:bold;width:180px' onClick=\"ToggleQCSpans('qc_call_information')\"></th>\n";
			echo "\t\t<th><input type='button' value='" . _QXZ("LOG INFO") . "' style='background-color:#" . $SSbutton_color . ";font-size:12px;font-weight:bold;width:180px' onClick=\"ToggleQCSpans('call_logs_span')\"></th>\n";
			echo "\t</tr>\n";

			echo "<tr><td align='center' colspan='5'>&nbsp;</td></tr>\n";


			echo "<tr><td align='center' colspan='5'>\n";

			//end_call is set by submit button to denote "save", without it this is a VIEW, with it this is a SAVE

			$qc_master_span_visibility = "block";
			$lead_information_span_visibility = "none";
			$qc_call_information_visibility = "none";
			$call_logs_span_visibility = "none";

			$qc_sentiment_data = null;
			$qc_agent_coaching = null;
			$qc_sentiment_stmt = mysqli_prepare($link, "SELECT * FROM quality_control_sentiment_analysis WHERE qc_log_id = ? LIMIT 1");
			if ($qc_sentiment_stmt) {
				mysqli_stmt_bind_param($qc_sentiment_stmt, 'i', $qc_log_id);
				if (mysqli_stmt_execute($qc_sentiment_stmt)) {
					$qc_sentiment_result = mysqli_stmt_get_result($qc_sentiment_stmt);
					$qc_sentiment_data = $qc_sentiment_result ? mysqli_fetch_assoc($qc_sentiment_result) : null;
				}
				mysqli_stmt_close($qc_sentiment_stmt);
			}
			if (ctype_digit((string) $qc_recording_id)) {
				$qc_analysis_file = '/var/spool/asterisk/uploads/ai_summary/' . $qc_recording_id . '_ai_analysis.json';
				if (is_file($qc_analysis_file) && is_readable($qc_analysis_file)) {
					$qc_analysis_contents = file_get_contents($qc_analysis_file);
					$qc_saved_analysis = is_string($qc_analysis_contents) ? json_decode($qc_analysis_contents, true) : null;
					if (is_array($qc_saved_analysis) && is_array($qc_saved_analysis['sentiment_analysis']['agent_coaching'] ?? null)) {
						$qc_agent_coaching = $qc_saved_analysis['sentiment_analysis']['agent_coaching'];
					}
				}
			}

			ob_start();
			echo "<div id='qc-ai-sentiment-panel' class='qc-sentiment-panel'>";
			$qc_sentiment_complete = is_array($qc_sentiment_data) && ($qc_sentiment_data['analysis_status'] ?? '') === 'COMPLETED';
			$qc_sentiment_badge = $qc_sentiment_complete ? _QXZ("AI GENERATED") : _QXZ("NOT GENERATED");
			echo "<div class='qc-sentiment-heading'><h2>" . _QXZ("Sentiment Analysis") . "</h2><div class='qc-sentiment-demo'>" . htmlspecialchars($qc_sentiment_badge, ENT_QUOTES, 'UTF-8') . "</div></div>";

			if (!$qc_sentiment_complete) {
				echo "<div class='qc-sentiment-card qc-sentiment-card-wide'><div class='qc-sentiment-card-title'>" . _QXZ("No sentiment analysis is available yet") . "</div><p class='qc-sentiment-reason'>" . _QXZ("Use Generate AI Checkpoints & Sentiment at the bottom of the scorecard to analyze this recording.") . "</p></div>";
			} else {
				$qc_interest_score = max(0, min(100, (int) $qc_sentiment_data['customer_interest_score']));
				$qc_buying_score = max(0, min(100, (int) $qc_sentiment_data['buying_intent_score']));
				$qc_empathy_score = max(0, min(100, (int) $qc_sentiment_data['agent_empathy_score']));
				$qc_behavior_score = max(0, min(100, (int) ($qc_sentiment_data['agent_behavior_score'] ?? 0)));
				$qc_outcome_confidence = max(0, min(100, (int) $qc_sentiment_data['outcome_confidence']));
				$qc_escape = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
				$qc_emotions = json_decode((string) ($qc_sentiment_data['emotions_json'] ?? ''), true);
				$qc_emotions = is_array($qc_emotions) ? $qc_emotions : [];
				$qc_behavior_flags = json_decode((string) ($qc_sentiment_data['agent_behavior_flags_json'] ?? ''), true);
				$qc_behavior_flags = is_array($qc_behavior_flags) ? $qc_behavior_flags : [];
				$qc_behavior_available = trim((string) ($qc_sentiment_data['agent_behavior_reason'] ?? '')) !== '';

				echo "<div class='qc-sentiment-grid'>";
				echo "<div class='qc-sentiment-card'><div class='qc-sentiment-card-title'>" . _QXZ("Customer Interest Score") . "</div><div class='qc-sentiment-score-row'><div class='qc-sentiment-score'>{$qc_interest_score}%</div><div class='qc-sentiment-meter'><div class='qc-sentiment-meter-fill' style='width:{$qc_interest_score}%'></div></div></div><p class='qc-sentiment-reason'><strong>" . _QXZ("Reason") . ":</strong> " . $qc_escape($qc_sentiment_data['customer_interest_reason']) . "</p></div>";
				echo "<div class='qc-sentiment-card'><div class='qc-sentiment-card-title'>" . _QXZ("Buying Intent Score") . "</div><div class='qc-sentiment-score-row'><div class='qc-sentiment-score'>{$qc_buying_score}%</div><div class='qc-sentiment-meter'><div class='qc-sentiment-meter-fill' style='width:{$qc_buying_score}%'></div></div></div><p class='qc-sentiment-reason'><strong>" . _QXZ("Reason") . ":</strong> " . $qc_escape($qc_sentiment_data['buying_intent_reason']) . "</p></div>";
				echo "<div class='qc-sentiment-card'><div class='qc-sentiment-card-title'>" . _QXZ("Agent Empathy Score") . "</div><div class='qc-sentiment-score-row'><div class='qc-sentiment-score'>{$qc_empathy_score}%</div><div class='qc-sentiment-meter'><div class='qc-sentiment-meter-fill' style='width:{$qc_empathy_score}%'></div></div></div><p class='qc-sentiment-reason'><strong>" . _QXZ("Reason") . ":</strong> " . $qc_escape($qc_sentiment_data['agent_empathy_reason']) . "</p></div>";
				echo "<div class='qc-sentiment-card'><div class='qc-sentiment-card-title'>" . _QXZ("Agent Behaviour Score") . "</div>";
				if (!$qc_behavior_available) {
					echo "<p class='qc-sentiment-reason'>" . _QXZ("Regenerate the AI analysis to calculate this score for an existing record.") . "</p>";
				} else {
					echo "<div class='qc-sentiment-score-row'><div class='qc-sentiment-score'>{$qc_behavior_score}%</div><div class='qc-sentiment-meter'><div class='qc-sentiment-meter-fill' style='width:{$qc_behavior_score}%'></div></div></div><p class='qc-sentiment-reason'><strong>" . _QXZ("Reason") . ":</strong> " . $qc_escape($qc_sentiment_data['agent_behavior_reason'] ?? '') . "</p>";
				if ($qc_behavior_flags) {
					echo "<div class='qc-behavior-flags'>";
					foreach ($qc_behavior_flags as $qc_behavior_flag) {
						if (!is_array($qc_behavior_flag)) continue;
						$qc_flag_type = $qc_escape($qc_behavior_flag['type'] ?? '');
						$qc_flag_timestamp = $qc_escape($qc_behavior_flag['timestamp'] ?? 'N/A');
						$qc_flag_evidence = $qc_escape($qc_behavior_flag['evidence'] ?? '');
						echo "<div class='qc-behavior-flag'><strong>{$qc_flag_type}</strong> {$qc_flag_timestamp} — {$qc_flag_evidence}</div>";
					}
					echo "</div>";
				} else {
					echo "<div class='qc-behavior-clear'>" . _QXZ("No inappropriate agent behaviour detected") . "</div>";
				}
				}
				echo "</div>";
				echo "<div class='qc-sentiment-card'><div class='qc-sentiment-card-title'>" . _QXZ("Customer Emotion Detection") . "</div><p class='qc-sentiment-reason'>" . _QXZ("Dominant emotion") . ": <strong>" . $qc_escape($qc_sentiment_data['dominant_emotion']) . "</strong></p><div class='qc-emotion-list'>";
				foreach ($qc_emotions as $qc_emotion) {
					if (!is_array($qc_emotion)) continue;
					$qc_emotion_name = $qc_escape($qc_emotion['emotion'] ?? '');
					$qc_emotion_confidence = max(0, min(100, (int) ($qc_emotion['confidence'] ?? 0)));
					echo "<div class='qc-emotion-chip'>{$qc_emotion_name} {$qc_emotion_confidence}%</div>";
				}
				echo "</div></div>";
				echo "<div class='qc-sentiment-card'><div class='qc-sentiment-card-title'>" . _QXZ("Call Outcome Prediction") . "</div><div class='qc-outcome-value'>" . $qc_escape($qc_sentiment_data['outcome_prediction']) . " - {$qc_outcome_confidence}%</div><p class='qc-sentiment-reason'><strong>" . _QXZ("Reason") . ":</strong> " . $qc_escape($qc_sentiment_data['outcome_reason']) . "</p></div>";
				$qc_coaching_assessment = is_array($qc_agent_coaching) ? trim((string) ($qc_agent_coaching['overall_assessment'] ?? '')) : '';
				$qc_coaching_suggestions = is_array($qc_agent_coaching) && is_array($qc_agent_coaching['suggestions'] ?? null) ? $qc_agent_coaching['suggestions'] : [];
				echo "<div class='qc-sentiment-card qc-sentiment-card-wide qc-coaching-card'><div class='qc-sentiment-card-title'>" . _QXZ("How the Agent Could Have Handled This Situation") . "</div>";
				if ($qc_coaching_assessment === '' && !$qc_coaching_suggestions) {
					echo "<p class='qc-sentiment-reason'>" . _QXZ("Regenerate the AI analysis to receive suggestions on how the agent could have handled the conversation.") . "</p>";
				} else {
					if ($qc_coaching_assessment !== '') {
						echo "<p class='qc-sentiment-reason'>" . $qc_escape($qc_coaching_assessment) . "</p>";
					}
					if ($qc_coaching_suggestions) {
						echo "<div class='qc-coaching-list'>";
						foreach ($qc_coaching_suggestions as $qc_coaching_suggestion) {
							if (!is_array($qc_coaching_suggestion)) continue;
							$qc_coaching_timestamp = $qc_escape($qc_coaching_suggestion['timestamp'] ?? 'N/A');
							$qc_coaching_observation = $qc_escape($qc_coaching_suggestion['observation'] ?? '');
							$qc_coaching_approach = $qc_escape($qc_coaching_suggestion['recommended_approach'] ?? '');
							$qc_coaching_example = $qc_escape($qc_coaching_suggestion['example_phrase'] ?? '');
							echo "<div class='qc-coaching-item'><div class='qc-coaching-item-title'>" . _QXZ("Coaching opportunity") . " {$qc_coaching_timestamp}</div>";
							if ($qc_coaching_observation !== '') echo "<p class='qc-sentiment-reason'><strong>" . _QXZ("Observed") . ":</strong> {$qc_coaching_observation}</p>";
							echo "<p class='qc-sentiment-reason'><strong>" . _QXZ("Recommended approach") . ":</strong> {$qc_coaching_approach}</p>";
							if ($qc_coaching_example !== '') echo "<div class='qc-sentiment-reason qc-coaching-example'><strong>" . _QXZ("Example") . ":</strong> &ldquo;{$qc_coaching_example}&rdquo;</div>";
							echo "</div>";
						}
						echo "</div>";
					}
				}
				echo "</div>";
				echo "</div>";
			}
			echo "</div>";
			$qc_sentiment_panel_html = ob_get_clean();

			if ($end_call > 0) {
				if ($LOGmodify_leads == '5') {
					echo "ERROR: " . _QXZ("You do not have permission to modify leads") . ": $LOGmodify_leads \n";
					exit;
				}
				$qc_master_span_visibility = "none";
				$lead_information_span_visibility = "block";
				if ($DB) {
					echo __LINE__ . "\n";
				}
				### update the lead record in the vicidial_list table
				$stmt = "UPDATE vicidial_list set status='" . mysqli_real_escape_string($link, $status) . "',title='" . mysqli_real_escape_string($link, $title) . "',first_name='" . mysqli_real_escape_string($link, $first_name) . "',middle_initial='" . mysqli_real_escape_string($link, $middle_initial) . "',last_name='" . mysqli_real_escape_string($link, $last_name) . "',address1='" . mysqli_real_escape_string($link, $address1) . "',address2='" . mysqli_real_escape_string($link, $address2) . "',address3='" . mysqli_real_escape_string($link, $address3) . "',city='" . mysqli_real_escape_string($link, $city) . "',state='" . mysqli_real_escape_string($link, $state) . "',province='" . mysqli_real_escape_string($link, $province) . "',postal_code='" . mysqli_real_escape_string($link, $postal_code) . "',country_code='" . mysqli_real_escape_string($link, $country_code) . "',alt_phone='" . mysqli_real_escape_string($link, $alt_phone) . "',phone_number='$phone_number',phone_code='$phone_code',email='" . mysqli_real_escape_string($link, $email) . "',security_phrase='" . mysqli_real_escape_string($link, $security) . "',comments='" . mysqli_real_escape_string($link, $comments) . "',rank='" . mysqli_real_escape_string($link, $rank) . "',owner='" . mysqli_real_escape_string($link, $owner) . "' where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "'";
				if ($DB) {
					echo "|$stmt|\n";
				}
				$rslt = mysql_to_mysqli($stmt, $link);
				//STATUS just changed, re-capture all data for client!
				//Added by Poundteam for QC. Gather record data to display on page and prepopulate title and hrefs, etc.
				$stmt = "SELECT * from vicidial_list A inner join vicidial_lists B on A.list_id=B.list_id inner join vicidial_campaigns C on B.campaign_id=C.campaign_id left outer join vicidial_statuses D on A.status=D.status left outer join vicidial_qc_codes E on A.status=E.code where A.lead_id='$lead_id'";
				if ($DB) {
					echo "$stmt\n";
				}
				$rslt = mysql_to_mysqli($stmt, $link);
				$row = mysqli_fetch_assoc($rslt);
				//QUALITY CONTROL CHANGE LOG BEGIN - CHANGE VERSION (VIEW VERSION IS BELOW, WHICH CREATES THE RECORD, THIS ONE MERELY MODIFIES THE EXISTING RECORD)
				$new_record = $row;
				//if status has changed, the join query above will have the "status" field of "vicidial_list" overwritten by the status field of "vicidial_statuses" ... which will be EMPTY if there is no matching status to the new QC status chosen. This will cause the changelog to be incorrect.
				if (strlen($new_record['status']) == '0') {
					$new_record['status'] = $new_record['code'];
				}

				if ($original_record != $new_record) {
					//Information changed: Find out what and record it, first disable "view" logging
					$qcchange = 'Y';
					$qcchangelist = '';
					$qcchangecounter = 0;
					foreach ($original_record as $key => $value) {
						//only list the changes in the first 35 fiels, those are from the vicidial_list table (the rest are from joined tables, and the changes cascade)
						if (($new_record[$key] != $value) && ($qcchangecounter <= 35))  $qcchangelist .= "----$key----\n$value => $new_record[$key]\n";
						$qcchangecounter++;
					}
					### insert a NEW record to the vicidial_closer_log table
					$qcchangelist = mysqli_real_escape_string($link, $qcchangelist);
					$view_epoch = preg_replace('/[^0-9]/', '', $_POST['viewtime']);
					$elapsed_seconds = $STARTtime - $view_epoch;

					$stmt = "UPDATE vicidial_qc_agent_log set save_datetime='$NOW_TIME',save_epoch='$STARTtime',elapsed_seconds='$elapsed_seconds',old_status='{$original_record['status']}',new_status='{$new_record['status']}',details='$qcchangelist'
			where view_epoch='$view_epoch' and lead_id='$lead_id'";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);
				}
				//QUALITY CONTROL CHANGE LOG END
				$original_sales_rep =	$row['user'];
				$campaign_id =			$row['campaign_id'];
				$campaign_name =		$row['campaign_name'];
				$lead_name =			trim(trim($row['first_name'] . ' ' . $row['middle_initial']) . ' ' . $row['last_name']);
				$scheduled_callback =	$row['scheduled_callback'];

				echo "<br>" . _QXZ("information modified") . "<BR><BR>\n";
				echo "<i><small><a href=\"$PHP_SELF?lead_id=$lead_id&DB=$DB\">" . _QXZ("Go back to re-modify this QC lead") . "</a></small></i><BR><BR><BR>\n";
				echo "<CENTER><B><FONT FACE='Courier' COLOR=BLACK SIZE=3><a href=\"admin.php?ADD=881&campaign_id=$campaign_id\">" . _QXZ("Proceed to QC CAMPAIGN") . " $campaign_id " . _QXZ("Queue") . "</a></B><BR><BR><B><I>" . _QXZ("Callback Information") . ":</I></B>\n";
				### LOG INSERTION Admin Log Table ###
				$SQL_log = "$stmt|";
				$SQL_log = preg_replace('/;/', '', $SQL_log);
				$SQL_log = addslashes($SQL_log);
				$stmt = "INSERT INTO vicidial_admin_log set event_date='$NOW_TIME', user='$PHP_AUTH_USER', ip_address='$ip', event_section='LEADS', event_type='MODIFY', record_id='$lead_id', event_code='ADMIN MODIFY LEAD', event_sql=\"$SQL_log\", event_notes='';";
				if ($DB) {
					echo "|$stmt|\n";
				}
				$rslt = mysql_to_mysqli($stmt, $link);
				if ($DB) {
					echo __LINE__ . "\n";
				}

				if (($dispo != $status) and ($dispo == 'CBHOLD')) {
					if ($DB) {
						echo __LINE__ . "\n";
					}
					### inactivate vicidial_callbacks record for this lead
					$stmt = "UPDATE vicidial_callbacks set status='INACTIVE' where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' and status='ACTIVE';";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);

					echo "<BR>" . _QXZ("vicidial_callback record inactivated") . ": $lead_id<BR>\n";
				}
				//Duped CBHOLD version for vicidial status type 'Scheduled Callback'
				if (($dispo != $status) and ($scheduled_callback == 'Y')) {
					if ($DB) {
						echo __LINE__ . "\n";
					}
					### inactivate vicidial_callbacks record for this lead
					$stmt = "UPDATE vicidial_callbacks set status='INACTIVE' where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' and status='ACTIVE';";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);

					echo "<BR>" . _QXZ("vicidial_callback record inactivated") . ": $lead_id<BR>\n";
				}
				if (($dispo != $status) and ($dispo == 'CALLBK')) {
					if ($DB) {
						echo __LINE__ . "\n";
					}
					### inactivate vicidial_callbacks record for this lead
					$stmt = "UPDATE vicidial_callbacks set status='INACTIVE' where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' and status IN('ACTIVE','LIVE');";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);

					echo "<BR>" . _QXZ("vicidial_callback record inactivated") . ": $lead_id<BR>\n";
				}

				if (($dispo != $status) and ($status == 'CBHOLD')) {
					if ($DB) {
						echo __LINE__ . "\n";
					}
					### find any vicidial_callback records for this lead
					$stmt = "select callback_id from vicidial_callbacks where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' and status IN('ACTIVE','LIVE') order by callback_id desc LIMIT 1;";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);
					$CBM_to_print = mysqli_num_rows($rslt);
					if ($CBM_to_print > 0) {
						$rowx = mysqli_fetch_row($rslt);
						$callback_id = $rowx[0];
					} else {
						$defaultappointment = date("Y-m-d H:i:s", mktime(date("H"), date("i"), date("s"), date("m"), date("d"), date("Y")));

						$stmt = "INSERT INTO vicidial_callbacks SET lead_id='" . mysqli_real_escape_string($link, $lead_id) . "',recipient='ANYONE',status='ACTIVE',user='$PHP_AUTH_USER',user_group='ADMIN',list_id='" . mysqli_real_escape_string($link, $list_id) . "',callback_time='$defaultappointment 12:00:00',entry_time='$NOW_TIME',comments='',campaign_id='" . mysqli_real_escape_string($link, $campaign_id) . "';";
						if ($DB) {
							echo "|$stmt|\n";
						}
						$rslt = mysql_to_mysqli($stmt, $link);

						echo "<BR>" . _QXZ("Scheduled Callback added") . ": $lead_id - $phone_number<BR>\n";
					}
				}

				//Duped CBHOLD version for vicidial status type 'Scheduled Callback'
				//This entry creates the callback
				if (($dispo != $status) and ($scheduled_callback == 'Y')) {
					if ($DB) {
						echo __LINE__ . "\n";
					}
					### find any vicidial_callback records for this lead
					$stmt = "select callback_id from vicidial_callbacks where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' and status IN('ACTIVE','LIVE') order by callback_id desc LIMIT 1;";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);
					$CBM_to_print = mysqli_num_rows($rslt);
					if ($CBM_to_print > 0) {
						$rowx = mysqli_fetch_row($rslt);
						$callback_id = $rowx[0];
					} else {
						$defaultappointment = date("Y-m-d H:i:s", mktime(date("H"), date("i"), date("s"), date("m"), date("d"), date("Y")));

						$stmt = "INSERT INTO vicidial_callbacks SET lead_id='" . mysqli_real_escape_string($link, $lead_id) . "',recipient='USERONLY',status='ACTIVE',user='$original_sales_rep',user_group='ADMIN',list_id='" . mysqli_real_escape_string($link, $list_id) . "',callback_time='$defaultappointment 12:00:00',entry_time='$NOW_TIME',comments='',campaign_id='" . mysqli_real_escape_string($link, $campaign_id) . "';";
						$debug1 = $stmt;
						if ($DB) {
							echo "|$stmt|\n";
						}
						$rslt = mysql_to_mysqli($stmt, $link);

						echo "<BR>" . _QXZ("Scheduled Callback added") . ": $lead_id - $phone_number<BR>\n";
					}
				}


				if (($dispo != $status) and ($status == 'DNC')) {
					if ($DB) {
						echo __LINE__ . "\n";
					}
					### add lead to the internal DNC list
					$stmt = "INSERT INTO vicidial_dnc (phone_number) values('" . mysqli_real_escape_string($link, $phone_number) . "');";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);

					echo "<BR>" . _QXZ("Lead added to DNC List") . ": $lead_id - $phone_number<BR>\n";
				}
				### update last record in vicidial_log table
				if (($dispo != $status) and ($modify_logs > 0)) {
					if ($DB) {
						echo __LINE__ . "\n";
					}
					$stmt = "UPDATE vicidial_log set status='" . mysqli_real_escape_string($link, $status) . "' where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' order by call_date desc limit 1";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);
				}

				### update last record in vicidial_closer_log table
				if (($dispo != $status) and ($modify_closer_logs > 0)) {
					if ($DB) {
						echo __LINE__ . "\n";
					}
					$stmt = "UPDATE vicidial_closer_log set status='" . mysqli_real_escape_string($link, $status) . "' where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' order by closecallid desc limit 1";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);
				}

				### update last record in vicidial_agent_log table
				if (($dispo != $status) and ($modify_agent_logs > 0)) {
					if ($DB) {
						echo __LINE__ . "\n";
					}
					$stmt = "UPDATE vicidial_agent_log set status='" . mysqli_real_escape_string($link, $status) . "' where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' order by agent_log_id desc limit 1";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);
				}

				if ($add_closer_record > 0) {
					if ($DB) echo __LINE__ . "\n";
					### insert a NEW record to the vicidial_closer_log table
					$stmt = "INSERT INTO vicidial_closer_log (lead_id,list_id,campaign_id,call_date,start_epoch,end_epoch,length_in_sec,status,phone_code,phone_number,user,comments,processed) values('" . mysqli_real_escape_string($link, $lead_id) . "','" . mysqli_real_escape_string($link, $list_id) . "','" . mysqli_real_escape_string($link, $campaign_id) . "','" . mysqli_real_escape_string($link, $parked_time) . "','$NOW_TIME','$STARTtime','1','" . mysqli_real_escape_string($link, $status) . "','" . mysqli_real_escape_string($link, $phone_code) . "','" . mysqli_real_escape_string($link, $phone_number) . "','$PHP_AUTH_USER','" . mysqli_real_escape_string($link, $comments) . "','Y')";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);
				}
			} else {


				# JCJ - call span
				echo "\n\n<span id='qc_call_information' style='display:" . $qc_call_information_visibility . "'><center>\n";
				echo _QXZ("PLACE A CALL TO LEAD") . ":<iframe data-src=\"qc_call_client_iframe.php?phone_number=$phone_number&phone_code=$phone_code&lead_id=$lead_id&list_id=$CLlist_id&stage=DISPLAY&submit_button=YES&user=$PHP_AUTH_USER&pass=$PHP_AUTH_PW&bgcolor=E6E6E6&agent_mapping_version=2\" style=\"background-color:transparent;border:0;vertical-align:middle;\" scrolling=\"no\" frameborder=\"0\" allowtransparency=\"true\" id=\"qcCallFormIFrame\" name=\"qcFormIFrame\" width=\"540\" height=\"64\" STYLE=\"z-index:18\"> </iframe><BR><BR>\n";

				/* JCJ - Commented out, moved to AJAX
	//Not a "Submit" result, viewing the record (possibly with URL options such as those below which modify callback status but not record data)
	if ($CBchangeUSERtoANY == 'YES') 
		{
		if($DB) echo __LINE__."\n";
		### set vicidial_callbacks record to an ANYONE callback for this lead
		$stmt="UPDATE vicidial_callbacks set recipient='ANYONE' where callback_id='" . mysqli_real_escape_string($link, $callback_id) . "';";
		if ($DB) {echo "|$stmt|\n";}
		$rslt=mysql_to_mysqli($stmt, $link);
		echo "<BR>"._QXZ("vicidial_callback record changed to ANYONE")."<BR>\n";
        $qcchange='Y';
		}
	if ($CBchangeUSERtoUSER == 'YES') 
		{
        if($DB) echo __LINE__."\n";
		### set vicidial_callbacks record to a different USERONLY callback record for this lead
		$stmt="UPDATE vicidial_callbacks set user='" . mysqli_real_escape_string($link, $CBuser) . "' where callback_id='" . mysqli_real_escape_string($link, $callback_id) . "';";
		if ($DB) {echo "|$stmt|\n";}
		$rslt=mysql_to_mysqli($stmt, $link);
		echo "<BR>"._QXZ("vicidial_callback record user changed to")." $CBuser<BR>\n";
        $qcchange='Y';
		}
	if ($CBchangeANYtoUSER == 'YES') 
		{
		if($DB) echo __LINE__."\n";
		### set vicidial_callbacks record to an USERONLY callback for this lead
		$stmt="UPDATE vicidial_callbacks set user='" . mysqli_real_escape_string($link, $CBuser) . "',recipient='USERONLY' where callback_id='" . mysqli_real_escape_string($link, $callback_id) . "';";
		if ($DB) {echo "|$stmt|\n";}
		$rslt=mysql_to_mysqli($stmt, $link);
		echo "<BR>"._QXZ("vicidial_callback record changed to USERONLY, user").": $CBuser<BR>\n";
		$qcchange='Y';
		}
	if ($CBchangeDATE == 'YES') 
		{
		if($DB) echo __LINE__."\n";
		### change date/time of vicidial_callbacks record for this lead
		$stmt="UPDATE vicidial_callbacks set callback_time='" . mysqli_real_escape_string($link, $appointment_date) . " " . mysqli_real_escape_string($link, $appointment_time) . "',comments='" . mysqli_real_escape_string($link, $comments) . "' where callback_id='" . mysqli_real_escape_string($link, $callback_id) . "';";
		if ($DB) {echo "|$stmt|\n";}
		$rslt=mysql_to_mysqli($stmt, $link);
		echo "<BR>"._QXZ("vicidial_callback record changed to")." $appointment_date $appointment_time<BR>\n";
		$qcchange='Y';
		}
*/

				//QUALITY CONTROL LOGGING BEGIN - VIEW ONLY
				//If no changes have been made, record "view" of this record.
				if ($qcchange != 'Y') {
					if ($DB) echo __LINE__ . "\n QCCHANGE != Y";
					### insert a NEW record to the vicidial_closer_log table
					$stmt = "INSERT INTO vicidial_qc_agent_log (qc_user,qc_user_group,qc_user_ip,lead_user,web_server_ip,view_datetime,view_epoch,lead_id,list_id,campaign_id,processed,qc_log_id)
		values('" . mysqli_real_escape_string($link, $PHP_AUTH_USER) . "','$LOGuser_group','{$_SERVER['REMOTE_ADDR']}','{$original_record['user']}','{$_SERVER['SERVER_ADDR']}','$NOW_TIME','$STARTtime','" . mysqli_real_escape_string($link, $lead_id) . "','" . mysqli_real_escape_string($link, $original_record['list_id']) . "','" . mysqli_real_escape_string($link, $campaign_id) . "','N', '$qc_log_id')";
					if ($DB) {
						echo "|$stmt|\n";
					}
					$rslt = mysql_to_mysqli($stmt, $link);
				}
				//QUALITY CONTROL LOGGING END

				if ($DB) echo __LINE__ . "\n";
				$stmt = "SELECT count(*) from vicidial_list where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "'";
				$rslt = mysql_to_mysqli($stmt, $link);
				if ($DB) {
					echo "$stmt\n";
				}
				$row = mysqli_fetch_row($rslt);
				$lead_count = $row[0];

				##### CALLBACK INFORMATION #####
				echo "<FORM METHOD=POST NAME=vsn ID=vsn ACTION='$PHP_SELF'>";
				echo "<TABLE BGCOLOR=#" . $SSstd_row4_background . " WIDTH=750 align='center'><TR><TD><font class='standard_bold'>";
				echo _QXZ("Callback Details") . ":</font><BR>";
				//Added scheduled_callback regular statuses option
				if (($dispo == 'CALLBK') or ($dispo == 'CBHOLD') || $scheduled_callback == 'Y') {
					if ($DB) {
						echo __LINE__ . "";
					}
					### find any vicidial_callback records for this lead
					$stmt = "select callback_id,lead_id,list_id,campaign_id,status,entry_time,callback_time,modify_date,user,recipient,comments,user_group from vicidial_callbacks where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' and status IN('ACTIVE','LIVE') order by callback_id desc LIMIT 1;";
					if ($DB) {
						echo "|$stmt|";
					}
					$rslt = mysql_to_mysqli($stmt, $link);
					$CB_to_print = mysqli_num_rows($rslt);
					$rowx = mysqli_fetch_row($rslt);

					if ($CB_to_print > 0) {
			?>

						<!-- JCJ			<FORM METHOD=POST NAME=vsn ID=vsn ACTION="<?php echo $PHP_SELF ?>"> //-->
						<BR>
						<CENTER>
							<font class='standard_bold'><?php echo _QXZ("Change Callback Information"); ?>:</font>
						</CENTER>
						<input type=hidden name=DB id=DB value=<?php echo $DB ?>>
						<input type=hidden name=CBchangeDATE value="YES">
						<input type=hidden name=lead_id id=lead_id value="<?php echo $lead_id ?>">
						<input type=hidden name=callback_id value="<?php echo $rowx[0] ?>">

						<TABLE BORDER=0 CELLPADDING=0 CELLSPACING=2 WIDTH=600 align='center'>
							<?php
							if ($rowx[9] == 'USERONLY') {
								echo "<TR BGCOLOR=\"#E6E6E6\"><TD width='50%' align='right'><FONT class='standard'>"; # <form action=$PHP_SELF method=POST>
								echo "<input type=hidden name=CBchangeUSERtoUSER value=\"YES\">";
								# echo "<input type=hidden name=DB value=\"$DB\">";
								# echo "<input type=hidden name=lead_id value=\"$lead_id\">";
								# echo "<input type=hidden name=callback_id value=\"$rowx[0]\">";
								echo _QXZ("New Callback Owner UserID") . ": <input class='cust_form' type=text name=CBuser id=CBuser size=8 maxlength=20 value=\"$rowx[8]\"> ";
								echo "</font></TD>";
								echo "<TD align='left'>";
								echo "<input style='background-color:#$SSbutton_color;width:270px' type=button onClick=\"UpdateCallback(this.form.callback_id.value, 'USERONLY', this.form.CBuser.value)\" name=submit value=\"" . _QXZ("CHANGE USERONLY CALLBACK USER") . "\"><input type=hidden name=viewtime value='$STARTtime' /></TD></TR>"; # </form>

								echo "<TR BGCOLOR=\"#E6E6E6\"><TD width='50%' align='right'>&nbsp;"; # <form action=$PHP_SELF method=POST>
								echo "<input type=hidden name=CBchangeUSERtoANY value=\"YES\">";
								# echo "<input type=hidden name=DB value=\"$DB\">";
								# echo "<input type=hidden name=lead_id value=\"$lead_id\">";
								# echo "<input type=hidden name=callback_id value=\"$rowx[0]\">";
								echo "</TD>";
								echo "<TD align='left'>";
								echo "<input style='background-color:#$SSbutton_color;width:270px' type=button onClick=\"UpdateCallback(this.form.callback_id.value, 'ANYONE', this.form.CBuser.value)\" name=submit value=\"" . _QXZ("CHANGE TO ANYONE CALLBACK") . "\"><input type=hidden name=viewtime value='$STARTtime' /></TD></TR>"; # </form>
							} else {
								echo "<br>($rowx[9])"; # <form action=$PHP_SELF method=POST>
								echo "<TR BGCOLOR=\"#E6E6E6\"><TD align=center colspan=2><FONT class='standard'>";
								echo "<input type=hidden name=CBchangeANYtoUSER value=\"YES\">";
								# echo "<input type=hidden name=DB value=\"$DB\">";
								# echo "<input type=hidden name=lead_id value=\"$lead_id\">";
								# echo "<input type=hidden name=callback_id value=\"$rowx[0]\">";
								echo _QXZ("New Callback Owner UserID") . ": <input class='cust_form' type=text name=CBuser id=CBuser size=8 maxlength=20 value=\"$rowx[8]\"> ";
								echo "<input style='background-color:#$SSbutton_color;width:270px' type=button onClick=\"UpdateCallback(this.form.callback_id.value, 'USERONLY', this.form.CBuser.value)\" name=submit value=\"" . _QXZ("CHANGE TO USERONLY CALLBACK") . "\"><input type=hidden name=viewtime value='$STARTtime' /></font></TD></TR>"; # </form>
							}

							$appointment_datetimeARRAY = explode(" ", $rowx[6]);
							$appointment_date = $appointment_datetimeARRAY[0];
							$appointment_timeARRAY = explode(":", $appointment_datetimeARRAY[1]);
							$appointment_hour = $appointment_timeARRAY[0];
							$appointment_min = $appointment_timeARRAY[1];
							?>
							<TR>
								<TD align=center colspan=2>
									<HR>
								</TD>
							</TR>
							<TR BGCOLOR="#E6E6E6">
								<TD ALIGN=RIGHT width="50%">
									<FONT class='standard'><?php echo _QXZ("CallBack Date/Time"); ?>: </FONT>
								</TD>
								<TD ALIGN=LEFT width="50%"><input class='cust_form' type=text name=appointment_date id=appointment_date size=10 maxlength=10 value="<?php echo $appointment_date ?>">

									<script type="text/javascript">
										var o_cal = new tcal({
											// form name
											'formname': 'vsn',
											// input name
											'controlname': 'appointment_date'
										}, {
											'months': ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
											'weekdays': ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
											'yearscroll': false, // show year scroller
											'weekstart': 0, // first day of week: 0-Su or 1-Mo
											'centyear': 70, // 2 digit years less than 'centyear' are in 20xx, othewise in 19xx.
											'imgpath': './images/' // directory with calendar images
										});
										o_cal.a_tpl.yearscroll = false;
										// o_cal.a_tpl.weekstart = 1; // Monday week start
									</script>
									&nbsp; &nbsp;
									<input type=hidden name=appointment_time id=appointment_time value="<?php echo $appointment_time ?>">
									<SELECT class='cust_form' name=appointment_hour id=appointment_hour>
										<option>00</option>
										<option>01</option>
										<option>02</option>
										<option>03</option>
										<option>04</option>
										<option>05</option>
										<option>06</option>
										<option>07</option>
										<option>08</option>
										<option>09</option>
										<option>10</option>
										<option>11</option>
										<option>12</option>
										<option>13</option>
										<option>14</option>
										<option>15</option>
										<option>16</option>
										<option>17</option>
										<option>18</option>
										<option>19</option>
										<option>20</option>
										<option>21</option>
										<option>22</option>
										<option>23</option>
										<OPTION value="<?php echo $appointment_hour ?>" selected><?php echo $appointment_hour ?></OPTION>
									</SELECT>:
									<SELECT class='cust_form' name=appointment_min id=appointment_min>
										<option>00</option>
										<option>05</option>
										<option>10</option>
										<option>15</option>
										<option>20</option>
										<option>25</option>
										<option>30</option>
										<option>35</option>
										<option>40</option>
										<option>45</option>
										<option>50</option>
										<option>55</option>
										<OPTION value="<?php echo $appointment_min ?>" selected><?php echo $appointment_min ?></OPTION>
									</SELECT>

								</TD>
							</TR>
							<TR BGCOLOR="#E6E6E6">
								<TD align=center colspan=2>
									<FONT class='standard'>
										<?php echo _QXZ("Comments"); ?>:

										<TEXTAREA class='cust_form' id=callback_comments name=callback_comments ROWS=3 COLS=65><?php echo $rowx[10] ?></TEXTAREA>
									</font>
								</TD>
							</TR>

							<TR BGCOLOR="#E6E6E6">
								<TD align=center colspan=2>

									<SCRIPT type="text/javascript">
										function submit_form() {
											var appointment_hourFORM = document.getElementById('appointment_hour');
											var appointment_hourVALUE = appointment_hourFORM[appointment_hourFORM.selectedIndex].text;
											var appointment_minFORM = document.getElementById('appointment_min');
											var appointment_minVALUE = appointment_minFORM[appointment_minFORM.selectedIndex].text;

											document.vsn.appointment_time.value = appointment_hourVALUE + ":" + appointment_minVALUE + ":00";

											document.vsn.submit();
										}
									</SCRIPT>

									<input style='background-color:#<?php echo "$SSbutton_color"; ?>:width:300px' type=button value="<?php echo _QXZ("CHANGE CALLBACK DATE/TIME"); ?>" name=smt id=smt onClick="ChangeCallbackDateTime(this.form.callback_id.value)">
								</TD>
							</TR>
							<input type=hidden name=viewtime value='<?php echo $STARTtime; ?>' />
						</TABLE>
						</FORM>

			<?php
					} else {
						echo "<BR>" . _QXZ("No Callback records found") . "<BR>";
					}
				} else {
					if ($DB) {
						echo __LINE__ . "";
					}
					//Modifed text to allow for other than CBHOLD via custom statuses with scheduled callback chosen
					# echo "<BR>"._QXZ("If you want to change this lead to a scheduled callback, first change the Disposition to CBHOLD or similar, then submit and you will be able to set the callback date and time.")."<BR>";
					echo "<BR>" . _QXZ("This lead is not currently a recognized callback status, such as CALLBK or CBHOLD, or a campaign or system status where the -scheduled callback- parameter is set to -Y-.") . "<BR>";
				}
				echo "</TD></TR></TABLE>";
				echo "</center></span>";
				################################
				# JCJ - end call span



				if ($lead_count > 0) {
					if ($DB) echo __LINE__ . "\n";
					##### grab vicidial_list_alt_phones records #####
					$stmt = "select phone_code,phone_number,alt_phone_note,alt_phone_count,active from vicidial_list_alt_phones where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' order by alt_phone_count limit 500;";
					$rslt = mysql_to_mysqli($stmt, $link);
					$alts_to_print = mysqli_num_rows($rslt);

					$c = 0;
					$alts_output = '';
					while ($alts_to_print > $c) {
						if ($DB) {
							echo __LINE__ . "\n";
						}
						$row = mysqli_fetch_row($rslt);
						if (preg_match("/1$|3$|5$|7$|9$/i", $c)) {
							$bgcolor = "bgcolor='#" . $SSstd_row2_background . "'";
						} else {
							$bgcolor = "bgcolor='#" . $SSstd_row1_background . "'";
						}

						$c++;
						$alts_output .= "<tr $bgcolor>";
						$alts_output .= "<td><font size=1>$c</td>";
						$alts_output .= "<td><font size=2>$row[0] $row[1]</td>";
						$alts_output .= "<td align=left><font size=2> $row[2]</td>\n";
						$alts_output .= "<td align=left><font size=2> $row[3]</td>\n";
						$alts_output .= "<td align=left><font size=2> $row[4] </td></tr>\n";
					}
				} else {
					echo _QXZ("lead lookup FAILED for lead_id") . " $lead_id &nbsp; &nbsp; &nbsp; $NOW_TIME\n<BR><BR>\n";
					#		echo "<a href=\"$PHP_SELF\">Close this window</a>\n<BR><BR>\n";
				}


				#### START LOG RECORD GENERATION #####


				##### grab vicidial_log records #####
				$stmt = "select uniqueid,lead_id,list_id,campaign_id,call_date,start_epoch,end_epoch,length_in_sec,status,phone_code,phone_number,user,comments,processed,user_group,term_reason,alt_dial from vicidial_log where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' order by uniqueid desc limit 500;";
				$rslt = mysql_to_mysqli($stmt, $link);
				$logs_to_print = mysqli_num_rows($rslt);
				if ($DB) echo __LINE__ . "<br>\n";
				$u = 0;
				$call_log = '';
				$log_campaign = '';
				while ($logs_to_print > $u) {
					if ($DB) echo __LINE__ . "<br>\n";
					$row = mysqli_fetch_row($rslt);
					if (strlen($log_campaign) < 1) {
						$log_campaign = $row[3];
					}
					if (preg_match("/1$|3$|5$|7$|9$/i", $u)) {
						$bgcolor = "bgcolor='#" . $SSstd_row2_background . "'";
					} else {
						$bgcolor = "bgcolor='#" . $SSstd_row1_background . "'";
					}

					$u++;
					$call_log .= "<tr $bgcolor class='small_standard'>";
					$call_log .= "<td>$u</td>";
					$call_log .= "<td>$row[4]</td>";
					$call_log .= "<td align=left> $row[7]</td>\n";
					$call_log .= "<td align=left> $row[8]</td>\n";
					$call_log .= "<td align=left> <A HREF=\"user_stats.php?user=$row[11]\" target=\"_blank\">$row[11]</A> </td>\n";
					$call_log .= "<td align=right> $row[3] </td>\n";
					$call_log .= "<td align=right> $row[2] </td>\n";
					$call_log .= "<td align=right> $row[1] </td>\n";
					$call_log .= "<td align=right> $row[15] </td>\n";
					$call_log .= "<td align=right>&nbsp; $row[10] </td></tr>\n";

					$campaign_id = $row[3];
				}

				##### grab vicidial_agent_log records #####
				$stmt = "select agent_log_id,user,server_ip,event_time,lead_id,campaign_id,pause_epoch,pause_sec,wait_epoch,wait_sec,talk_epoch,talk_sec,dispo_epoch,dispo_sec,status,user_group,comments,sub_status from vicidial_agent_log where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' order by agent_log_id desc limit 500;";
				$rslt = mysql_to_mysqli($stmt, $link);
				$Alogs_to_print = mysqli_num_rows($rslt);
				if ($DB) echo __LINE__ . "<br>\n";
				$y = 0;
				$agent_log = '';
				$Alog_campaign = '';
				while ($Alogs_to_print > $y) {
					$row = mysqli_fetch_row($rslt);
					if (strlen($Alog_campaign) < 1) {
						$Alog_campaign = $row[5];
					}
					if (preg_match("/1$|3$|5$|7$|9$/i", $y)) {
						$bgcolor = "bgcolor='#" . $SSstd_row2_background . "'";
					} else {
						$bgcolor = "bgcolor='#" . $SSstd_row1_background . "'";
					}

					$y++;
					$agent_log .= "<tr $bgcolor class='small_standard'>";
					$agent_log .= "<td>$y</td>";
					$agent_log .= "<td>$row[3]</td>";
					$agent_log .= "<td align=left> $row[5]</td>\n";
					$agent_log .= "<td align=left> <A HREF=\"user_stats.php?user=$row[1]\" target=\"_blank\">$row[1]</A> </td>\n";
					$agent_log .= "<td align=right> $row[7]</td>\n";
					$agent_log .= "<td align=right> $row[9] </td>\n";
					$agent_log .= "<td align=right> $row[11] </td>\n";
					$agent_log .= "<td align=right> $row[13] </td>\n";
					$agent_log .= "<td align=right> &nbsp; $row[14] </td>\n";
					$agent_log .= "<td align=right> &nbsp; $row[15] </td>\n";
					$agent_log .= "<td align=right> &nbsp; $row[17] </td></tr>\n";

					$campaign_id = $row[5];
				}


				##### grab vicidial_qc_agent_log records #####
				//Differentiate between View and Mod
				$stmt = "select * from vicidial_qc_agent_log where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' and view_epoch<'$STARTtime' order by qc_agent_log_id desc limit 100;";
				$rslt = mysql_to_mysqli($stmt, $link);
				$Alogs_to_print = mysqli_num_rows($rslt);
				if ($DB) echo "$stmt<br>\n";
				$y = 0;
				$qc_agent_log = '';
				$Alog_campaign = '';
				while ($Alogs_to_print > $y) {
					$row = mysqli_fetch_assoc($rslt);
					if (strlen($Alog_campaign) < 1) {
						$Alog_campaign = $row[5];
					}
					if (preg_match("/1$|3$|5$|7$|9$/i", $y)) {
						$bgcolor = "bgcolor='#" . $SSstd_row2_background . "'";
					} else {
						$bgcolor = "bgcolor='#" . $SSstd_row1_background . "'";
					}
					$y++;
					if (strlen($row['save_epoch']) == '0') { //VIEW ONLY record
						$qc_agent_log .= "<tr $bgcolor class='small_standard'>";
						$qc_agent_log .= "<td>$y</td>";
						$qc_agent_log .= "<td>{$row['view_datetime']}</td>";
						$qc_agent_log .= "<td colspan='2' align='center'><font color='white'>" . _QXZ("View Only - No changes") . "</td>";
						$qc_agent_log .= "<td align='center'><A HREF='user_stats.php?user={$row['qc_user']}' target='_blank'>{$row['qc_user']}</A></td>";
						$qc_agent_log .= "<td align='center'><A HREF='user_stats.php?user={$row['lead_user']}' target='_blank'>{$row['lead_user']}</A></td>";
						$qc_agent_log .= "<td align='center'>{$row['campaign_id']}</td>";
						$qc_agent_log .= "<td align='center'>{$row['list_id']}</td>";
						$qc_agent_log .= "<td align='right'>&nbsp;</td>";
						$qc_agent_log .= "<td align='right'>&nbsp;</td></tr>\n";
					} else { // CHANGE record
						$detailtooltip = str_replace("\n", "&#10;", $row['details']); // tool tip line break &#10; and &#xD; both work in IE, but not firefox.
						$qc_agent_log .= "<tr $bgcolor class='small_standard'>";
						$qc_agent_log .= "<td>$y</td>";
						$qc_agent_log .= "<td>{$row['view_datetime']}</td>";
						$qc_agent_log .= "<td align='center'>{$row['old_status']}</td>";
						$qc_agent_log .= "<td align='center'>{$row['new_status']}</td>";
						$qc_agent_log .= "<td align='center'><A HREF='user_stats.php?user={$row['qc_user']}' target='_blank'>{$row['qc_user']}</A></td>";
						$qc_agent_log .= "<td align='center'><A HREF='user_stats.php?user={$row['lead_user']}' target='_blank'>{$row['lead_user']}</A></td>";
						$qc_agent_log .= "<td align='center'>{$row['campaign_id']}</td>";
						$qc_agent_log .= "<td align='center'>{$row['list_id']}</td>";
						$qc_agent_log .= "<td align='right'>" . ($row['elapsed_seconds'] + 0) . "</td>";
						$qc_agent_log .= "<td title='$detailtooltip' align='right'><font color='yellow'>" . _QXZ("DETAILS") . "</td></tr>\n";
					}
				}

				##### grab vicidial_closer_log records #####
				$stmt = "SELECT closecallid,lead_id,list_id,campaign_id,call_date,start_epoch,end_epoch,length_in_sec,status,phone_code,phone_number,user,comments,processed,queue_seconds,user_group,xfercallid,term_reason,uniqueid,agent_only from vicidial_closer_log where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' order by closecallid desc limit 500;";
				$rslt = mysql_to_mysqli($stmt, $link);
				$Clogs_to_print = mysqli_num_rows($rslt);

				$y = 0;
				$closer_log = '';
				$Clog_campaign = '';
				while ($Clogs_to_print > $y) {
					$row = mysqli_fetch_row($rslt);
					if (strlen($Clog_campaign) < 1) {
						$Clog_campaign = $row[3];
					}
					if (preg_match("/1$|3$|5$|7$|9$/i", $y)) {
						$bgcolor = "bgcolor='#" . $SSstd_row2_background . "'";
					} else {
						$bgcolor = "bgcolor='#" . $SSstd_row1_background . "'";
					}

					$y++;
					$closer_log .= "<tr $bgcolor  class='small_standard'>";
					$closer_log .= "<td>$y</td>";
					$closer_log .= "<td>$row[4]</td>";
					$closer_log .= "<td align=left> $row[7]</td>\n";
					$closer_log .= "<td align=left> $row[8]</td>\n";
					$closer_log .= "<td align=left> <A HREF=\"user_stats.php?user=$row[11]\" target=\"_blank\">$row[11]</A> </td>\n";
					$closer_log .= "<td align=right> $row[3] </td>\n";
					$closer_log .= "<td align=right> $row[2] </td>\n";
					$closer_log .= "<td align=right> $row[1] </td>\n";
					$closer_log .= "<td align=right> &nbsp; $row[14] </td>\n";
					$closer_log .= "<td align=right> &nbsp; $row[17] </td></tr>\n";

					$campaign_id = $row[3];
				}
				#### END LOG RECORD GENERATION #####


				# JCJ - Lead information span
				echo "\n\n<span id='lead_information_span' style='display:" . $lead_information_span_visibility . "'><CENTER>";

				if ($DB) {
					echo __LINE__ . "\n";
				}
				echo "<br><font class='standard_bold'>" . _QXZ("Call information") . ": $first_name $last_name - $phone_number</font><br><br>";
				echo "<form action='$PHP_SELF' method='POST'>\n";
				echo "<input type=hidden name=end_call value='1'>\n";
				echo "<input type=hidden name=DB value=\"$DB\">\n";
				echo "<input type=hidden name=lead_id id='leadinfo_lead_id' value=\"$lead_id\">\n";
				echo "<input type=hidden name=modify_date id='modify_date' value=\"$modify_date\">\n";
				echo "<input type=hidden name=dispo id='dispo' value=\"$dispo\">\n";
				echo "<input type=hidden name=list_id value=\"$list_id\">\n";
				echo "<input type=hidden name=campaign_id value=\"$campaign_id\">\n";
				echo "<input type=hidden name=old_phone value=\"$phone_number\">\n";
				echo "<input type=hidden name=server_ip value=\"$server_ip\">\n";
				echo "<input type=hidden name=extension value=\"$extension\">\n";
				echo "<input type=hidden name=channel value=\"$channel\">\n";
				echo "<input type=hidden name=call_began value=\"$call_began\">\n";
				echo "<input type=hidden name=parked_time value=\"$parked_time\">\n";
				echo "<input type=hidden name=qc_log_id value=\"$qc_log_id\">\n";
				echo "<input type=hidden name=qc_agent value=\"$qc_agent\">\n";

				echo "<table class='standard' cellpadding=1 cellspacing=0 width='750'>\n";
				echo "<tr><td colspan=2>" . _QXZ("Status") . ": &nbsp; &nbsp; <span id='dispo_display'>$dispo</span></td></tr>\n";
				echo "<tr><td colspan=2>$label_vendor_lead_code: <span id='vendor_id_display'>$vendor_id</span> &nbsp; &nbsp; " . _QXZ("Lead ID") . ": $lead_id</td></tr>\n";
				echo "<tr><td colspan=2>" . _QXZ("Fronter") . ": <span id='tsr_display'><A HREF=\"user_stats.php?user=$tsr\" target='_blank'>$tsr</A></span> &nbsp; &nbsp; " . _QXZ("List ID") . ": $list_id &nbsp; &nbsp; " . _QXZ("Called Count") . ": <span id='called_count_display'>$called_count</span></td></tr>\n";

				echo "<tr><td align=right>$label_title: </td><td align=left><input type=text id=title name=title size=4 maxlength=$MAXtitle value=\"$title\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"> &nbsp; \n";
				echo "$label_first_name: <input type=text id=first_name name=first_name size=15 maxlength=$MAXfirst_name value=\"$first_name\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"> </td></tr>\n";
				echo "<tr><td align=right>$label_middle_initial:  </td><td align=left><input type=text id=middle_initial name=middle_initial size=4 maxlength=$MAXmiddle_initial value=\"$middle_initial\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"> &nbsp; \n";
				echo " $label_last_name: <input type=text id=last_name name=last_name size=15 maxlength=$MAXlast_name value=\"$last_name\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"> </td></tr>\n";
				echo "<tr><td align=right>$label_address1 : </td><td align=left><input type=text id=address1 name=address1 size=40 maxlength=$MAXaddress1 value=\"$address1\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>$label_address2 : </td><td align=left><input type=text id=address2 name=address2 size=40 maxlength=$MAXaddress2 value=\"$address2\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>$label_address3 : </td><td align=left><input type=text id=address3 name=address3 size=40 maxlength=$MAXaddress3 value=\"$address3\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>$label_city : </td><td align=left><input type=text id=city name=city size=40 maxlength=$MAXcity value=\"$city\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>$label_state: </td><td align=left><input type=text id=state name=state size=2 maxlength=$MAXstate value=\"$state\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"> &nbsp; \n";
				echo " $label_postal_code: <input type=text id=postal_code name=postal_code size=10 maxlength=$MAXpostal_code value=\"$postal_code\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"> </td></tr>\n";

				echo "<tr><td align=right>$label_province : </td><td align=left><input type=text id=province name=province size=30 maxlength=$MAXprovince value=\"$province\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>" . _QXZ("Country") . " : </td><td align=left><input type=text id=country_code name=country_code size=3 maxlength=$MAXcountry_code value=\"$country_code\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>$label_phone_number : </td><td align=left><input type=text id=phone_number name=phone_number size=18 maxlength=$MAXphone_number value=\"$phone_number\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>$label_phone_code : </td><td align=left><input type=text id=phone_code name=phone_code size=10 maxlength=$MAXphone_code value=\"$phone_code\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>$label_alt_phone : </td><td align=left><input type=text id=alt_phone name=alt_phone size=12 maxlength=$MAXalt_phone value=\"$alt_phone\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>$label_email : </td><td align=left><input type=text id=email name=email size=40 maxlength=$MAXemail value=\"$email\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>$label_security_phrase : </td><td align=left><input type=text id=security name=security size=40 maxlength=$MAXsecurity_phrase value=\"$security\" onBlur=\"UpdateCustomerInfo($lead_id, 'security_phrase', this.value)\"></td></tr>\n";
				echo "<tr><td align=right>" . _QXZ("Rank") . " : </td><td align=left><input type=text id=rank name=rank size=7 maxlength=5 value=\"$rank\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>" . _QXZ("Owner") . " : </td><td align=left><input type=text id=owner name=owner size=22 maxlength=$MAXowner value=\"$owner\" onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\"></td></tr>\n";
				echo "<tr><td align=right>$label_comments : </td><td align=left><TEXTAREA id=comments name=comments ROWS=3 COLS=65 onBlur=\"UpdateCustomerInfo($lead_id, this.name, this.value)\">$comments</TEXTAREA></td></tr>\n";
				$stmt = "SELECT user_id, timestamp, list_id, campaign_id, comment from vicidial_comments where lead_id='$lead_id' order by timestamp";
				$rslt = mysql_to_mysqli($stmt, $link);
				$row_count = mysqli_num_rows($rslt);
				$o = 0;
				$comments = false;
				while ($row_count > $o) {
					if (!$comments) {
						echo "<tr><td colspan='2' align=center><b>" . _QXZ("Comment History") . "</b></td></tr>\n";
						$comments = true;
					}
					$rowx = mysqli_fetch_row($rslt);
					echo "<tr><td align=right>$rowx[0] : </td><td align=left><hr>$rowx[1]<br><b>" . _QXZ("List ID") . ":</b> $rowx[2]; <b>" . _QXZ("Campaign ID") . ":</b> $rowx[3]<br>$rowx[4]</td></tr>\n";
					$o++;
				}

				/* JCJ - Removed, as it's on the scorecard now

	if ($comments) 
		{
		# echo "<tr><td align=center></td><td><hr></td></tr>\n";
		}
	echo "<tr bgcolor=#".$SSstd_row4_background."><td align=right>"._QXZ("QC Result").": </td><td align=left><select size=1 name=status>\n";

//This section reserved for future expansion (when each campaign will have its own list of QC Result Codes instead of using the the entire master set)
//	$list_campaign='';
//	$stmt="SELECT campaign_id from vicidial_lists where list_id='$list_id'";
//	$rslt=mysql_to_mysqli($stmt, $link);
//	if ($DB) {echo "$stmt\n";}
//	$Cstatuses_to_print = mysqli_num_rows($rslt);
//	if ($Cstatuses_to_print > 0)
//		{
//		$row=mysqli_fetch_row($rslt);
//		$list_campaign = $row[0];
//		}

	$stmt="SELECT code,code_name,qc_result_type from vicidial_qc_codes order by code_name";
	$rslt=mysql_to_mysqli($stmt, $link);
	$statuses_to_print = mysqli_num_rows($rslt);
	$statuses_list='';

	$o=0;
	$DS=0;
	$statuses_list = "<option SELECTED value=\"$dispo\">$dispo</option>\n"; $DS++;
	while ($statuses_to_print > $o)
		{
		$rowx=mysqli_fetch_row($rslt);
		$statuses_list .= "<option value=\"$rowx[0]\">$rowx[0] - $rowx[1]</option>\n";
		$o++;
		}

	$stmt="SELECT status,status_name,selectable,campaign_id,human_answered,category,sale,dnc,customer_contact,not_interested,unworkable from vicidial_campaign_statuses where selectable='Y' and campaign_id='$list_campaign' order by status";
	$rslt=mysql_to_mysqli($stmt, $link);
	$CAMPstatuses_to_print = mysqli_num_rows($rslt);

	$o=0;
	$CBhold_set=0;
        //This function gathers campaign specific statuses to display as dispositions for this record (Note Added by Poundteam)
        //This function is disabled in QC (statuses are generated from qc codes instead)
	while ($CAMPstatuses_to_print > $o)
		{
		if($DB) 
			{
			echo __LINE__."\n";
			}
		$rowx=mysqli_fetch_row($rslt);
		if ( (strlen($dispo) ==  strlen($rowx[0])) and (preg_match("/$dispo/",$rowx[0])) )
			{$statuses_list .= "<option SELECTED value=\"$rowx[0]\">$rowx[0] - $rowx[1]</option>\n"; $DS++;}
		else
			{$statuses_list .= "<option value=\"$rowx[0]\">$rowx[0] - $rowx[1]</option>\n";}
		if ($rowx[0] == 'CBHOLD') {$CBhold_set++;}
		$o++;
		}

	if ($dispo == 'CBHOLD') {$CBhold_set++;}

	if($DB) 
		{
		echo __LINE__."\n";
		}
	if ($DS < 1)
		{$statuses_list .= "<option SELECTED value=\"$dispo\">$dispo</option>\n";}
	if ($CBhold_set < 1)
		{$statuses_list .= "<option value=\"CBHOLD\">"._QXZ("CBHOLD - Scheduled Callback")."</option>\n";}
	echo "$statuses_list";
	echo "</select> <i>("._QXZ("with")." $list_campaign "._QXZ("statuses").")</i></td></tr>\n";
*/


				//      Section Modified for QC Functionality By PoundTeam
				//	echo "<tr bgcolor=#B6D3FC><td align=left>Modify vicidial log </td><td align=left><input type=checkbox name=modify_logs value=\"1\" CHECKED></td></tr>\n";
				//	echo "<tr bgcolor=#B6D3FC><td align=left>Modify agent log </td><td align=left><input type=checkbox name=modify_agent_logs value=\"1\" CHECKED></td></tr>\n";
				//	echo "<tr bgcolor=#B6D3FC><td align=left>Modify closer log </td><td align=left><input type=checkbox name=modify_closer_logs value=\"1\"></td></tr>\n";
				//	echo "<tr bgcolor=#".$SSstd_row4_background."><td align=left>"._QXZ("Disable QC log entry")." </td><td align=left><input type=checkbox name=add_qc_record value=\"1\">("._QXZ("this feature is not active yet").")</td></tr>\n";

				//	echo "<tr><td colspan=2 align=center><input style='background-color:#$SSbutton_color' type=submit name=submit value=\""._QXZ("SUBMIT")."\"></td></tr>\n";
				echo "<tr><td align=right>";
				if (strlen($qc_webform) > 4) {
					echo "<a href=\"$qc_webform\" target='_blank'>" . _QXZ("QC Webform") . "</a>\n";
				} else {
					echo "&nbsp;";
				}
				echo "</td><td>&nbsp;</td></tr>\n";
				echo "</table><input type=hidden name=viewtime value='$STARTtime'></form>\n";

				if ($c > 0) {
					if ($DB) {
						echo __LINE__ . "\n";
					}
					echo "<BR><B>" . _QXZ("EXTENDED ALTERNATE PHONE NUMBERS FOR THIS LEAD") . ":</B>\n";
					echo "<TABLE width=550 cellspacing=0 cellpadding=1>\n";
					echo "<tr><td><font size=1># </td><td><font size=2>" . _QXZ("ALT PHONE") . " </td><td align=left><font size=2>" . _QXZ("ALT NOTE") . "</td><td align=left><font size=2> " . _QXZ("ALT COUNT") . "</td><td align=left><font size=2> " . _QXZ("ACTIVE") . "</td></tr>\n";

					echo "$alts_output\n";

					echo "</TABLE>\n";
					echo "<BR><BR>\n";
				}

				### iframe for custom fields display/editing

				if ($custom_fields_enabled > 0) {
					$CLlist_id = $list_id;
					if (strlen($entry_list_id) > 2) {
						$CLlist_id = $entry_list_id;
					}
					$stmt = "SHOW TABLES LIKE \"custom_$CLlist_id\";";
					if ($DB > 0) {
						echo "$stmt";
					}
					$rslt = mysql_to_mysqli($stmt, $link);
					$tablecount_to_print = mysqli_num_rows($rslt);
					if ($tablecount_to_print > 0) {
						$stmt = "SELECT count(*) from custom_$CLlist_id where lead_id='$lead_id';";
						if ($DB > 0) {
							echo "$stmt";
						}
						$rslt = mysql_to_mysqli($stmt, $link);
						$fieldscount_to_print = mysqli_num_rows($rslt);
						if ($fieldscount_to_print > 0) {
							$rowx = mysqli_fetch_row($rslt);
							$custom_records_count =	$rowx[0];
							$submit_buttonURL = '&submit_button=YES';
							if ($LOGmodify_leads == '5') {
								$submit_buttonURL = '&submit_button=READONLY';
							}

							echo "<font class='standard_bold'>" . _QXZ("CUSTOM FIELDS FOR THIS LEAD") . ":</font><BR>\n";
							echo "<iframe data-src=\"../agc/$vdc_form_display?lead_id=$lead_id&list_id=$CLlist_id&stage=DISPLAY$submit_buttonURL&user=$PHP_AUTH_USER&pass=$PHP_AUTH_PW&bcrypt=OFF&bgcolor=E6E6E6\" style=\"background-color:transparent;\" scrolling=\"auto\" frameborder=\"2\" allowtransparency=\"true\" id=\"vcFormIFrame\" name=\"vcFormIFrame\" width=\"740\" height=\"300\" STYLE=\"z-index:18\"> </iframe>\n";
							echo "<BR><BR>";
						}
					}
				}
				echo "</center></span>";
				# JCJ - END LEAD INFO span


				# JCJ - CALL LOG span
				echo "\n\n<span id='call_logs_span' style='display:" . $call_logs_span_visibility . "'><CENTER>";

				echo "<font class='standard_bold'>" . _QXZ("QUALITY CONTROL LOG RECORDS FOR THIS LEAD") . ":</font>\n";
				echo "<TABLE width=750 cellspacing=0 cellpadding=1>\n";
				echo "<tr class='small_standard_bold'><td># </td><td>" . _QXZ("DATE/TIME") . " </td><td align=center>" . _QXZ("OLD STATUS") . "</td><td align=center>" . _QXZ("NEW STATUS") . "</td><td align=center>" . _QXZ("QC USER") . "</td><td align=center>" . _QXZ("AGENT") . "</td><td align=center>" . _QXZ("CAMPAIGN") . "</td><td align=center>" . _QXZ("LIST") . "</td><td align=center>" . _QXZ("ELAPSED") . "</td><td align=center>&nbsp;</td></tr>\n";
				echo "$qc_agent_log";
				echo "</TABLE>\n";
				echo "<BR><BR>\n\n";

				echo "<font class='standard_bold'>" . _QXZ("CALLS TO THIS LEAD") . ":</font>\n";
				echo "<TABLE width=750 cellspacing=0 cellpadding=1>\n";
				echo "<tr class='small_standard_bold'><td># </td><td>" . _QXZ("DATE/TIME") . " </td><td align=left>" . _QXZ("LENGTH") . "</td><td align=left> " . _QXZ("STATUS") . "</td><td align=left> " . _QXZ("TSR") . "</td><td align=right> " . _QXZ("CAMPAIGN") . "</td><td align=right> " . _QXZ("LIST") . "</td><td align=right> " . _QXZ("LEAD") . "</td><td align=right> " . _QXZ("HANGUP REASON") . "</td><td align=right> " . _QXZ("PHONE") . "</td></tr>\n";
				echo "$call_log";
				echo "</TABLE>\n";
				echo "<BR><BR>\n\n";

				echo "<font class='standard_bold'>" . _QXZ("CLOSER RECORDS FOR THIS LEAD") . ":</font>\n";
				echo "<TABLE width=750 cellspacing=0 cellpadding=1>\n";
				echo "<tr class='small_standard_bold'><td># </td><td>" . _QXZ("DATE/TIME") . " </td><td align=left>" . _QXZ("LENGTH") . "</td><td align=left> " . _QXZ("STATUS") . "</td><td align=left> " . _QXZ("TSR") . "</td><td align=right> " . _QXZ("CAMPAIGN") . "</td><td align=right> " . _QXZ("LIST") . "</td><td align=right> " . _QXZ("LEAD") . "</td><td align=right> " . _QXZ("WAIT") . "</td><td align=right> " . _QXZ("HANGUP REASON") . "</td></tr>\n";
				echo "$closer_log";
				echo "</TABLE>\n";
				echo "<BR><BR>\n\n";


				echo "<font class='standard_bold'>" . _QXZ("AGENT LOG RECORDS FOR THIS LEAD") . ":</font>\n";
				echo "<TABLE width=750 cellspacing=0 cellpadding=1>\n";
				echo "<tr class='small_standard_bold'><td># </td><td>" . _QXZ("DATE/TIME") . " </td><td align=left>" . _QXZ("CAMPAIGN") . "</td><td align=left> " . _QXZ("TSR") . "</td><td align=left> " . _QXZ("PAUSE") . "</td><td align=right> " . _QXZ("WAIT") . "</td><td align=right> " . _QXZ("TALK") . "</td><td align=right> " . _QXZ("DISPO") . "</td><td align=right> " . _QXZ("STATUS") . "</td><td align=right> " . _QXZ("GROUP") . "</td><td align=right> " . _QXZ("SUB") . "</td></tr>\n";
				echo "$agent_log";
				echo "</TABLE>\n";
				echo "<BR><BR>\n\n";

				echo "</CENTER></span>\n";
				# JCJ - END CALL LOG SPAN


				# JCJ - QC LOG span
				echo "\n\n<span id='qc_master_span' style='display:" . $qc_master_span_visibility . "'><CENTER>\n";

				echo "<form action='" . $PHP_SELF . "' name='qc_form' id='qc_form' method='post'>\n";

				echo "<input type=hidden name=DB value=\"$DB\">\n";
				echo "<input type=hidden name=lead_id value=\"$lead_id\">\n";
				echo "<input type=hidden name=dispo value=\"$dispo\">\n";
				echo "<input type=hidden name=list_id value=\"$list_id\">\n";
				echo "<input type=hidden name=campaign_id value=\"$campaign_id\">\n";
				echo "<input type=hidden name=qc_log_id value=\"$qc_log_id\">\n";
				echo "<input type=hidden name=qc_agent value=\"$qc_agent\">\n";
				echo "<input type=hidden name=referring_section value=\"$referring_section\">\n";
				echo "<input type=hidden name=referring_element value=\"$referring_element\">\n";
				echo "<input type=hidden name=finish_qc value='1'>\n";

				if ($qc_log_id) {
					$checkpoint_stmt = "select * from quality_control_checkpoint_log where qc_log_id='$qc_log_id' order by checkpoint_rank asc";
					$checkpoint_rslt = mysql_to_mysqli($checkpoint_stmt, $link);
					if (mysqli_num_rows($checkpoint_rslt) == 0) {
						echo _QXZ("ERROR - NO QUALITY CONTROL RECORD FOUND!");
					} else {
						$scorecard_stmt = "select qc_scorecard_id, recording_id from quality_control_queue where qc_log_id='$qc_log_id'";
						$scorecard_rslt = mysql_to_mysqli($scorecard_stmt, $link);
						$scorecard_row = mysqli_fetch_row($scorecard_rslt);
						$qc_scorecard_id = $scorecard_row[0];
						$qc_recording_id = $scorecard_row[1];

						$stmt = "select recording_id,channel,server_ip,extension,start_time,start_epoch,end_time,end_epoch,length_in_sec,length_in_min,filename,location,lead_id,user,vicidial_id from recording_log where recording_id='" . mysqli_real_escape_string($link, $qc_recording_id) . "' order by recording_id desc limit 500;";
						$rslt = mysql_to_mysqli($stmt, $link);
						$row = mysqli_fetch_row($rslt);

						$location = $row[11];

						if (strlen($location) > 2) {
							$URLserver_ip = $location;
							$URLserver_ip = preg_replace('/http:\/\//i', '', $URLserver_ip);
							$URLserver_ip = preg_replace('/https:\/\//i', '', $URLserver_ip);
							$URLserver_ip = preg_replace('/\/.*/i', '', $URLserver_ip);
							$stmt = "select count(*) from servers where server_ip='$URLserver_ip';";
							$rsltx = mysql_to_mysqli($stmt, $link);
							$rowx = mysqli_fetch_row($rsltx);

							if ($rowx[0] > 0) {
								$stmt = "select recording_web_link,alt_server_ip,external_server_ip from servers where server_ip='$URLserver_ip';";
								$rsltx = mysql_to_mysqli($stmt, $link);
								$rowx = mysqli_fetch_row($rsltx);

								if (preg_match("/ALT_IP/i", $rowx[0])) {
									$location = preg_replace("/$URLserver_ip/i", "$rowx[1]", $location);
								}
								if (preg_match("/EXTERNAL_IP/i", $rowx[0])) {
									$location = preg_replace("/$URLserver_ip/i", "$rowx[2]", $location);
								}
							}
						}

						if (strlen($location) > 30) {
							$locat = substr($location, 0, 27);
							$locat = "$locat...";
						} else {
							$locat = $location;
						}
						$play_audio = '';
						if ((preg_match('/ftp/i', $location)) or (preg_match('/http/i', $location))) {
							$play_audio = "<audio id='main_QC_recording' controls preload=\"none\" onplay='LogAudioRecordingAccess($log_recording_access, $row[0], $row[12], this.id)'> <source src='qc_recording_stream.php?recording_id=" . rawurlencode((string) $row[0]) . "'>" . _QXZ("No browser audio playback support") . "</audio>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;\n";
							if ($log_recording_access < 1) {
								$location = "<a href=\"$location\">$locat</a>";
							} else {
								$location = "<a href=\"recording_log_redirect.php?recording_id=$row[0]&lead_id=$row[12]&search_archived_data=0\">$locat</a>";
							}
						} else {
							$location = $locat;
						}

						$totals_stmt = "SELECT COALESCE(SUM(checkpoint_points_earned), 0), COALESCE(SUM(checkpoint_points), 0) FROM quality_control_checkpoint_log WHERE qc_log_id='$qc_log_id'";
						$totals_rslt = mysql_to_mysqli($totals_stmt, $link);
						$totals_row = mysqli_fetch_row($totals_rslt);
						$total_earned_points = (float) ($totals_row[0] ?? 0);
						$total_max_points = (float) ($totals_row[1] ?? 0);
						$total_score_percentage = $total_max_points > 0
							? (100 * $total_earned_points / $total_max_points)
							: 0;

						echo "<div class='qc-score-summary'>";
						echo "<div class='qc-score-summary-item'>" . _QXZ("EARNED POINTS") . ": <span id='qc_total_earned_points' class='qc-score-summary-value'>" . number_format($total_earned_points, 2, '.', '') . "</span></div>";
						echo "<div class='qc-score-summary-item'>" . _QXZ("SCORE") . ": <span id='qc_total_score' class='qc-score-summary-value'>" . number_format($total_score_percentage, 2, '.', '') . "%</span></div>";
						echo "<div class='qc-score-summary-item'>" . _QXZ("MAX POINTS") . ": <span id='qc_total_max_points' class='qc-score-summary-value'>" . number_format($total_max_points, 2, '.', '') . "</span></div>";
						echo "<div class='qc-score-summary-item'>" . _QXZ("CONVERSATION TIME") . ": <span class='qc-score-summary-value'>" . htmlspecialchars($conversation_time, ENT_QUOTES, 'UTF-8') . "</span></div>";
						echo "</div>";

						echo "<TABLE width='100%' cellspacing=0 cellpadding=3>\n";
						echo "<tr bgcolor='#000'>";
						echo "<td align='left' colspan='2'><font class='standard_bold white_text'>" . _QXZ("SCORECARD ID") . ":</font> <font class='standard_bold' color='#F00'>$qc_scorecard_id</font></td>";
						echo "<td align='right' colspan='4'><font class='standard_bold white_text'>" . _QXZ("RECORDING ID") . ": <input type='text' class='cust_form' name='recording_id" . $qccl_id . "' size='8' maxlength='10' value='$qc_recording_id'></font></td>";
						echo "</tr>\n";

						echo "<tr bgcolor='#000'>";
						echo "<td align='left'>&nbsp;</td>";
						echo "<td align='left'><font class='standard_bold white_text' width='*'>" . _QXZ("Checkpoint") . "</font></td>";
						echo "<td align='left'><font class='standard_bold white_text'>" . _QXZ("Fail") . "?</font></td>";
						echo "<th><font class='standard_bold white_text'>" . _QXZ("Points") . "</font></th>";
						echo "<td align='center'><font class='standard_bold white_text'>" . _QXZ("Comments") . "</font></td>";
						echo "<td align='left'>&nbsp;</td>";
						echo "</tr>\n";
						$i = 1;
						while ($checkpoint_row = mysqli_fetch_array($checkpoint_rslt)) {
							$qccl_id = $checkpoint_row["qc_checkpoint_log_id"];
							$instant_fail_active = $checkpoint_row["instant_fail"];
							$instant_fail_value = $checkpoint_row["instant_fail_value"];
							if (preg_match("/1$|3$|5$|7$|9$/i", $i)) {
								$bgcolor = "bgcolor='#" . $SSstd_row3_background . "'";
							} else {
								$bgcolor = "bgcolor='#" . $SSstd_row2_background . "'";
							}

							echo "<tr $bgcolor>";
							echo "<td align='left'><font class='standard'>$i</font></td>";
							echo "<td align='left' width='*'><font class='small_standard'>{$checkpoint_row['checkpoint_text']}</font></td>";
							if ($instant_fail_active == "Y") {
								echo "<td><input type='checkbox' data-checkpoint-row-id='{$checkpoint_row['checkpoint_row_id']}' data-checkpoint-rank='{$checkpoint_row['checkpoint_rank']}' onClick=\"LogQCData('" . $qccl_id . "')\" id='instant_fail_value" . $qccl_id . "' name='instant_fail_value" . $qccl_id . "' value='Y' " . ($instant_fail_value == "Y" ? "checked" : "") . "></td>";
							} else {
								echo "<td>&nbsp;</td>";
							}
							echo "<td align='center' nowrap><font class='standard'><input type='text' data-checkpoint-row-id='{$checkpoint_row['checkpoint_row_id']}' data-checkpoint-rank='{$checkpoint_row['checkpoint_rank']}' data-max-points='{$checkpoint_row['checkpoint_points']}' onInput='RefreshQcScoreTotals()' onBlur=\"LogQCData('" . $qccl_id . "')\" size=2 maxlength=6 class='cust_form' name='checkpoint_points_earned" . $qccl_id . "' id='checkpoint_points_earned" . $qccl_id . "' value='{$checkpoint_row['checkpoint_points_earned']}'> / {$checkpoint_row['checkpoint_points']}</font></td>";
							echo "<td align='center'>";
							$checkpoint_comment_display = preg_replace('/^\[AI generated\]\R?/i', '', (string) $checkpoint_row['checkpoint_comment_agent']);
							$checkpoint_comment_display = htmlspecialchars($checkpoint_comment_display, ENT_QUOTES, 'UTF-8');
							echo "<textarea data-checkpoint-row-id='{$checkpoint_row['checkpoint_row_id']}' data-checkpoint-rank='{$checkpoint_row['checkpoint_rank']}' onBlur=\"LogQCData('" . $qccl_id . "')\" class='cust_form' name='checkpoint_comment_agent" . $qccl_id . "' id='checkpoint_comment_agent" . $qccl_id . "' rows='6' cols='55'>$checkpoint_comment_display</textarea>";
							echo "<BR><input type='button' class='qc-comment-action' style='margin:6px 3px;background-color:#" . $SSbutton_color . ";font-weight:bold' value='ADD REC TIMESTAMP' onClick=\"AddToComments('" . $qccl_id . "', 1)\">";
							echo "</td>";

							echo "<td align='center'><font class='small_standard'>";

							if (strlen($checkpoint_row["checkpoint_text_presets"]) > 0) {
								echo "Presets: <select class='cust_form' name='checkpoint_text_presets" . $qccl_id . "' id='checkpoint_text_presets" . $qccl_id . "'>\n";
								$presets_array = explode("\n", $checkpoint_row["checkpoint_text_presets"]);
								for ($j = 0; $j < count($presets_array); $j++) {
									# $preset_keyvals=explode(",", $presets_array[$j]);
									# $pi=count($preset_keyvals)-1;
									#						echo "<option value='$preset_keyvals[0]'>".$preset_keyvals[$pi]."</option>\n";
									echo "<option value='$presets_array[$j]'>" . (strlen($presets_array[$j]) > 20 ? substr($presets_array[$j], 0, 20) . "..." : $presets_array[$j]) . "</option>\n";
								}
								echo "</select><BR>\n";
								echo "<input type='button' class='qc-comment-action' style='margin:3px;background-color:#" . $SSbutton_color . ";font-weight:bold' value='ADD TO COMMENTS' onClick=\"AddToComments('" . $qccl_id . "', 0)\"><BR>";
								echo "<input type='button' class='qc-comment-action' style='margin:3px;background-color:#" . $SSbutton_color . ";font-weight:bold' value='TIMESTAMP & ADD' onClick=\"AddToComments('" . $qccl_id . "', 2)\">";
							} else {
								echo "&nbsp;";
							}
							echo "</font></td>";

							echo "</tr>";
							$i++;
						}
						echo "<tr bgcolor='#" . $SSstd_row4_background . "'>";
						echo "<td align='right' colspan='2'>$play_audio &nbsp;</font></td>";
						echo "<td align='center' colspan='2'><font class='standard_bold'>$location</font></td>";
							// Always return through the claimed QC record. The original request may
							// contain claim_QC=CLAIM, which must not be replayed by the Back button.
							$summary_return_url = 'qc_modify_lead.php?' . http_build_query([
								'qc_log_id' => $qc_log_id,
								'lead_id' => $lead_id,
								'referring_section' => $referring_section,
								'referring_element' => $referring_element,
								'qc_theme' => in_array(($_GET['qc_theme'] ?? ''), ['light', 'dark'], true) ? $_GET['qc_theme'] : '',
							]);
							$saved_ai_summary = '';
							if (ctype_digit((string) $qc_recording_id)) {
								$summary_file = '/var/spool/asterisk/uploads/ai_summary/' . $qc_recording_id . '_ai_summary.txt';
								if (is_file($summary_file) && is_readable($summary_file)) {
									$summary_contents = file_get_contents($summary_file);
									if ($summary_contents !== false) {
										$saved_ai_summary = $summary_contents;
									}
								}
							}
							$saved_ai_summary_json = json_encode(
								$saved_ai_summary,
								JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
							);
							$qc_return_url_json = json_encode(
								$summary_return_url,
								JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
							);
							$checkpoint_scorecard_json = json_encode(
								(string) $qc_scorecard_id,
								JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
							);
							$checkpoint_scorecard_argument = htmlspecialchars(
								(string) $checkpoint_scorecard_json,
								ENT_QUOTES,
								'UTF-8'
							);
							$recording_id_json = json_encode(
								(string) $qc_recording_id,
								JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
							);
							$recording_id_argument = htmlspecialchars(
								(string) $recording_id_json,
								ENT_QUOTES,
								'UTF-8'
							);
							$action_button_style = 'display:inline-flex;align-items:center;justify-content:center;'
								. 'min-height:30px;padding:0 12px;border-radius:4px;color:#fff;'
								. 'font-family:Arial,sans-serif;font-size:12px;line-height:1.2;font-weight:700;'
								. 'text-decoration:none;white-space:nowrap;box-sizing:border-box;';

							echo "<td align='center' colspan='2' style='padding:8px 10px'>";
							$summary_button_label = $saved_ai_summary === '' ? 'Generate AI Summary' : 'Regenerate AI Summary';
							echo "<div class='qc-ai-steps'>";
							echo "<div class='qc-ai-step'><span class='qc-ai-step-number'>1</span><div class='qc-ai-step-content'>";
							echo "<a class='qc-ai-action' href='#qc-ai-summary-panel' style='" . $action_button_style . "' onclick=\"generateAiSummary($checkpoint_scorecard_argument, $recording_id_argument, this); return false;\">$summary_button_label</a></div></div>";
							echo "<div class='qc-ai-step'><span class='qc-ai-step-number'>2</span><div class='qc-ai-step-content'>";
							echo "<a class='qc-ai-action' href='#' style='" . $action_button_style . "' onclick=\"generateAiAnalysis($checkpoint_scorecard_argument, $recording_id_argument, this); return false;\">Generate AI Checkpoints &amp; Sentiment</a></div></div>";
							echo "</div>";
						echo "</td>";
						echo "</tr>\n";
							echo "<tr><td colspan='6'><div id='qc-ai-summary-panel' class='qc-ai-summary-panel' role='status' aria-live='polite'" . ($saved_ai_summary === '' ? " hidden" : "") . "></div></td></tr>\n";
							echo "<tr><td colspan='6' style='padding:10px'>" . $qc_sentiment_panel_html . "</td></tr>\n";
							echo "<script>
							var savedAiSummary = $saved_ai_summary_json;

							function renderAiSummary(summary) {
								var summaryPanel = document.getElementById('qc-ai-summary-panel');
								var source = String(summary || '').trim();
								summaryPanel.classList.remove('is-no-conversation');
								var parsed = new DOMParser().parseFromString(source, 'text/html');
								var allowedTags = new Set(['P', 'BR', 'STRONG', 'EM', 'UL', 'OL', 'LI']);

								parsed.body.querySelectorAll('*').forEach(function(element) {
									if (!allowedTags.has(element.tagName)) {
										element.replaceWith(document.createTextNode(element.textContent));
										return;
									}
									Array.from(element.attributes).forEach(function(attribute) {
										element.removeAttribute(attribute.name);
									});
								});

								summaryPanel.replaceChildren.apply(summaryPanel, Array.from(parsed.body.childNodes));
								summaryPanel.hidden = source === '';
							}

							function renderNoConversationMessage(message) {
								var summaryPanel = document.getElementById('qc-ai-summary-panel');
								summaryPanel.textContent = String(message || 'AI could not find a connected two-way conversation in this recording.');
								summaryPanel.classList.add('is-no-conversation');
								summaryPanel.hidden = false;
							}

							if (savedAiSummary.trim() !== '') renderAiSummary(savedAiSummary);

							async function readAiResponse(response, actionName) {
								var data;
								var responseText = await response.text();
								try {
									data = JSON.parse(responseText);
								} catch (error) {
									throw new Error('AI could not complete the ' + actionName + '. Please try again.');
								}

								if (!response.ok || !data.success) {
									if (data.no_connected_conversation) {
										var noConversationError = new Error(data.message || 'AI could not find a connected two-way conversation in this recording.');
										noConversationError.noConnectedConversation = true;
										throw noConversationError;
									}
									if (data.attempts || response.status < 500) {
										throw new Error(data.message || 'AI could not complete the ' + actionName + '. Please try again.');
									}
									throw new Error('The AI service is temporarily unavailable. Please try again later or contact your supervisor.');
								}

								return data;
							}

							function applyCheckpointEvaluation(data) {
								var scores = data && data.evaluation && Array.isArray(data.evaluation.checkpoints)
									? data.evaluation.checkpoints
									: [];
								var updated = 0;
								var saveRequests = [];

								scores.forEach(function(score) {
									var rowId = String(score.checkpoint_row_id || '');
									var rank = String(score.checkpoint_rank || '');
									var pointsField = rowId
										? document.querySelector('[id^=\"checkpoint_points_earned\"][data-checkpoint-row-id=\"' + rowId + '\"]')
										: null;

									if (!pointsField && rank) {
										pointsField = document.querySelector('[id^=\"checkpoint_points_earned\"][data-checkpoint-rank=\"' + rank + '\"]');
									}

									if (!pointsField) {
										throw new Error('AI returned a checkpoint that does not match this scorecard. Please refresh the page and try again.');
									}

									var logId = pointsField.id.replace('checkpoint_points_earned', '');
									var maximum = Number(pointsField.dataset.maxPoints || 0);
									var earned = Number(score.earned_points);

									if (!Number.isFinite(earned)) {
										earned = 0;
									}

									pointsField.value = Math.max(0, Math.min(Math.round(earned), maximum));

									var failField = document.getElementById('instant_fail_value' + logId);
									if (failField) {
										var statusIsFail = String(score.status || '').toLowerCase() === 'fail';
										failField.checked = score.is_failed === true || statusIsFail;
									}

									var commentField = document.getElementById('checkpoint_comment_agent' + logId);
									if (commentField) {
										var timestamp = String(score.timestamp || 'N/A').trim() || 'N/A';
										var evidence = String(score.evidence || '').trim();
										var reason = String(score.reason || '').trim();
										var commentParts = [];

										if (evidence) commentParts.push('Evidence ' + timestamp + ': ' + evidence);
										if (reason) commentParts.push('Reason ' + timestamp + ': ' + reason);
										commentField.value = commentParts.join('\\n');
									}

									saveRequests.push(LogQCData(logId, true));
									updated++;
								});

								RefreshQcScoreTotals();

								return {updated: updated, saveRequests: saveRequests};
							}

							async function refreshAiSentimentPanel() {
								var response = await fetch($qc_return_url_json, {credentials: 'same-origin', cache: 'no-store'});
								if (!response.ok) throw new Error('Could not load the saved sentiment analysis. Please refresh the page.');
								var page = new DOMParser().parseFromString(await response.text(), 'text/html');
								var savedPanel = page.getElementById('qc-ai-sentiment-panel');
								var currentPanel = document.getElementById('qc-ai-sentiment-panel');
								if (!savedPanel || !currentPanel) throw new Error('Could not load the saved sentiment analysis. Please refresh the page.');
								currentPanel.replaceWith(document.importNode(savedPanel, true));
							}

							async function generateAiAnalysis(qcScorecardId, recordingId, button) {
								var originalText = button.textContent;
								button.style.pointerEvents = 'none';
								button.textContent = 'Analyzing recording...';

								try {
									var response = await fetch('summarize.php', {
										method: 'POST',
										headers: {'Content-Type': 'application/json'},
										body: JSON.stringify({
											qc_log_id: String(qc_log_id),
											qc_scorecard_id: qcScorecardId,
											recording_id: recordingId,
											analysis_mode: 'checkpoints_sentiment',
											force_regenerate: true
										})
									});
									var data = await readAiResponse(response, 'checkpoints and sentiment analysis');

									// Display persisted sentiment even if a later checkpoint save fails.
									var sentimentRefreshError = null;
									await refreshAiSentimentPanel().catch(function(error) {
										sentimentRefreshError = error;
									});

									var result = applyCheckpointEvaluation(data);

									var expectedCheckpointCount = document.querySelectorAll('[id^=\"checkpoint_points_earned\"]').length;
									if (result.updated !== expectedCheckpointCount) {
										if (data.cached) {
											window.location.replace($qc_return_url_json);
											return;
										}

									throw new Error('AI returned only ' + result.updated + ' of ' + expectedCheckpointCount + ' checkpoint results. Please generate the analysis again.');
									}

									var saved = await Promise.all(result.saveRequests);
									// Totals synchronization is best effort; the refreshed page calculates
									// displayed totals directly from the saved checkpoints.
									await SyncQcStoredTotals();

									if (saved.some(function(success) { return !success; })) {
									throw new Error('Sentiment analysis was saved, but some checkpoint updates could not be saved. Please check your session or connection and try again.' + (sentimentRefreshError ? ' ' + sentimentRefreshError.message : ''));
									}

									// Refresh the saved QC data without adding a duplicate history entry.
									// This prevents Browser Back from reopening the one-time claim URL.
									window.location.replace($qc_return_url_json);
								} catch (error) {
								alert(error instanceof TypeError
									? 'The AI service could not be reached. Please check your connection and try again.'
									: (error && error.message ? error.message : 'AI could not generate the checkpoints and sentiment analysis. Please try again.'));
								} finally {
									button.style.pointerEvents = '';
									button.textContent = originalText;
								}
							}

							async function generateAiSummary(qcScorecardId, recordingId, button) {
								var originalText = button.textContent;
								var shouldRegenerate = !document.getElementById('qc-ai-summary-panel').hidden;
								button.style.pointerEvents = 'none';
								button.textContent = 'Generating summary...';

								try {
									var response = await fetch('summarize.php', {
										method: 'POST',
										headers: {'Content-Type': 'application/json'},
										body: JSON.stringify({
											qc_log_id: String(qc_log_id),
											qc_scorecard_id: qcScorecardId,
											recording_id: recordingId,
											analysis_mode: 'summary_only',
											force_regenerate: shouldRegenerate
										})
									});
									var data = await readAiResponse(response, 'AI summary');

									renderAiSummary(data.summary || '');
									button.textContent = 'Regenerate AI Summary';
									document.getElementById('qc-ai-summary-panel').scrollIntoView({behavior: 'smooth', block: 'nearest'});
								} catch (error) {
									if (error && error.noConnectedConversation) {
										renderNoConversationMessage(error.message);
										document.getElementById('qc-ai-summary-panel').scrollIntoView({behavior: 'smooth', block: 'nearest'});
									} else {
										alert(error instanceof TypeError
											? 'The AI service could not be reached. Please check your connection and try again.'
											: (error && error.message ? error.message : 'AI could not generate the summary. Please try again.'));
									}
								} finally {
									button.style.pointerEvents = '';
									if (!document.getElementById('qc-ai-summary-panel').hidden) {
										button.textContent = 'Regenerate AI Summary';
									} else {
										button.textContent = originalText;
									}
								}
							}
						</script>";
						$qc_agent_comment_display = htmlspecialchars((string) $qc_agent_comment, ENT_QUOTES, 'UTF-8');
						echo "<tr bgcolor='#000' class='qc-comment-row'>";
						echo "<td align='left'><font class='standard_bold white_text'>" . _QXZ("QC COMMENT") . ": </font></td>";
						// echo "<td colspan='5'><textarea name='qc_agent_comment' id='qc_agent_comment' rows='5' class='cust_form' style='width:98%; box-sizing:border-box;'>$qc_agent_comment_display</textarea></td>";
						echo "<td colspan='5'>";
						echo "<textarea
								name='qc_agent_comment'
								id='qc_agent_comment'
								rows='5'
								class='cust_form'
								style='width:98%; box-sizing:border-box;'>"
							. $qc_agent_comment_display
							. "</textarea><br>";

						// echo "<input
						// 		type='button'
						// 		value='SAVE QC COMMENT'
						// 		class='green_btn'
						// 		onclick='SaveQcAgentComment(this)'>";

						// echo " <span id='qc-comment-save-status'></span>";
						echo "<div style='
								display:flex;
								justify-content:flex-end;
								align-items:center;
								gap:12px;
								margin:15px 0;
								padding-right:2%;
							'>";

						echo "<span id='qc-comment-save-status'></span>";

						echo "<input
								type='button'
								value='SAVE QC COMMENT'
								class='green_btn'
								style='margin:0;'
								onclick='SaveQcAgentComment(this)'>";

						echo "</div>";
						echo "</td>";
						
						echo "</tr>\n";
						echo "<tr bgcolor='#000'>";
						echo "<td align='left'><font class='standard_bold white_text'>" . _QXZ("FINISH QC") . ": </font></td>";

						# echo "<tr bgcolor=#".$SSstd_row4_background."><td align=right>"._QXZ("QC Result").": </td>";
						echo "<td align=left colspan='3'><select size=1 name=status class='cust_form'>\n";

						//This section reserved for future expansion (when each campaign will have its own list of QC Result Codes instead of using the the entire master set)
						//	$list_campaign='';
						//	$stmt="SELECT campaign_id from vicidial_lists where list_id='$list_id'";
						//	$rslt=mysql_to_mysqli($stmt, $link);
						//	if ($DB) {echo "$stmt\n";}
						//	$Cstatuses_to_print = mysqli_num_rows($rslt);
						//	if ($Cstatuses_to_print > 0)
						//		{
						//		$row=mysqli_fetch_row($rslt);
						//		$list_campaign = $row[0];
						//		}

						$stmt = "SELECT code,code_name,qc_result_type from vicidial_qc_codes order by code_name";
						$rslt = mysql_to_mysqli($stmt, $link);
						$statuses_to_print = mysqli_num_rows($rslt);
						$statuses_list = '';

						$o = 0;
						$DS = 0;
						$statuses_list = "<option SELECTED value=\"$dispo\">$dispo</option>\n";
						$DS++;
						while ($statuses_to_print > $o) {
							$rowx = mysqli_fetch_row($rslt);
							$statuses_list .= "<option value=\"$rowx[0]\">$rowx[0] - $rowx[1]</option>\n";
							$o++;
						}

						$stmt = "SELECT status,status_name,selectable,campaign_id,human_answered,category,sale,dnc,customer_contact,not_interested,unworkable from vicidial_campaign_statuses where selectable='Y' and campaign_id='$list_campaign' order by status";
						$rslt = mysql_to_mysqli($stmt, $link);
						$CAMPstatuses_to_print = mysqli_num_rows($rslt);

						$o = 0;
						$CBhold_set = 0;
						//This function gathers campaign specific statuses to display as dispositions for this record (Note Added by Poundteam)
						//This function is disabled in QC (statuses are generated from qc codes instead)
						while ($CAMPstatuses_to_print > $o) {
							if ($DB) {
								echo __LINE__ . "\n";
							}
							$rowx = mysqli_fetch_row($rslt);
							if ((strlen($dispo) ==  strlen($rowx[0])) and (preg_match("/$dispo/", $rowx[0]))) {
								$statuses_list .= "<option SELECTED value=\"$rowx[0]\">$rowx[0] - $rowx[1]</option>\n";
								$DS++;
							} else {
								$statuses_list .= "<option value=\"$rowx[0]\">$rowx[0] - $rowx[1]</option>\n";
							}
							if ($rowx[0] == 'CBHOLD') {
								$CBhold_set++;
							}
							$o++;
						}

						if ($dispo == 'CBHOLD') {
							$CBhold_set++;
						}

						if ($DB) {
							echo __LINE__ . "\n";
						}
						if ($DS < 1) {
							$statuses_list .= "<option SELECTED value=\"$dispo\">$dispo</option>\n";
						}
						if ($CBhold_set < 1) {
							$statuses_list .= "<option value=\"CBHOLD\">" . _QXZ("CBHOLD - Scheduled Callback") . "</option>\n";
						}
						echo "$statuses_list";
						echo "</select> <font class='standard_bold white_text'><i>(" . _QXZ("with") . " $list_campaign " . _QXZ("statuses") . ")</i></font></td>";


						#			echo "<td colspan='2' align='center'><input type='button' class='red_btn' style='width:100px' value='FAIL' onClick=\"FinishQCRecord('$qc_log_id', 'FAIL')\"></td>";
						echo "<td align='center'><input type='button' class='green_btn' style='width:100px' value='" . _QXZ("FINISH") . "' onClick=\"FinishQCRecord('$qc_log_id', 'FINISHED', this.form)\"></td><td align='center'><input type='button' class='red_btn' style='width:100px' value='" . _QXZ("RELEASE") . "' onClick=\"FinishQCRecord('$qc_log_id', 'RELEASE', this.form)\"></td>";
						echo "</tr>\n";
						echo "</TABLE>\n";
					}
					echo "<BR><BR><BR>";
				}


				echo "<font class='standard_bold'>" . _QXZ("ALL RECORDINGS FOR THIS LEAD") . ":</font>\n";
				echo "<TABLE class='small_standard' width='95%' cellspacing=1 cellpadding=1>\n";
				echo "<tr><td># </td><td align=left> " . _QXZ("LEAD") . "</td><td>" . _QXZ("DATE/TIME") . " </td><td align=left>" . _QXZ("SECONDS") . " </td><td align=left> &nbsp; " . _QXZ("RECID") . "</td><td align=center>" . _QXZ("FILENAME") . "</td><td align=left>" . _QXZ("LOCATION") . "</td><td align=left>" . _QXZ("TSR") . "</td><td align=left>&nbsp; </td></tr>\n";

				$stmt = "select recording_id,channel,server_ip,extension,start_time,start_epoch,end_time,end_epoch,length_in_sec,length_in_min,filename,location,lead_id,user,vicidial_id from recording_log where lead_id='" . mysqli_real_escape_string($link, $lead_id) . "' and recording_id!='" . mysqli_real_escape_string($link, $qc_recording_id) . "' order by recording_id desc limit 500;";
				$rslt = mysql_to_mysqli($stmt, $link);
				$logs_to_print = mysqli_num_rows($rslt);
				if ($DB) {
					echo "$logs_to_print|$stmt|\n";
				}

				$u = 0;
				while ($logs_to_print > $u) {
					$row = mysqli_fetch_row($rslt);
					if (preg_match("/1$|3$|5$|7$|9$/i", $u)) {
						$bgcolor = "bgcolor='#" . $SSstd_row2_background . "'";
					} else {
						$bgcolor = "bgcolor='#" . $SSstd_row1_background . "'";
					}

					$location = $row[11];

					if (strlen($location) > 2) {
						$URLserver_ip = $location;
						$URLserver_ip = preg_replace('/http:\/\//i', '', $URLserver_ip);
						$URLserver_ip = preg_replace('/https:\/\//i', '', $URLserver_ip);
						$URLserver_ip = preg_replace('/\/.*/i', '', $URLserver_ip);
						$stmt = "select count(*) from servers where server_ip='$URLserver_ip';";
						$rsltx = mysql_to_mysqli($stmt, $link);
						$rowx = mysqli_fetch_row($rsltx);

						if ($rowx[0] > 0) {
							$stmt = "select recording_web_link,alt_server_ip,external_server_ip from servers where server_ip='$URLserver_ip';";
							$rsltx = mysql_to_mysqli($stmt, $link);
							$rowx = mysqli_fetch_row($rsltx);

							if (preg_match("/ALT_IP/i", $rowx[0])) {
								$location = preg_replace("/$URLserver_ip/i", "$rowx[1]", $location);
							}
							if (preg_match("/EXTERNAL_IP/i", $rowx[0])) {
								$location = preg_replace("/$URLserver_ip/i", "$rowx[2]", $location);
							}
						}
					}

					if (strlen($location) > 30) {
						$locat = substr($location, 0, 27);
						$locat = "$locat...";
					} else {
						$locat = $location;
					}
					$play_audio = '<td align=left>&nbsp; </font></td>';
					if ((preg_match('/ftp/i', $location)) or (preg_match('/http/i', $location))) {
						$play_audio = "<td align=left> <audio id='QC_recording_id_" . $row[0] . "' controls preload=\"none\" onplay='LogAudioRecordingAccess($log_recording_access, $row[0], $row[12], this.id)'> <source src='qc_recording_stream.php?recording_id=" . rawurlencode((string) $row[0]) . "'>" . _QXZ("No browser audio playback support") . "</audio> </td>\n";
						if ($log_recording_access < 1) {
							$location = "<a href=\"$location\">$locat</a>";
						} else {
							$location = "<a href=\"recording_log_redirect.php?recording_id=$row[0]&lead_id=$row[12]&search_archived_data=0\">$locat</a>";
						}
					} else {
						$location = $locat;
					}
					$u++;
					echo "<tr $bgcolor>";
					echo "<td>$u</td>";
					echo "<td align=left> $row[12] </td>";
					echo "<td align=left> $row[4] </td>\n";
					echo "<td align=left> $row[8] </td>\n";
					echo "<td align=left> $row[0] &nbsp;</td>\n";
					echo "<td align=center> $row[10] </td>\n";
					echo "<td align=left> $location </td>\n";
					echo "<td align=left> <A HREF=\"user_stats.php?user=$row[13]\" target=\"_blank\">$row[13]</A> </td>";
					echo "$play_audio";
					echo "</tr>\n";
				}


				echo "</TABLE><BR><BR>\n";
				echo "</CENTER></span>\n";

				echo "<input type='hidden' name='qc_process_status' id='qc_process_status'>\n";
				echo "</form>\n";
				# END QC LOG SPAN

				$stmt = "SELECT count(*) from vicidial_users where user='$PHP_AUTH_USER' and pass='$PHP_AUTH_PW' and user_level >= 9 and modify_leads IN('1','2','3','4');";
				if ($DB) {
					echo "|$stmt|\n";
				}
				if ($non_latin > 0) {
					$rslt = mysql_to_mysqli("SET NAMES 'UTF8'", $link);
				}
				$rslt = mysql_to_mysqli($stmt, $link);
				$row = mysqli_fetch_row($rslt);
				$admin_display = $row[0];
				if ($admin_display > 0) {
					echo "<a href=\"admin.php?ADD=720000000000000&stage=$lead_id&category=LEADS\">" . _QXZ("Click here to see Lead Modify changes to this lead") . "</a>\n";
				}
			}

			echo "</td></tr></table>\n";

			$ENDtime = date("U");

			$RUNtime = ($ENDtime - $STARTtime);

			echo "\n\n\n<br><br><br>\n\n";


			echo "<font size=0>\n\n\n<br><br><br>\n" . _QXZ("script runtime") . ": $RUNtime " . _QXZ("seconds") . "</font>";


			?>

</body>

</html>

<?php
if ($DB) {
	echo "<pre>" . _QXZ("original_record") . ":<br>";
	print_r($original_record);
	echo "<pre>" . _QXZ("new_record") . ":<br>";
	print_r($new_record);
	echo _QXZ("scheduled_callback") . ":<br>";
	print_r($scheduled_callback);
	echo _QXZ("debug1") . ":<br>";
	print_r($debug1);
	echo _QXZ("Post") . ":<br>";
	print_r($_POST);
	echo _QXZ("qcchangelist") . ":<br>";
	print_r($qcchangelist);
	echo "</pre>";
}
exit;

?>
