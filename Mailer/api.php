<?php
/*
 * Mailer — PUBLIC CONTRACT for other IANSEO modules. Only the functions below are supported; everything else
 * (ml_*) is internal and may change. Including this file has no side effect (no output, no session, no SQL).
 *
 * Usage from another module, as an optional dependency:
 *
 *   function mymod_mailer() {
 *       $f = dirname(__DIR__) . '/Mailer/api.php';
 *       if (!is_file($f)) return false;
 *       require_once($f);
 *       return MAILER_API >= 1 and mailer_available();
 *   }
 *   if (mymod_mailer()) mailer_queue('mymod.welcome', $TourId, $email, array('archer.firstName' => 'Jean'), array('lang' => 'fr'));
 *
 * Templates: declare them in <your module>/mailer-templates.php (format: see Mailer/mailer-templates.php).
 * The organiser can customise them per competition in Competition › Emails; the module only provides variables.
 *
 * Compatibility: MAILER_API is increased only for additions; existing functions keep their signature and meaning.
 */

if (!defined('MAILER_API')) {
	define('MAILER_API', 1);

	require_once(__DIR__ . '/lib.php');

	/**
	 * True when emails can be sent: SMTP configured and a configuration test succeeded with the current settings.
	 */
	function mailer_available() {
		try {
			return ml_ready();
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * Queues an email built from a template. It is sent at the end of the current request (after the answer
	 * reached the browser), retried later on failure. Never throws.
	 *
	 * @param string $template  template id, e.g. 'selfreg.received'
	 * @param int    $TourId    competition (header, logos, competition.* variables, customised texts)
	 * @param string $to        one email address
	 * @param array  $vars      template variables, name => text (values are escaped in the HTML version)
	 * @param array  $opts      lang ('fr'|'en', default: competition language), replyTo (default: competition
	 *                          contact), ref (your reference, max 64 chars, e.g. a request id), module
	 * @return int|false        outbox id, or false (not available, invalid address or unknown template)
	 */
	function mailer_queue($template, $TourId, $to, $vars = array(), $opts = array()) {
		try {
			return ml_queue((string)$template, intval($TourId), trim((string)$to), (array)$vars, (array)$opts);
		} catch (Throwable $e) {
			error_log('Mailer: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Contact of the competition (Competition › Emails): ['name' => …, 'email' => …], email '' when not set.
	 * Use it to notify the organiser.
	 */
	function mailer_contact($TourId) {
		try {
			$c = ml_competition(intval($TourId));
			$tour = ml_tour(intval($TourId));
			return array('name' => $c['contactName'] ?: ($tour ? (string)$tour->ToComDescr : ''), 'email' => $c['contactEmail']);
		} catch (Throwable $e) {
			return array('name' => '', 'email' => '');
		}
	}

	/**
	 * Language of the competition's emails when the recipient's is unknown ('fr' | 'en'), e.g. for the organiser.
	 */
	function mailer_default_lang($TourId) {
		try {
			return ml_default_lang(ml_tour(intval($TourId)));
		} catch (Throwable $e) {
			return 'en';
		}
	}

	/**
	 * Formats a date ('Y-m-d' or 'Y-m-d H:i:s') like the emails do: "samedi 12 décembre 2026 à 10:00".
	 */
	function mailer_format_date($date, $lang, $weekday = true) {
		return ml_format_date((string)$date, $lang === 'fr' ? 'fr' : 'en', $weekday);
	}

	/**
	 * URL path of the Mailer administration page (templates, contact, sent emails), for links in your pages.
	 */
	function mailer_admin_path() {
		global $CFG;
		return $CFG->ROOT_DIR . 'Modules/Custom/Mailer/index.php';
	}
}
