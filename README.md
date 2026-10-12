# Emails for IANSEO (Mailer)

[Français](#français) · English

IANSEO module that sends emails on behalf of other modules: online registration acknowledgements, validations, notifications…

- **One SMTP server for the whole installation**: your club's email host or a sending service (Brevo, Mailjet, OVH…). Set up by an IANSEO administrator.
  - **Configuration test**: a "Send a test" button sends a test email and shows the SMTP dialogue when it fails.
  - No email is sent until a test succeeds with the current settings.
- **Per competition**: sender name, and contact (replies of archers, organiser notifications).
- **Templates in French and English**, provided by each module:
  - customisable per competition, with a live preview and variables to click;
  - "send this preview" button and back to the default template;
  - emails in HTML with the competition's logos, name, dates and venue, and its bottom banner, plus a text version.
- **Outbox**: emails are sent right after the page answers, so the visitor never waits. Failures are retried automatically. The history shows each email, with view, resend and purge.
- **For developers**: a small, versioned PHP contract (`Mailer/api.php`) and a template file per module.

Templates included today: configuration test (Mailer) and, with [online self-registration](https://github.com/yet-another-quentin/ianseo-self-registration):
- acknowledgement;
- validation;
- refusal;
- notification of the organiser.

## Installation

1. Download `Mailer.zip` from the [latest release](https://github.com/yet-another-quentin/ianseo-mailer/releases/latest).
2. Unzip it into IANSEO's `Modules/Custom/` folder, so that you get `Modules/Custom/Mailer/index.php`.
3. Open a competition: **Competition › Emails**. An administrator enters the SMTP server, then clicks **Send a test**.

Requirements: PHP `openssl` (for TLS) and `gd` (logos in emails), and the SMTP server reachable from the IANSEO server.

Optional: a cron task to retry failed emails even when nobody opens the page:

```sh
*/5 * * * * php /path/to/ianseo/Modules/Custom/Mailer/cron.php
```

## For module developers

```php
// optional dependency: works without Mailer
function mymod_mailer() {
    $f = dirname(__DIR__) . '/Mailer/api.php';
    if (!is_file($f)) return false;
    require_once($f);
    return MAILER_API >= 1 and mailer_available();
}

if (mymod_mailer()) {
    mailer_queue('mymod.welcome', $TourId, $email, array('archer.firstName' => 'Jean'), array('lang' => 'fr'));
}
```

Declare the templates in `<your module>/mailer-templates.php`. The format is documented in `Mailer/mailer-templates.php`. Check the file with `php tests/templates.test.php path/to/mailer-templates.php`.

Template syntax:
- `{variable}`;
- `{?variable}shown if filled{/variable}`;
- `**bold**`;
- `- ` list;
- blank line = paragraph;
- links.

`competition.*` variables (name, dates, place, organiser, contact…) are always available. Values are always escaped, and only variables named `…Url` become links.

## Development

```sh
php tests/core.test.php && php tests/templates.test.php && node --test tests/*.test.js

# Test IANSEO on http://localhost:8090/, emails caught by Mailpit on http://localhost:8025/
cd dev && IANSEO_DIR=/path/to/ianseo-copy docker compose up -d --build && IANSEO_DIR=/path/to/ianseo-copy ./seed.sh
```

PHPMailer 7.1.1 (LGPL 2.1) is included in `Mailer/vendor/phpmailer`.

---

## Français

Module IANSEO qui envoie les emails des autres modules : accusés de réception des inscriptions en ligne, validations, notifications…

- **Un serveur SMTP pour toute l'installation** : messagerie du club ou service d'envoi (Brevo, Mailjet, OVH…). Réglé par un administrateur IANSEO.
  - **Test de configuration** : le bouton « Envoyer un test » envoie un email de test et affiche le dialogue SMTP en cas d'échec.
  - Aucun email ne part tant qu'un test n'a pas réussi avec les réglages actuels.
- **Par compétition** : nom de l'expéditeur, et contact (réponses des archers, notifications de l'organisateur).
- **Modèles en français et en anglais**, fournis par chaque module :
  - personnalisables par compétition, avec aperçu en direct et variables à cliquer ;
  - bouton « Envoyer cet aperçu » et retour au modèle par défaut ;
  - emails en HTML avec les logos, le nom, les dates et le lieu de la compétition, et son bandeau du bas, plus une version texte.
- **File d'envoi** : les emails partent juste après la réponse de la page, le visiteur n'attend donc jamais. Les échecs sont réessayés automatiquement. L'historique affiche chaque email, avec consultation, renvoi et purge.

### Installation

1. Télécharger `Mailer.zip` depuis la [dernière release](https://github.com/yet-another-quentin/ianseo-mailer/releases/latest).
2. Décompresser l'archive dans le dossier `Modules/Custom/` d'IANSEO, pour obtenir `Modules/Custom/Mailer/index.php`.
3. Ouvrir une compétition : **Compétition › Emails**. Un administrateur saisit le serveur SMTP, puis clique sur **Envoyer un test**.

Pour réessayer les emails en échec même sans ouvrir la page, ajouter une tâche cron : voir la partie anglaise.
