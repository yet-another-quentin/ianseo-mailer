// Translations and release versioning: node --test tests/*.test.js
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const I = require('../Mailer/i18n.js');
const { nightlyVersion } = require('../scripts/nightly-version.js');

test('French and English dictionaries have the same keys', () => {
  assert.deepEqual(Object.keys(I.DICTS.fr).sort(), Object.keys(I.DICTS.en).sort());
});

test('every message key used by admin.js exists', () => {
  const code = fs.readFileSync(path.join(__dirname, '../Mailer/admin.js'), 'utf8');
  const used = new Set();
  for (const m of code.matchAll(/\b(?:th|t)\('([a-z]+\.[\w.]+)'/g)) used.add(m[1]);
  for (const m of code.matchAll(/\[\s*'\w*',\s*'([a-z]+\.\w+)'\s*\]/g)) used.add(m[1]);
  const runtime = [
    ...['queued', 'sent', 'failed'].map(s => 'outbox.st.' + s),
    ...['archer', 'organiser', 'admin'].map(s => 'tpl.to.' + s),
    'update.channel.stable', 'update.channel.nightly',
  ];
  const missing = [...used, ...runtime].filter(k => !k.endsWith('.') && !(k in I.DICTS.en));
  assert.deepEqual(missing, []);
});

test('language follows IANSEO', () => {
  assert.equal(I.make('fr-FR').lang, 'fr');
  assert.equal(I.make('it').lang, 'en');
});

test('nightly version follows the last stable tag', () => {
  assert.equal(nightlyVersion('v1.2.3', '20261004', 'abc1234'), '1.2.4-nightly.20261004.abc1234');
});
