#!/bin/bash
# Operator maintenance only: review pending packages and arrange recovery first.
# Does not restart networkd; leaves its persistent explicit-stop guard installed.
set -euo pipefail
if [[ $# != 1 || ! "$1" =~ ^[a-f0-9]{32}$ ]]; then
    echo 'Usage: repair-nfs-pending-packages.sh EXPECTED_MACHINE_ID' >&2
    exit 2
fi
[[ $EUID == 0 ]] || { echo 'Run as root' >&2; exit 2; }
expected="$1"
test "$(cat /etc/machine-id)" = "$expected"
test "$(findmnt -n -o FSTYPE /)" = nfs
systemctl is-active --quiet systemd-networkd
if test -e /usr/sbin/policy-rc.d || test -L /usr/sbin/policy-rc.d; then echo 'Existing policy-rc.d requires review'; exit 1; fi
backup=$(mktemp -d /var/backups/greenwood-guarded-packages-XXXXXXXX)
chmod 700 "$backup"
echo "BACKUP=$backup"
tar --acls --xattrs -cpf "$backup/before.tar" /var/lib/dpkg/status /var/lib/dpkg/updates /var/lib/dpkg/triggers /etc/passwd /etc/group /etc/shadow /etc/gshadow /etc/netplan /etc/systemd/system /boot/firmware 2>"$backup/tar.log"
network_pid=$(systemctl show systemd-networkd -p MainPID --value)
ip -j address show eth0 > "$backup/address-before.json"
systemctl list-units --state=active --no-pager > "$backup/services-before.txt"
guard=/etc/systemd/system/systemd-networkd.service.d/90-greenwood-nfs-root.conf
test ! -e "$guard"
install -d -o root -g root -m 755 "$(dirname "$guard")"
printf '[Unit]\n# NFS root depends on this interface. Maintenance must use tested recovery.\nRefuseManualStop=yes\n' > "$guard"
chmod 644 "$guard"
chown root:root "$guard"
systemctl daemon-reload
test "$(systemctl show systemd-networkd -p RefuseManualStop --value)" = yes
test "$(systemctl show systemd-networkd -p MainPID --value)" = "$network_pid"
printf '#!/bin/sh\n# Temporary Greenwood package repair: defer service activation to maintenance.\nexit 101\n' > /usr/sbin/policy-rc.d
chmod 755 /usr/sbin/policy-rc.d
chown root:root /usr/sbin/policy-rc.d
trap 'rm -f /usr/sbin/policy-rc.d' EXIT
export DEBIAN_FRONTEND=noninteractive
# Finish only the already unpacked transaction; never upgrade or install packages.
dpkg --configure --pending > "$backup/configure.log" 2>&1 || { tail -80 "$backup/configure.log"; exit 1; }
cat "$backup/configure.log"
test "$(systemctl show systemd-networkd -p MainPID --value)" = "$network_pid"
test "$(systemctl show systemd-networkd -p RefuseManualStop --value)" = yes
systemctl is-active --quiet systemd-networkd ssh containerd
ip -j address show eth0 > "$backup/address-after.json"
dpkg --audit > "$backup/dpkg-audit.txt"
test ! -s "$backup/dpkg-audit.txt"
echo "SUCCESS: packages configured; networkd PID $network_pid unchanged; restart guard remains installed"
