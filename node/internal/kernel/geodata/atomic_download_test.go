package geodata

import (
	"bytes"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync"
	"testing"
)

func TestConcurrentAtomicDownload(t *testing.T) {
	body := bytes.Repeat([]byte("complete-dataset\n"), 1024)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write(body) }))
	defer srv.Close()
	target := filepath.Join(t.TempDir(), "dataset.dat")
	var wg sync.WaitGroup
	fail := make(chan error, 8)
	for i := 0; i < 8; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if err := atomicDownload(target, srv.URL); err != nil {
				fail <- err
			}
		}()
	}
	wg.Wait()
	close(fail)
	for err := range fail {
		t.Error(err)
	}
	got, err := os.ReadFile(target)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, body) {
		t.Fatal("concurrent writes produced partial dataset")
	}
	files, err := filepath.Glob(filepath.Join(filepath.Dir(target), ".geodata-*.tmp"))
	if err != nil || len(files) != 0 {
		t.Fatalf("temporary downloads leaked: %v %v", files, err)
	}
}
func TestFailedDownloadPreservesDataset(t *testing.T) {
	target := filepath.Join(t.TempDir(), "dataset.dat")
	if err := os.WriteFile(target, []byte("existing"), 0600); err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(503) }))
	defer srv.Close()
	if err := atomicDownload(target, srv.URL); err == nil {
		t.Fatal("503 accepted")
	}
	got, err := os.ReadFile(target)
	if err != nil || string(got) != "existing" {
		t.Fatal("failed download replaced current data")
	}
}
