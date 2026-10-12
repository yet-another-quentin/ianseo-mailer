<?php
/*
 * Mailer — JSON API of the administration page (not to be confused with api.php, the contract for other modules).
 * Rights: competition edition (AclCompetition); SMTP settings and module updates: administrators (AclRoot).
 * JSON bodies only: a cross-site form cannot send them.
 */
require_once(dirname(__FILE__, 4) . '/config.php');
require_once(__DIR__ . '/lib.php');

if (!CheckTourSession()) JsonOut(array('error' => ml_t('noTournament')));
if (!hasFullACL(AclCompetition, '', AclReadWrite)) JsonOut(array('error' => ml_t('forbidden')));
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' or stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) JsonOut(array('error' => ml_t('invalid')));

$TourId = intval($_SESSION['TourId']);
$isRoot = hasFullACL(AclRoot, '', AclReadWrite);
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) JsonOut(array('error' => ml_t('invalid')));
$action = (string)($in['action'] ?? '');

function ml_require_root() {
	global $isRoot;
	if (!$isRoot) JsonOut(array('error' => ml_t('adminOnly')));
}

function ml_in_lang($in) {
	return ($in['lang'] ?? '') === 'en' ? 'en' : 'fr';
}

// Template being edited: ['subject', 'body'] from the request, limited in size.
function ml_in_texts($in) {
	return array(
		'subject' => mb_substr((string)($in['subject'] ?? ''), 0, 300),
		'body' => mb_substr(str_replace("\r", '', (string)($in['body'] ?? '')), 0, 20000),
	);
}

function ml_in_template($in) {
	$id = (string)($in['id'] ?? '');
	if (!isset(ml_templates()[$id])) JsonOut(array('error' => ml_t('unknownTemplate')));
	return $id;
}

