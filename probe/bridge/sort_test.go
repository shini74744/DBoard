package bridge

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"
)

func TestSortControlValidatesBeforeUpdating(t *testing.T) {
	reg, err := NewRegistry(filepath.Join(t.TempDir(), "devices.json"))
	if err != nil {
		t.Fatal(err)
	}
	if err = reg.Put(Device{UUID: deviceID, ServerID: 7}); err != nil {
		t.Fatal(err)
	}
	key := ID()
	calls := 0
	g := &Gateway{Registry: reg, ControlKey: key, Sort: func(items []SortItem) error {
		calls++
		if len(items) != 1 || items[0].Sort != 3 {
			t.Fatal(items)
		}
		return nil
	}}
	request := func(method, path, token string, body any) int {
		data, _ := json.Marshal(body)
		r := httptest.NewRequest(method, Prefix+path, bytes.NewReader(data))
		r.Header.Set("Authorization", "Bearer "+token)
		w := httptest.NewRecorder()
		g.Handler(http.NotFoundHandler()).ServeHTTP(w, r)
		return w.Code
	}
	good := map[string]any{"items": []SortItem{{UUID: deviceID, Sort: 3}}}
	if c := request("POST", "/control/devices/sort", "bad", good); c != 401 {
		t.Fatal(c)
	}
	if c := request("GET", "/control/devices/sort", key, good); c != 405 {
		t.Fatal(c)
	}
	for _, items := range [][]SortItem{nil, {{UUID: deviceID, Sort: -1}}, {{UUID: deviceID, Sort: 1000000001}}, {{UUID: deviceID, Sort: 1}, {UUID: deviceID, Sort: 2}}, {{UUID: "invalid", Sort: 1}}} {
		if c := request("POST", "/control/devices/sort", key, map[string]any{"items": items}); c != 422 {
			t.Fatal(c)
		}
	}
	if calls != 0 {
		t.Fatal("invalid order dispatched")
	}
	if c := request("POST", "/control/devices/sort", key, good); c != 200 || calls != 1 {
		t.Fatal(c, calls)
	}
	d, _ := reg.Get(deviceID)
	d.Deleted = true
	reg.Put(d)
	if c := request("POST", "/control/devices/sort", key, good); c != 422 {
		t.Fatal(c)
	}
}
func TestDeviceProvisionCarriesOptionalSort(t *testing.T) {
	reg, _ := NewRegistry(filepath.Join(t.TempDir(), "devices.json"))
	key := ID()
	calls := 0
	g := &Gateway{Registry: reg, ControlKey: key, Provision: func(d Device) (uint64, error) {
		calls++
		if d.Sort == nil || *d.Sort != 9 {
			t.Fatal("sort missing")
		}
		return 7, nil
	}}
	body := []byte(`{"uuid":"c9a2215c-a675-448e-b6ac-c96dd420a79b","enabled":true,"sort":9}`)
	r := httptest.NewRequest("POST", Prefix+"/control/device", bytes.NewReader(body))
	r.Header.Set("Authorization", "Bearer "+key)
	w := httptest.NewRecorder()
	g.Handler(http.NotFoundHandler()).ServeHTTP(w, r)
	if w.Code != 200 || calls != 1 {
		t.Fatal(w.Code, calls)
	}
}
