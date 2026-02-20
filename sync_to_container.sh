#!/bin/sh
set -eu

CONTAINER="${1:-dolibarr-pova-web-pova-1}"
BASE="/var/www/html/custom/projectprofit"
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT INT TERM

export_from_git() {
  path="$1"
  out="$TMPDIR/$path"
  mkdir -p "$(dirname "$out")"
  if git cat-file -e "HEAD:$path" 2>/dev/null; then
    git show "HEAD:$path" > "$out"
  else
    cp "$path" "$out"
  fi
  echo "$out"
}

if command -v php >/dev/null 2>&1; then
  php -l ProjectProfitCron.class.php >/dev/null
  php -l ProjectProfitCronRunner.class.php >/dev/null
  php -l projectprofit.lib.php >/dev/null
else
  echo "[warn] local php binary not found; skipping local lint checks" >&2
fi

SRC1="ProjectProfitCron.class.php"
DST1="$BASE/class/ProjectProfitCron.class.php"
SRC1="$(export_from_git "$SRC1")"
echo "[sync] $SRC1 -> $CONTAINER:$DST1"
docker exec -i "$CONTAINER" sh -lc "cat > '$DST1'" < "$SRC1"
docker exec "$CONTAINER" sh -lc "chmod 0644 '$DST1'"
L1="$(sha256sum "$SRC1" | awk '{print $1}')"
R1="$(docker exec "$CONTAINER" sh -lc "sha256sum '$DST1'" | awk '{print $1}')"
[ "$L1" = "$R1" ]

SRC2="ProjectProfitCronRunner.class.php"
DST2="$BASE/class/ProjectProfitCronRunner.class.php"
SRC2="$(export_from_git "$SRC2")"
echo "[sync] $SRC2 -> $CONTAINER:$DST2"
docker exec -i "$CONTAINER" sh -lc "cat > '$DST2'" < "$SRC2"
docker exec "$CONTAINER" sh -lc "chmod 0644 '$DST2'"
L2="$(sha256sum "$SRC2" | awk '{print $1}')"
R2="$(docker exec "$CONTAINER" sh -lc "sha256sum '$DST2'" | awk '{print $1}')"
[ "$L2" = "$R2" ]

SRC3="projectprofit.lib.php"
DST3="$BASE/lib/projectprofit.lib.php"
SRC3="$(export_from_git "$SRC3")"
echo "[sync] $SRC3 -> $CONTAINER:$DST3"
docker exec -i "$CONTAINER" sh -lc "cat > '$DST3'" < "$SRC3"
docker exec "$CONTAINER" sh -lc "chmod 0644 '$DST3'"
L3="$(sha256sum "$SRC3" | awk '{print $1}')"
R3="$(docker exec "$CONTAINER" sh -lc "sha256sum '$DST3'" | awk '{print $1}')"
[ "$L3" = "$R3" ]

if [ -f projectprofit.cron.lib.php ]; then
  SRC4="projectprofit.cron.lib.php"
  DST4="$BASE/lib/projectprofit.cron.lib.php"
  SRC4="$(export_from_git "$SRC4")"
  echo "[sync] $SRC4 -> $CONTAINER:$DST4"
  docker exec -i "$CONTAINER" sh -lc "cat > '$DST4'" < "$SRC4"
  docker exec "$CONTAINER" sh -lc "chmod 0644 '$DST4'"
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
