#!/bin/sh
set -eu
umask 077
base=${GREENWOOD_POLICY_TRANSPORT_DIRECTORY:?Protected transport directory required}
state=${GREENWOOD_POLICY_STAGING_DIRECTORY:?Protected staging directory required}
peer=${GREENWOOD_POLICY_SSH_PEER:?Pinned policy publisher SSH account required}
port=${GREENWOOD_POLICY_SFTP_PORT:?Dedicated SFTP port required}
scripts=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
temporary=$(mktemp "$state/.bundle-XXXXXXXX")
trap 'rm -f "$temporary"' EXIT HUP INT TERM
/usr/bin/sftp -q -b - -F /dev/null -P "$port" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
  -o UserKnownHostsFile="$base/known_hosts" -o GlobalKnownHostsFile=/dev/null \
  -o ConnectTimeout=5 -o ServerAliveInterval=5 -o ServerAliveCountMax=2 \
  -i "$base/id_ed25519" "$peer" <<EOF
get /bundle.json "$temporary"
EOF
/usr/bin/php "$scripts/receiver-policy-install.php" < "$temporary"
