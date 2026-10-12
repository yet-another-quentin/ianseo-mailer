<?php
/*
 * Mailer — PHP helpers: settings, outbox, sending (PHPMailer), templates, admin page, self-update.
 * Internal prefix ml_. Other modules must only use api.php (prefix mailer_), which loads this file.
 * Every function takes the competition id explicitly: emails are also queued from public pages without a
 * competition session.
 */

require_once(__DIR__ . '/core.php');

const ML_MODULE = 'Mailer';
const ML_SCHEMA = 1;
const ML_MAX_TRIES = 4;            // sending attempts before an email stays failed
const ML_FLUSH_BATCH = 10;         // emails sent at the end of a request

function ml_version_info() {
	$v = @json_decode(@file_get_contents(__DIR__ . '/version.json'), true);
	return is_array($v) ? $v : array('version' => '0.0.0', 'repo' => '', 'asset' => ML_MODULE . '.zip');
}

function ml_asset($file) {
	return $file . '?v=' . rawurlencode(ml_version_info()['version'] . '-' . intval(@filemtime(__DIR__ . '/' . $file)));
}

// ---------- language ----------

function ml_lang() {
	return preg_match('/^fr/i', (string)SelectLanguage()) ? 'fr' : 'en';
}

function ml_t($key, $params = array()) {
	static $dict = array(
		'en' => array(
			'menu' => 'Emails',
			'noTournament' => 'No competition open',
			'forbidden' => 'Insufficient rights',
			'adminOnly' => 'Administrator only',
			'unknownAction' => 'Unknown action',
			'invalid' => 'Invalid data',
			'notConfigured' => 'Email sending is not configured',
			'badAddress' => 'Invalid email address',
			'unknownTemplate' => 'Unknown template',
			'previewSubject' => '[Preview] {subject}',
			'channelInvalid' => 'Invalid channel',
			'repoMissing' => 'Repository not configured',
			'connection' => 'connection failed',
			'connectionFopen' => 'connection failed (curl missing, allow_url_fopen or SSL?)',
			'githubUnexpected' => 'unexpected GitHub answer',
			'noRelease' => 'no release published yet',
			'noUpdate' => 'No new version',
			'manual' => ' — Manual update: download {asset} and unzip it into Modules/Custom/.',
			'zipMissing' => 'PHP "zip" extension missing',
			'notWritable' => 'Module folder not writable by the web server',
			'downloadFailed' => 'Download failed: {error}',
			'zipUnreadable' => 'Unreadable archive',
			'zipRefused' => 'Archive refused: unexpected path "{name}"',
			'zipIncomplete' => 'Incomplete archive',
			'versionInvalid' => 'Invalid version.json in the archive',
			'backupFailed' => 'Backup failed',
			'copyFailed' => 'Cannot copy the new files: previous version restored',
			'footer' => 'Email sent with IANSEO',
		),
		'fr' => array(
			'menu' => 'Emails',
			'noTournament' => 'Aucune compétition ouverte',
			'forbidden' => 'Droits insuffisants',
			'adminOnly' => 'Réservé à l\'administrateur',
			'unknownAction' => 'Action inconnue',
			'invalid' => 'Données invalides',
			'notConfigured' => 'L\'envoi d\'emails n\'est pas configuré',
			'badAddress' => 'Adresse email invalide',
			'unknownTemplate' => 'Modèle inconnu',
			'previewSubject' => '[Aperçu] {subject}',
			'channelInvalid' => 'Canal invalide',
			'repoMissing' => 'Dépôt non configuré',
			'connection' => 'connexion impossible',
			'connectionFopen' => 'connexion impossible (curl absent, allow_url_fopen ou SSL ?)',
			'githubUnexpected' => 'réponse GitHub inattendue',
			'noRelease' => 'aucune version publiée pour le moment',
			'noUpdate' => 'Aucune nouvelle version',
			'manual' => ' — Mise à jour manuelle : télécharger {asset} et décompresser son contenu dans Modules/Custom/.',
			'zipMissing' => 'Extension PHP « zip » absente',
			'notWritable' => 'Dossier du module non modifiable par le serveur web',
			'downloadFailed' => 'Téléchargement impossible : {error}',
			'zipUnreadable' => 'Archive illisible',
			'zipRefused' => 'Archive refusée : chemin inattendu « {name} »',
			'zipIncomplete' => 'Archive incomplète',
			'versionInvalid' => 'version.json invalide dans l\'archive',
			'backupFailed' => 'Sauvegarde impossible',
			'copyFailed' => 'Copie des nouveaux fichiers impossible : ancienne version restaurée',
			'footer' => 'Email envoyé avec IANSEO',
		),
	);
	$s = $dict[$params['_lang'] ?? ml_lang()][$key] ?? $dict['en'][$key] ?? $key;
	foreach ($params as $k => $v) if ($k !== '_lang') $s = str_replace('{' . $k . '}', (string)$v, $s);
	return $s;
}

// ---------- parameters (JSON strings, stored serialized like setModuleParameter(); TourId 0 = installation) ----------

function ml_param_get($TourId, $param, $default = null) {
	$q = safe_r_sql("select MpValue from ModulesParameters where MpModule=" . StrSafe_DB(ML_MODULE)
		. " and MpParameter=" . StrSafe_DB($param) . " and MpTournament=" . intval($TourId));
	if ($r = safe_fetch($q)) {
		$v = @unserialize($r->MpValue, array('allowed_classes' => false));
		$d = json_decode(is_string($v) ? $v : $r->MpValue, true);
		return $d === null ? $default : $d;
	}
	return $default;
}

