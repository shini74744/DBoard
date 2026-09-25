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
 *) echo "未知参数: $1" >&2; exit 2;;
 esac
done
[[ "$EUID" == 0 ]] || { echo '请使用 root 安装'; exit 1; }
[[ "$endpoint" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || { echo '需要 HTTPS 探针地址'; exit 1; }
[[ "$uuid" =~ ^[a-f0-9-]{36}$ && "$enrollment" =~ ^[a-f0-9]{48}$ && "$version" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([-+][A-Za-z0-9.-]+)?$ ]] || { echo '安装参数无效'; exit 1; }
for tool in curl systemctl sha256sum; do command -v "$tool" >/dev/null; done
case "$(uname -m)" in x86_64) arch=amd64;; aarch64|arm64) arch=arm64;; *) echo '不支持的架构'; exit 1;; esac
legacy_active=0; probe_active=0
systemctl is-active --quiet DBoard-node.service && legacy_active=1
systemctl is-active --quiet nezha-agent.service && probe_active=1
if [[ "$legacy_active" == 1 && "$takeover" != 1 ]]; then
 echo '检测到现有节点服务。迁移安装请在命令末尾添加 --takeover。'
 exit 1
fi
tmp="$(mktemp -d)"
changed=0
finish(){
 result=$?
 if [[ "$result" != 0 && "$changed" == 1 ]]; then
  systemctl stop nezha-agent.service || true
  if [[ -f "$tmp/old-agent" ]]; then install -m 755 "$tmp/old-agent" /usr/local/bin/nezha-agent; fi
  if [[ -f "$tmp/old-config" ]]; then install -m 600 "$tmp/old-config" /etc/nezha-agent/config.json; fi
  if [[ -f "$tmp/old-unit" ]]; then install -m 644 "$tmp/old-unit" /etc/systemd/system/nezha-agent.service; fi
  systemctl daemon-reload || true
  if [[ "$probe_active" == 1 ]]; then systemctl start nezha-agent.service || true; fi
  if [[ "$legacy_active" == 1 ]]; then systemctl start DBoard-node.service || true; fi
  echo '探针未通过连接检查，已尝试恢复原服务。' >&2
 fi
 rm -rf -- "$tmp"
}
trap finish EXIT
asset="nezha-agent-linux-$arch"
curl --fail --silent --show-error --proto '=https' "$endpoint/bridge/v1/artifacts/$version/$asset" -o "$tmp/$asset"
curl --fail --silent --show-error --proto '=https' "$endpoint/bridge/v1/artifacts/$version/SHA256SUMS" -o "$tmp/SHA256SUMS"
(cd "$tmp"; grep -E "^[a-f0-9]{64}  $asset$" SHA256SUMS | sha256sum -c -)
chmod 700 "$tmp/$asset"
"$tmp/$asset" -v | grep -F "Integrated Nezha Agent $version" >/dev/null
[[ ! -f /usr/local/bin/nezha-agent ]] || cp -p /usr/local/bin/nezha-agent "$tmp/old-agent"
[[ ! -f /etc/nezha-agent/config.json ]] || cp -p /etc/nezha-agent/config.json "$tmp/old-config"
[[ ! -f /etc/systemd/system/nezha-agent.service ]] || cp -p /etc/systemd/system/nezha-agent.service "$tmp/old-unit"
install -d -m 700 /etc/nezha-agent /var/lib/nezha-agent
enroll_args=()
if [[ "$legacy_active" == 1 && -f /etc/DBoard-node/config.yml && "$probe_active" == 0 ]]; then enroll_args+=(--legacy-config /etc/DBoard-node/config.yml); fi
"$tmp/$asset" "${enroll_args[@]}" --enroll --endpoint "$endpoint" --uuid "$uuid" --enrollment "$enrollment" -c /etc/nezha-agent/config.json
changed=1
install -m 755 "$tmp/$asset" /usr/local/bin/nezha-agent.new
mv -f /usr/local/bin/nezha-agent.new /usr/local/bin/nezha-agent
cat > /etc/systemd/system/nezha-agent.service <<'UNIT'
[Unit]
Description=Integrated Nezha Agent
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
ExecStart=/usr/local/bin/nezha-agent -c /etc/nezha-agent/config.json
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
systemctl enable nezha-agent.service
systemctl restart nezha-agent.service
healthy=0
for ((i=0;i<90;i++)); do
 if /usr/local/bin/nezha-agent --health-check --version "$version" --since "$started" -c /etc/nezha-agent/config.json; then healthy=1; break; fi
 sleep 1
done
[[ "$healthy" == 1 ]] || exit 1
if [[ "$legacy_active" == 1 ]]; then systemctl disable DBoard-node.service; fi
changed=0
echo '整合 Agent 已启动，监控和节点通道均已连接。'
