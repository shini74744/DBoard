# Reproducible dependency patches

The module cache is unchanged. go.mod points to these version-pinned, licensed source copies.

- `sing`: github.com/sagernet/sing v0.8.2.
  - Protect TimeoutPacketConn activity/timing shared by UDP read/write goroutines.
- `sing-box`: github.com/cedar2025/sing-box v1.14.0-alpha.2.0.20260316103356-2e665cb7e295.
  - Synchronize gRPC-lite late-reader setup and close. Release Write buffers.
  - Atomic SOCKS authenticator replacement for concurrent user updates.

Upstream LICENSE/NOTICE files remain in each source tree. These patches address
races actually observed under `go test -race` with real TCP/UDP/gRPC and reloads.
No runtime configuration is silently changed to a different proxy core.
