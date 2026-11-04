#!/bin/sh
set -eu
umask 077
base=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
public=${GREENWOOD_POLICY_PUBLIC_DIRECTORY:?Protected publication directory required}
publisher=${GREENWOOD_POLICY_PUBLISHER_DIRECTORY:?Protected publisher directory required}
exec 9>"$publisher/renew.lock"
flock -n 9
temporary=$(mktemp "$public/.bundle-XXXXXXXX")
trap 'rm -f "$temporary"' EXIT HUP INT TERM
/usr/bin/php "$base/receiver-policy-publisher.php" "$@" > "$temporary"
/usr/bin/php "$base/receiver-policy-install.php" < "$temporary"
chmod 0644 "$temporary"
sync -f "$temporary"
mv -f "$temporary" "$public/bundle.json"
sync -f "$public"
