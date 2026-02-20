#!/bin/sh
#!/usr/bin/env sh
set -eu

CONTAINER="${1:-dolibarr-pova-web-pova-1}"
BASE="/var/www/html/custom/projectprofit"

if command -v php >/dev/null 2>&1; then
  php -l ProjectProfitCron.class.php >/dev/null
  php -l ProjectProfitCronRunner.class.php >/dev/null
  php -l projectprofit.lib.php >/dev/null
else
  echo "[warn] local php binary not found; skipping local lint checks" >&2
fi
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
check_local_php() {
  php -l "$1" >/dev/null
  f="$1"
  php -l "$f" >/dev/null
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

docker exec -it "$CONTAINER" sh -lc "php -l $BASE/class/ProjectProfitCron.class.php"
docker exec -it "$CONTAINER" sh -lc "php -l $BASE/class/ProjectProfitCronRunner.class.php"
docker exec -it "$CONTAINER" sh -lc "php -l $BASE/lib/projectprofit.lib.php"
docker exec -it "$CONTAINER" sh -lc "! grep -q '<!DOCTYPE html>' $BASE/class/ProjectProfitCron.class.php"
docker exec -it "$CONTAINER" sh -lc "test \"\$(grep -c 'class ProjectProfitCron' $BASE/class/ProjectProfitCron.class.php)\" -eq 1"

echo '---- head cron ----'
docker exec -it "$CONTAINER" sh -lc "nl -ba $BASE/class/ProjectProfitCron.class.php | sed -n '1,30p'"

echo '---- hashes ----'
docker exec -it "$CONTAINER" sh -lc "sha256sum $BASE/class/ProjectProfitCron.class.php $BASE/class/ProjectProfitCronRunner.class.php $BASE/lib/projectprofit.lib.php"
docker exec -it "$CONTAINER" sh -lc "
php -l $BASE/class/ProjectProfitCron.class.php &&
php -l $BASE/class/ProjectProfitCronRunner.class.php &&
php -l $BASE/lib/projectprofit.lib.php &&
! grep -q '<!DOCTYPE html>' $BASE/class/ProjectProfitCron.class.php &&
test \"\$(grep -c 'class ProjectProfitCron' $BASE/class/ProjectProfitCron.class.php)\" -eq 1 &&
echo '---- head cron ----' &&
nl -ba $BASE/class/ProjectProfitCron.class.php | sed -n '1,30p' &&
echo '---- hashes ----' &&
sha256sum $BASE/class/ProjectProfitCron.class.php $BASE/class/ProjectProfitCronRunner.class.php $BASE/lib/projectprofit.lib.php
"
