#!/usr/bin/env bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Запустите скрипт через sudo: sudo bash scripts/setup-self-hosted-runner.sh"
  exit 1
fi

apt-get update
apt-get install -y ca-certificates curl git jq

install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc

. /etc/os-release
cat >/etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: ${UBUNTU_CODENAME:-$VERSION_CODENAME}
Components: stable
Signed-By: /etc/apt/keyrings/docker.asc
Architectures: amd64
EOF

apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker

echo "Docker установлен."
echo "Далее создайте runner в GitHub:"
echo "Settings -> Actions -> Runners -> New self-hosted runner -> Linux -> x64"
echo "Используйте имя runner-а или произвольный label: booking-saas"
