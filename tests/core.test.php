<?php
// Pure logic tests, without IANSEO or database: php tests/core.test.php
require __DIR__ . '/../Mailer/core.php';
require __DIR__ . '/lib.php';

// ---------- addresses ----------
check('email ok', ml_valid_email('jean.dupont+arc@club.fr'), true);
check('email header injection', ml_valid_email("a@b.fr\r\nBcc: x@y.fr"), false);
check('email list', ml_valid_email('a@b.fr,c@d.fr'), false);
check('email invalid', ml_valid_email('nope'), false);

// ---------- languages ----------
check('lang picked', ml_pick_lang(array('fr' => 'Bonjour', 'en' => 'Hello'), 'en'), 'Hello');
check('lang fallback fr', ml_pick_lang(array('fr' => 'Bonjour', 'en' => ''), 'en'), 'Bonjour');
check('lang string', ml_pick_lang('Salut', 'en'), 'Salut');

// ---------- subject ----------
$vars = array('archer.firstName' => 'Jean', 'reference' => 'AB12', 'empty' => '');
check('subject vars', ml_render_subject('Inscription {reference} – {archer.firstName}', $vars), 'Inscription AB12 – Jean');
check('subject no header injection', ml_render_subject('Hi {x}', array('x' => "a\r\nBcc: evil@x.fr")), 'Hi a Bcc: evil@x.fr');
check('subject unknown removed', ml_render_subject('A {nope} B', $vars), 'A B');
check('subject unknown kept in preview', ml_render_subject('A {nope} B', $vars, true), 'A {nope} B');

// ---------- conditional blocks ----------
check('block shown', ml_render_text("A{?reference} ref {reference}{/reference}", $vars), "A ref AB12\n");
check('block hidden', ml_render_text("A{?empty} x {empty}{/empty}B", $vars), "AB\n");
check('block multiline hidden', ml_render_text("A\n{?empty}\n- x\n{/empty}\nB", $vars), "A\n\nB\n");

// ---------- text version ----------
check('text bold removed', ml_render_text("**Bonjour** {archer.firstName}", $vars), "Bonjour Jean\n");

// ---------- HTML version ----------
check('html paragraphs', ml_render_html("Bonjour {archer.firstName},\n\nMerci.", $vars), "<p>Bonjour Jean,</p>\n<p>Merci.</p>");
check('html line break', ml_render_html("A\nB", $vars), "<p>A<br>\nB</p>");
check('html list', ml_render_html("- un\n- deux", $vars), '<ul><li>un</li><li>deux</li></ul>');
check('html bold', ml_render_html('**Réf.** {reference}', $vars), '<p><strong>Réf.</strong> AB12</p>');
check('html link in template', ml_render_html('Voir https://ianseo.net/x?a=1&b=2.', $vars),
	'<p>Voir <a href="https://ianseo.net/x?a=1&amp;b=2">https://ianseo.net/x?a=1&amp;b=2</a>.</p>');
check('html value escaped', ml_render_html('{c}', array('c' => '<script>alert(1)</script>')), '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
check('html value cannot add markup', ml_render_html('{c}', array('c' => '**x** - y')), '<p>**x** - y</p>');
check('html value lines kept', ml_render_html('{c}', array('c' => "L1\nL2")), "<p>L1<br>\nL2</p>");
check('html *Url value is a link', ml_render_html('{formUrl}', array('formUrl' => 'https://a.fr/r?c=T&x=1')),
	'<p><a href="https://a.fr/r?c=T&amp;x=1">https://a.fr/r?c=T&amp;x=1</a></p>');
check('html other value never a link', ml_render_html('{comment}', array('comment' => 'voir https://evil.example')),
	'<p>voir https://evil.example</p>');
check('html url never swallows a variable', ml_render_html('https://a.fr/{u}', array('u' => '"><img src=x>')),
	'<p><a href="https://a.fr/">https://a.fr/</a>&quot;&gt;&lt;img src=x&gt;</p>');
check('html *Url value with quote', ml_render_html('{adminUrl}', array('adminUrl' => 'https://a.fr/"onmouseover="x')),
	'<p><a href="https://a.fr/">https://a.fr/</a>&quot;onmouseover=&quot;x</p>');
check('html unknown kept escaped in preview', ml_render_html('{nope}', $vars, true), '<p>{nope}</p>');
check('html template tags escaped', ml_render_html('<b>x</b>', $vars), '<p>&lt;b&gt;x&lt;/b&gt;</p>');

// ---------- dates and places ----------
check('date fr', ml_format_date('2026-12-12', 'fr'), '12 décembre 2026');
check('date en weekday time', ml_format_date('2026-12-12 10:00:00', 'en', true), 'Saturday 12 December 2026 at 10:00');
check('date fr weekday time', ml_format_date('2026-12-13 15:30:00', 'fr', true), 'dimanche 13 décembre 2026 à 15:30');
check('date midnight no time', ml_format_date('2026-12-12 00:00:00', 'fr'), '12 décembre 2026');
check('date empty', ml_format_date('0000-00-00', 'fr'), '');
check('range same day', ml_format_range('2026-12-12', '2026-12-12', 'fr'), '12 décembre 2026');
check('range same month', ml_format_range('2026-12-12', '2026-12-13', 'fr'), '12–13 décembre 2026');
check('range two months', ml_format_range('2026-11-30', '2026-12-01', 'en'), '30 November – 1 December 2026');
check('place', ml_place('Gymnase Bourrié', 'Cestas'), 'Gymnase Bourrié, Cestas');
check('place town in venue', ml_place('Gymnase de Cestas', 'Cestas'), 'Gymnase de Cestas');
check('place town only', ml_place('', 'Cestas'), 'Cestas');

done();
