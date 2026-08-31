<?php
# Search call history by phone number and open the selected call in QC.

header('Content-type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

require('dbconnect_mysqli.php');
require('functions.php');

$link = mysqli_connect($VARDB_server, $VARDB_user, $VARDB_pass, $VARDB_database, $VARDB_port);
if (!$link) {
	die('MySQL connect ERROR');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}
$has_admin_session = !empty($_SESSION['user']);
$PHP_AUTH_USER = isset($_SESSION['user']) ? (string) $_SESSION['user'] : (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
$PHP_AUTH_PW = isset($_SESSION['pass']) ? (string) $_SESSION['pass'] : (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
$PHP_AUTH_USER = preg_replace('/[^-_0-9a-zA-Z]/', '', $PHP_AUTH_USER);
$PHP_AUTH_PW = preg_replace('/[^-_0-9a-zA-Z]/', '', $PHP_AUTH_PW);

$user_escaped = mysqli_real_escape_string($link, $PHP_AUTH_USER);
$user_result = mysqli_query($link, "SELECT full_name,user_level,user_group,view_reports,qc_enabled FROM vicidial_users WHERE user='$user_escaped' AND active='Y' LIMIT 1");
$user_row = $user_result instanceof mysqli_result ? mysqli_fetch_assoc($user_result) : null;
$auth = ($has_admin_session && is_array($user_row) && ($user_row['qc_enabled'] ?? '0') === '1');
if (!$auth && user_authorization($PHP_AUTH_USER, $PHP_AUTH_PW, '', 1, 0) === 'GOOD') {
	$auth = is_array($user_row) && ($user_row['qc_enabled'] ?? '0') === '1';
}
if (!$auth) {
	header('HTTP/1.1 403 Forbidden');
	echo _QXZ('You are not authorized to use Quality Control');
	exit;
}

$LOGfull_name = $user_row['full_name'];
$LOGuser_level = (int) $user_row['user_level'];
$LOGuser_group = $user_row['user_group'];
$LOGview_reports = (int) $user_row['view_reports'];
$qc_auth = 1;
$permissions_result = mysqli_query($link, "SELECT qc_allowed_campaigns,qc_allowed_inbound_groups FROM vicidial_user_groups WHERE user_group='" . mysqli_real_escape_string($link, $LOGuser_group) . "' LIMIT 1");
$permissions = $permissions_result instanceof mysqli_result ? mysqli_fetch_assoc($permissions_result) : [];
$make_allowed_clause = static function ($value, $column) use ($link) {
	$value = (string) $value;
	if (preg_match('/-ALL/i', $value)) {
		return '1=1';
	}
	$ids = array_values(array_filter(preg_split('/\s+/', trim(str_replace(' -', '', $value)))));
	if (!$ids) {
		return '1=0';
	}
	$escaped = array_map(static function ($id) use ($link) {
		return "'" . mysqli_real_escape_string($link, $id) . "'";
	}, $ids);
	return $column . ' IN(' . implode(',', $escaped) . ')';
};
$campaign_permission_clause = $make_allowed_clause($permissions['qc_allowed_campaigns'] ?? '', 'al.campaign_id');
$ingroup_permission_clause = $make_allowed_clause($permissions['qc_allowed_inbound_groups'] ?? '', 'cl.campaign_id');
$LOGast_admin_access = '1';
$SSoutbound_autodial_active = '1';
$ADD = '999998';
$hh = 'qc';
$ADMIN = 'admin.php';
$PHP_SELF = $_SERVER['PHP_SELF'];
$page_width = '870';
$section_width = '850';
$header_font_size = '3';
$subheader_font_size = '2';
$subcamp_font_size = '2';
$header_selected_bold = '<b>';
$header_nonselected_bold = '';
$admin_color = '#E6E6E6';
$admin_font = 'BLACK';
$subcamp_color = '#C6C6C6';

$phone_input = trim((string) ($_GET['phone_number'] ?? ''));
$phone_number = preg_replace('/\D+/', '', $phone_input);
$searched = array_key_exists('phone_number', $_GET);
$error_message = '';
$calls = [];

if ($searched && strlen($phone_number) < 6) {
	$error_message = _QXZ('Enter at least 6 digits of the phone number.');
} elseif ($searched) {
	$phone_escaped = mysqli_real_escape_string($link, $phone_number);
	$sql = "SELECT DISTINCT al.agent_log_id,al.lead_id,al.user,al.status,al.campaign_id,al.uniqueid,
		(al.event_time + INTERVAL (al.wait_sec + al.pause_sec) SECOND) AS call_date,
		CASE WHEN COALESCE(al.comments,'') IN ('CHAT','EMAIL','INBOUND') THEN 'INBOUND' ELSE 'OUTBOUND' END AS direction,
		COALESCE(vlog.phone_number,cl.phone_number,vl.phone_number) AS phone_number,
		COALESCE(vlog.list_id,cl.list_id,vl.list_id) AS list_id,
		TRIM(CONCAT(vl.first_name,' ',vl.last_name)) AS lead_name,
		COALESCE(cl.campaign_id,'') AS group_id,q.qc_log_id,q.qc_status AS queue_status
		FROM vicidial_agent_log al
		INNER JOIN vicidial_list vl ON vl.lead_id=al.lead_id
		LEFT JOIN vicidial_log vlog ON vlog.lead_id=al.lead_id AND vlog.uniqueid=al.uniqueid
		LEFT JOIN vicidial_closer_log cl ON cl.lead_id=al.lead_id AND cl.uniqueid=al.uniqueid
		LEFT JOIN quality_control_queue q ON q.agent_log_id=al.agent_log_id
		WHERE (vlog.phone_number='$phone_escaped' OR cl.phone_number='$phone_escaped')
		AND ((COALESCE(al.comments,'') NOT IN ('CHAT','EMAIL','INBOUND') AND $campaign_permission_clause)
			OR (COALESCE(al.comments,'') IN ('CHAT','EMAIL','INBOUND') AND $ingroup_permission_clause))
		ORDER BY call_date DESC,al.agent_log_id DESC LIMIT 500";
	$result = mysqli_query($link, $sql);
	if ($result instanceof mysqli_result) {
		while ($row = mysqli_fetch_assoc($result)) {
			$calls[] = $row;
		}
	} else {
		$error_message = _QXZ('The call search could not be completed.');
	}
}

echo "<html><head><title>" . _QXZ('QC Calls by Phone') . "</title>\n";
echo '<link rel="stylesheet" type="text/css" href="vicidial_stylesheet.php">';
echo "</head><body>\n";
require('admin_header.php');
?>
<style>
.qc-phone-page{font-family:Arial,sans-serif;max-width:1150px;margin:22px auto;padding:0 22px;color:#eaf7f4}.qc-phone-title{color:#00ffc5;font-size:22px;margin:0 0 20px}.qc-phone-card{background:#001923;border:1px solid #164956;border-radius:12px;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,.22)}.qc-phone-form{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}.qc-phone-field{display:flex;flex-direction:column;gap:7px;min-width:300px}.qc-phone-field label{font-size:13px;font-weight:700;color:#b9d9d4}.qc-phone-field input{height:42px;padding:0 13px;border:1px solid #39707a;border-radius:7px;background:#071219;color:#fff;font-size:16px}.qc-phone-button{height:42px;padding:0 22px;border:0;border-radius:7px;background:#00ffc5;color:#00231d;font-weight:700;cursor:pointer}.qc-phone-help{margin:10px 0 0;color:#9bb4b8;font-size:13px}.qc-phone-message{margin-top:20px;padding:13px;border-radius:7px;background:#2c0b0e;border:1px solid #731c24;color:#ffb4bc}.qc-phone-results{width:100%;margin-top:22px;border-collapse:collapse;background:#001923;border:1px solid #164956}.qc-phone-results th,.qc-phone-results td{padding:12px 14px;text-align:left;border-bottom:1px solid #164956;font-size:13px}.qc-phone-results th{background:#002b3a;color:#00ffc5;text-transform:uppercase}.qc-phone-results tr:hover td{background:#062a34}.qc-phone-results a{color:#00ffc5;font-weight:700}.qc-phone-empty{margin-top:20px;color:#c8d7d9}.qc-light-theme .qc-phone-page{color:#15252b}.qc-light-theme .qc-phone-card,.qc-light-theme .qc-phone-results{background:#fff;border-color:#d7e0e1}.qc-light-theme .qc-phone-field input{background:#fff;color:#15252b}.qc-light-theme .qc-phone-results th{background:#e5ebeb;color:#007d69}.qc-light-theme .qc-phone-results td{border-color:#d7e0e1}.qc-light-theme .qc-phone-results a,.qc-light-theme .qc-phone-title{color:#007d69}@media(max-width:850px){.qc-phone-results-wrap{overflow-x:auto}.qc-phone-results{min-width:850px}}
</style>
<div class="qc-phone-page">
 <h1 class="qc-phone-title"><?php echo htmlspecialchars(_QXZ('QC Calls by Phone'), ENT_QUOTES, 'UTF-8'); ?></h1>
 <div class="qc-phone-card">
  <form method="get" action="qc_calls_by_phone.php" class="qc-phone-form">
   <div class="qc-phone-field"><label for="phone_number"><?php echo htmlspecialchars(_QXZ('Phone Number'), ENT_QUOTES, 'UTF-8'); ?></label><input id="phone_number" name="phone_number" type="tel" inputmode="numeric" value="<?php echo htmlspecialchars($phone_input, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter phone number" required autofocus></div>
   <button class="qc-phone-button" type="submit"><?php echo htmlspecialchars(_QXZ('Search Calls'), ENT_QUOTES, 'UTF-8'); ?></button>
  </form>
  <p class="qc-phone-help"><?php echo htmlspecialchars(_QXZ('Spaces, dashes, parentheses and a leading plus sign are ignored.'), ENT_QUOTES, 'UTF-8'); ?></p>
 </div>
 <?php if ($error_message !== '') { ?><div class="qc-phone-message"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
 <?php if ($searched && !$error_message && !$calls) { ?><p class="qc-phone-empty"><?php echo htmlspecialchars(_QXZ('No calls were found for this phone number.'), ENT_QUOTES, 'UTF-8'); ?></p><?php } ?>
 <?php if ($calls) { ?>
 <div class="qc-phone-results-wrap"><table class="qc-phone-results"><thead><tr><th>Call ID</th><th>Lead ID</th><th>Name</th><th>Call Date</th><th>Direction</th><th>Agent</th><th>Campaign / Ingroup</th><th>Status</th><th>QC</th></tr></thead><tbody>
 <?php foreach ($calls as $call) {
	$group_type = $call['direction'] === 'INBOUND' ? 'INGROUP' : 'CAMPAIGN';
	$params = $call['qc_log_id'] ? [
		'qc_log_id' => $call['qc_log_id'], 'lead_id' => $call['lead_id'], 'referring_section' => 'PHONE'
	] : [
		'claim_QC' => 'CLAIM', 'qc_display_method' => 'CALL', 'qc_display_group_type' => $group_type,
		'agent_log_id' => $call['agent_log_id'], 'qc_status' => $call['status'], 'lead_id' => $call['lead_id'],
		'campaign_id' => $call['campaign_id'], 'list_id' => $call['list_id'], 'group_id' => $call['group_id'],
		'lead_name' => $call['lead_name'], 'referring_section' => 'PHONE', 'referring_element' => $phone_number
	];
	$modify_url = 'qc_modify_lead.php?' . http_build_query($params);
 ?>
 <tr><td><a href="<?php echo htmlspecialchars($modify_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int) $call['agent_log_id']; ?></a></td><td><?php echo (int) $call['lead_id']; ?></td><td><?php echo htmlspecialchars($call['lead_name'] ?: _QXZ('No Name'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($call['call_date'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($call['direction'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($call['user'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($call['direction'] === 'INBOUND' ? $call['group_id'] : $call['campaign_id'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($call['status'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo $call['qc_log_id'] ? htmlspecialchars($call['queue_status'], ENT_QUOTES, 'UTF-8') : _QXZ('Not claimed'); ?></td></tr>
 <?php } ?></tbody></table></div>
 <?php } ?>
</div>
<script>
(function(){function sync(){var t=document.querySelector('.inner_main_right')||document.body,c=getComputedStyle(t).backgroundColor,m=c.match(/[\d.]+/g),light=m&&m.length>=3&&((m[0]*299+m[1]*587+m[2]*114)/1000>170);document.documentElement.classList.toggle('qc-light-theme',!!light)}document.addEventListener('DOMContentLoaded',sync);new MutationObserver(sync).observe(document.documentElement,{attributes:true,subtree:true,attributeFilter:['class','style','data-theme']})})();
</script>
</body></html>
