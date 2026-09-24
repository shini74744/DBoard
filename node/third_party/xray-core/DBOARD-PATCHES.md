# DBoard Xray patches

Upstream: github.com/cedar2025/Xray-core v0.0.0-20260409213332-f47935539965.

- common/buf/writer.go: count the io.Writer path in BufferToBytesWriter. BufferedWriter used this path to flush initial protocol data and bypassed outbound uplink counters. Preserve the original transport and pipe types, including Vision and mux behavior.

Retain upstream LICENSE and source; all other upstream files are unchanged.
