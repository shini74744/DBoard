package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync/atomic"
	"time"
)

type downloadOptions struct {
	client                               *http.Client
	attempts                             int
	attemptTimeout, timeIdle, retryDelay time.Duration
	maxBytes                             int64
	progress                             func(int64, int64, int)
}

func slowDownloadOptions() downloadOptions {
	return downloadOptions{
		client:   &http.Client{Transport: &http.Transport{Proxy: http.ProxyFromEnvironment, DialContext: (&net.Dialer{Timeout: 20 * time.Second, KeepAlive: 30 * time.Second}).DialContext, TLSHandshakeTimeout: 15 * time.Second, ResponseHeaderTimeout: 30 * time.Second, IdleConnTimeout: 90 * time.Second}},
		attempts: 4, attemptTimeout: 15 * time.Minute, timeIdle: 90 * time.Second, retryDelay: 3 * time.Second, maxBytes: 200 << 20,
	}
}
func downloadWithRetry(ctx context.Context, url, dest string, opt downloadOptions) error {
	var last error
	for attempt := 1; attempt <= opt.attempts; attempt++ {
		retry, err := downloadAttempt(ctx, url, dest, opt, attempt)
		if err == nil {
			return nil
		}
		last = err
		if !retry || attempt == opt.attempts || ctx.Err() != nil {
			break
		}
		timer := time.NewTimer(opt.retryDelay * time.Duration(attempt))
		select {
		case <-ctx.Done():
			timer.Stop()
			return ctx.Err()
		case <-timer.C:
		}
	}
	return fmt.Errorf("下载失败，旧程序未替换: %w", last)
}
func downloadAttempt(parent context.Context, url, dest string, opt downloadOptions, attempt int) (bool, error) {
	ctx, cancel := context.WithTimeout(parent, opt.attemptTimeout)
	defer cancel()
	f, err := os.OpenFile(dest, os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return false, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return false, err
	}
	offset := fi.Size()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return false, err
	}
	req.Header.Set("User-Agent", "DBoard-upgrader")
	req.Header.Set("Accept-Encoding", "identity")
	if offset > 0 {
		req.Header.Set("Range", fmt.Sprintf("bytes=%d-", offset))
	}
	resp, err := opt.client.Do(req)
	if err != nil {
		return true, err
	}
	defer resp.Body.Close()
	total := resp.ContentLength
	switch resp.StatusCode {
	case http.StatusOK:
		offset = 0
		if err = f.Truncate(0); err != nil {
			return false, err
		}
	case http.StatusPartialContent:
		var start, end int64
		if _, err = fmt.Sscanf(resp.Header.Get("Content-Range"), "bytes %d-%d/%d", &start, &end, &total); err != nil || start != offset || end < start || end >= total {
			return false, errors.New("invalid resume response")
		}
	case http.StatusRequestedRangeNotSatisfiable:
		if err = f.Truncate(0); err != nil {
			return false, err
		}
		return true, errors.New("resume unavailable; restarting download")
	default:
		return resp.StatusCode == 408 || resp.StatusCode == 429 || resp.StatusCode >= 500, fmt.Errorf("GitHub HTTP %d", resp.StatusCode)
	}
	if total > opt.maxBytes || offset > opt.maxBytes {
		return false, errors.New("download exceeds size limit")
	}
	if _, err = f.Seek(offset, io.SeekStart); err != nil {
		return false, err
	}
	var lastByte atomic.Int64
	lastByte.Store(time.Now().UnixNano())
	done := make(chan struct{})
	defer close(done)
	go func() {
		ticker := time.NewTicker(max(opt.timeIdle/3, time.Millisecond))
		defer ticker.Stop()
		for {
			select {
			case <-done:
				return
			case <-ctx.Done():
				return
			case <-ticker.C:
				if time.Since(time.Unix(0, lastByte.Load())) >= opt.timeIdle {
					cancel()
					return
				}
			}
		}
	}()
	buffer := make([]byte, 128<<10)
	written := offset
	nextReport := time.Time{}
	for {
		n, readErr := resp.Body.Read(buffer)
		if n > 0 {
			lastByte.Store(time.Now().UnixNano())
			if written+int64(n) > opt.maxBytes {
				return false, errors.New("download exceeds size limit")
			}
			count, e := f.Write(buffer[:n])
			written += int64(count)
			if e != nil {
				return false, e
			}
			if count != n {
				return false, io.ErrShortWrite
			}
			if opt.progress != nil && time.Now().After(nextReport) {
				opt.progress(written, total, attempt)
				nextReport = time.Now().Add(2 * time.Second)
			}
		}
		if readErr != nil {
			if readErr != io.EOF {
				return true, readErr
			}
			break
		}
	}
	if total >= 0 && written != total {
		return true, fmt.Errorf("incomplete download: %d/%d bytes", written, total)
	}
	if written == 0 {
		return true, errors.New("empty download")
	}
	if opt.progress != nil {
		opt.progress(written, total, attempt)
	}
	return false, f.Sync()
}
func verifyReleaseFile(path, artifact string, manifest []byte) error {
	expected := ""
	for _, line := range strings.Split(string(manifest), "\n") {
		fields := strings.Fields(line)
		if len(fields) == 2 && strings.TrimPrefix(fields[1], "*") == artifact {
			if expected != "" {
				return errors.New("duplicate checksum entry")
			}
			expected = fields[0]
		}
	}
	decoded, err := hex.DecodeString(expected)
	if err != nil || len(decoded) != sha256.Size {
		return fmt.Errorf("missing or invalid SHA256 for %s", artifact)
	}
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()
	sum := sha256.New()
	if _, err = io.Copy(sum, f); err != nil {
		return err
	}
	if !strings.EqualFold(hex.EncodeToString(sum.Sum(nil)), expected) {
		return fmt.Errorf("SHA256 mismatch for %s; old program retained", artifact)
	}
	return nil
}
func downloadMessage(name string, done, total int64, attempt int) string {
	size := fmt.Sprintf("%.1f MB", float64(done)/(1<<20))
	if total > 0 {
		size += " / " + fmt.Sprintf("%.1f MB", float64(total)/(1<<20))
	}
	return "正在下载" + name + "：" + size + "（第 " + strconv.Itoa(attempt) + " 次尝试）"
}
