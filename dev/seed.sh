#!/bin/sh
# Prepares the test IANSEO started by docker-compose.yml: schema, a French indoor competition "TEST26" with venue, logos
# and banner (they appear in the email header), and Mailer's SMTP settings pointing to Mailpit (not tested yet: use
# "Send a test" in Competition › Emails).
# Usage: IANSEO_DIR=/path/to/ianseo-copy ./seed.sh   (from the dev/ folder, containers running)
set -e
: "${IANSEO_DIR:?copy of the IANSEO sources}"
cd "$(dirname "$0")"
URL=${URL:-http://localhost:8090}
sql() { docker compose exec -T db mariadb -uianseo -pianseo ianseo "$@"; }

if [ ! -f "$IANSEO_DIR/Common/config.inc.php" ]; then
  cat > "$IANSEO_DIR/Common/config.inc.php" <<'PHP'
<?php
$CFG->W_HOST = 'db';
$CFG->W_USER = 'ianseo';
$CFG->W_PASS = 'ianseo';
$CFG->R_HOST = 'db';
$CFG->R_USER = 'ianseo';
$CFG->R_PASS = 'ianseo';
$CFG->DB_NAME = 'ianseo';
$CFG->ROOT_DIR = '/';
PHP
fi

until sql -e 'select 1' >/dev/null 2>&1; do sleep 1; done
if ! sql -e 'show tables like "Tournament"' | grep -q Tournament; then
  sql < "$IANSEO_DIR/Install/install.sql"
fi

# IANSEO upgrades its schema when its home page is first opened
curl -s -o /dev/null "$URL/"

if ! sql -N -e "select count(*) from Tournament where ToCode='TEST26'" | grep -q '^[1-9]'; then
  jar=$(mktemp)
  curl -s -c "$jar" -b "$jar" -o /dev/null "$URL/Tournament/index.php?New=1"
  curl -s -c "$jar" -b "$jar" -o /dev/null "$URL/Tournament/index.php?New=1" \
    --data-urlencode 'Command=SAVE' --data-urlencode 'd_ToCode=TEST26' --data-urlencode 'd_ToName=Tir salle Cestas (test)' \
    --data-urlencode 'd_ToCommitee=0333333' --data-urlencode 'd_ToComDescr=Archers de Cestas' --data-urlencode 'd_ToWhere=Cestas' \
    --data-urlencode 'd_Rule=FR' --data-urlencode 'd_ToType=6' --data-urlencode 'd_SubRule=4' --data-urlencode 'd_ToCountry=FRA' \
    --data-urlencode 'd_ToTimeZone=+01:00' --data-urlencode 'd_ToIocCode=FRA' \
    --data-urlencode 'xx_ToWhenFromYear=2026' --data-urlencode 'xx_ToWhenFromMonth=12' --data-urlencode 'xx_ToWhenFromDay=12' \
    --data-urlencode 'xx_ToWhenToYear=2026' --data-urlencode 'xx_ToWhenToMonth=12' --data-urlencode 'xx_ToWhenToDay=13'
  rm -f "$jar"
fi

sql <<'SQL'
set @t = (select max(ToId) from Tournament where ToCode='TEST26');
update Tournament set ToIocCode='FRA', ToNumSession=4 where ToId=@t;
delete from Session where SesTournament=@t;
insert into Session (SesTournament,SesOrder,SesType,SesName,SesTar4Session,SesAth4Target,SesFirstTarget,SesStatus,SesDtStart,SesDtEnd,SesOdfCode,SesOdfPeriod,SesOdfVenue,SesOdfLocation,SesLocation) values
 (@t,1,'Q','Samedi matin',18,2,1,'','2026-12-12 10:00','2026-12-12 13:00','','','','',''),
 (@t,2,'Q','Samedi après-midi',18,2,1,'','2026-12-12 15:00','2026-12-12 18:00','','','','',''),
 (@t,3,'Q','Dimanche matin',18,2,1,'','2026-12-13 10:00','2026-12-13 13:00','','','','',''),
 (@t,4,'Q','Dimanche après-midi',18,2,1,'','2026-12-13 15:00','2026-12-13 18:00','','','','','');
-- Mailer SMTP settings → Mailpit, stored like ml_param_set() does: serialized JSON string
set @j = '{"host":"mailpit","port":1025,"security":"","user":"","password":"","fromEmail":"ianseo@example.org","fromName":"IANSEO (test)","tested":""}';
-- (insert ignore: a configuration already set in this database is kept)
insert ignore into ModulesParameters (MpModule, MpParameter, MpTournament, MpValue)
  values ('Mailer', 'smtp', 0, concat('s:', length(@j), ':"', @j, '";'));
select ToId, ToCode, ToLocRule, ToIocCode from Tournament where ToId=@t;
SQL

# Competition details shown in the email header: venue and test images (left/right logos, bottom banner), drawn with GD
docker compose exec -T web php -r '
function img($w, $h, $bg, $text) {
  $im = imagecreatetruecolor($w, $h);
  imagefill($im, 0, 0, imagecolorallocate($im, ...$bg));
  imagerectangle($im, 2, 2, $w - 3, $h - 3, imagecolorallocate($im, 255, 255, 255));
  imagestring($im, 5, intval(($w - 9 * strlen($text)) / 2), intval($h / 2) - 8, $text, imagecolorallocate($im, 255, 255, 255));
  ob_start(); imagepng($im); return ob_get_clean();
}
$db = new mysqli("db", "ianseo", "ianseo", "ianseo");
$st = $db->prepare("update Tournament set ToVenue=?, ToImgL=?, ToImgR=?, ToImgB=? where ToCode=\"TEST26\"");
$venue = "Gymnase Bourrié, avenue du Haut-Lévêque";
$l = img(240, 240, [47, 111, 79], "CLUB"); $r = img(240, 240, [31, 78, 121], "FFTA"); $b = img(1200, 160, [90, 90, 90], "SPONSORS");
$st->bind_param("ssss", $venue, $l, $r, $b);
$st->execute();
'

echo "Admin:   $URL/  (open TEST26, then Competition › Emails and click « Send a test »)"
echo "Mailpit: http://localhost:8025/"
