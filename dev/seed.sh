#!/bin/sh
# Prepares the test IANSEO started by docker-compose.yml (same seed as ianseo-self-registration): schema, a French indoor competition "TEST26"
# (4 sessions; session 1 has only 2 targets × 2 archers), fictitious licenses with birth dates, and Cloudflare's
# Turnstile TEST keys (always pass; replace the secret with 2x0000000000000000000000000000000AA to test a failure).
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
 (@t,1,'Q','Samedi matin',2,2,1,'','2026-12-12 10:00','2026-12-12 13:00','','','','',''),
 (@t,2,'Q','Samedi après-midi',18,2,1,'','2026-12-12 15:00','2026-12-12 18:00','','','','',''),
 (@t,3,'Q','Dimanche matin',18,2,1,'','2026-12-13 10:00','2026-12-13 13:00','','','','',''),
 (@t,4,'Q','Dimanche après-midi',18,2,1,'','2026-12-13 15:00','2026-12-13 18:00','','','','','');
delete from LookUpEntries where LueIocCode='FRA' and LueCode in ('123456A','654321B','222222D','333333E','444444F','555555G','666666H');
insert into LookUpEntries (LueCode,LueIocCode,LueFamilyName,LueName,LueSex,LueClassified,LueCtrlCode,LueCountry,LueCoDescr,LueCoShort,LueCountry2,LueCoDescr2,LueCoShort2,LueCountry3,LueCoDescr3,LueCoShort3,LueDivision,LueClass,LueSubClass,LueStatus,LueStatusValidUntil,LueDefault,LueNameOrder) values
 ('123456A','FRA','DUPONT','Jean',0,0,'1985-03-12','0333001','Archers de Cestas','ARC CESTAS','','','','','','','','','',0,'2027-08-31',1,0),
 ('654321B','FRA','MARTIN','Elodie',1,0,'2010-02-01','0333002','Archers de Pessac','ARC PESSAC','','','','','','','','','',0,'2027-08-31',1,0),
 ('222222D','FRA','ROUX','Marc',0,0,'1970-05-05','0333003','Club Z','CLUB Z','','','','','','','','','',0,'2027-08-31',1,0),
 ('333333E','FRA','LEROY','Anne',1,0,'1980-01-01','0333004','Club X','CLUB X','','','','','','','','','',0,'2027-08-31',1,0),
 ('444444F','FRA','EXPIRE','Zoé',1,0,'2000-03-12','0333004','Club X','CLUB X','','','','','','','','','',0,'2026-09-01',1,0),
 ('555555G','FRA','DURAND','Paul',0,0,'1960-01-01','0333005','Club Durand','CLUB D','','','','','','','','','',0,'2027-08-31',1,0),
 ('666666H','FRA','PETIT','Lucas',0,0,'2015-06-20','0333001','Archers de Cestas','ARC CESTAS','','','','','','','','','',0,'2027-08-31',1,0);
-- Cloudflare Turnstile test keys, stored like sr_param_set() does: serialized JSON string
set @j = '{"site":"1x00000000000000000000AA","secret":"1x0000000000000000000000000000000AA"}';
insert into ModulesParameters (MpModule, MpParameter, MpTournament, MpValue)
  values ('SelfRegistration', 'turnstile', 0, concat('s:', length(@j), ':"', @j, '";'))
  on duplicate key update MpValue=values(MpValue);
select ToId, ToCode, ToLocRule, ToIocCode from Tournament where ToId=@t;
SQL

# Competition details shown by the public form: venue and test images (left/right logos, bottom banner), drawn with GD
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

echo "Admin:   $URL/  (open TEST26, then Competition › Emails; SMTP: host mailpit, port 1025, security none)"
echo "Mailpit: http://localhost:8025/"
echo "Public form (with SELFREG_DIR): $URL/Modules/Custom/SelfRegistration/register.php?c=TEST26"
