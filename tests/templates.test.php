<?php
/*
 * Checks a mailer-templates.php file (Mailer's own by default, or the one given as argument — other modules can
 * run this test on theirs): php tests/templates.test.php [path/to/mailer-templates.php]
 */
require __DIR__ . '/../Mailer/core.php';
require __DIR__ . '/lib.php';

$file = $argv[1] ?? __DIR__ . '/../Mailer/mailer-templates.php';
$defs = include($file);
check("$file returns an array", is_array($defs), true);
$common = array('competition.name', 'competition.code', 'competition.dates', 'competition.place', 'competition.venue',
	'competition.town', 'competition.organiser', 'competition.contactName', 'competition.contactEmail');

foreach ((array)$defs as $id => $d) {
	check("$id: id format", (bool)preg_match('/^[a-z][a-z0-9]*\.[a-zA-Z0-9]+$/', $id) && strlen($id) <= 26, true);
	foreach (array('name', 'description', 'subject', 'body') as $k) {
		check("$id: $k in fr and en", isset($d[$k]['fr'], $d[$k]['en']) && $d[$k]['fr'] !== '' && $d[$k]['en'] !== '', true);
	}
	check("$id: recipient", in_array($d['to'] ?? '', array('archer', 'organiser', 'admin'), true), true);
	$declared = array_merge($common, array_keys($d['vars'] ?? array()));
	check("$id: every variable has a sample", array_values(array_diff(array_keys($d['vars'] ?? array()), array_keys($d['sample'] ?? array()))), array());
	foreach (array('fr', 'en') as $lang) {
		$text = $d['subject'][$lang] . "\n" . $d['body'][$lang];
		preg_match_all('/\{\??\/?(' . ML_VAR . ')\}/', $text, $m);
		check("$id/$lang: variables declared", array_values(array_unique(array_diff($m[1], $declared))), array());
		// blocks are balanced
		preg_match_all('/\{\?(' . ML_VAR . ')\}/', $text, $open);
		preg_match_all('/\{\/(' . ML_VAR . ')\}/', $text, $close);
		check("$id/$lang: blocks balanced", $open[1], $close[1]);
		// sample rendering keeps no unknown variable
		$sample = array();
		foreach ($declared as $v) $sample[$v] = 'x';
		check("$id/$lang: renders", strpos(ml_render_text($d['body'][$lang], $sample), '{') === false, true);
	}
}

done();
