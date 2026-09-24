# Managed front authentication

DBoard v0.1.12 adds a per-node setting immediately below the bound-server control. It protects a landing node using identities held by managed front programs, without changing host firewall or routing settings.

## Configuration

1. Upgrade the landing and its fronts to v0.1.12 or newer; wait for capability reports.
2. Edit the landing and enable **仅允许指定前置**. Select multiple front nodes and/or permission groups used by plans.
3. Group membership is resolved dynamically. Explicit nodes and group members form a union; the landing itself, disabled nodes, and child aliases are excluded. An empty group does not permit any access.
4. In each front's outbound rules, choose the existing landing node. Authentication is generated automatically. Hand-entered raw node links do not receive managed credentials.
5. Reopen the landing to check whether the saved policy has actually been applied. Authorization changes disconnect existing sessions on that landing.

Enabling the setting uses internal VLESS over TLS 1.3 with mutual certificate authentication on the existing service port. Ordinary client subscriptions cannot connect directly. Disabling restores the original protocol configuration retained in the panel database.

## Security and routing

- Per-node ECDSA keys are encrypted with the panel application key in `dboard_node_identity`.
- The landing trusts only allowed front certificates. Each front verifies the landing identity.
- Private identities are delivered only through authenticated node configuration, never customer subscriptions or admin list responses. Panel keys, node host access, and control-plane credentials remain administrator secrets.
- Empty trust, missing client certificates, unknown fronts, invalid protected configuration, and wrong landing identities fail closed.
- Legacy landing programs cannot enable the setting until they report support.
- Managed outbound IDs, tags, proxy chains, split routes, balancers, original inbound routing tags, and traffic counters are preserved.

## Upgrade and verification

Run panel database migrations through `2026_09_24_000017_add_node_front_gate`. Existing nodes default to disabled; upgrading binaries does not enable restrictions automatically.

Verified with real Xray/sing-box combinations in both directions: TCP, UDP, multiple fronts, unauthorized access rejection, revocation of new and established connections, empty groups, direct/block routing, chained outbounds, balancers, and outbound statistics. Server suites and node-outbound tests passed 40 tests with 291 assertions; desktop and mobile editor checks cover selection, scrolling, persistence, and self exclusion.
