<?php
/*
 * Mailer — pure logic, without database or IANSEO: template rendering, text → HTML, dates, addresses.
 * Tested by tests/core.test.php. Internal prefix ml_ (the public contract for other modules is api.php, prefix mailer_).
 *
 * Template syntax (subject and body):
 *   {name}                 variable (dots allowed: {archer.firstName}); unknown variables are kept in previews,
 *                          removed in real emails
 *   {?name}…{/name}        block shown only when the variable is not empty
 *   **bold**               bold (HTML version)
 *   - item                 list item, on its own line
 *   blank line             new paragraph
 *   https://…              link
 * Variable values are always escaped in the HTML version: a value can never add markup, and only variables whose name
 * ends with "Url" (formUrl, adminUrl…) are made clickable.
 */

const ML_VAR = '[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)*';

function ml_valid_email($s) {
	$s = (string)$s;
	return strlen($s) <= 254 and filter_var($s, FILTER_VALIDATE_EMAIL) !== false and !preg_match('/[\r\n,;<>]/', $s);
}

// Template in the wanted language: override (per competition) > default; language > French > English > first one.
function ml_pick_lang($texts, $lang) {
	if (!is_array($texts)) return (string)$texts;
	foreach (array($lang, 'fr', 'en') as $l) {
		if (isset($texts[$l]) and $texts[$l] !== '') return (string)$texts[$l];
	}
	return (string)(reset($texts) ?: '');
}

// {?name}…{/name} blocks (not nested) kept when the variable is not empty, removed otherwise.
function ml_blocks($tpl, $vars) {
	return preg_replace_callback('/\{\?(' . ML_VAR . ')\}(.*?)\{\/\1\}/s', function ($m) use ($vars) {
		return (isset($vars[$m[1]]) and trim((string)$vars[$m[1]]) !== '') ? $m[2] : '';
	}, $tpl);
}

/**
 * Replaces {name} with $map(value) for known variables. Unknown ones: kept when $keepUnknown (previews), else removed.
 */
function ml_replace_vars($tpl, $vars, $map, $keepUnknown) {
	return preg_replace_callback('/\{(' . ML_VAR . ')\}/', function ($m) use ($vars, $map, $keepUnknown) {
		if (array_key_exists($m[1], $vars)) return $map((string)$vars[$m[1]]);
		return $keepUnknown ? $m[0] : '';
	}, $tpl);
}

// Subject: one line, no control characters (no header injection), at most 200 characters.
function ml_render_subject($tpl, $vars, $keepUnknown = false) {
	$s = ml_replace_vars(ml_blocks($tpl, $vars), $vars, function ($v) { return $v; }, $keepUnknown);
	$s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s));
	return mb_substr(preg_replace('/\s{2,}/u', ' ', $s), 0, 200);
}

// Plain-text version: variables as-is, markup removed (**x** → x), at most two consecutive blank lines.
function ml_render_text($tpl, $vars, $keepUnknown = false) {
	$s = ml_replace_vars(ml_blocks(str_replace("\r", '', $tpl), $vars), $vars, function ($v) { return $v; }, $keepUnknown);
	$s = preg_replace('/\*\*(.+?)\*\*/s', '$1', $s);
	$s = preg_replace("/\n{3,}/", "\n\n", $s);
	return trim($s) . "\n";
}

