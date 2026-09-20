# Dual-core managed routing and balancing

## Scope

The panel's normalized outbounds, structured routes and custom_balancers work
with either selected core. This is not a converter for arbitrary kernel-native
custom_config/custom_routes JSON, nor a claim that every inbound transport
available in one upstream core exists in the other. Unsupported native-only
features must report an explicit error instead of silently dropping options or
changing the selected core. No automatic kernel switching is used for balancing.

## Common balancing contract

Both integrations use internal/balance. Xray dispatches to the chosen existing
outbound handler; sing-box registers a per-instance outbound adapter. Neither
starts a second proxy core, nor opens an extra loopback proxy listener.

- random: random eligible healthy member for each new session.
- round_robin (legacy roundRobin): round-robin new TCP/UDP sessions.
- latency (legacy leastPing): lowest smoothed dedicated probe round-trip time.
- least_load (legacy leastLoad): least active TCP connections / UDP associations,
  with measured latency as a tie-breaker. This is NOT remote CPU/bandwidth load
  and is intentionally not Xray native leastLoad's latency distribution metric.

A TCP connection or UDP association stays pinned. Existing payload is never
replayed against another member. Health changes affect new sessions. Failed
initial dials may be retried by the sing-box adapter before user payload exists;
Xray does not replay a dispatched transport.Link. Native transport error timing
can therefore differ even though the scheduling/health policy is common.

Health is a verified HTTP(S) request through the exact member, no redirect and
no inherited host HTTP proxy. Default probe endpoint is gstatic generate_204,
interval 30 seconds, timeout 5 seconds, 2 failed probes exclude a member.
A failing user destination alone does not mark its whole proxy unhealthy.
Until initial probes complete, unmeasured members are eligible. Recovery re-enables
members. If no eligible member remains, use an explicitly configured healthy
Fallback; otherwise fail closed. No implicit direct fallback.
TCP health probes are not proof of end-to-end UDP reachability. UDP-incompatible
outbounds are excluded where advertised by the core's adapter; operators must
still provision UDP-capable upstream servers for UDP workloads.

## Structured routes

The same IP/domain AND/OR expression and common source/port/network/protocol
conditions are compiled for each core. Empty conditions match all TCP/UDP on the
current node. sing-box needs explicit logical children to avoid widening AND
conditions. DNS resolution occurs only when an IP-dependent rule is reached.
Domain-OR can match before DNS, preserving remote-only domain exits.

GeoSite/GeoIP expand from the same .dat dataset to both cores' supported primitive
rules. IPv4, IPv6, domain attributes and GeoIP inversion are handled. Unknown,
empty or corrupt datasets reject the update; a failed update does not replace a
working configuration. Downloads are size-bounded and atomically renamed.

## Backwards compatibility

Panel fields tag/strategy/selector/fallback_tag are preserved. Canonical strategy
aliases and legacy one-member groups remain accepted. Opening/editing a node no
longer silently clears historical match.ports. New UI groups still recommend at
least two members. Dependencies (including fallback) are added server-side to
outbound_ids. Renaming an exit updates both routes and group member/fallback refs.
Raw native escape hatches remain native to the chosen kernel.

## Validation and deployment

Missing references, duplicate members, invalid protocols/ports/regexes, cycles,
and invalid health settings are rejected before switching the runtime config.
Outbound/group changes reconstruct the dependent runtime, not just route JSON.
Use the release build tags to test REALITY/uTLS. Automated tests use loopback
fixtures and explicit test certificates, not disabled certificate verification.
An external-network soak test is still required before replacing production nodes.