try {
	ml_ensure_schema();
	switch ($action) {
		case 'context':
			JsonOut(ml_context($TourId, $isRoot));

		case 'smtp-save':
			ml_require_root();
			$old = ml_smtp();
			$s = is_array($in['smtp'] ?? null) ? $in['smtp'] : array();
			$host = trim((string)($s['host'] ?? ''));
			$fromEmail = trim((string)($s['fromEmail'] ?? ''));
			if ($host !== '' and !preg_match('/^[A-Za-z0-9.-]{1,253}$|^\[[0-9a-fA-F:.]+\]$/', $host)) JsonOut(array('error' => ml_t('invalid')));
			if ($fromEmail !== '' and !ml_valid_email($fromEmail)) JsonOut(array('error' => ml_t('badAddress')));
			$new = array(
				'host' => $host,
				'port' => max(1, min(65535, intval($s['port'] ?? 587))),
				'security' => in_array($s['security'] ?? 'tls', array('', 'tls', 'ssl'), true) ? $s['security'] : 'tls',
				'user' => mb_substr(trim((string)($s['user'] ?? '')), 0, 254),
				// empty password = keep the stored one (it is never sent to the page), unless the user is cleared
				'password' => ($s['user'] ?? '') === '' ? '' : (($s['password'] ?? '') !== '' ? (string)$s['password'] : $old['password']),
				'fromEmail' => $fromEmail,
				'fromName' => mb_substr(trim(preg_replace('/[\r\n]+/', ' ', (string)($s['fromName'] ?? ''))), 0, 100),
				'tested' => $old['tested'],   // still valid only if the settings did not change (ml_ready())
			);
			ml_param_set(0, 'smtp', $new);
			JsonOut(ml_context($TourId, $isRoot));

		case 'test':
			$res = ml_test($TourId, trim((string)($in['to'] ?? '')), ml_in_lang($in));
			JsonOut($res + array('ready' => ml_ready()));

		case 'competition-save':
			$c = is_array($in['competition'] ?? null) ? $in['competition'] : array();
			$email = trim((string)($c['contactEmail'] ?? ''));
			if ($email !== '' and !ml_valid_email($email)) JsonOut(array('error' => ml_t('badAddress')));
			$clean = function ($v) { return mb_substr(trim(preg_replace('/[\r\n]+/', ' ', (string)$v)), 0, 100); };
			ml_param_set($TourId, 'competition', array(
				'fromName' => $clean($c['fromName'] ?? ''), 'contactName' => $clean($c['contactName'] ?? ''), 'contactEmail' => $email,
			));
			JsonOut(ml_context($TourId, $isRoot));

		case 'template':
			$id = ml_in_template($in);
			$lang = ml_in_lang($in);
			$d = ml_template_detail($TourId, $id, $lang);
			JsonOut(array('template' => $d, 'preview' => ml_preview($TourId, $id, $d, $lang)));

		case 'preview':
			$id = ml_in_template($in);
			JsonOut(array('preview' => ml_preview($TourId, $id, ml_in_texts($in), ml_in_lang($in))));

		case 'template-save':
		case 'template-reset':
			$id = ml_in_template($in);
			$lang = ml_in_lang($in);
			$o = ml_override($TourId, $id);
			$texts = ml_in_texts($in);
			$tpl = ml_templates()[$id];
			foreach (array('subject', 'body') as $k) {
				// a text equal to the default is not stored: later improvements of the default then apply
				if ($action === 'template-reset' or trim($texts[$k]) === '' or $texts[$k] === ml_pick_lang($tpl[$k], $lang)) unset($o[$k][$lang]);
				else $o[$k][$lang] = $texts[$k];
			}
			if (array_filter($o['subject']) or array_filter($o['body'])) ml_param_set($TourId, 'tpl:' . $id, $o);
			else ml_param_del($TourId, 'tpl:' . $id);
			$d = ml_template_detail($TourId, $id, $lang);
			JsonOut(array('template' => $d, 'preview' => ml_preview($TourId, $id, $d, $lang), 'templates' => ml_template_list($TourId)));

		case 'preview-send':
			$id = ml_in_template($in);
			$lang = ml_in_lang($in);
			JsonOut(ml_send_direct($TourId, $id, ml_in_texts($in), ml_sample($id, $lang), trim((string)($in['to'] ?? '')), $lang, false, ml_t('previewSubject', array('_lang' => $lang))));

		case 'outbox-html':
			$html = ml_outbox_html($TourId, intval($in['id'] ?? 0));
			if ($html === null) JsonOut(array('error' => ml_t('invalid')));
			global $CFG;
			// embedded images (cid:) shown through IANSEO's own logo page
			$html = preg_replace_callback('/cid:ml-([LRB])/', function ($m) use ($CFG) {
				return $CFG->ROOT_DIR . 'Common/TourLogo.php?Type=' . $m[1] . '&W=' . ($m[1] === 'B' ? 1200 : 220) . '&H=' . ($m[1] === 'B' ? 400 : 220);
			}, $html);
			JsonOut(array('html' => $html));

		case 'retry':
			ml_retry($TourId, $in['ids'] ?? array());
			ml_flush(20);
			JsonOut(ml_context($TourId, $isRoot));

		case 'flush':
			$r = ml_flush(20);
			JsonOut($r + ml_context($TourId, $isRoot));

		case 'purge':
			$n = ml_purge($TourId, intval($in['days'] ?? 90));
			JsonOut(array('purged' => $n) + ml_context($TourId, $isRoot));

		case 'update-check':
			ml_require_root();
			JsonOut(ml_update_check(!empty($in['force'])));

		case 'update-channel':
			ml_require_root();
			if (!in_array($in['channel'] ?? '', array('stable', 'nightly'), true)) JsonOut(array('error' => ml_t('channelInvalid')));
			ml_param_set(0, 'channel', $in['channel']);
			JsonOut(array('ok' => true, 'channel' => $in['channel']));

		case 'update-install':
			ml_require_root();
			JsonOut(ml_update_install());
	}
	JsonOut(array('error' => ml_t('unknownAction')));
} catch (Throwable $e) {
	JsonOut(array('error' => $e->getMessage()));
}
