#!/usr/bin/env sh
set -eu

CONTAINER="${1:-dolibarr-pova-web-pova-1}"
BASE="/var/www/html/custom/projectprofit"

copy_file() {
  src="$1"
  dst="$2"
  echo "[sync] $src -> $CONTAINER:$dst"
  docker cp "$src" "$CONTAINER:$dst"
}

copy_file "ProjectProfitCron.class.php" "$BASE/class/ProjectProfitCron.class.php"
copy_file "ProjectProfitCronRunner.class.php" "$BASE/class/ProjectProfitCronRunner.class.php"
copy_file "projectprofit.lib.php" "$BASE/lib/projectprofit.lib.php"

# Optional helper file if present
if [ -f "projectprofit.cron.lib.php" ]; then
  copy_file "projectprofit.cron.lib.php" "$BASE/lib/projectprofit.cron.lib.php"
fi

docker exec -it "$CONTAINER" sh -lc "
php -l $BASE/class/ProjectProfitCron.class.php &&
php -l $BASE/class/ProjectProfitCronRunner.class.php &&
php -l $BASE/lib/projectprofit.lib.php &&
echo '---- head cron ----' &&
nl -ba $BASE/class/ProjectProfitCron.class.php | sed -n '1,30p' &&
echo '---- hashes ----' &&
sha256sum $BASE/class/ProjectProfitCron.class.php $BASE/class/ProjectProfitCronRunner.class.php $BASE/lib/projectprofit.lib.php
"
