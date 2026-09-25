#!/usr/bin/env bash
set -euo pipefail
umask 077
endpoint='' uuid='' enrollment='' version='' takeover=0
while (($#)); do
 case "$1" in
 --endpoint) endpoint="$2"; shift 2;;
 --uuid) uuid="$2"; shift 2;;
 --enrollment) enrollment="$2"; shift 2;;
 --version) version="$2"; shift 2;;
 --takeover) takeover=1; shift;;
 *) echo '未知安装参数' >&2; exit 2;;
 esac
done
[[ "$EUID" == 0 ]] || { echo '请使用 root 安装'; exit 1; }
[[ "$endpoint" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || { echo '需要 HTTPS 探针地址'; exit 1; }
[[ "$uuid" =~ ^[a-f0-9-]{36}$ && "$enrollment" =~ ^[a-f0-9]{48}$ && "$version" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([-+][A-Za-z0-9.-]+)?$ ]] || { echo '安装参数无效'; exit 1; }
for tool in curl systemctl sha256sum flock; do command -v "$tool" >/dev/null; done
exec 9>/run/lock/nezha-integrated-agent-install.lock
flock -n 9 || { echo '已有探针安装任务执行中'; exit 1; }
case "$(uname -m)" in x86_64) arch=amd64;; aarch64|arm64) arch=arm64;; *) echo '不支持的架构'; exit 1;; esac
# A stock Nezha installation has an independent service, binary and configuration.
unit=nezha-integrated-agent.service
binary=/usr/local/bin/nezha-integrated-agent
config=/etc/nezha-integrated-agent/config.json
unit_file=/etc/systemd/system/nezha-integrated-agent.service
legacy_active=0; probe_active=0; probe_enabled=0
systemctl is-active --quiet DBoard-node.service && legacy_active=1
systemctl is-active --quiet "$unit" && probe_active=1
systemctl is-enabled --quiet "$unit" && probe_enabled=1
if [[ "$legacy_active" == 1 && "$takeover" != 1 ]]; then
 echo '检测到现有节点服务。迁移安装请在命令末尾添加 --takeover。'
 exit 1
fi
tmp="$(mktemp -d)"
changed=0
finish(){
 result=$?
 if [[ "$result" != 0 && "$changed" == 1 ]]; then
  systemctl stop "$unit" || true
  [[ "$probe_enabled" == 1 ]] || systemctl disable "$unit" || true
  for kind in agent config unit; do
   case "$kind" in agent) dest="$binary"; mode=755;; config) dest="$config"; mode=600;; unit) dest="$unit_file"; mode=644;; esac
   if [[ -f "$tmp/old-$kind" ]]; then install -m "$mode" "$tmp/old-$kind" "$dest"; else rm -f -- "$dest"; fi
  done
  systemctl daemon-reload || true
  if [[ "$probe_active" == 1 ]]; then systemctl start "$unit" || true; fi
  if [[ "$legacy_active" == 1 ]]; then systemctl start DBoard-node.service || true; fi
  echo '探针未通过连接检查，已恢复原服务文件并尝试启动原服务。' >&2
 fi
 rm -rf -- "$tmp"
}
trap finish EXIT
trap 'exit 143' TERM INT HUP
asset="nezha-agent-linux-$arch"
curl --fail --silent --show-error --retry 3 --connect-timeout 20 --max-time 600 --proto '=https' "$endpoint/bridge/v1/artifacts/$version/$asset" -o "$tmp/$asset"
curl --fail --silent --show-error --retry 3 --connect-timeout 20 --max-time 60 --proto '=https' "$endpoint/bridge/v1/artifacts/$version/SHA256SUMS" -o "$tmp/SHA256SUMS"
(cd "$tmp"; grep -E "^[a-f0-9]{64}  $asset$" SHA256SUMS | sha256sum -c -)
chmod 700 "$tmp/$asset"
"$tmp/$asset" -v | grep -F "Integrated Nezha Agent $version" >/dev/null
[[ ! -f "$binary" ]] || cp -p "$binary" "$tmp/old-agent"
[[ ! -f "$config" ]] || cp -p "$config" "$tmp/old-config"
[[ ! -f "$unit_file" ]] || cp -p "$unit_file" "$tmp/old-unit"
install -d -m 700 /etc/nezha-integrated-agent /var/lib/nezha-integrated-agent
enroll_args=()
if [[ "$legacy_active" == 1 && -f /etc/DBoard-node/config.yml && "$probe_active" == 0 ]]; then enroll_args+=(--legacy-config /etc/DBoard-node/config.yml); fi
changed=1
"$tmp/$asset" "${enroll_args[@]}" --enroll --endpoint "$endpoint" --uuid "$uuid" --enrollment "$enrollment" -c "$config"
install -m 755 "$tmp/$asset" "$binary.new"
mv -f "$binary.new" "$binary"
cat > "$unit_file" <<'UNIT'
[Unit]
Description=Integrated Nezha monitoring and node agent
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
ExecStart=/usr/local/bin/nezha-integrated-agent -c /etc/nezha-integrated-agent/config.json
Restart=always
RestartSec=5
TimeoutStopSec=30
LimitNOFILE=1048576
[Install]
WantedBy=multi-user.target
UNIT
if [[ "$legacy_active" == 1 ]]; then systemctl stop DBoard-node.service; fi
started="$(date +%s)"
systemctl daemon-reload
systemctl enable "$unit"
systemctl restart "$unit"
healthy=0
for ((i=0;i<90;i++)); do
 if "$binary" --health-check --version "$version" --since "$started" -c "$config"; then healthy=1; break; fi
 sleep 1
done
[[ "$healthy" == 1 ]] || exit 1
if [[ "$legacy_active" == 1 ]]; then systemctl disable DBoard-node.service; fi
changed=0
echo '整合 Agent 已启动，监控和节点通道均已连接；原版哪吒未修改。'
