<?php
# qc_scorecards.php
# 
# Copyright (C) 2022  Matt Florell <vicidial@gmail.com>    LICENSE: AGPLv2
#
# this screen manages QC Scorecards within VICIdial
#
# changes:
# 210306-1532 - First Build
# 210827-1818 - Fix for security issue
# 220224-2144 - Added allow_web_debug system setting
#

$admin_version = '2.14-2';
$build = '220224-2144';

header ("Content-type: text/html; charset=utf-8");

require("dbconnect_mysqli.php"); # /srv/www/vhosts/vicimarketing/vicidial/
require("functions.php");
$link=mysqli_connect("$VARDB_server", "$VARDB_user", "$VARDB_pass", "$VARDB_database", $VARDB_port);
if (!$link) 
	{
    die("MySQL connect ERROR:  " . mysqli_error('mysqli'));
	}
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}
$has_admin_session = !empty($_SESSION['user']);
$PHP_AUTH_USER = isset($_SESSION['user'])
	? (string) $_SESSION['user']
	: (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
$PHP_AUTH_PW = isset($_SESSION['pass'])
	? (string) $_SESSION['pass']
	: (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
$QUERY_STRING = getenv("QUERY_STRING");
$PHP_SELF=$_SERVER['PHP_SELF'];
$PHP_SELF = preg_replace('/\.php.*/i','.php',$PHP_SELF);
if (isset($_GET["DB"]))				{$DB=$_GET["DB"];}
	elseif (isset($_POST["DB"]))	{$DB=$_POST["DB"];}
if (isset($_GET["submit"]))	{$submit=$_GET["submit"];}
	elseif (isset($_POST["submit"]))	{$submit=$_POST["submit"];}
if (isset($_GET["new_scorecard_id"]))	{$new_scorecard_id=$_GET["new_scorecard_id"];}
	elseif (isset($_POST["new_scorecard_id"]))	{$new_scorecard_id=$_POST["new_scorecard_id"];}
if (isset($_GET["new_scorecard_name"]))	{$new_scorecard_name=$_GET["new_scorecard_name"];}
	elseif (isset($_POST["new_scorecard_name"]))	{$new_scorecard_name=$_POST["new_scorecard_name"];}
if (isset($_GET["new_active"]))	{$new_active=$_GET["new_active"];}
	elseif (isset($_POST["new_active"]))	{$new_active=$_POST["new_active"];}
if (isset($_GET["active"]))	{$active=$_GET["active"];}
	elseif (isset($_POST["active"]))	{$active=$_POST["active"];}
if (isset($_GET["action"]))	{$action=$_GET["action"];}
	elseif (isset($_POST["action"]))	{$action=$_POST["action"];}
if (isset($_GET["confirm_deletion"]))	{$confirm_deletion=$_GET["confirm_deletion"];}
	elseif (isset($_POST["confirm_deletion"]))	{$confirm_deletion=$_POST["confirm_deletion"];}
if (isset($_GET["scorecard_id"]))	{$scorecard_id=$_GET["scorecard_id"];}
	elseif (isset($_POST["scorecard_id"]))	{$scorecard_id=$_POST["scorecard_id"];}

if ( file_exists("/etc/mysql_enc.conf") ) {
	$DBCagc = file("/etc/mysql_enc.conf");
	foreach ($DBCagc as $DBCline) {
		$DBCline = preg_replace("/ |>|\n|\r|\t|\#.*|;.*/","",$DBCline);
		if (ereg("^enckey", $DBCline)) {$enckey = $DBCline;   $enckey = preg_replace("/.*=/","",$enckey);}
	}
}

$DB=preg_replace('/[^0-9]/','',$DB);

#############################################
##### START SYSTEM_SETTINGS LOOKUP #####
$stmt = "SELECT use_non_latin,auto_dial_limit,user_territories_active,allow_custom_dialplan,callcard_enabled,admin_modify_refresh,nocache_admin,webroot_writable,admin_screen_colors,qc_features_active,hosted_settings,allow_web_debug FROM system_settings;";
$rslt=mysql_to_mysqli($stmt, $link);
#if ($DB) {echo "$stmt\n";}
$qm_conf_ct = mysqli_num_rows($rslt);
if ($qm_conf_ct > 0)
	{
	$row=mysqli_fetch_row($rslt);
	$non_latin =					$row[0];
	$SSauto_dial_limit =			$row[1];
	$SSuser_territories_active =	$row[2];
	$SSallow_custom_dialplan =		$row[3];
	$SScallcard_enabled =			$row[4];
	$SSadmin_modify_refresh =		$row[5];
	$SSnocache_admin =				$row[6];
	$SSwebroot_writable =			$row[7];
	$SSadmin_screen_colors =		$row[8];
	$SSqc_features_active =			$row[9];
	$SShosted_settings =			$row[10];
	# slightly increase limit value, because PHP somehow thinks 2.8 > 2.8
	$SSauto_dial_limit = ($SSauto_dial_limit + 0.001);
	$SSallow_web_debug =			$row[11];
	}
if ($SSallow_web_debug < 1) {$DB=0;}
##### END SETTINGS LOOKUP #####
###########################################

$submit=preg_replace('/[^-0-9 \p{L}]/u','',$submit);
$new_active = preg_replace('/[^NY]/','',$new_active);
$active = preg_replace('/[^NY]/','',$active);
$action = preg_replace('/[^-_0-9a-zA-Z]/','',$action);
$confirm_deletion = preg_replace('/[^Y]/','',$confirm_deletion);
$scorecard_id = preg_replace('/[^-_0-9a-zA-Z]/','',$scorecard_id);

if ($non_latin < 1)
	{
	$PHP_AUTH_USER = preg_replace('/[^-_0-9a-zA-Z]/', '', $PHP_AUTH_USER);
	$PHP_AUTH_PW = preg_replace('/[^-_0-9a-zA-Z]/', '', $PHP_AUTH_PW);
	$new_scorecard_id = preg_replace('/[^-_0-9a-zA-Z]/','',$new_scorecard_id);
	$new_scorecard_name = preg_replace('/[^- \.\,\_0-9a-zA-Z]/','',$new_scorecard_name);
	}
else
	{
	$PHP_AUTH_USER = preg_replace('/[^-_0-9\p{L}]/u', '', $PHP_AUTH_USER);
	$PHP_AUTH_PW = preg_replace('/[^-_0-9\p{L}]/u', '', $PHP_AUTH_PW);
	$new_scorecard_id = preg_replace('/[^-_0-9\p{L}]/u','',$new_scorecard_id);
	$new_scorecard_name = preg_replace('/[^- \.\,\_0-9\p{L}]/u','',$new_scorecard_name);
	}

$SSmenu_background='015B91';
$SSframe_background='D9E6FE';
$SSstd_row1_background='9BB9FB';
$SSstd_row2_background='B9CBFD';
$SSstd_row3_background='8EBCFD';
$SSstd_row4_background='B6D3FC';
$SSstd_row5_background='A3C3D6';
$SSalt_row1_background='BDFFBD';
$SSalt_row2_background='99FF99';
$SSalt_row3_background='CCFFCC';

if ($SSadmin_screen_colors != 'default')
	{
	$stmt = "SELECT menu_background,frame_background,std_row1_background,std_row2_background,std_row3_background,std_row4_background,std_row5_background,alt_row1_background,alt_row2_background,alt_row3_background,web_logo FROM vicidial_screen_colors where colors_id='$SSadmin_screen_colors';";
	$rslt=mysql_to_mysqli($stmt, $link);
	if ($DB) {echo "$stmt\n";}
	$colors_ct = mysqli_num_rows($rslt);
	if ($colors_ct > 0)
		{
		$row=mysqli_fetch_row($rslt);
		$SSmenu_background =		$row[0];
		$SSframe_background =		$row[1];
		$SSstd_row1_background =	$row[2];
		$SSstd_row2_background =	$row[3];
		$SSstd_row3_background =	$row[4];
		$SSstd_row4_background =	$row[5];
		$SSstd_row5_background =	$row[6];
		$SSalt_row1_background =	$row[7];
		$SSalt_row2_background =	$row[8];
		$SSalt_row3_background =	$row[9];
		$SSweb_logo =				$row[10];
		}
	}
$Mhead_color =	$SSstd_row5_background;
$Mmain_bgcolor = $SSmenu_background;
$Mhead_color =	$SSstd_row5_background;

# Valid user
$auth=0;
$auth_message = '';
$session_user_escaped = mysqli_real_escape_string($link, $PHP_AUTH_USER);
$session_auth_result = mysqli_query(
	$link,
	"SELECT qc_enabled FROM vicidial_users WHERE user='$session_user_escaped' AND active='Y' LIMIT 1"
);
$session_auth_row = $session_auth_result instanceof mysqli_result
	? mysqli_fetch_assoc($session_auth_result)
	: null;

if ($has_admin_session && is_array($session_auth_row) && ($session_auth_row['qc_enabled'] ?? '0') === '1') {
	$auth=1;
} else {
	$auth_message = user_authorization($PHP_AUTH_USER,$PHP_AUTH_PW,'',1,0);
	if ($auth_message == 'GOOD') {
		$auth=1;
	}
}

if ($auth < 1)
	{
	$VDdisplayMESSAGE = _QXZ("Login incorrect, please try again");
	if ($auth_message == 'LOCK')
		{
		$VDdisplayMESSAGE = _QXZ("Too many login attempts, try again in 15 minutes");
		Header ("Content-type: text/html; charset=utf-8");
		echo "$VDdisplayMESSAGE: |$PHP_AUTH_USER|$auth_message|\n";
		exit;
		}
	if ($auth_message == 'IPBLOCK')
		{
		$VDdisplayMESSAGE = _QXZ("Your IP Address is not allowed") . ": $ip";
		Header ("Content-type: text/html; charset=utf-8");
		echo "$VDdisplayMESSAGE: |$PHP_AUTH_USER|$auth_message|\n";
		exit;
		}
	// The custom admin application uses PHP sessions. Never issue a Basic Auth
	// challenge, because browsers display it as a separate sign-in dialog.
	Header("HTTP/1.1 403 Forbidden");
	echo "$VDdisplayMESSAGE: |$PHP_AUTH_USER|$auth_message|\n";
	exit;
	}	

# submit new scorecard
if ($submit==_QXZ("SUBMIT NEW SCORECARD")) 
	{
	$ins_stmt="insert into quality_control_scorecards(qc_scorecard_id, scorecard_name, active) VALUES('".mysqli_escape_string($link, $new_scorecard_id)."', '".mysqli_escape_string($link, $new_scorecard_name)."', '$new_active')";
	$ins_rslt=mysql_to_mysqli($ins_stmt, $link);
	}

if ($auth) 
	{
	$office_no=strtoupper($PHP_AUTH_USER);
	$password=strtoupper($PHP_AUTH_PW);
	$auth_stmt="SELECT user_id,user,pass,full_name,user_level,user_group,phone_login,phone_pass,delete_users,delete_user_groups,delete_lists,delete_campaigns,delete_ingroups,delete_remote_agents,load_leads,campaign_detail,ast_admin_access,ast_delete_phones,delete_scripts,modify_leads,hotkeys_active,change_agent_campaign,agent_choose_ingroups,closer_campaigns,scheduled_callbacks,agentonly_callbacks,agentcall_manual,vicidial_recording,vicidial_transfers,delete_filters,alter_agent_interface_options,closer_default_blended,delete_call_times,modify_call_times,modify_users,modify_campaigns,modify_lists,modify_scripts,modify_filters,modify_ingroups,modify_usergroups,modify_remoteagents,modify_servers,view_reports,vicidial_recording_override,alter_custdata_override,qc_enabled,qc_user_level,qc_pass,qc_finish,qc_commit,add_timeclock_log,modify_timeclock_log,delete_timeclock_log,alter_custphone_override,vdc_agent_api_access,modify_inbound_dids,delete_inbound_dids,active,alert_enabled,download_lists,agent_shift_enforcement_override,manager_shift_enforcement_override,shift_override_flag,export_reports,delete_from_dnc,email,user_code,territory,allow_alerts,callcard_admin,force_change_password,modify_shifts,modify_phones,modify_carriers,modify_labels,modify_statuses,modify_voicemail,modify_audiostore,modify_moh,modify_tts,modify_contacts,modify_same_user_level from vicidial_users where user='$PHP_AUTH_USER';";
	$rslt=mysqli_query($link, $auth_stmt);
	$row=mysqli_fetch_row($rslt);
	$LOGfull_name				=$row[3];
	$LOGuser_level				=$row[4];
	$LOGuser_group				=$row[5];
	$LOGdelete_users			=$row[8];
	$LOGdelete_user_groups		=$row[9];
	$LOGdelete_lists			=$row[10];
	$LOGdelete_campaigns		=$row[11];
	$LOGdelete_ingroups			=$row[12];
	$LOGdelete_remote_agents	=$row[13];
	$LOGload_leads				=$row[14];
	$LOGcampaign_detail			=$row[15];
	$LOGast_admin_access		=$row[16];
	$LOGast_delete_phones		=$row[17];
	$LOGdelete_scripts			=$row[18];
	$LOGdelete_filters			=$row[29];
	$LOGalter_agent_interface	=$row[30];
	$LOGdelete_call_times		=$row[32];
	$LOGmodify_call_times		=$row[33];
	$LOGmodify_users			=$row[34];
	$LOGmodify_campaigns		=$row[35];
	$LOGmodify_lists			=$row[36];
	$LOGmodify_scripts			=$row[37];
	$LOGmodify_filters			=$row[38];
	$LOGmodify_ingroups			=$row[39];
	$LOGmodify_usergroups		=$row[40];
	$LOGmodify_remoteagents		=$row[41];
	$LOGmodify_servers			=$row[42];
	$LOGview_reports			=$row[43];
	$qc_auth					=$row[46];
	$LOGmodify_dids				=$row[56];
	$LOGdelete_dids				=$row[57];
	$LOGmanager_shift_enforcement_override=$row[61];
	$LOGexport_reports			=$row[64];
	$LOGdelete_from_dnc			=$row[65];
	$LOGcallcard_admin			=$row[70];
	$LOGforce_change_password	=$row[71];
	$LOGmodify_shifts			=$row[72];
	$LOGmodify_phones			=$row[73];
	$LOGmodify_carriers			=$row[74];
	$LOGmodify_labels			=$row[75];
	$LOGmodify_statuses			=$row[76];
	$LOGmodify_voicemail		=$row[77];
	$LOGmodify_audiostore		=$row[78];
	$LOGmodify_moh				=$row[79];
	$LOGmodify_tts				=$row[80];
	$LOGmodify_contacts			=$row[81];
	$LOGmodify_same_user_level	=$row[82];

	$stmt="SELECT allowed_campaigns,allowed_reports,admin_viewable_groups,admin_viewable_call_times from vicidial_user_groups where user_group='$LOGuser_group';";
	$rslt=mysqli_query($link, $stmt);
	$row=mysqli_fetch_row($rslt);
	$LOGallowed_campaigns =			$row[0];
	$LOGallowed_reports =			$row[1];
	$LOGadmin_viewable_groups =		$row[2];
	$LOGadmin_viewable_call_times =	$row[3];
	}

$stmt="SELECT allowed_campaigns,allowed_reports from vicidial_user_groups where user_group='$LOGuser_group';";
if ($DB) {echo "|$stmt|\n";}
$rslt=mysqli_query($link, $stmt);
$row=mysqli_fetch_row($rslt);
$LOGallowed_campaigns = $row[0];
$LOGallowed_reports =	$row[1];

$LOGallowed_campaignsSQL='';
$whereLOGallowed_campaignsSQL='';
if ( (!preg_match("/ALL-/",$LOGallowed_campaigns)) )
	{
	$rawLOGallowed_campaignsSQL = preg_replace("/ -/",'',$LOGallowed_campaigns);
	$rawLOGallowed_campaignsSQL = preg_replace("/ /","','",$rawLOGallowed_campaignsSQL);
	$LOGallowed_campaignsSQL = "and campaign_id IN('$rawLOGallowed_campaignsSQL')";
	$whereLOGallowed_campaignsSQL = "where campaign_id IN('$rawLOGallowed_campaignsSQL')";
	}
$regexLOGallowed_campaigns = " $LOGallowed_campaigns ";

header ("Content-type: text/html; charset=utf-8");
header ("Cache-Control: no-cache, must-revalidate");  // HTTP/1.1
header ("Pragma: no-cache");      // HTTP/1.0

echo "<html>\n";
echo "<head>\n";
echo "<link rel=\"stylesheet\" type=\"text/css\" href=\"vicidial_stylesheet.php\">\n";
echo "<script language=\"JavaScript\" src=\"help.js\"></script>\n";
echo "<div id='HelpDisplayDiv' class='help_info' style='display:none;'></div>";	
echo "<title>"._QXZ("Quality control scorecards")."</title>\n";
echo "</head>\n";

$NWB = "<IMG SRC=\"help.png\" onClick=\"FillAndShowHelpDiv(event, '";
$NWE = "')\" WIDTH=20 HEIGHT=20 BORDER=0 ALT=\"HELP\" ALIGN=TOP>";


##### BEGIN Set variables to make header show properly #####
$ADD =  '999998';
$hh =       'qc';
$qc_display_group_type =		'SCORECARD';
$LOGast_admin_access = '1';
$SSoutbound_autodial_active = '1';
$ADMIN =				'admin.php';
$page_width='770';
$section_width='750';
$header_font_size='3';
$subheader_font_size='2';
$subcamp_font_size='2';
$header_selected_bold='<b>';
$header_nonselected_bold='';
$admin_color =    '#FFFF99';
$admin_font =      'BLACK';
$admin_color =    '#E6E6E6';
$subcamp_color =	'#C6C6C6';
##### END Set variables to make header show properly #####


require("admin_header.php");

echo "<!-- QC: $SSqc_features_active $qc_auth -->";

?>
<style type="text/css">
<!--
   .green {color: white; background-color: green}
   .red {color: white; background-color: red}
   .blue {color: white; background-color: blue}
   .purple {color: white; background-color: purple}

td.small_grey {
 	FONT-SIZE: 10pt;
    background: #FFFFCC;
    border-bottom:1px dotted #000000;
}
th.display_header {
    background: #000000;
	color:#FFFFFF;
    font-family: Arial, Sans-Serif;
	FONT-SIZE: 10pt;
	FONT-WEIGHT: bold;
    border-spacing: 0px;
	padding: 2px;
	border-collapse: separate;
}
th.small_display_header {
    background: #000000;
	color:#FFFFFF;
    font-family: Arial, Sans-Serif;
	FONT-SIZE: 8pt;
	FONT-WEIGHT: bold;
    border-spacing: 0px;
	padding: 2px;
	border-collapse: separate;
}
th.display_white_header {
    background: #FFFFFF;
	color:#000000;
    font-family: Arial, Sans-Serif;
	FONT-SIZE: 10pt;
	FONT-WEIGHT: bold;
    border-spacing: 0px;
	padding: 2px;
	border-collapse: separate;
}

input.red_btn{
   color:#FFFFFF;
   font-size:12px;
   font-weight:bold;
   background-color:#993333;
   border:2px solid;
   border-top-color:#FFCCCC;
   border-left-color:#FFCCCC;
   border-right-color:#660000;
   border-bottom-color:#660000;
   filter:progid:DXImageTransform.Microsoft.Gradient
      (GradientType=0,StartColorStr='#00ffffff',EndColorStr='#ff660000');}
input.blue_btn{
   color:#FFFFFF;
   font-size:12px;
   font-weight:bold;
   background-color:#3333FF;
   border:2px solid;
   border-top-color:#CCCCFF;
   border-left-color:#CCCCFF;
   border-right-color:#000066;
   border-bottom-color:#000066;
   filter:progid:DXImageTransform.Microsoft.Gradient
      (GradientType=0,StartColorStr='#00ffffff',EndColorStr='#ff000066');}
input.yellow_btn{
   color:#000000;
   font-size:12px;
   font-weight:bold;
   background-color:#FFFF00;
   border:2px solid;
   border-top-color:#FFFFCC;
   border-left-color:#FFFFCC;
   border-right-color:#333300;
   border-bottom-color:#333300;
   filter:progid:DXImageTransform.Microsoft.Gradient
      (GradientType=0,StartColorStr='#00ffffff',EndColorStr='#ffFFFF00');}
input.green_btn{
   color:#FFFFFF;
   font-size:12px;
   font-weight:bold;
   background-color:#009900;
   border:2px solid;
   border-top-color:#CCFFCC;
   border-left-color:#CCFFCC;
   border-right-color:#003300;
   border-bottom-color:#003300;
   filter:progid:DXImageTransform.Microsoft.Gradient
      (GradientType=0,StartColorStr='#00ffffff',EndColorStr='#FF003300');}
input.tiny_red_btn{
   color:#FFFFFF;
   font-size:8px;
   font-weight:bold;
   background-color:#993333;
   border:2px solid;
   border-top-color:#FFCCCC;
   border-left-color:#FFCCCC;
   border-right-color:#660000;
   border-bottom-color:#660000;
   filter:progid:DXImageTransform.Microsoft.Gradient
      (GradientType=0,StartColorStr='#00ffffff',EndColorStr='#ff660000');}
input.tiny_blue_btn{
   color:#FFFFFF;
   font-size:8px;
   font-weight:bold;
   background-color:#3333FF;
   border:2px solid;
   border-top-color:#CCCCFF;
   border-left-color:#CCCCFF;
   border-right-color:#000066;
   border-bottom-color:#000066;
   filter:progid:DXImageTransform.Microsoft.Gradient
      (GradientType=0,StartColorStr='#00ffffff',EndColorStr='#ff000066');}
input.tiny_yellow_btn{
   color:#000000;
   font-size:8px;
   font-weight:bold;
   background-color:#FFFF00;
   border:2px solid;
   border-top-color:#FFFFCC;
   border-left-color:#FFFFCC;
   border-right-color:#333300;
   border-bottom-color:#333300;
   filter:progid:DXImageTransform.Microsoft.Gradient
      (GradientType=0,StartColorStr='#00ffffff',EndColorStr='#ffFFFF00');}
input.tiny_green_btn{
   color:#FFFFFF;
   font-size:8px;
   font-weight:bold;
   background-color:#009900;
   border:2px solid;
   border-top-color:#CCFFCC;
   border-left-color:#CCFFCC;
   border-right-color:#003300;
   border-bottom-color:#003300;
   filter:progid:DXImageTransform.Microsoft.Gradient
      (GradientType=0,StartColorStr='#00ffffff',EndColorStr='#FF003300');}
.form_field {
    font-family: Arial, Sans-Serif;
    font-size: 12px;
    margin-bottom: 3px;
 
    padding: 2px;
    border: solid 1px #000066;
    background-image: url( 'images/blue_bg.jpg' );
    background-repeat: repeat-x;
    background-position: top;
}
textarea.notes_box {
    font-family: Arial, Sans-Serif;
    font-size: 10px;
    margin-bottom: 3px;
    padding: 2px;
    border: solid 1px #000066;
}

/* Klozer dashboard theme */
.qc-scorecards-page { width: 100%; color: #eaf7f4; font-family: 'Poppins', Arial, sans-serif; }
.qc-scorecards-header { display:flex; align-items:center; gap:14px; margin:0 0 22px; }
.qc-scorecards-header-icon { width:48px; height:48px; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; background:#002b3a; border:1px solid #00b389; }
.qc-scorecards-header h2 { margin:0; color:#00ffc5; font-size:20px; font-weight:700; }
.qc-delete-confirm { display:inline-block; margin-bottom:20px; color:#eaf7f4 !important; font-weight:600; }
.qc-scorecards-page, #scorecards_display, #checkpoint_display { box-sizing:border-box; width:100%; max-width:100%; }
.qc-scorecards-card { box-sizing:border-box; width:100%; max-width:100%; overflow:hidden; background:#002130; border:1px solid #063747; border-radius:14px; box-shadow:0 8px 24px rgba(0,0,0,.16); }
.qc-scorecards-table { width:100%; max-width:100%; border-collapse:collapse; table-layout:fixed; }
.qc-scorecards-table thead td { padding:15px 14px; color:#00ffc5 !important; background:#002b3a !important; border-bottom:1px solid rgba(0,255,197,.22); font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
.qc-scorecards-table tbody td { padding:14px; color:#eaf7f4 !important; border-bottom:1px solid rgba(0,255,197,.13); font-size:13px; font-weight:500; }
.qc-scorecards-table tbody tr:nth-child(odd) td { background:#052b38 !important; }
.qc-scorecards-table tbody tr:nth-child(even) td { background:#073442 !important; }
.qc-scorecards-table tbody tr:hover td { background:#0a4050 !important; }
.qc-scorecards-table font, #checkpoint_display font { color:inherit !important; font-size:inherit !important; }
#scorecards_display > table { width:100%; border-collapse:collapse; background:#002130; }
#scorecards_display > table tr:first-child td { padding:15px 14px; color:#00ffc5 !important; background:#002b3a !important; font-size:12px; font-weight:700; text-transform:uppercase; }
#scorecards_display > table tr:not(:first-child) td { padding:14px; color:#eaf7f4 !important; border-bottom:1px solid rgba(0,255,197,.13); background:#052b38 !important; }
#scorecards_display > table font { color:inherit !important; font-size:inherit !important; }
.qc-scorecards-actions { display:flex; justify-content:flex-end; padding:16px; background:#002b3a; }
.qc-scorecards-page input.tiny_blue_btn, #checkpoint_display input.tiny_blue_btn, #checkpoint_display input.blue_btn, #checkpoint_display input.green_btn { min-height:38px; padding:8px 16px; color:#001713; background:#00ffc5; border:0; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; filter:none; }
.qc-scorecards-page input.tiny_red_btn, #checkpoint_display input.tiny_red_btn, #checkpoint_display input.red_btn { min-height:38px; padding:8px 16px; color:#fff; background:#b8404b; border:0; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; filter:none; }
html body #checkpoint_display input.green_btn,
html body #checkpoint_display input.green,
html body #checkpoint_display input[value='SUBMIT NEW SCORECARD'],
html body input[value='ADD'] {
	min-height: 42px !important;
	padding: 9px 20px !important;
	color: #001713 !important;
	background: #00ffc5 !important;
	border: 1px solid #00ffc5 !important;
	border-radius: 7px !important;
	box-shadow: 0 5px 16px rgba(0, 255, 197, .16) !important;
	font-family: 'Poppins', Arial, sans-serif !important;
	font-size: 14px !important;
	font-weight: 700 !important;
	line-height: 1.2 !important;
	cursor: pointer;
	filter: none !important;
	appearance: none;
}
html body #checkpoint_display input.green_btn:hover,
html body #checkpoint_display input.green:hover,
html body #checkpoint_display input[value='SUBMIT NEW SCORECARD']:hover,
html body input[value='ADD']:hover {
	background: #42f3d1 !important;
	border-color: #42f3d1 !important;
}
.qc-scorecards-page input[type='checkbox'], #checkpoint_display input[type='checkbox'] { width:18px; height:18px; accent-color:#00ffc5; }
#checkpoint_display { display:block; width:100%; max-width:100%; margin-top:26px; color:#eaf7f4; overflow-x:auto; }
#checkpoint_display > center, #checkpoint_display > form, #checkpoint_display > div { box-sizing:border-box; width:100%; max-width:100%; }
#checkpoint_display table { box-sizing:border-box; width:100% !important; max-width:100% !important; color:#eaf7f4; background:#002130; border:1px solid #063747; border-radius:14px; border-collapse:separate; border-spacing:0; table-layout:fixed; overflow:hidden; }
#checkpoint_display td, #checkpoint_display th { box-sizing:border-box; min-width:0; padding:12px 14px; color:#eaf7f4 !important; border:0; border-bottom:1px solid rgba(0,255,197,.13); background:#052b38 !important; font-family:'Poppins', Arial, sans-serif; overflow-wrap:anywhere; }
#checkpoint_display th, #checkpoint_display tr[bgcolor='black'] td { color:#00ffc5 !important; background:#002b3a !important; font-weight:700; }
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) { table-layout:auto !important; }
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(1) { width:4% !important; }
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(2) { width:5% !important; }
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(3) { width:27% !important; }
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(4) { width:25% !important; }
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(5) { width:5% !important; }
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(6) { width:6% !important; }
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(7) { width:20% !important; }
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(8) {
	width:8% !important;
	min-width:110px !important;
	white-space:nowrap !important;
}
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(3) textarea,
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(4) textarea,
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(7) textarea {
	width:100% !important;
	min-width:0 !important;
}
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(1) select,
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(5) input {
	width:64px !important;
	max-width:100% !important;
	min-width:0 !important;
}
#checkpoint_display input[type='text'], #checkpoint_display input[type='number'], #checkpoint_display select, #checkpoint_display textarea { box-sizing:border-box; max-width:100% !important; min-height:40px; padding:8px 11px; color:#fff; background:#001923; border:1px solid #087c6a; border-radius:7px; font-family:inherit; outline:none; }
#checkpoint_display input[type='button'], #checkpoint_display input[type='submit'], #checkpoint_display button { box-sizing:border-box; max-width:100%; white-space:normal; }
#checkpoint_display input.red_btn,
#checkpoint_display input.tiny_red_btn,
#checkpoint_display input[value='DELETE'] {
	width:auto !important;
	min-width:96px !important;
	max-width:100% !important;
	padding:9px 14px !important;
	font-size:13px !important;
	line-height:1.2 !important;
	white-space:nowrap !important;
	word-break:normal !important;
	overflow-wrap:normal !important;
}
#checkpoint_display input.green_btn[value='ADD'],
#checkpoint_display input.green[value='ADD'],
#checkpoint_display input[value='ADD'],
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(8) button,
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(8) a {
	width:auto !important;
	min-width:82px !important;
	max-width:100% !important;
	min-height:42px !important;
	padding:9px 16px !important;
	font-size:13px !important;
	line-height:1.2 !important;
	white-space:nowrap !important;
	word-break:normal !important;
	overflow-wrap:normal !important;
}
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(8) button,
#checkpoint_display table:has(> tbody > tr > :nth-child(8)) > tbody > tr > :nth-child(8) a {
	display:inline-flex !important;
	align-items:center !important;
	justify-content:center !important;
}
#checkpoint_display input:focus, #checkpoint_display select:focus, #checkpoint_display textarea:focus { border-color:#00ffc5; box-shadow:0 0 0 2px rgba(0,255,197,.12); }
.qc-light-theme .qc-scorecards-card, .qc-light-theme #checkpoint_display table { color:#15252b; background:#f1f3f3; border-color:#d7e0e1; box-shadow:0 10px 28px rgba(0,33,48,.10); }
.qc-light-theme .qc-scorecards-page { color:#15252b; }
.qc-light-theme .qc-scorecards-header-icon { background:#e5ebeb; }
.qc-light-theme .qc-scorecards-header h2 { color:#15252b; }
.qc-light-theme .qc-delete-confirm { color:#15252b !important; }
.qc-light-theme .qc-scorecards-table thead td { color:#007d69 !important; background:#e5ebeb !important; border-bottom-color:#9cc8c1; }
.qc-light-theme .qc-scorecards-table tbody td { color:#15252b !important; border-bottom-color:#cedddd; }
.qc-light-theme .qc-scorecards-table tbody tr:nth-child(odd) td { background:#f8f9f9 !important; }
.qc-light-theme .qc-scorecards-table tbody tr:nth-child(even) td { background:#eef2f2 !important; }
.qc-light-theme .qc-scorecards-table tbody tr:hover td { background:#e1efec !important; }
.qc-light-theme .qc-scorecards-actions { background:#e5ebeb; }
.qc-light-theme #scorecards_display > table { background:#f1f3f3; }
.qc-light-theme #scorecards_display > table tr:first-child td { color:#007d69 !important; background:#e5ebeb !important; }
.qc-light-theme #scorecards_display > table tr:not(:first-child) td { color:#15252b !important; background:#f8f9f9 !important; border-bottom-color:#cedddd; }
.qc-light-theme #checkpoint_display { color:#15252b; }
.qc-light-theme #checkpoint_display td { color:#15252b !important; background:#f8f9f9 !important; border-bottom-color:#cedddd; }
.qc-light-theme #checkpoint_display th, .qc-light-theme #checkpoint_display tr[bgcolor='black'] td { color:#007d69 !important; background:#e5ebeb !important; }
.qc-light-theme #checkpoint_display input[type='text'], .qc-light-theme #checkpoint_display input[type='number'], .qc-light-theme #checkpoint_display select, .qc-light-theme #checkpoint_display textarea { color:#15252b; background:#fff; border-color:#70b8ab; }
.qc-light-theme body #checkpoint_display input.green_btn,
.qc-light-theme body #checkpoint_display input.green,
.qc-light-theme body #checkpoint_display input[value='SUBMIT NEW SCORECARD'],
.qc-light-theme body input[value='ADD'] {
	color: #ffffff !important;
	background: #007d69 !important;
	border-color: #007d69 !important;
	box-shadow: 0 5px 16px rgba(0, 125, 105, .18) !important;
}
.qc-light-theme body #checkpoint_display input.green_btn:hover,
.qc-light-theme body #checkpoint_display input.green:hover,
.qc-light-theme body #checkpoint_display input[value='SUBMIT NEW SCORECARD']:hover,
.qc-light-theme body input[value='ADD']:hover {
	background: #006653 !important;
	border-color: #006653 !important;
}
@media (max-width:1050px) { .qc-scorecards-card, #checkpoint_display { overflow-x:auto; } .qc-scorecards-table, #checkpoint_display table { min-width:900px; } }
-->
</style>
<script>
(function () {
	if (window.refreshQcTheme) { window.refreshQcTheme(); return; }
	window.refreshQcTheme = function () {
		var target = document.querySelector('.inner_main_right') || document.body;
		var color = window.getComputedStyle(target).backgroundColor;
		var values = color.match(/[\d.]+/g);
		if ((!values || values.length < 3 || (values.length > 3 && Number(values[3]) === 0)) && target !== document.body) {
			values = window.getComputedStyle(document.body).backgroundColor.match(/[\d.]+/g);
		}
		var light = values && values.length >= 3 && ((values[0] * 299 + values[1] * 587 + values[2] * 114) / 1000 > 170);
		document.documentElement.classList.toggle('qc-light-theme', !!light);
		localStorage.setItem('qc-theme', light ? 'light' : 'dark');
	};
	document.addEventListener('DOMContentLoaded', window.refreshQcTheme);
	new MutationObserver(function () { requestAnimationFrame(window.refreshQcTheme); }).observe(document.documentElement, { attributes:true, subtree:true, attributeFilter:['class', 'style', 'data-theme'] });
})();
</script>
<script language="JavaScript">
function NewScorecard() {
	var xmlhttp=false;
	try {
		xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
	} catch (e) {
		try {
			xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
		} catch (E) {
			xmlhttp = false;
		}
	}
	if (!xmlhttp && typeof XMLHttpRequest!='undefined') {
		xmlhttp = new XMLHttpRequest();
	}
	if (xmlhttp) { 
		var display_query = "&qc_action=new_scorecard";
		xmlhttp.open('POST', 'qc_session_actions.php'); 
		xmlhttp.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
		xmlhttp.send(display_query); 
		xmlhttp.onreadystatechange = function() { 
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
				var QCCheckpoints = xmlhttp.responseText;
				document.getElementById("checkpoint_display").innerHTML = QCCheckpoints;
			}
		}
		delete xmlhttp;
	}
}
function LoadCheckpoints(scorecard_id) {
	var xmlhttp=false;
	try {
		xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
	} catch (e) {
		try {
			xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
		} catch (E) {
			xmlhttp = false;
		}
	}
	if (!xmlhttp && typeof XMLHttpRequest!='undefined') {
		xmlhttp = new XMLHttpRequest();
	}
	if (xmlhttp) { 
		var display_query = "&qc_action=load_checkpoints&scorecard_id="+scorecard_id;
		xmlhttp.open('POST', 'qc_session_actions.php'); 
		xmlhttp.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
		xmlhttp.send(display_query); 
		xmlhttp.onreadystatechange = function() { 
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
				var QCCheckpoints = xmlhttp.responseText;
				document.getElementById("checkpoint_display").innerHTML = QCCheckpoints;
				ReloadScorecardDisplay();
			}
		}
		delete xmlhttp;
	}
}
function ReloadScorecardDisplay() {
	var xmlhttp=false;
	try {
		xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
	} catch (e) {
		try {
			xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
		} catch (E) {
			xmlhttp = false;
		}
	}
	if (!xmlhttp && typeof XMLHttpRequest!='undefined') {
		xmlhttp = new XMLHttpRequest();
	}
	if (xmlhttp) { 
		var display_query = "&qc_action=reload_scorecard_display";
		xmlhttp.open('POST', 'qc_session_actions.php'); 
		xmlhttp.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
		xmlhttp.send(display_query); 
		xmlhttp.onreadystatechange = function() { 
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
				var QCDisplay = xmlhttp.responseText.replace(
					/qc_session_actions\.php\?scorecard_id=/g,
					'qc_scorecards.php?scorecard_id='
				);
				document.getElementById("scorecards_display").innerHTML = QCDisplay;
			}
		}
		delete xmlhttp;
	}
}
function AddCheckpoint(scorecard_id) {
	var qrank = document.getElementById("new_checkpoint_rank");
	var checkpoint_rank = qrank.options[qrank.selectedIndex].value;	

	var qactive = document.getElementById("new_active");
	if (qactive.checked) {var active="Y";} else {var active="N";}

	var qinstfail=document.getElementById("new_instant_fail");
	if (qinstfail.checked) {var instant_fail="Y";} else {var instant_fail="N";}

	// var qcomm=document.getElementById("new_commission_loss");
	// if (qcomm.checked) {var comm_loss="Y";} else {var comm_loss="N";}
	var comm_loss="N";

	var qtext = document.getElementById("new_checkpoint_text");
	var checkpoint_text=encodeURIComponent(qtext.value);

	var qtext_presets = document.getElementById("new_checkpoint_text_presets");
	var checkpoint_text_presets=encodeURIComponent(qtext_presets.value);

	var qpts = document.getElementById("new_checkpoint_points");
	var checkpoint_points=encodeURIComponent(qpts.value);

	var qadmin = document.getElementById("new_admin_notes");
	var admin_notes=encodeURIComponent(qadmin.value);

	var add_query = "&qc_action=add_checkpoint&scorecard_id="+scorecard_id+"&new_checkpoint_rank="+checkpoint_rank+"&new_active="+active+"&new_checkpoint_text="+checkpoint_text+"&new_checkpoint_text_presets="+checkpoint_text_presets+"&new_admin_notes="+admin_notes+"&new_checkpoint_points="+checkpoint_points+"&new_instant_fail="+instant_fail+"&new_commission_loss="+comm_loss;

	var xmlhttp=false;
	try {
		xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
	} catch (e) {
		try {
			xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
		} catch (E) {
			xmlhttp = false;
		}
	}
	if (!xmlhttp && typeof XMLHttpRequest!='undefined') {
		xmlhttp = new XMLHttpRequest();
	}
	if (xmlhttp) { 
		xmlhttp.open('POST', 'qc_session_actions.php'); 
		xmlhttp.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
		xmlhttp.send(add_query); 
		xmlhttp.onreadystatechange = function() { 
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
				var QCCheckpoints = xmlhttp.responseText;
				LoadCheckpoints(scorecard_id);
			}
		}
		delete xmlhttp;
	}

}
function ChangeCheckpoint(checkpoint_row_id, scorecard_id, q_action, parameter, old_checkpoint_rank, new_checkpoint_rank) {
	if (q_action=="delete_checkpoint") 
		{
		var elementID="checkpoint_rank"+checkpoint_row_id;
		var e = document.getElementById(elementID);
		var checkpoint_rank = e.options[e.selectedIndex].value;
		var update_query = "&qc_action="+q_action+"&scorecard_id="+scorecard_id+"&checkpoint_row_id="+checkpoint_row_id+"&checkpoint_rank="+checkpoint_rank;
		}
	else if (q_action=="update_scorecard")
		{
		var elementID=parameter+scorecard_id;
		var e = document.getElementById(elementID);
		var parameter_value="";
		if (parameter=="active") 
			{
			if (e.checked) {parameter_value="Y";} else {parameter_value="N";}
			}
		else
			{
			parameter_value=encodeURIComponent(e.value);
			}
		var update_query = "&qc_action="+q_action+"&scorecard_id="+scorecard_id+"&parameter="+parameter+"&parameter_value="+parameter_value;
		}
	else 
		{
		var elementID=parameter+checkpoint_row_id;
		var e = document.getElementById(elementID);
		var parameter_value="";

		if (parameter=="active" || parameter=="instant_fail" || parameter=="commission_loss") 
			{
			if (e.checked) {parameter_value="Y";} else {parameter_value="N";}
			} 
		else if (parameter=="checkpoint_text" || parameter=="checkpoint_text_presets" || parameter=="checkpoint_points" || parameter=="admin_notes") 
			{
			parameter_value=encodeURIComponent(e.value);
			}

		var update_query = "&qc_action="+q_action+"&scorecard_id="+scorecard_id+"&checkpoint_row_id="+checkpoint_row_id+"&parameter="+parameter+"&parameter_value="+parameter_value+"&old_checkpoint_rank="+old_checkpoint_rank+"&new_checkpoint_rank="+new_checkpoint_rank;
		}


	var xmlhttp=false;
	try {
		xmlhttp = new ActiveXObject("Msxml2.XMLHTTP");
	} catch (e) {
		try {
			xmlhttp = new ActiveXObject("Microsoft.XMLHTTP");
		} catch (E) {
			xmlhttp = false;
		}
	}
	if (!xmlhttp && typeof XMLHttpRequest!='undefined') {
		xmlhttp = new XMLHttpRequest();
	}
	if (xmlhttp) { 
		xmlhttp.open('POST', 'qc_session_actions.php'); 
		xmlhttp.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
		xmlhttp.send(update_query); 
		xmlhttp.onreadystatechange = function() { 
			if (xmlhttp.readyState == 4 && xmlhttp.status == 200) {
				var QCCheckpoints = xmlhttp.responseText;
				if (q_action!="update_scorecard") {LoadCheckpoints(scorecard_id);} else {ReloadScorecardDisplay();}
			}
		}
		delete xmlhttp;
	}
}
</script>
<?php
    echo "<div class='qc-scorecards-page'>\n";
	echo "<div class='qc-scorecards-header'><span class='qc-scorecards-header-icon'><svg width='25' height='25' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'><path d='M12 2L20 5V11C20 16.05 16.59 20.74 12 22C7.41 20.74 4 16.05 4 11V5L12 2Z' stroke='#00ffc5' stroke-width='1.7'/><path d='M8.5 12L10.75 14.25L15.75 9.25' stroke='#00ffc5' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/></svg></span><h2>"._QXZ("Quality Control Scorecards")."</h2></div>\n";

	if ($action=="delete_scorecard" && $scorecard_id && !$confirm_deletion) 
		{
		echo "<a class='qc-delete-confirm' href='qc_scorecards.php?scorecard_id=$scorecard_id&action=delete_scorecard&confirm_deletion=Y'>"._QXZ("CLICK HERE TO CONFIRM SCORECARD")." \"$scorecard_id\" "._QXZ("DELETION")."</a><BR><BR>";
		}
	else if ($action=="delete_scorecard" && $scorecard_id && $confirm_deletion=="Y") 
		{
		$del_stmt="delete from quality_control_scorecards where qc_scorecard_id='$scorecard_id'";
		$del_rslt=mysql_to_mysqli($del_stmt, $link);
		if(mysqli_affected_rows($link)>0)
			{
			echo "<B>"._QXZ("SCORECARD")." $scorecard_id "._QXZ("DELETED")."</B><BR><BR>";
			}


		$del_stmt="delete from quality_control_checkpoints where qc_scorecard_id='$scorecard_id'";
		$del_rslt=mysql_to_mysqli($del_stmt, $link);

		}
	echo "<span id='scorecards_display'><div class='qc-scorecards-card'>\n";
	echo "<table class='qc-scorecards-table'>\n";
	echo "\t<thead><tr nowrap>\n";
	echo "\t\t<td align=left><font size=1 color=white>"._QXZ("Scorecard")."</font></td>\n";
	echo "\t\t<td align=left><font size=1 color='white'>"._QXZ("Checkpoints")."</font></td>\n";
	echo "\t\t<td align=left><font size=1 color='white'>"._QXZ("Pass/max score")."</font></td>\n";
	echo "\t\t<td align=left><font size=1 color='white'>"._QXZ("Campaigns")."</font></td>\n";
	echo "\t\t<td align=left><font size=1 color='white'>"._QXZ("In-groups")."</font></td>\n";
	echo "\t\t<td align=left><font size=1 color='white'>"._QXZ("Lists")."</font></td>\n";
	echo "\t\t<td align=left><font size=1 color='white'>"._QXZ("Last modified")."</font></td>\n";
	echo "\t\t<td align=left><font size=1 color='white'>"._QXZ("Active")."</font></td>\n";
	echo "\t\t<td align=left><font size=1 color='white'>&nbsp;</font></td>\n";
	echo "\t\t<td align=left><font size=1 color='white'>&nbsp;</font></td>\n";
	echo "\t</tr></thead><tbody>\n";
	# <select name='scorecard_id' onChange='LoadCheckpoints(this.value)'>
	$stmt="select * from quality_control_scorecards order by qc_scorecard_id asc";
	$rslt=mysql_to_mysqli($stmt, $link);
	$i=0;
	while ($row=mysqli_fetch_array($rslt)) {
		$i++;
		if ($i%2==0) {$bgcolor=$SSstd_row1_background;} else {$bgcolor=$SSstd_row2_background;}
		echo "\t<tr bgcolor='".$bgcolor."'>\n";
		echo "\t\t\t<td align=left><font size=1>$row[qc_scorecard_id] - $row[scorecard_name]</font></td>\n";

		$qstmt="select count(*), sum(checkpoint_points) from quality_control_checkpoints where qc_scorecard_id='$row[qc_scorecard_id]'";
		$qrslt=mysql_to_mysqli($qstmt, $link);
		$qrow=mysqli_fetch_row($qrslt);
		$checkpoints=$qrow[0];
		($checkpoints==0 ? $score_total="--" : $score_total=$qrow[1]);
		echo "\t\t\t<td align=left><font size=1>$checkpoints</font></td>\n";
		echo "\t\t\t<td align=left><font size=1>$row[passing_score] / $score_total</font></td>\n";


		$qstmt="select count(*) from vicidial_campaigns where qc_scorecard_id='$row[qc_scorecard_id]'";
		$qrslt=mysql_to_mysqli($qstmt, $link);
		$qrow=mysqli_fetch_row($qrslt);
		$campaigns_in_use=$qrow[0];

		echo "\t\t\t<td align=left><font size=1>$campaigns_in_use</font></td>\n";


		$qstmt="select count(*) from vicidial_inbound_groups where qc_scorecard_id='$row[qc_scorecard_id]'";
		$qrslt=mysql_to_mysqli($qstmt, $link);
		$qrow=mysqli_fetch_row($qrslt);
		$ingroups_in_use=$qrow[0];

		echo "\t\t\t<td align=left><font size=1>$ingroups_in_use</font></td>\n";


		$qstmt="select count(*) from vicidial_lists where qc_scorecard_id='$row[qc_scorecard_id]'";
		$qrslt=mysql_to_mysqli($qstmt, $link);
		$qrow=mysqli_fetch_row($qrslt);
		$lists_in_use=$qrow[0];

		echo "\t\t\t<td align=left><font size=1>$lists_in_use</font></td>\n";
		echo "\t\t\t<td align=left><font size=1>$row[last_modified]</font></td>\n";
		echo "\t\t\t<td align=left><input type='checkbox' name='active".$row["qc_scorecard_id"]."' id='active".$row["qc_scorecard_id"]."' onClick=\"ChangeCheckpoint('0', '$row[qc_scorecard_id]', 'update_scorecard', 'active')\"".($row["active"]=="Y" ? " checked" : "")."></td>\n";

		echo "\t\t\t<td align=left><input type='button' class='tiny_blue_btn' onClick=\"LoadCheckpoints('$row[qc_scorecard_id]')\" value='"._QXZ("MODIFY")."'></td>\n";
		echo "\t\t\t<td align=left><input type='button' class='tiny_red_btn' onClick=\"window.location.href='qc_scorecards.php?scorecard_id=$row[qc_scorecard_id]&action=delete_scorecard'\" value='"._QXZ("DELETE")."'></font></td>\n";
		echo "</tr>";
	}
	echo "</tbody></table>\n";
	echo "<div class='qc-scorecards-actions'><input type='button' class='tiny_blue_btn' onClick='NewScorecard()' value='"._QXZ("ADD NEW SCORECARD")."'></div>\n";
	echo "</div>\n";
	echo "</span>\n";

	echo "<BR><a name='display_tag'><span id='checkpoint_display'>";
	echo "</span>";

	echo "</div>\n";

?>
	</body>
</html>
