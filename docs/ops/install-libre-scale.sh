#!/bin/sh
# Run on the Mac:  sh docs/ops/install-libre-scale.sh   (needs fsn1-root)
# Creates the restricted libre-scale user, its forced-command key, the sudoers rule, and the
# key directory that gets mounted into the lingua container. See ../libretranslate-autoscaling.md.
set -e
d=$(cd "$(dirname "$0")" && pwd)
scp -q "$d/libre-scale" "$d/libre-scale-apply" fsn1-root:/tmp/
ssh -o BatchMode=yes fsn1-root 'set -e
id libre-scale >/dev/null 2>&1 || adduser --system --shell /bin/sh --home /home/libre-scale --group libre-scale >/dev/null
install -o root -g root -m 0755 /tmp/libre-scale /tmp/libre-scale-apply /usr/local/bin/
echo "libre-scale ALL=(dokku) NOPASSWD: /usr/local/bin/libre-scale-apply 0, /usr/local/bin/libre-scale-apply 1" > /etc/sudoers.d/libre-scale
chmod 440 /etc/sudoers.d/libre-scale; visudo -cf /etc/sudoers.d/libre-scale
touch /var/log/libre-scale.log; chown dokku /var/log/libre-scale.log
k=/var/lib/dokku/data/storage/lingua-scaler; mkdir -p $k
[ -f $k/id_ed25519 ] || ssh-keygen -q -t ed25519 -N "" -C lingua-libre-scale -f $k/id_ed25519
mkdir -p /home/libre-scale/.ssh
echo "command=\"/usr/local/bin/libre-scale\",restrict $(cat $k/id_ed25519.pub)" > /home/libre-scale/.ssh/authorized_keys
chown -R libre-scale: /home/libre-scale/.ssh; chmod 700 /home/libre-scale/.ssh; chmod 600 /home/libre-scale/.ssh/authorized_keys
ssh-keyscan -t ed25519 172.17.0.1 2>/dev/null > $k/known_hosts
uid=$(docker exec lingua.web.1 id -u); chown -R $uid $k; chmod 600 $k/id_ed25519
echo "container uid $uid"; ls -la $k'
ssh -o BatchMode=yes fsn1 storage:mount lingua /var/lib/dokku/data/storage/lingua-scaler:/app/scaler
ssh -o BatchMode=yes fsn1 config:set --no-restart lingua ENGINE_AUTOSCALE_SSH_TARGET=libre-scale@172.17.0.1 ENGINE_AUTOSCALE_SSH_KEY=/app/scaler/id_ed25519 ENGINE_AUTOSCALE_ENABLED=1
ssh -o BatchMode=yes fsn1 ps:restart lingua