function ml_h($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * HTML version of the body (inner part, without the layout). The template is escaped and its markup applied
 * BEFORE the variables are inserted, so values are only ever text (escaped, line breaks kept).
 */
function ml_render_html($tpl, $vars, $keepUnknown = false) {
	$tpl = ml_blocks(str_replace("\r", '', $tpl), $vars);
	// protect the placeholders while the template is escaped and its markup applied
	$slots = array();
	$tpl = preg_replace_callback('/\{(' . ML_VAR . ')\}/', function ($m) use (&$slots) {
		$slots[] = $m[1];
		return "\x01" . (count($slots) - 1) . "\x02";
	}, $tpl);

	$html = array();
	foreach (preg_split("/\n\s*\n/", trim($tpl)) as $para) {
		if ($para === '') continue;
		$lines = explode("\n", $para);
		$isList = count(array_filter($lines, function ($l) { return preg_match('/^\s*-\s+/', $l); })) === count($lines);
		if ($isList) {
			$items = array_map(function ($l) { return '<li>' . ml_inline(preg_replace('/^\s*-\s+/', '', $l)) . '</li>'; }, $lines);
			$html[] = '<ul>' . implode('', $items) . '</ul>';
		} else {
			$html[] = '<p>' . implode("<br>\n", array_map('ml_inline', $lines)) . '</p>';
		}
	}
	$html = implode("\n", $html);

	return preg_replace_callback("/\x01(\d+)\x02/", function ($m) use ($slots, $vars, $keepUnknown) {
		$name = $slots[intval($m[1])];
		// values are text: escaped, line breaks kept. Only variables named *Url become links (shown as-is): a value
		// typed by a visitor (comment…) can never put a clickable link in an email sent from the club's address
		if (array_key_exists($name, $vars)) {
			$v = nl2br(ml_h($vars[$name]), false);
			return preg_match('/Url$/', $name) ? ml_links($v) : $v;
		}
		return $keepUnknown ? ml_h('{' . $name . '}') : '';
	}, $html);
}

// Inline markup of a template line: escape, **bold**, links.
function ml_inline($line) {
	$s = ml_h($line);
	$s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
	return ml_links($s);
}

// Links in already escaped text. Placeholder markers (\x01…\x02) end a URL: a variable is never part of a link target.
function ml_links($escaped) {
	// escaped quotes and brackets (&quot; &apos; &lt; &gt;) also end a URL
	$char = '(?:(?!&quot;|&apos;|&#0?39;|&lt;|&gt;)[^\s<>"\'\x01\x02])';
	return preg_replace_callback('~\bhttps?://' . $char . '*(?:(?!&quot;|&apos;|&#0?39;|&lt;|&gt;)[^\s<>"\'\x01\x02.,;:!?)\]])~', function ($m) {
		return '<a href="' . $m[0] . '">' . $m[0] . '</a>';
	}, $escaped);
}

// ---------- dates ----------

function ml_month($n, $lang) {
	static $months = array(
		'fr' => array('janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'),
		'en' => array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'),
	);
	return $months[$lang === 'fr' ? 'fr' : 'en'][$n - 1];
}

function ml_weekday($n, $lang) {
	static $days = array(
		'fr' => array('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'),
		'en' => array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
	);
	return $days[$lang === 'fr' ? 'fr' : 'en'][$n - 1];
}

// "12 décembre 2026" / "12 December 2026"; with $weekday: "samedi 12 décembre 2026"; with a time: "… à 10:00" / "… at 10:00".
function ml_format_date($dt, $lang, $weekday = false) {
	if (!$dt or substr($dt, 0, 4) === '0000' or ($t = strtotime($dt)) === false) return '';
	$s = intval(date('j', $t)) . ' ' . ml_month(intval(date('n', $t)), $lang) . ' ' . date('Y', $t);
	if ($weekday) $s = ml_weekday(intval(date('N', $t)), $lang) . ' ' . $s;
	if (strlen($dt) > 10 and date('H:i', $t) !== '00:00') $s .= ($lang === 'fr' ? ' à ' : ' at ') . date('H:i', $t);
	return $s;
}

// "12 décembre 2026", "12–13 décembre 2026", "30 novembre – 1 décembre 2026"…
function ml_format_range($from, $to, $lang) {
	if (!$to or $to === $from) return ml_format_date($from, $lang);
	$a = strtotime($from);
	$b = strtotime($to);
	if (!$a or !$b) return ml_format_date($from, $lang);
	if (date('Y-m', $a) === date('Y-m', $b)) return date('j', $a) . '–' . ml_format_date($to, $lang);
	if (date('Y', $a) === date('Y', $b)) return date('j', $a) . ' ' . ml_month(intval(date('n', $a)), $lang) . ' – ' . ml_format_date($to, $lang);
	return ml_format_date($from, $lang) . ' – ' . ml_format_date($to, $lang);
}

// Venue and town, without repeating the town when the venue already contains it.
function ml_place($venue, $town) {
	$venue = trim((string)$venue);
	$town = trim((string)$town);
	if ($venue !== '' and $town !== '' and mb_stripos($venue, $town) !== false) return $venue;
	return implode(', ', array_filter(array($venue, $town), 'strlen'));
}
