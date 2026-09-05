#!/bin/bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
NAME="cache-array-share"
VERSION="2026.09.02a"
BUILD="$ROOT/build"
PACKAGE="$NAME-$VERSION-noarch-1.txz"

rm -rf "$BUILD"
mkdir -p "$BUILD/package"
cp -a "$ROOT/src/." "$BUILD/package/"

chmod 0755 "$BUILD/package/usr/local/emhttp/plugins/$NAME/scripts/$NAME.php"
chmod 0644 "$BUILD/package/usr/local/emhttp/plugins/$NAME/$NAME.page"
chmod 0644 "$BUILD/package/usr/local/emhttp/plugins/$NAME/api.php"
chmod 0644 "$BUILD/package/install/slack-desc"

if tar --version 2>&1 | grep -q 'GNU tar'; then
  TAR_OWNER_FLAGS=(--owner=0 --group=0 --numeric-owner)
else
  TAR_OWNER_FLAGS=(--uid 0 --gid 0)
fi
(cd "$BUILD/package" && find . | LC_ALL=C sort | tar --no-recursion "${TAR_OWNER_FLAGS[@]}" -cJf "$BUILD/$PACKAGE" -T -)

if base64 --help 2>&1 | grep -q -- '-w'; then
  PAYLOAD="$(base64 -w 0 "$BUILD/$PACKAGE")"
else
  PAYLOAD="$(base64 -i "$BUILD/$PACKAGE")"
fi
awk -v payload="$PAYLOAD" '{
  if ($0 == "@@PACKAGE_BASE64@@") print payload;
  else print;
}' "$ROOT/plugin/cache-array-share.plg.in" > "$ROOT/cache-array-share.plg"

chmod 0644 "$ROOT/cache-array-share.plg"
echo "Built $ROOT/cache-array-share.plg"
