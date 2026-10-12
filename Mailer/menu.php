<?php
// Menu entry in Competition, for users who can edit the competition.
// Included from both get_which_menu() and get_which_run_menu(); in the latter $acl and $ret['COMP'] may not exist.
if (!empty($on) and isset($ret['COMP'], $acl) and subFeatureAcl($acl, AclCompetition, '') == AclReadWrite) {
	require_once(__DIR__ . '/lib.php');
	$ret['COMP'][] = MENU_DIVIDER;
	$ret['COMP'][] = ml_t('menu') . '|' . $CFG->ROOT_DIR . 'Modules/Custom/Mailer/index.php';
}
