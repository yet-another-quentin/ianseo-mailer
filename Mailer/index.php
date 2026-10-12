<?php
/*
 * Mailer — administration page: SMTP settings (installation, administrators), competition sender and contact,
 * templates of every module (customisable per competition), sent emails.
 */
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');

CheckTourSession(true);
checkFullACL(AclCompetition, '', AclReadWrite);

ml_ensure_schema();
ml_purge_orphans();

$PAGE_TITLE = ml_t('menu');
$JS_SCRIPT = array(
	'<link href="./' . ml_asset('admin.css') . '" rel="stylesheet" type="text/css">',
	'<script src="./' . ml_asset('i18n.js') . '"></script>',
	'<script src="./' . ml_asset('admin.js') . '" defer></script>',
	'<script>window.ML_CONTEXT = ' . json_encode(ml_context($_SESSION['TourId'], hasFullACL(AclRoot, '', AclReadWrite)), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>',
);

include('Common/Templates/head.php');
?>
<div id="ml-admin">
	<noscript>JavaScript is required for this module.</noscript>
</div>
<?php
include('Common/Templates/tail.php');
