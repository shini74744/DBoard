package main

import (
	"context"
	"crypto/sha256"
	"errors"
	"fmt"
	"github.com/shini74744/DBoard/node/internal/upgrade"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func testDownloadOptions() downloadOptions {
	return downloadOptions{client: &http.Client{}, attempts: 3, attemptTimeout: time.Second, timeIdle: 100 * time.Millisecond, retryDelay: time.Millisecond, maxBytes: 1 << 20}
}
func TestDownloadResumesTruncatedResponse(t *testing.T) {
	var calls atomic.Int32
	payload := "abcdefghij"
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		if calls.Load() == 1 {
			w.Header().Set("Content-Length", "10")
			w.Write([]byte(payload[:4]))
			return
		}
		if r.Header.Get("Range") != "bytes=4-" {
			t.Errorf("range %q", r.Header.Get("Range"))
		}
		w.Header().Set("Content-Range", "bytes 4-9/10")
		w.WriteHeader(206)
		w.Write([]byte(payload[4:]))
	}))
	defer server.Close()
	path := filepath.Join(t.TempDir(), "download")
	if err := downloadWithRetry(context.Background(), server.URL, path, testDownloadOptions()); err != nil {
		t.Fatal(err)
	}
	got, _ := os.ReadFile(path)
	if string(got) != payload || calls.Load() != 2 {
		t.Fatalf("%q calls=%d", got, calls.Load())
	}
}
func TestDownloadRangeIgnoredRestartsAndChecksumRejectsCorruption(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("complete")) }))
	defer server.Close()
	path := filepath.Join(t.TempDir(), "download")
	os.WriteFile(path, []byte("oldpartial"), 0600)
	if err := downloadWithRetry(context.Background(), server.URL, path, testDownloadOptions()); err != nil {
		t.Fatal(err)
	}
	manifest := []byte(fmt.Sprintf("%x  node\n", sha256.Sum256([]byte("complete"))))
	if err := verifyReleaseFile(path, "node", manifest); err != nil {
		t.Fatal(err)
	}
	os.WriteFile(path, []byte("corrupt"), 0600)
	if verifyReleaseFile(path, "node", manifest) == nil {
		t.Fatal("accepted corruption")
	}
}
func TestDownloadStallAndPermanentFailureAreBounded(t *testing.T) {
	for _, stall := range []bool{false, true} {
		t.Run(fmt.Sprint(stall), func(t *testing.T) {
			var calls atomic.Int32
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				calls.Add(1)
				if !stall {
					w.WriteHeader(404)
					return
				}
				w.Header().Set("Content-Length", "100")
				w.Write([]byte("x"))
				w.(http.Flusher).Flush()
				<-r.Context().Done()
			}))
			defer server.Close()
			opt := testDownloadOptions()
			opt.timeIdle = 15 * time.Millisecond
			started := time.Now()
			err := downloadWithRetry(context.Background(), server.URL, filepath.Join(t.TempDir(), "part"), opt)
			if err == nil {
				t.Fatal("wanted failure")
			}
			if time.Since(started) > time.Second {
				t.Fatal("stall unbounded")
			}
			if !stall && calls.Load() != 1 {
				t.Fatalf("retried permanent error %d", calls.Load())
			}
		})
	}
}
func TestHealthRequiresStableProcess(t *testing.T) {
	var calls atomic.Int32
	err := waitStableHealth(100*time.Millisecond, time.Millisecond, func() (string, error) {
		calls.Add(1)
		if calls.Load() == 1 {
			return "", errors.New("starting")
		}
		if calls.Load() < 4 {
			return fmt.Sprint(calls.Load()), nil
		}
		return "stable", nil
	})
	if err != nil || calls.Load() < 6 {
		t.Fatalf("%v calls=%d", err, calls.Load())
	}
}
func TestUpgradeFailuresPreserveOrRestoreExistingBinaries(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("requires root check; all paths and side effects are isolated")
	}
	for _, scenario := range []string{"download", "checksum", "restart", "health", "success"} {
		t.Run(scenario, func(t *testing.T) {
			dir := t.TempDir()
			oldBinary, oldCLI, oldConfig, oldMeta := defaultBinaryPath, defaultCLIPath, defaultConfigPath, defaultMetaPath
			oldDownload, oldRestart, oldHealth, oldLock, oldStatus := upgradeDownload, upgradeRestart, upgradeHealth, upgradeLockPath, upgradeStatusPath
			defer func() {
				defaultBinaryPath, defaultCLIPath, defaultConfigPath, defaultMetaPath = oldBinary, oldCLI, oldConfig, oldMeta
				upgradeDownload, upgradeRestart, upgradeHealth, upgradeLockPath, upgradeStatusPath = oldDownload, oldRestart, oldHealth, oldLock, oldStatus
			}()
			defaultBinaryPath = filepath.Join(dir, "DBoard-node")
			defaultCLIPath = filepath.Join(dir, "xbctl")
			defaultConfigPath = filepath.Join(dir, "config.yml")
			defaultMetaPath = filepath.Join(dir, "meta")
			upgradeLockPath = filepath.Join(dir, "lock")
			upgradeStatusPath = filepath.Join(dir, "status")
			original := []byte("old program")
			os.WriteFile(defaultBinaryPath, original, 0755)
			os.WriteFile(defaultCLIPath, original, 0755)
			node := []byte("#!/bin/sh\necho 'DBoard-node v9.8.7 (test)'\n")
			cli := []byte("#!/bin/sh\necho 'xbctl v9.8.7 (test)'\n")
			manifest := []byte(fmt.Sprintf("%x  DBoard-node-linux-%s\n%x  xbctl-linux-%s\n", sha256.Sum256(node), runtime.GOARCH, sha256.Sum256(cli), runtime.GOARCH))
			upgradeDownload = func(ctx context.Context, url, path string, opt downloadOptions) error {
				if scenario == "download" {
					return errors.New("slow network failed")
				}
				data := node
				if strings.HasSuffix(url, "SHA256SUMS") {
					data = manifest
				} else if strings.Contains(url, "xbctl-linux") {
					data = cli
				}
				if scenario == "checksum" && !strings.HasSuffix(url, "SHA256SUMS") {
					data = []byte("corrupted")
				}
				return os.WriteFile(path, data, 0600)
			}
			restarts, healths := 0, 0
			upgradeRestart = func() error {
				restarts++
				if scenario == "restart" && restarts == 1 {
					return errors.New("restart failed")
				}
				return nil
			}
			upgradeHealth = func(time.Duration) error {
				healths++
				if scenario == "health" && healths == 1 {
					return errors.New("health failed")
				}
				return nil
			}
			err := runUpgrade([]string{"--version", "v9.8.7", "--request-id", "0123456789abcdef"})
			if (err == nil) != (scenario == "success") {
				t.Fatalf("result %v", err)
			}
			state, e := upgrade.ReadStatus(upgradeStatusPath)
			if e != nil || state == nil {
				t.Fatalf("status %v", e)
			}
			got, _ := os.ReadFile(defaultBinaryPath)
			gotCLI, _ := os.ReadFile(defaultCLIPath)
			if scenario == "success" {
				if state.State != "success" || string(got) != string(node) {
					t.Fatal("success not installed")
				}
			} else {
				if string(got) != string(original) || string(gotCLI) != string(original) {
					t.Fatal("old binaries not preserved")
				}
				if scenario == "download" || scenario == "checksum" {
					if restarts != 0 {
						t.Fatal("restarted on download failure")
					}
				} else if restarts != 2 || state.State != "rolled_back" {
					t.Fatalf("rollback restarts=%d state=%s", restarts, state.State)
				}
			}
		})
	}
}
