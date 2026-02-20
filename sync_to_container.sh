#!/bin/sh
set -eu

CONTAINER="${1:-dolibarr-pova-web-pova-1}"
BASE="/var/www/html/custom/projectprofit"

php -l ProjectProfitCron.class.php >/dev/null
php -l ProjectProfitCronRunner.class.php >/dev/null
php -l projectprofit.lib.php >/dev/null

SRC1="ProjectProfitCron.class.php"
DST1="$BASE/class/ProjectProfitCron.class.php"
echo "[sync] $SRC1 -> $CONTAINER:$DST1"
docker cp "$SRC1" "$CONTAINER:$DST1"
L1="$(sha256sum "$SRC1" | awk '{print $1}')"
R1="$(docker exec "$CONTAINER" sh -lc "sha256sum '$DST1'" | awk '{print $1}')"
[ "$L1" = "$R1" ]

SRC2="ProjectProfitCronRunner.class.php"
DST2="$BASE/class/ProjectProfitCronRunner.class.php"
echo "[sync] $SRC2 -> $CONTAINER:$DST2"
docker cp "$SRC2" "$CONTAINER:$DST2"
L2="$(sha256sum "$SRC2" | awk '{print $1}')"
R2="$(docker exec "$CONTAINER" sh -lc "sha256sum '$DST2'" | awk '{print $1}')"
[ "$L2" = "$R2" ]

SRC3="projectprofit.lib.php"
DST3="$BASE/lib/projectprofit.lib.php"
echo "[sync] $SRC3 -> $CONTAINER:$DST3"
docker cp "$SRC3" "$CONTAINER:$DST3"
L3="$(sha256sum "$SRC3" | awk '{print $1}')"
R3="$(docker exec "$CONTAINER" sh -lc "sha256sum '$DST3'" | awk '{print $1}')"
[ "$L3" = "$R3" ]

if [ -f projectprofit.cron.lib.php ]; then
  SRC4="projectprofit.cron.lib.php"
  DST4="$BASE/lib/projectprofit.cron.lib.php"
  echo "[sync] $SRC4 -> $CONTAINER:$DST4"
  docker cp "$SRC4" "$CONTAINER:$DST4"
  L4="$(sha256sum "$SRC4" | awk '{print $1}')"
  R4="$(docker exec "$CONTAINER" sh -lc "sha256sum '$DST4'" | awk '{print $1}')"
  [ "$L4" = "$R4" ]
fi

docker exec -it "$CONTAINER" sh -lc "php -l $DST1"
docker exec -it "$CONTAINER" sh -lc "php -l $DST2"
docker exec -it "$CONTAINER" sh -lc "php -l $DST3"
docker exec -it "$CONTAINER" sh -lc "! grep -q '<!DOCTYPE html>' $DST1"
docker exec -it "$CONTAINER" sh -lc "test \"\$(grep -c 'class ProjectProfitCron' $DST1)\" -eq 1"

echo '---- head cron ----'
docker exec -it "$CONTAINER" sh -lc "nl -ba $DST1 | sed -n '1,30p'"
echo '---- hashes ----'
docker exec -it "$CONTAINER" sh -lc "sha256sum $DST1 $DST2 $DST3"
