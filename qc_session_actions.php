<?php

// Session-authenticated bridge for the stock VICIdial QC AJAX handler. The
// legacy handler expects HTTP Basic variables; supplying them server-side keeps
// browser credential dialogs out of the custom admin interface.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$sessionUser = trim((string) ($_SESSION['user'] ?? ''));
$sessionPass = (string) ($_SESSION['pass'] ?? '');

if ($sessionUser === '' || $sessionPass === '') {
    http_response_code(403);
    exit('Admin session expired.');
}

// Older QC markup builds scorecard deletion links from PHP_SELF. When that
// markup is returned by this AJAX bridge, those links incorrectly point back
// here. Send any such request to the actual scorecards page.
if (($_GET['action'] ?? '') === 'delete_scorecard') {
    $query = [
        'scorecard_id' => (string) ($_GET['scorecard_id'] ?? ''),
        'action' => 'delete_scorecard',
    ];

    if (($_GET['confirm_deletion'] ?? '') === 'Y') {
        $query['confirm_deletion'] = 'Y';
    }

    header('Location: qc_scorecards.php?' . http_build_query($query), true, 303);
    exit;
}

$_SERVER['PHP_AUTH_USER'] = $sessionUser;
$_SERVER['PHP_AUTH_PW'] = $sessionPass;
$PHP_AUTH_USER = $sessionUser;
$PHP_AUTH_PW = $sessionPass;

$legacyHandler = __DIR__ . '/qc_module_actions.php';

if (!is_file($legacyHandler)) {
    http_response_code(503);
    exit('The QC actions handler is unavailable.');
}

require $legacyHandler;
