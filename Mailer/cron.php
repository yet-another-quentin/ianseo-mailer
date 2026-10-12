<?php
// Mailer — sends the queue from the command line, for installations where emails should not wait for the next
// page view (e.g. failed emails to retry). Example crontab, every 5 minutes:
//   */5 * * * * php /path/to/ianseo/Modules/Custom/Mailer/cron.php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/Modules/Custom/Mailer/cron.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$SKIP_AUTH = true;
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');

$total = array('sent' => 0, 'failed' => 0);
do {
	$r = ml_flush(50);
	$total['sent'] += $r['sent'];
	$total['failed'] += $r['failed'];
} while ($r['sent'] + $r['failed'] >= 50);
echo "Mailer: {$total['sent']} sent, {$total['failed']} failed\n";
