# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

IANSEO module (archery competition management software) that sends emails for **other modules**: SMTP settings for the whole installation, templates (French/English) that each module declares and the organiser can customise per competition, an outbox with retries, and a configuration test. Code, identifiers and comments are in English; the admin UI is translated French/English (`Mailer/i18n.js`, `ml_t()` in `lib.php`). Other IANSEO modules of the same author follow the same conventions (layout, releases, self-update).

## Commands

```sh
php tests/core.test.php                       # rendering, escaping, dates (pure logic of core.php)
php tests/templates.test.php [file]           # checks a mailer-templates.php (Mailer's own by default)
node --test tests/*.test.js                   # translations, nightly versioning
for f in Mailer/*.php; do php -l "$f"; done
```

No local PHP? Use `docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/core.test.php`. To check another module's templates: add `-v /path/to/Module:/m` and pass `/m/mailer-templates.php`.

Dev IANSEO in `dev/`:

```sh
# IANSEO_DIR = a COPY of the IANSEO sources (seed.sh writes its Common/config.inc.php).
# Optional: EXTRA_MODULE_DIR=/path/to/MyModule EXTRA_MODULE_NAME=MyModule mounts a module that uses Mailer.
cd dev && IANSEO_DIR=/path/to/copy docker compose up -d --build && IANSEO_DIR=/path/to/copy ./seed.sh
```

- IANSEO runs on http://localhost:8090/.
- Mailpit catches every email: SMTP `mailpit:1025`, no security, inbox on http://localhost:8025/ (its API `/api/v1/messages` is handy in tests).
- `seed.sh` creates competition TEST26 (venue, logos, banner for the email header) and points Mailer's SMTP to Mailpit (not tested: click « Send a test »).
- Reference IANSEO sources are in `../ianseo`, read-only.

## Architecture

**Public contract: `Mailer/api.php`** (prefix `mailer_`, constant `MAILER_API`).
- It is the only thing other modules may use; everything `ml_*` is internal.
- Including it has no side effect.
- Functions: `mailer_available()`, `mailer_queue($template, $TourId, $to, $vars, $opts)`, `mailer_contact()`, `mailer_default_lang()`, `mailer_format_date()`, `mailer_admin_path()`.
- Compatibility rule: only additions (bump `MAILER_API`), never change an existing signature or meaning.
- Callers load it as an optional dependency (`is_file(dirname(__DIR__) . '/Mailer/api.php')`), see the README example.

**Templates are declared by the modules themselves.**
- Each module declares them in `<module>/mailer-templates.php`, discovered by `ml_templates()` (`glob Modules/Custom/*/mailer-templates.php`).
- Format: documented at the top of `Mailer/mailer-templates.php`.
- Ids are `<prefix>.<name>`, at most 26 characters, because they are stored as `tpl:<id>` in `MpParameter`, limited to 30.
- Mailer only ships `mailer.test`.
- Customisations are stored per competition (`tpl:<id>`, per language). A text equal to the default is not stored, so later improvements of the defaults apply.

**Rendering (`core.php`, pure)**:
- Syntax: `{var}`, `{?var}…{/var}`, `**bold**`, `- ` lists, blank line = paragraph, links in the template text.
- The HTML version escapes the template and applies its markup **before** inserting the variables. Values are therefore always escaped text and can never add markup.
- Only variables named `*Url` become links: a visitor's text (comment) must never put a clickable link in an email sent from the club's address.
- Subjects are single-line (no header injection).
- Keep the tests in `tests/core.test.php` when touching this.

**Layout (`ml_layout()`)**:
- Table-based HTML with inline styles: competition logos `ToImgL`/`ToImgR`, name, organiser, dates, place, then the body, then the banner `ToImgB`.
- Images are `cid:ml-L|R|B`, embedded at sending time from the competition, re-encoded as PNG by GD.
- Previews and the outbox viewer replace the cids with IANSEO's `Common/TourLogo.php` (admin session). They render in an iframe `sandbox="allow-same-origin"`, so no script can run.

**Outbox (`MailerOutbox`, created by `ml_ensure_schema()`)**:
- `ml_queue()` renders immediately and stores subject/text/HTML.
- Sending happens at shutdown of the same request (`ml_flush_at_shutdown()`): with PHP-FPM after `fastcgi_finish_request()`; otherwise after flushing, which needs the caller's response to send `Content-Length`. The visitor never waits for SMTP.
  - The session is closed first (`session_write_close()`): PHP unlocks it only after the shutdown functions, so the user's next request would otherwise wait for the emails.
  - Competition images are re-encoded once per request (`ml_tour_image()` cache).
- `ml_flush()` holds `GET_LOCK('mailer_flush')`. Retries: 4 tries, after 5/20/45 minutes.
- Rows left "sending" for 15 minutes go back to the queue.
- Further sending points: the admin page (`flush` on load) and `cron.php` (CLI only).
- `ml_purge_orphans()` removes rows of deleted competitions, because IANSEO's `tour_delete()` does not know the table.

**SMTP (`ml_smtp()`, installation-wide, `MpTournament = 0`)**:
- Sending requires `ml_ready()`: configured **and** a test that succeeded with the current settings (`tested` = hash of host/port/security/user/password/from).
- The password is never sent to the page and is not part of competition exports.
- PHPMailer 7.1.1 (LGPL) is vendored in `Mailer/vendor/phpmailer` (only `src/` files, its licence and its French messages).
- The SMTP dialogue of a test is returned with the credentials masked.

**Per competition (`ml_competition()`)**:
- Sender **name** and contact (name + email).
- The contact is the default Reply-To and the address for organiser notifications (`mailer_contact()`).
- The sender address cannot change per competition: SPF/DMARC.

**Admin**:
- `index.php` + `admin.js` + `admin.css` + `ajax.php` (JSON bodies only). The name is not `api.php`: that one is the contract.
- Rights: `AclCompetition`; SMTP and updates: `AclRoot`.
- `admin.js` re-renders through `innerHTML` with `esc()`/`th()`, except while typing in the template editor (only the preview iframe is refreshed).
- `menu.php`: Competition menu.

## Persistence

- `ModulesParameters`, module `Mailer`. Values are JSON strings stored serialized (`ml_param_get/set`, direct SQL; TourId 0 = installation).
- Installation: `smtp`, `channel`, `updateCheck`, `schema`.
- Competition: `competition`, `tpl:<id>`.

## Releases and self-update

Same as the sister modules:
- a `vX.Y.Z` tag runs `release.yml`; `nightly.yml` publishes the `nightly` pre-release;
- `version.json` stays `0.0.0-dev` in the repo;
- the archive contains only `Mailer/…` with `Mailer/version.json`;
- the GitHub repo `yet-another-quentin/ianseo-mailer` must be public.

## Git

- Commit messages: [gitmoji](https://gitmoji.dev/) prefix (Unicode emoji), English, a single line, no `Co-Authored-By`.
- Commits are GPG-signed. If signing fails because pinentry cannot prompt, ask the user to run the commit.
