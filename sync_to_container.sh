#!/usr/bin/env sh
set -eu

CONTAINER="${1:-dolibarr-pova-web-pova-1}"
BASE="/var/www/html/custom/projectprofit"

check_local_php() {
  php -l "$1" >/dev/null
}

local_hash() {
  sha256sum "$1" | awk '{print $1}'
}

container_hash() {
  docker exec "$CONTAINER" sh -lc "sha256sum '$1'" | awk '{print $1}'
}

copy_file() {
  src="$1"
  dst="$2"

  check_local_php "$src"
  echo "[sync] $src -> $CONTAINER:$dst"
  docker cp "$src" "$CONTAINER:$dst"

  src_hash="$(local_hash "$src")"
  dst_hash="$(container_hash "$dst")"

  if [ "$src_hash" != "$dst_hash" ]; then
    echo "[error] hash mismatch after copy for $dst" >&2
    echo "        local:     $src_hash" >&2
    echo "        container: $dst_hash" >&2
    exit 1
  fi
}

copy_file "ProjectProfitCron.class.php" "$BASE/class/ProjectProfitCron.class.php"
copy_file "ProjectProfitCronRunner.class.php" "$BASE/class/ProjectProfitCronRunner.class.php"
copy_file "projectprofit.lib.php" "$BASE/lib/projectprofit.lib.php"

if [ -f "projectprofit.cron.lib.php" ]; then
  copy_file "projectprofit.cron.lib.php" "$BASE/lib/projectprofit.cron.lib.php"
fi

docker exec -it "$CONTAINER" sh -lc "php -l $BASE/class/ProjectProfitCron.class.php"
docker exec -it "$CONTAINER" sh -lc "php -l $BASE/class/ProjectProfitCronRunner.class.php"
docker exec -it "$CONTAINER" sh -lc "php -l $BASE/lib/projectprofit.lib.php"
docker exec -it "$CONTAINER" sh -lc "! grep -q '<!DOCTYPE html>' $BASE/class/ProjectProfitCron.class.php"
docker exec -it "$CONTAINER" sh -lc "test \"\$(grep -c 'class ProjectProfitCron' $BASE/class/ProjectProfitCron.class.php)\" -eq 1"

echo '---- head cron ----'
docker exec -it "$CONTAINER" sh -lc "nl -ba $BASE/class/ProjectProfitCron.class.php | sed -n '1,30p'"

echo '---- hashes ----'
docker exec -it "$CONTAINER" sh -lc "sha256sum $BASE/class/ProjectProfitCron.class.php $BASE/class/ProjectProfitCronRunner.class.php $BASE/lib/projectprofit.lib.php"
