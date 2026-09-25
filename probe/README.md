# DBoard / Nezha integration

This tree contains pinned Nezha Dashboard and Agent source, the authenticated gateway,
the panel connector, and the single integrated Agent in ../node/cmd/nezha-agent.

## Connections

- Agent -> probe HTTPS origin: Nezha gRPC monitoring, scoped node HTTPS API and WebSocket.
- Panel connector -> probe origin: an authenticated outbound WebSocket.
- Connector -> panel: loopback HTTP and loopback node WebSocket only.
- Customer proxy traffic uses the existing proxy engine and routes; it does not traverse the monitoring gateway.
- The Agent has a per-server UUID and scoped key. It does not receive the panel origin, panel machine ID, or panel machine token.
- Nezha's terminal, file manager, alerts and monitoring backend remain available. Integrated Agent updates and origin changes are managed by the panel; stock Agent self-updates and upstream config/ownership replacement are disabled.

A root or hypervisor administrator can still inspect the process, files, proxy ports,
and memory. This design isolates the panel management origin; it is not process invisibility.

## Build

Prerequisites: Linux, Go 1.26.6 (or Go toolchain auto-download), Python 3, network access
for pinned dependencies and frontend releases, and sufficient free build space.

Run from repository root:

    python3 probe/prepare-dashboard.py
    bash probe/build.sh v0.2.0

Preparation verifies frontend SHA-256 digests in frontend-assets.lock.json.
Updating pins requires an explicit --update-lock operation after upstream review.
Builds generate OpenAPI documentation before embedding the real Nezha frontend.

Output: probe/build/<version>/ contains amd64 and arm64 Agents, SHA256SUMS,
probe-dashboard, probe-connector, install.sh and release.json. The Agent is one
binary with Nezha monitoring and the existing sing-box/Xray node implementation.
Build output is ignored by Git. Building does not publish or install anything.

## Deployment sequence (not performed by this change)

1. Back up the panel database and existing service configurations.
2. Deploy panel code and run its normal migrations, including migration 000024.
3. Prepare a probe host with a monitoring domain and valid HTTPS certificate.
   Copy probe-dashboard there. Create its directories and a restricted nezha service
   account; example configurations are in examples/.
4. Initially start the dashboard on loopback without NEZHA_BRIDGE_KEY_FILE. Through a
   local tunnel initialize the real backend, change its bootstrap admin/admin password
   before publishing, and note the intended owner's numeric Nezha user ID.
5. Generate a random control key of at least 32 characters in a mode-0600 file.
   Configure bridge.env.example with the probe origin, key file, data directory and owner.
   Back up the dashboard DB, bridge/devices.json and control key together.
6. Publish through the example Nginx routes. gRPC HTTP/2 and WebSocket upgrades must
   both work on the same HTTPS origin. The root and /dashboard are real Nezha pages.
7. Place install.sh at <bridge-data>/artifacts/install.sh and Agent binaries plus
   SHA256SUMS at <bridge-data>/artifacts/<version>/. Node installations and upgrades
   retrieve these from the probe, not GitHub.
8. In DBoard: 系统管理 -> 探针管理 -> 接入设置. Enter the probe origin, same control key,
   and staged integrated Agent version. Download the connector configuration.
   Confirm its loopback panel port (default 7001) and node WebSocket port (default 8076)
   match the deployment. Store it mode 0600 owned by the connector service user.
9. Start the connector on the panel host. It never gives the panel origin or machine
   token to the probe. Confirm “检查连接” shows it connected.
10. Add a server in server management. This provisions a real Nezha server and produces
    a 15-minute enrollment command. Run it on the intended server. Each Agent identity
    is scoped to that server and can be disabled centrally.
11. Existing nodes are not automatically changed by enabling this feature. Generate
    their probe command and append --takeover when deliberately migrating them.
    The installer preserves the core selection from /etc/DBoard-node/config.yml,
    stops the old service only after download and enrollment, and attempts to restore
    it if monitoring + node-channel health checks fail.

Adapt example users, paths and ports to the installation. The connector's internal
API is loopback-only. The monitoring backend opens in its own browser window so its
existing login and CSRF cookies work across different domains.

## Change the probe connection address

1. Point the new domain and optional backups at the same gateway and install valid
   HTTPS certificates. All aliases need both gRPC and WebSocket routes.
2. Open 探针管理 -> 更新连接地址, enter the new origin, and select the servers.
3. The panel authenticates the replacement gateway, stores a task per selected server,
   and updates the connector's origin. An unavailable old origin does not prevent
   validating a working replacement.
4. An online Agent checks HTTPS authentication, monitoring gRPC and node WebSocket
   authentication before atomically saving. It reconnects both transports; the UI
   records the actual connected address.
5. Offline servers retain their task in the panel database and receive it when they
   reconnect. Configured backups are tried after repeated failures, even on idle nodes.
   Fallback is shown separately from a successful migration.
6. Keep the old origin until all intended servers have moved. A node with every known
   origin unavailable cannot learn a previously unknown domain: restore a known origin
   or update that node's local config/install command.
7. A move to a different gateway host requires copying the Nezha database, scoped
   identity registry and control key too. Updating an origin alone does not copy data.

## Reporting and upgrades

Reports are persisted locally before acknowledgement and retried with a stable batch ID.
The panel commits incremental customer accounting and the receipt in one SQL transaction.
Duplicate reports do not charge twice, and receipt IDs bind the node identity.
Permanently rejected batches (for example a deleted node) are retained in the Agent data directory under quarantine so they do not block unrelated nodes. Telemetry-only reports do not grow the receipt ledger. Existing outbound cumulative
cursors, global totals and per-node totals keep their independent accounting.

The disk queue is bounded at 10,000 reports. When full, submission fails and the existing
tracker retains incremental traffic in memory. Provision adequate disk and restore long
outages promptly. This queue does not replace panel backups.

Integrated Agents use the version staged in probe settings, independently from stock
DBoard GitHub releases. Downloads remain on the probe origin. The updater verifies
SHA-256 and the integrated binary identity, keeps a previous binary, and requires
monitoring plus the node channel to reconnect before success. Failed checks restore
the previous executable. Real systemd takeover/upgrade is a deployment acceptance
step and is not run against existing production services by development tests.

## Tests

    cd probe/bridge && go test -race ./...
    cd node && go test ./internal/probeagent ./internal/panel ./cmd/nezha-agent
    cd panel && php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Feature/ProbeIntegrationTest.php

Built-binary smoke test, with loopback listeners and temporary keys/database only:

    cd probe/bridge
    PROBE_TEST_AGENT=/absolute/path/nezha-agent-linux-amd64 \
    PROBE_TEST_DASHBOARD=/absolute/path/probe-dashboard \
    go test -run TestBuiltIntegratedStack -v -timeout 4m

It starts the real Nezha Dashboard and combined Agent, enrolls, receives monitoring,
transfers through a real VLESS node, checks traffic forwarding, changes the origin,
then restarts to check persistence. Its panel endpoint is a fixture; actual PHP handlers
are tested independently by the feature tests.

## Upstream and licensing

UPSTREAM.json records imported revisions. Upstream licenses/notices remain in agent/
and dashboard/. The original upstream Agent command remains for comparison. The embedded
library in pkg/integrated is derived from that pinned command; upstream fixes must also
be ported to the library and checked with integration tests.
