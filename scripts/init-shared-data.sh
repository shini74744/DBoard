#!/usr/bin/env bash
set -Eeuo pipefail

DATA_DIR="${DBOARD_DATA_DIR:-/opt/dboard/shared}"

if [[ $EUID -ne 0 ]]; then
  echo "请使用 root 运行，或通过 sudo 执行。" >&2
  exit 1
fi

install -d -m 0750 "$DATA_DIR"

for dir in \
  data redis plugins \
  storage/app storage/backup storage/logs storage/theme \
  public-theme; do
  install -d -m 0750 "$DATA_DIR/$dir"
done

if [[ ! -e "$DATA_DIR/.env" ]]; then
  install -m 0640 /dev/null "$DATA_DIR/.env"
fi
echo "DBoard 持久数据目录已初始化：$DATA_DIR"
echo "请根据部署方式设置目录所有者，并填写 $DATA_DIR/.env"
