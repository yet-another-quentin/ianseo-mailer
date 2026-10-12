<?php
/*
 * Templates provided by the Mailer module itself.
 *
 * Any module in Modules/Custom/ can provide templates with a file named mailer-templates.php returning the same
 * structure; Mailer discovers them. Template ids: "<module prefix>.<name>", at most 26 characters.
 *   name, description:  shown in the template editor, per language
 *   to:                 who receives it (informative): archer | organiser | admin
 *   vars:               variables the module provides, with their label per language (the editor lists them);
 *                       competition.* variables are always available (see ml_tour_vars())
 *   sample:             values used for previews (a value may be an array per language)
 *   subject, body:      default texts per language (syntax: see core.php)
 */
return array(
	'mailer.test' => array(
		'name' => array('fr' => 'Test de configuration', 'en' => 'Configuration test'),
		'description' => array(
			'fr' => 'Envoyé par le bouton « Envoyer un test » pour vérifier la configuration SMTP.',
			'en' => 'Sent by the "Send a test" button to check the SMTP configuration.',
		),
		'to' => 'admin',
		'vars' => array(
			'smtp.host' => array('fr' => 'Serveur SMTP', 'en' => 'SMTP server'),
			'smtp.from' => array('fr' => 'Adresse d\'expédition', 'en' => 'Sender address'),
			'sentAt' => array('fr' => 'Date et heure d\'envoi', 'en' => 'Date and time sent'),
		),
		'sample' => array('smtp.host' => 'smtp.example.org:587', 'smtp.from' => 'ianseo@example.org', 'sentAt' => array('fr' => '12 décembre 2026 à 10:00', 'en' => '12 December 2026 at 10:00')),
		'subject' => array(
			'fr' => 'Test d\'envoi d\'email – {competition.name}',
			'en' => 'Email test – {competition.name}',
		),
		'body' => array(
			'fr' => "Bonjour,\n\nCet email confirme que la configuration d'envoi d'IANSEO fonctionne.\n\n- Serveur : {smtp.host}\n- Expéditeur : {smtp.from}\n- Envoyé le : {sentAt}\n\nLes emails des compétitions partiront avec cette configuration.",
			'en' => "Hello,\n\nThis email confirms that IANSEO's email configuration works.\n\n- Server: {smtp.host}\n- Sender: {smtp.from}\n- Sent: {sentAt}\n\nCompetition emails will be sent with this configuration.",
		),
	),
);