function ml_param_set($TourId, $param, $value) {
	$v = StrSafe_DB(serialize(json_encode($value, JSON_UNESCAPED_UNICODE)));
	safe_w_sql("insert into ModulesParameters set MpModule=" . StrSafe_DB(ML_MODULE)
		. ", MpParameter=" . StrSafe_DB($param) . ", MpTournament=" . intval($TourId) . ", MpValue=$v"
		. " on duplicate key update MpValue=$v");
}

function ml_param_del($TourId, $param) {
	safe_w_sql("delete from ModulesParameters where MpModule=" . StrSafe_DB(ML_MODULE)
		. " and MpParameter=" . StrSafe_DB($param) . " and MpTournament=" . intval($TourId));
}

// ---------- schema ----------

function ml_ensure_schema() {
	static $done = false;
	if ($done) return;
	$done = true;
	if (intval(ml_param_get(0, 'schema', 0)) >= ML_SCHEMA) return;
	safe_w_sql("CREATE TABLE IF NOT EXISTS `MailerOutbox` (
		`MoId` int unsigned NOT NULL AUTO_INCREMENT,
		`MoTournament` int unsigned NOT NULL,
		`MoModule` varchar(30) NOT NULL DEFAULT '',
		`MoTemplate` varchar(40) NOT NULL DEFAULT '',
		`MoRef` varchar(64) NOT NULL DEFAULT '',
		`MoLang` varchar(5) NOT NULL DEFAULT '',
		`MoTo` varchar(254) NOT NULL,
		`MoReplyTo` varchar(254) NOT NULL DEFAULT '',
		`MoSubject` varchar(255) NOT NULL,
		`MoText` mediumtext NOT NULL,
		`MoHtml` mediumtext NOT NULL,
		`MoStatus` tinyint NOT NULL DEFAULT 0,
		`MoTries` tinyint NOT NULL DEFAULT 0,
		`MoError` varchar(500) NOT NULL DEFAULT '',
		`MoCreated` datetime NOT NULL,
		`MoNextTry` datetime NOT NULL,
		`MoSent` datetime NULL,
		PRIMARY KEY (`MoId`),
		KEY `MoQueue` (`MoStatus`, `MoNextTry`),
		KEY `MoTour` (`MoTournament`, `MoCreated`),
		KEY `MoRef` (`MoTournament`, `MoModule`, `MoRef`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	ml_param_set(0, 'schema', ML_SCHEMA);
}

const ML_QUEUED = 0;
const ML_SENT = 1;
const ML_FAILED = 2;
const ML_SENDING = 3;

// ---------- SMTP configuration (installation) and competition settings ----------

function ml_smtp() {
	$s = ml_param_get(0, 'smtp', array());
	if (!is_array($s)) $s = array();
	return array(
		'host' => (string)($s['host'] ?? ''),
		'port' => intval($s['port'] ?? 587) ?: 587,
		'security' => in_array($s['security'] ?? 'tls', array('', 'tls', 'ssl'), true) ? ($s['security'] ?? 'tls') : 'tls',
		'user' => (string)($s['user'] ?? ''),
		'password' => (string)($s['password'] ?? ''),
		'fromEmail' => (string)($s['fromEmail'] ?? ''),
		'fromName' => (string)($s['fromName'] ?? ''),
		'tested' => (string)($s['tested'] ?? ''),
	);
}

// Fingerprint of the settings that affect sending: a successful test is only valid for these settings.
function ml_smtp_hash($s) {
	return hash('sha256', json_encode(array($s['host'], $s['port'], $s['security'], $s['user'], $s['password'], $s['fromEmail'])));
}

function ml_configured() {
	$s = ml_smtp();
	return $s['host'] !== '' and ml_valid_email($s['fromEmail']);
}

// Configured AND a test email went through with the current settings.
function ml_ready() {
	$s = ml_smtp();
	return ml_configured() and $s['tested'] !== '' and hash_equals($s['tested'], ml_smtp_hash($s));
}

function ml_competition($TourId) {
	$c = ml_param_get($TourId, 'competition', array());
	if (!is_array($c)) $c = array();
	return array(
		'fromName' => (string)($c['fromName'] ?? ''),
		'contactName' => (string)($c['contactName'] ?? ''),
		'contactEmail' => ml_valid_email($c['contactEmail'] ?? '') ? $c['contactEmail'] : '',
	);
}

// ---------- competition ----------

function ml_tour($TourId) {
	return safe_fetch(safe_r_sql("select ToId, ToCode, ToName, ToComDescr, ToWhere, ToVenue, ToIocCode, ToPrintLang,
		ToWhenFrom, ToWhenTo, length(ToImgL) as ImgL, length(ToImgR) as ImgR, length(ToImgB) as ImgB
		from Tournament where ToId=" . intval($TourId)));
}

// Language when the caller gives none: the competition's print language, else French for French competitions.
function ml_default_lang($tour) {
	if ($tour and preg_match('/^(fr|en)/i', (string)$tour->ToPrintLang, $m)) return strtolower($m[1]);
	return ($tour and $tour->ToIocCode === 'FRA') ? 'fr' : 'en';
}

// Variables available in every template.
function ml_tour_vars($tour, $lang) {
	if (!$tour) return array();
	$c = ml_competition($tour->ToId);
	return array(
		'competition.name' => (string)$tour->ToName,
		'competition.code' => (string)$tour->ToCode,
		'competition.dates' => ml_format_range($tour->ToWhenFrom, $tour->ToWhenTo, $lang),
		'competition.place' => ml_place($tour->ToVenue, $tour->ToWhere),
		'competition.venue' => (string)$tour->ToVenue,
		'competition.town' => (string)$tour->ToWhere,
		'competition.organiser' => (string)$tour->ToComDescr,
		'competition.contactName' => $c['contactName'] ?: (string)$tour->ToComDescr,
		'competition.contactEmail' => $c['contactEmail'],
	);
}

function ml_tour_var_labels() {
	return array(
		'competition.name' => array('fr' => 'Nom de la compétition', 'en' => 'Competition name'),
		'competition.code' => array('fr' => 'Code de la compétition', 'en' => 'Competition code'),
		'competition.dates' => array('fr' => 'Dates', 'en' => 'Dates'),
		'competition.place' => array('fr' => 'Lieu (salle, ville)', 'en' => 'Venue and town'),
		'competition.venue' => array('fr' => 'Salle / terrain', 'en' => 'Venue'),
		'competition.town' => array('fr' => 'Ville', 'en' => 'Town'),
		'competition.organiser' => array('fr' => 'Organisateur', 'en' => 'Organiser'),
		'competition.contactName' => array('fr' => 'Nom du contact', 'en' => 'Contact name'),
		'competition.contactEmail' => array('fr' => 'Email du contact', 'en' => 'Contact email'),
	);
}

/**
 * Competition image re-encoded as PNG with GD (L/R logos, B banner), or null.
 */
function ml_tour_image($TourId, $type, $maxW, $maxH) {
	// cached for the request: a batch of emails of the same competition re-encodes each image once
	static $cache = array();
	$key = intval($TourId) . "|$type|$maxW|$maxH";
	if (!array_key_exists($key, $cache)) $cache[$key] = ml_tour_image_load($TourId, $type, $maxW, $maxH);
	return $cache[$key];
}

function ml_tour_image_load($TourId, $type, $maxW, $maxH) {
	if (!in_array($type, array('L', 'R', 'B'), true) or !function_exists('imagecreatefromstring')) return null;
	$r = safe_fetch(safe_r_sql("select ToImg$type as img from Tournament where ToId=" . intval($TourId)));
	if (!$r or !strlen((string)$r->img) or !($im = @imagecreatefromstring($r->img))) return null;
	$w = imagesx($im);
	$h = imagesy($im);
	$scale = min(1, $maxW / $w, $maxH / $h);
	if ($scale < 1) {
		$small = imagecreatetruecolor(max(1, intval($w * $scale)), max(1, intval($h * $scale)));
		imagealphablending($small, false);
		imagesavealpha($small, true);
		imagecopyresampled($small, $im, 0, 0, 0, 0, imagesx($small), imagesy($small), $w, $h);
		imagedestroy($im);
		$im = $small;
	} else {
		imagesavealpha($im, true);
	}
	ob_start();
	imagepng($im);
	imagedestroy($im);
	return ob_get_clean();
}

// ---------- templates ----------

/**
 * Templates of every installed module: Modules/Custom/<module>/mailer-templates.php (see Mailer's own file).
 * Invalid definitions are skipped; ids must be unique ("<prefix>.<name>", at most 26 characters).
 */
function ml_templates() {
	static $all = null;
	if ($all !== null) return $all;
	$all = array();
	foreach (glob(dirname(__DIR__) . '/*/mailer-templates.php') as $file) {
		$module = basename(dirname($file));
		$defs = (function ($f) { return include($f); })($file);
		if (!is_array($defs)) continue;
		foreach ($defs as $id => $d) {
			if (!preg_match('/^[a-z][a-z0-9]*\.[a-zA-Z0-9]+$/', $id) or strlen($id) > 26 or isset($all[$id])) continue;
			if (!is_array($d) or empty($d['subject']) or empty($d['body'])) continue;
			$d['module'] = $module;
			foreach (array('name', 'description', 'vars', 'sample') as $k) if (!isset($d[$k]) or !is_array($d[$k])) $d[$k] = array();
			$all[$id] = $d;
		}
	}
	ksort($all);
	return $all;
}

// Competition customisation of a template: {subject: {lang: text}, body: {lang: text}}.
function ml_override($TourId, $id) {
	$o = ml_param_get($TourId, 'tpl:' . $id, array());
	return array('subject' => (array)($o['subject'] ?? array()), 'body' => (array)($o['body'] ?? array()));
}

function ml_texts($TourId, $id, $lang) {
	$tpl = ml_templates()[$id] ?? null;
	if (!$tpl) return null;
	$o = ml_override($TourId, $id);
	return array(
		'subject' => (isset($o['subject'][$lang]) and $o['subject'][$lang] !== '') ? $o['subject'][$lang] : ml_pick_lang($tpl['subject'], $lang),
		'body' => (isset($o['body'][$lang]) and $o['body'][$lang] !== '') ? $o['body'][$lang] : ml_pick_lang($tpl['body'], $lang),
		'custom' => (isset($o['subject'][$lang]) and $o['subject'][$lang] !== '') or (isset($o['body'][$lang]) and $o['body'][$lang] !== ''),
	);
}

function ml_sample($id, $lang) {
	$tpl = ml_templates()[$id] ?? array('sample' => array());
	$out = array();
	foreach ($tpl['sample'] as $k => $v) $out[$k] = is_array($v) ? ml_pick_lang($v, $lang) : (string)$v;
	return $out;
}

/**
 * Renders subject/text/HTML. $texts: ['subject', 'body'] (template or text being edited); $vars: module variables
 * (competition.* added). Returns ['subject', 'text', 'html', 'images' => [cid => type]].
 */
function ml_render($tour, $texts, $vars, $lang, $keepUnknown = false) {
	$vars = array_merge(ml_tour_vars($tour, $lang), array_map('strval', $vars));
	$inner = ml_render_html($texts['body'], $vars, $keepUnknown);
	list($html, $images) = ml_layout($tour, $lang, $inner);
	return array(
		'subject' => ml_render_subject($texts['subject'], $vars, $keepUnknown),
		'text' => ml_render_text($texts['body'], $vars, $keepUnknown),
		'html' => $html,
		'images' => $images,
	);
}

/**
 * Email layout: competition header (left logo, name, place and dates, right logo), body, bottom banner, footer.
 * Images are referenced as cid:ml-L / ml-R / ml-B and embedded at sending time. Returns [html, [cid => type]].
 */
function ml_layout($tour, $lang, $inner) {
	$images = array();
	$img = function ($type, $style) use ($tour, &$images) {
		if (!$tour or intval($tour->{'Img' . $type}) <= 0) return '';
		$images['ml-' . $type] = $type;
		return '<img src="cid:ml-' . $type . '" alt="" style="' . $style . '">';
	};
	$name = $tour ? ml_h($tour->ToName) : '';
	$facts = $tour ? ml_h(implode(' · ', array_filter(array(ml_format_range($tour->ToWhenFrom, $tour->ToWhenTo, $lang), ml_place($tour->ToVenue, $tour->ToWhere))))) : '';
	$left = $img('L', 'display:block;max-width:110px;max-height:110px;height:auto;width:auto;');
	$right = $img('R', 'display:block;max-width:110px;max-height:110px;height:auto;width:auto;');
	$banner = $img('B', 'display:block;width:100%;max-width:600px;height:auto;border:0;');
	$organiser = $tour ? ml_h($tour->ToComDescr) : '';
	$font = "font-family:Arial,Helvetica,sans-serif;";

	$html = '<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<title>' . $name . '</title></head>'
		. '<body style="margin:0;padding:0;background:#f6f5f1;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f5f1;"><tr><td align="center" style="padding:16px 8px;">'
		. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #d8d6d0;border-radius:8px;">'
		. '<tr><td style="padding:16px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
		. '<td width="110" valign="middle" align="left">' . $left . '</td>'
		. '<td valign="middle" align="center" style="padding:0 8px;' . $font . 'color:#1d1d1b;">'
		. '<div style="font-size:20px;font-weight:bold;line-height:1.3;">' . $name . '</div>'
		. ($organiser ? '<div style="font-size:13px;color:#6b6a66;margin-top:4px;">' . $organiser . '</div>' : '')
		. ($facts ? '<div style="font-size:13px;color:#6b6a66;margin-top:2px;">' . $facts . '</div>' : '')
		. '</td><td width="110" valign="middle" align="right">' . $right . '</td>'
		. '</tr></table></td></tr>'
		. '<tr><td style="padding:4px 24px 16px;' . $font . 'font-size:15px;line-height:1.5;color:#1d1d1b;">'
		. ml_style_body($inner)
		. '</td></tr>'
		. ($banner ? '<tr><td style="padding:0;">' . $banner . '</td></tr>' : '')
		. '</table>'
		. '<div style="' . $font . 'font-size:12px;color:#8a8984;padding:12px;">' . ml_h(ml_t('footer', array('_lang' => $lang))) . '</div>'
		. '</td></tr></table></body></html>';
	return array($html, $images);
}

// Inline styles of the body elements (many email clients ignore <style>).
function ml_style_body($inner) {
	return strtr($inner, array(
		'<p>' => '<p style="margin:0 0 14px;">',
		'<ul>' => '<ul style="margin:0 0 14px;padding-left:22px;">',
		'<li>' => '<li style="margin:0 0 4px;">',
		'<a href=' => '<a style="color:#2f6f4f;" href=',
	));
}

// ---------- outbox ----------

/**
 * Queues a rendered template. Returns the outbox id, or false when sending is not ready, the address or the
 * template is invalid. $opts: lang, replyTo, ref (caller reference), module.
 */
function ml_queue($template, $TourId, $to, $vars, $opts) {
	if (!ml_ready() or !ml_valid_email($to)) return false;
	ml_ensure_schema();
	$tour = ml_tour($TourId);
	$lang = in_array($opts['lang'] ?? '', array('fr', 'en'), true) ? $opts['lang'] : ml_default_lang($tour);
	$texts = ml_texts($TourId, $template, $lang);
	if (!$texts) return false;
	$m = ml_render($tour, $texts, (array)$vars, $lang);
	$replyTo = $opts['replyTo'] ?? ml_competition($TourId)['contactEmail'];
	$now = StrSafe_DB(date('Y-m-d H:i:s'));
	safe_w_sql("insert into MailerOutbox set MoTournament=" . intval($TourId)
		. ", MoModule=" . StrSafe_DB(substr((string)($opts['module'] ?? (ml_templates()[$template]['module'] ?? '')), 0, 30))
		. ", MoTemplate=" . StrSafe_DB($template) . ", MoRef=" . StrSafe_DB(substr((string)($opts['ref'] ?? ''), 0, 64))
		. ", MoLang=" . StrSafe_DB($lang) . ", MoTo=" . StrSafe_DB($to)
		. ", MoReplyTo=" . StrSafe_DB(ml_valid_email($replyTo) ? $replyTo : '')
		. ", MoSubject=" . StrSafe_DB($m['subject']) . ", MoText=" . StrSafe_DB($m['text']) . ", MoHtml=" . StrSafe_DB($m['html'])
		. ", MoStatus=" . ML_QUEUED . ", MoCreated=$now, MoNextTry=$now");
	$id = safe_w_last_id();
	ml_flush_at_shutdown();
	return $id;
}

/**
 * Sends the queue once the answer has reached the visitor: with PHP-FPM the response is closed first
 * (fastcgi_finish_request); otherwise output buffers are flushed and the client stops reading at Content-Length.
 */
function ml_flush_at_shutdown() {
	static $registered = false;
	if ($registered) return;
	$registered = true;
	register_shutdown_function(function () {
		ignore_user_abort(true);
		// PHP writes and unlocks the session only after the shutdown functions: without this, the visitor's next
		// request (or the admin's) would wait until every email is sent
		if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		} else {
			while (ob_get_level() > 0) @ob_end_flush();
			@flush();
		}
		@set_time_limit(120);
		try {
			ml_flush(ML_FLUSH_BATCH);
		} catch (Throwable $e) {
			error_log('Mailer: ' . $e->getMessage());
		}
	});
}

/**
 * Sends up to $max due emails (queued, or failed with tries left once their retry time has come).
 * One sender at a time (GET_LOCK). Returns ['sent' => n, 'failed' => n].
 */
function ml_flush($max = ML_FLUSH_BATCH) {
	$res = array('sent' => 0, 'failed' => 0);
	if (!ml_ready()) return $res;
	ml_ensure_schema();
	if (!intval(safe_fetch(safe_w_sql("select get_lock('mailer_flush', 0) as l"))->l)) return $res;
	try {
		$now = date('Y-m-d H:i:s');
		// emails left "sending" by a crashed request go back to the queue
		safe_w_sql("update MailerOutbox set MoStatus=" . ML_QUEUED . " where MoStatus=" . ML_SENDING
			. " and MoNextTry < " . StrSafe_DB(date('Y-m-d H:i:s', time() - 900)));
		$q = safe_r_sql("select * from MailerOutbox where MoNextTry <= " . StrSafe_DB($now)
			. " and (MoStatus=" . ML_QUEUED . " or (MoStatus=" . ML_FAILED . " and MoTries < " . ML_MAX_TRIES . "))"
			. " order by MoId limit " . intval($max));
		$rows = array();
		while ($r = safe_fetch($q)) $rows[] = $r;
		$mailer = null;
		foreach ($rows as $r) {
			safe_w_sql("update MailerOutbox set MoStatus=" . ML_SENDING . ", MoNextTry=" . StrSafe_DB($now) . " where MoId=" . intval($r->MoId));
			$err = ml_send_row($r, $mailer);
			$tries = intval($r->MoTries) + 1;
			if ($err === '') {
				safe_w_sql("update MailerOutbox set MoStatus=" . ML_SENT . ", MoTries=$tries, MoError='', MoSent=" . StrSafe_DB(date('Y-m-d H:i:s'))
					. " where MoId=" . intval($r->MoId));
				$res['sent']++;
			} else {
				// retry after 5, 20, 45 minutes
				safe_w_sql("update MailerOutbox set MoStatus=" . ML_FAILED . ", MoTries=$tries, MoError=" . StrSafe_DB(mb_substr($err, 0, 500))
					. ", MoNextTry=" . StrSafe_DB(date('Y-m-d H:i:s', time() + 300 * $tries * $tries)) . " where MoId=" . intval($r->MoId));
				$res['failed']++;
			}
		}
		if ($mailer) $mailer->smtpClose();
	} finally {
		safe_w_sql("select release_lock('mailer_flush')");
	}
	return $res;
}

// ---------- sending (PHPMailer) ----------

function ml_phpmailer() {
	require_once(__DIR__ . '/vendor/phpmailer/Exception.php');
	require_once(__DIR__ . '/vendor/phpmailer/PHPMailer.php');
	require_once(__DIR__ . '/vendor/phpmailer/SMTP.php');
}

/**
 * New PHPMailer set up with the installation's SMTP settings. $debug (by reference) receives the SMTP dialogue,
 * with the credentials masked, when not null.
 */
function ml_new_mailer($lang, &$debug = null) {
	ml_phpmailer();
	$s = ml_smtp();
	$m = new PHPMailer\PHPMailer\PHPMailer(true);
	$m->isSMTP();
	$m->Host = $s['host'];
	$m->Port = $s['port'];
	$m->SMTPSecure = $s['security'] === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : ($s['security'] === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
	$m->SMTPAutoTLS = $s['security'] !== '';
	$m->SMTPAuth = $s['user'] !== '';
	$m->Username = $s['user'];
	$m->Password = $s['password'];
	$m->Timeout = 15;
	$m->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
	$m->Encoding = 'quoted-printable';
	$m->XMailer = 'IANSEO Mailer';
	$m->setLanguage($lang === 'fr' ? 'fr' : 'en', __DIR__ . '/vendor/phpmailer/language/');
	$m->SMTPKeepAlive = true;
	if ($debug !== null) {
		$secrets = array_filter(array($s['password'], base64_encode($s['password']), base64_encode("\0" . $s['user'] . "\0" . $s['password'])));
		$m->SMTPDebug = PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
		$m->Debugoutput = function ($str) use (&$debug, $secrets) {
			$debug .= str_replace($secrets, '********', $str);
		};
	}
	return $m;
}

/**
 * Sends one message. $msg: tourId, to, replyTo, subject, text, html, lang. Images referenced as cid:ml-X are
 * embedded from the competition. Returns '' on success, the error otherwise. $mailer is reused between calls.
 */
function ml_send_message($msg, &$mailer = null, &$debug = null) {
	try {
		if (!$mailer) $mailer = ml_new_mailer($msg['lang'], $debug);
		$s = ml_smtp();
		$c = ml_competition($msg['tourId']);
		$mailer->clearAllRecipients();
		$mailer->clearReplyTos();
		$mailer->clearAttachments();
		$mailer->setFrom($s['fromEmail'], $c['fromName'] ?: $s['fromName']);
		$mailer->addAddress($msg['to']);
		if ($msg['replyTo'] and ml_valid_email($msg['replyTo'])) $mailer->addReplyTo($msg['replyTo']);
		$mailer->Subject = $msg['subject'];
		$mailer->isHTML(true);
		$mailer->Body = $msg['html'];
		$mailer->AltBody = $msg['text'];
		if (preg_match_all('/cid:ml-([LRB])/', $msg['html'], $m)) {
			foreach (array_unique($m[1]) as $type) {
				$png = ml_tour_image($msg['tourId'], $type, $type === 'B' ? 1200 : 220, $type === 'B' ? 400 : 220);
				if ($png) $mailer->addStringEmbeddedImage($png, 'ml-' . $type, 'image-' . strtolower($type) . '.png', 'base64', 'image/png');
			}
		}
		$mailer->send();
		return '';
	} catch (Throwable $e) {
		$err = $mailer ? ($mailer->ErrorInfo ?: $e->getMessage()) : $e->getMessage();
		if ($mailer) { try { $mailer->smtpClose(); } catch (Throwable $x) {} }
		$mailer = null;   // a failed SMTP session is not reused
		return $err;
	}
}

function ml_send_row($r, &$mailer) {
	return ml_send_message(array(
		'tourId' => intval($r->MoTournament), 'to' => $r->MoTo, 'replyTo' => $r->MoReplyTo,
		'subject' => $r->MoSubject, 'text' => $r->MoText, 'html' => $r->MoHtml, 'lang' => $r->MoLang ?: 'fr',
	), $mailer);
}

/**
 * Immediate sending of a template (not queued): configuration test and previews. Returns ['ok', 'error', 'log'].
 * A successful configuration test marks the current SMTP settings as tested (ml_ready()).
 */
function ml_send_direct($TourId, $template, $texts, $vars, $to, $lang, $markTested = false, $subjectPrefix = '') {
	if (!ml_configured()) return array('ok' => false, 'error' => ml_t('notConfigured'), 'log' => '');
	if (!ml_valid_email($to)) return array('ok' => false, 'error' => ml_t('badAddress'), 'log' => '');
	$tour = ml_tour($TourId);
	$m = ml_render($tour, $texts, $vars, $lang, false);
	$debug = '';
	$mailer = null;
	$err = ml_send_message(array(
		'tourId' => $TourId, 'to' => $to, 'replyTo' => ml_competition($TourId)['contactEmail'],
		'subject' => $subjectPrefix ? str_replace('{subject}', $m['subject'], $subjectPrefix) : $m['subject'],
		'text' => $m['text'], 'html' => $m['html'], 'lang' => $lang,
	), $mailer, $debug);
	if ($mailer) $mailer->smtpClose();
	if ($err === '' and $markTested) {
		$s = ml_param_get(0, 'smtp', array());
		$s['tested'] = ml_smtp_hash(ml_smtp());
		ml_param_set(0, 'smtp', $s);
	}
	return array('ok' => $err === '', 'error' => $err, 'log' => $debug);
}

function ml_test($TourId, $to, $lang) {
	$s = ml_smtp();
	$vars = array(
		'smtp.host' => $s['host'] . ':' . $s['port'],
		'smtp.from' => $s['fromEmail'],
		'sentAt' => ml_format_date(date('Y-m-d H:i:s'), $lang),
	);
	return ml_send_direct($TourId, 'mailer.test', ml_texts($TourId, 'mailer.test', $lang), $vars, $to, $lang, true);
}

// ---------- outbox (admin) ----------

function ml_outbox($TourId, $limit = 200) {
	$out = array();
	$q = safe_r_sql("select MoId, MoModule, MoTemplate, MoTo, MoSubject, MoStatus, MoTries, MoError, MoCreated, MoSent
		from MailerOutbox where MoTournament=" . intval($TourId) . " order by MoId desc limit " . intval($limit));
	while ($r = safe_fetch($q)) {
		$out[] = array(
			'id' => intval($r->MoId), 'module' => $r->MoModule, 'template' => $r->MoTemplate, 'to' => $r->MoTo,
			'subject' => $r->MoSubject, 'status' => intval($r->MoStatus), 'tries' => intval($r->MoTries), 'error' => $r->MoError,
			'created' => date('c', strtotime($r->MoCreated)), 'sent' => $r->MoSent ? date('c', strtotime($r->MoSent)) : null,
		);
	}
	return $out;
}

function ml_outbox_counts($TourId) {
	$c = array(ML_QUEUED => 0, ML_SENT => 0, ML_FAILED => 0, ML_SENDING => 0);
	$q = safe_r_sql("select MoStatus, count(*) as n from MailerOutbox where MoTournament=" . intval($TourId) . " group by MoStatus");
	while ($r = safe_fetch($q)) $c[intval($r->MoStatus)] = intval($r->n);
	return array('queued' => $c[ML_QUEUED] + $c[ML_SENDING], 'sent' => $c[ML_SENT], 'failed' => $c[ML_FAILED]);
}

function ml_outbox_html($TourId, $id) {
	$r = safe_fetch(safe_r_sql("select MoHtml from MailerOutbox where MoTournament=" . intval($TourId) . " and MoId=" . intval($id)));
	return $r ? $r->MoHtml : null;
}

// Failed emails (or any, for "resend") queued again now, with their tries reset.
function ml_retry($TourId, $ids) {
	$ids = array_values(array_filter(array_map('intval', (array)$ids)));
	if (!$ids) return 0;
	safe_w_sql("update MailerOutbox set MoStatus=" . ML_QUEUED . ", MoTries=0, MoError='', MoNextTry=" . StrSafe_DB(date('Y-m-d H:i:s'))
		. " where MoTournament=" . intval($TourId) . " and MoStatus!=" . ML_SENDING . " and MoId in (" . implode(',', $ids) . ")");
	return safe_w_affected_rows();
}

// Removes sent and failed emails older than $days days (personal data are not kept longer than needed).
function ml_purge($TourId, $days) {
	safe_w_sql("delete from MailerOutbox where MoTournament=" . intval($TourId) . " and MoStatus in (" . ML_SENT . ", " . ML_FAILED . ")"
		. " and MoCreated < " . StrSafe_DB(date('Y-m-d H:i:s', time() - 86400 * max(0, intval($days)))));
	return safe_w_affected_rows();
}

// IANSEO's tour_delete() does not know the module table: rows and settings of deleted competitions are removed here.
function ml_purge_orphans() {
	safe_w_sql("delete from MailerOutbox where MoTournament>0 and MoTournament not in (select ToId from Tournament)");
	safe_w_sql("delete from ModulesParameters where MpModule=" . StrSafe_DB(ML_MODULE)
		. " and MpTournament>0 and MpTournament not in (select ToId from Tournament)");
}

// ---------- admin page ----------

function ml_channel() {
	return ml_param_get(0, 'channel', 'stable') === 'nightly' ? 'nightly' : 'stable';
}

function ml_template_list($TourId) {
	$out = array();
	foreach (ml_templates() as $id => $t) {
		$o = ml_override($TourId, $id);
		$out[] = array(
			'id' => $id, 'module' => $t['module'], 'to' => (string)($t['to'] ?? ''),
			'name' => $t['name'], 'description' => $t['description'],
			'custom' => array_keys(array_filter($o['subject'] + $o['body'], 'strlen')),
		);
	}
	return $out;
}

// Everything the editor needs for one template and language.
function ml_template_detail($TourId, $id, $lang) {
	$t = ml_templates()[$id] ?? null;
	if (!$t) return null;
	$texts = ml_texts($TourId, $id, $lang);
	$vars = array();
	foreach (ml_tour_var_labels() + $t['vars'] as $k => $label) $vars[] = array('name' => $k, 'label' => ml_pick_lang($label, ml_lang()));
	return array(
		'id' => $id, 'lang' => $lang, 'subject' => $texts['subject'], 'body' => $texts['body'], 'custom' => $texts['custom'],
		'defaultSubject' => ml_pick_lang($t['subject'], $lang), 'defaultBody' => ml_pick_lang($t['body'], $lang),
		'vars' => $vars,
	);
}

function ml_preview($TourId, $id, $texts, $lang) {
	$tour = ml_tour($TourId);
	$m = ml_render($tour, $texts, ml_sample($id, $lang), $lang, true);
	// images of the preview come from IANSEO's own logo page (the admin has the competition session)
	global $CFG;
	foreach ($m['images'] as $cid => $type) {
		$m['html'] = str_replace('cid:' . $cid, $CFG->ROOT_DIR . 'Common/TourLogo.php?Type=' . $type . '&W=' . ($type === 'B' ? 1200 : 220) . '&H=' . ($type === 'B' ? 400 : 220), $m['html']);
	}
	return array('subject' => $m['subject'], 'text' => $m['text'], 'html' => $m['html']);
}

function ml_context($TourId, $isRoot) {
	$s = ml_smtp();
	$tour = ml_tour($TourId);
	return array(
		'lang' => ml_lang(),
		'tour' => array('code' => $tour->ToCode, 'name' => $tour->ToName, 'lang' => ml_default_lang($tour)),
		'isRoot' => $isRoot,
		'smtp' => array(
			'host' => $s['host'], 'port' => $s['port'], 'security' => $s['security'], 'user' => $isRoot ? $s['user'] : '',
			'hasPassword' => $s['password'] !== '', 'fromEmail' => $s['fromEmail'], 'fromName' => $s['fromName'],
		),
		'configured' => ml_configured(),
		'ready' => ml_ready(),
		'competition' => ml_competition($TourId),
		'templates' => ml_template_list($TourId),
		'counts' => ml_outbox_counts($TourId),
		'outbox' => ml_outbox($TourId),
		'channel' => ml_channel(),
		'version' => ml_version_info()['version'],
		'gd' => function_exists('imagecreatefromstring'),
		'cron' => 'php ' . __DIR__ . '/cron.php',
	);
}

// ---------- updates from GitHub releases (same mechanism as the other yet-another-quentin modules) ----------

function ml_http($url, $accept = '*/*') {
	$ua = 'IANSEO-Mailer';
	if (function_exists('curl_init')) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
			CURLOPT_HTTPHEADER => array('User-Agent: ' . $ua, 'Accept: ' . $accept),
		));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($body === false) return array(0, '', $err ?: ml_t('connection'));
		return array($code, $body, $code >= 400 ? "HTTP $code" : '');
	}
	$context = stream_context_create(array('http' => array(
		'header' => "User-Agent: $ua\r\nAccept: $accept\r\n", 'timeout' => 60, 'ignore_errors' => true, 'follow_location' => 1,
	)));
	$body = @file_get_contents($url, false, $context);
	if ($body === false) return array(0, '', ml_t('connectionFopen'));
	$code = 0;
	if (!empty($http_response_header)) {
		foreach ($http_response_header as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = intval($m[1]);
	}
	return array($code, $body, $code >= 400 ? "HTTP $code" : '');
}

function ml_is_update($channel, $latest, $current) {
	if ($latest === '' or $latest === $current) return false;
	if ($channel === 'nightly') return strpos($current, '-nightly') !== false or version_compare($latest, $current, '>');
	return version_compare($latest, $current, '>');
}

function ml_update_check($force = false) {
	$info = ml_version_info();
	$channel = ml_channel();
	$cache = ml_param_get(0, 'updateCheck', array());
	if (!$force and !empty($cache['at']) and time() - $cache['at'] < 86400
		and ($cache['current'] ?? '') === $info['version'] and ($cache['channel'] ?? '') === $channel) {
		return $cache;
	}
	$res = array('at' => time(), 'channel' => $channel, 'current' => $info['version'], 'latest' => '', 'notes' => '', 'asset' => '', 'url' => '', 'error' => '');
	if (empty($info['repo'])) {
		$res['error'] = ml_t('repoMissing');
	} else {
		$api = rtrim($info['api'] ?? 'https://api.github.com', '/');
		$path = $channel === 'nightly' ? '/releases/tags/nightly' : '/releases/latest';
		list($code, $body, $err) = ml_http($api . '/repos/' . $info['repo'] . $path, 'application/vnd.github+json');
		$j = $err ? null : json_decode($body, true);
		if ($code == 404) {
			$res['error'] = ml_t('noRelease');
		} elseif (!$j or empty($j['tag_name'])) {
			$res['error'] = $err ?: ml_t('githubUnexpected');
		} else {
			$res['notes'] = (string)($j['body'] ?? '');
			$res['url'] = (string)($j['html_url'] ?? '');
			foreach ((array)($j['assets'] ?? array()) as $a) {
				if (($a['name'] ?? '') === $info['asset']) $res['asset'] = $a['browser_download_url'];
			}
			$res['latest'] = $channel === 'nightly' ? trim((string)($j['name'] ?? '')) : ltrim($j['tag_name'], 'vV');
		}
	}
	$res['available'] = ($res['asset'] !== '' and ml_is_update($channel, $res['latest'], $info['version']));
	ml_param_set(0, 'updateCheck', $res);
	return $res;
}

function ml_rrmdir($dir) {
	if (!is_dir($dir)) return;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
		$f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
	}
	@rmdir($dir);
}

function ml_rcopy($src, $dst) {
	if (!is_dir($dst) and !@mkdir($dst, 0775, true)) return false;
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($it as $f) {
		$target = $dst . DIRECTORY_SEPARATOR . $it->getSubPathName();
		if ($f->isDir()) {
			if (!is_dir($target) and !@mkdir($target, 0775, true)) return false;
		} elseif (!@copy($f->getPathname(), $target)) {
			return false;
		}
	}
	return true;
}

function ml_update_install() {
	$check = ml_update_check(true);
	if (!$check['available']) return array('error' => $check['error'] ?: ml_t('noUpdate'));
	$manual = ml_t('manual', array('asset' => $check['asset']));
	if (!class_exists('ZipArchive')) return array('error' => ml_t('zipMissing') . $manual);
	$dir = __DIR__;
	if (!is_writable($dir)) return array('error' => ml_t('notWritable') . $manual);

	list($code, $body, $err) = ml_http($check['asset'], 'application/octet-stream');
	if ($err or !$body) return array('error' => ml_t('downloadFailed', array('error' => $err)) . $manual);

	$tmp = tempnam(sys_get_temp_dir(), 'ml');
	$extracted = $tmp . '-new';
	$backup = $tmp . '-backup';
	file_put_contents($tmp, $body);
	$cleanup = function () use ($tmp, $extracted, $backup) { @unlink($tmp); ml_rrmdir($extracted); ml_rrmdir($backup); };

	$zip = new ZipArchive();
	if ($zip->open($tmp) !== true) { $cleanup(); return array('error' => ml_t('zipUnreadable') . $manual); }
	$hasVersion = false;
	for ($i = 0; $i < $zip->numFiles; $i++) {
		$name = str_replace('\\', '/', $zip->getNameIndex($i));
		if (strpos($name, '..') !== false or substr($name, 0, strlen(ML_MODULE) + 1) !== ML_MODULE . '/') {
			$zip->close(); $cleanup();
			return array('error' => ml_t('zipRefused', array('name' => $name)));
		}
		if ($name === ML_MODULE . '/version.json') $hasVersion = true;
	}
	if (!$hasVersion or !$zip->extractTo($extracted)) { $zip->close(); $cleanup(); return array('error' => ml_t('zipIncomplete') . $manual); }
	$zip->close();

	$new = @json_decode(@file_get_contents($extracted . '/' . ML_MODULE . '/version.json'), true);
	if (empty($new['version'])) { $cleanup(); return array('error' => ml_t('versionInvalid')); }

	if (!ml_rcopy($dir, $backup)) { $cleanup(); return array('error' => ml_t('backupFailed') . $manual); }
	if (!ml_rcopy($extracted . '/' . ML_MODULE, $dir)) {
		ml_rcopy($backup, $dir);
		$cleanup();
		return array('error' => ml_t('copyFailed') . $manual);
	}
	$cleanup();
	if (function_exists('opcache_reset')) @opcache_reset();
	ml_param_set(0, 'updateCheck', array());
	return array('ok' => true, 'version' => $new['version']);
}
