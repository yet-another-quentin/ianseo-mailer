<?php
// Minimal test helpers for the PHP tests (no dependency).
$GLOBALS['failures'] = 0;
$GLOBALS['count'] = 0;

function check($name, $actual, $expected) {
	$GLOBALS['count']++;
	if ($actual !== $expected) {
		$GLOBALS['failures']++;
		echo "FAIL $name\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
	}
}

function done() {
	$f = $GLOBALS['failures'];
	$c = $GLOBALS['count'];
	echo $f ? "\n$f/$c failed\n" : "$c passed\n";
	exit($f ? 1 : 0);
}
