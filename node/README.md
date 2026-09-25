# DBoard-node

> 使用整合探针时，请先阅读[完整安装指南](../docs/installation.md)和[探针说明](../probe/README.md)，从服务器管理取得整合 Agent 命令。本文后续的传统节点直连安装会把面板地址配置到节点，不能与探针安装混用。

DBoard node backend. Supports `sing-box` / `xray-core` dual kernels.

> **Disclaimer**: This project is for educational and learning purposes only.

## Features

- Protocols: V2Ray family, Trojan, Shadowsocks, Hysteria2, TUIC, AnyTLS
- Sync: WebSocket push + REST polling dual channel
- User controls: speed limit, device limit, alive-IP tracking, hot update
- Deploy modes: node mode, machine mode, standalone mode
- Multi-instance: single process binding multiple panels / nodes

## Install

### Docker

```bash
git clone --depth 1 https://github.com/shini74744/DBoard.git
cd DBoard/node
docker build -t dboard-node:local .
docker run -d --restart=always --network=host \
  -e apiHost=https://panel.com -e apiKey=TOKEN -e nodeID=1 \
  dboard-node:local
```

### Installer (Linux systemd)

```bash
# Node mode
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/node/install.sh | \
  sudo bash -s -- --mode node --panel https://panel.example.com --token TOKEN --node-id 1

# Machine mode
curl -fsSL https://raw.githubusercontent.com/shini74744/DBoard/main/node/install.sh | \
  sudo bash -s -- --mode machine --panel https://panel.example.com --token TOKEN --machine-id 1
```

The panel URL may be an IP plus port, for example `http://203.0.113.10:8888`.
The installer downloads `DBoard-node` and `xbctl` from the latest DBoard GitHub Release.
Existing `DUI-node` and `xboard-node` systemd installs can migrate with `sudo bash install.sh upgrade`. The installer copies their config to `/etc/DBoard-node`, enables `DBoard-node.service`, checks health, then disables the previous service. The old config and binary remain as a recovery copy.

## Remote upgrades from the admin panel

After the first manual upgrade to a release containing remote-upgrade support, open **Upgrade node backend** in **Server Management**, then select servers or choose all servers in the dialog. The panel sends a machine-level WebSocket command. Each selected server downloads the latest DBoard GitHub Release binary and `xbctl`, upgrades once, and restarts `DBoard-node.service`. All nodes on that server reconnect together. The panel reports the result when the machine reconnects with the target version. Servers running older node versions or Docker nodes do not accept this command; upgrade those manually first. The existing config is reused.

## xbctl

Run `xbctl` after installation for help. Common commands:

```bash
xbctl list                          # list all instances
xbctl status                        # running status
xbctl bind add-node --panel URL --token TOKEN --node-id 1
xbctl bind add-machine --panel URL --token TOKEN --machine-id 1
xbctl bind remove-node --panel URL --node-id 1
xbctl service restart
```

## Configuration

Legacy single-panel config is fully compatible. Appending bindings auto-migrates to `instances` format. See `config.yml.example`.

## Extensions

- Custom routes: [docs-custom-routes.md](docs-custom-routes.md)
- Custom outbounds: [docs-custom-outbounds.md](docs-custom-outbounds.md)
- DNS providers (ACME DNS-01): [docs-dns-providers.md](docs-dns-providers.md)

## License

MPL-2.0.
