package probebridge

import (
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"gorm.io/gorm"

	"github.com/nezhahq/nezha/model"
	"github.com/nezhahq/nezha/service/singleton"
	"github.com/shini74744/DBoard/probe/bridge"
)

var Registry *bridge.Registry

func Wrap(next http.Handler) (http.Handler, error) {
	keyFile := os.Getenv("NEZHA_BRIDGE_KEY_FILE")
	if keyFile == "" {
		return next, nil
	}
	b, e := os.ReadFile(keyFile)
	if e != nil {
		return nil, e
	}
	key := strings.TrimSpace(string(b))
	if len(key) < 32 {
		return nil, errors.New("bridge key is too short")
	}
	public, e := bridge.PublicURL(os.Getenv("NEZHA_BRIDGE_URL"), os.Getenv("NEZHA_BRIDGE_ALLOW_LOCAL") == "1")
	if e != nil {
		return nil, e
	}
	dir := os.Getenv("NEZHA_BRIDGE_DATA")
	if dir == "" {
		dir = "data/bridge"
	}
	reg, e := bridge.NewRegistry(filepath.Join(dir, "devices.json"))
	if e != nil {
		return nil, e
	}
	Registry = reg
	owner, e := strconv.ParseUint(os.Getenv("NEZHA_BRIDGE_OWNER_ID"), 10, 64)
	if e != nil || owner == 0 {
		return nil, errors.New("NEZHA_BRIDGE_OWNER_ID is required")
	}
	var user model.User
	if e = singleton.DB.First(&user, owner).Error; e != nil {
		return nil, fmt.Errorf("bridge owner does not exist")
	}
	g := &bridge.Gateway{Registry: reg, Public: public.String(), ControlKey: key, Artifacts: filepath.Join(dir, "artifacts")}
	g.Provision = func(d bridge.Device) (uint64, error) {
		var s model.Server
		if sid, ok := singleton.ServerShared.UUIDToID(d.UUID); ok {
			if err := singleton.DB.First(&s, sid).Error; err != nil {
				return 0, err
			}
			if s.GetUserID() != owner {
				return 0, errors.New("probe identity belongs to another owner")
			}
			changes := map[string]any{"name": d.Name}
			if d.Sort != nil {
				changes["display_index"] = -*d.Sort
				s.DisplayIndex = -*d.Sort
			}
			if err := singleton.DB.Model(&s).Updates(changes).Error; err != nil {
				return 0, err
			}
			if live, ok := singleton.ServerShared.Get(sid); ok {
				s.CopyFromRunningServer(live)
			}
			s.Name = d.Name
			singleton.ServerShared.Update(&s, d.UUID)
			return sid, nil
		}
		s = model.Server{UUID: d.UUID, Name: d.Name, HideForGuest: true, Common: model.Common{UserID: owner}}
		if d.Sort != nil {
			s.DisplayIndex = -*d.Sort
		}
		if err := singleton.DB.Create(&s).Error; err != nil {
			return 0, err
		}
		model.InitServer(&s)
		singleton.ServerShared.Update(&s, d.UUID)
		return s.ID, nil
	}
	g.Deprovision = func(d bridge.Device) error { return deleteServer(owner, d.UUID) }
	g.Sort = func(items []bridge.SortItem) error { return sortServers(owner, items) }
	return g.Handler(next), nil
}

// Scoped credentials never fall back to a user-global Agent secret.
func Authenticate(uuid, secret string) (uint64, bool, bool) {
	if Registry == nil {
		return 0, false, false
	}
	d, exists := Registry.Get(uuid)
	if !exists {
		return 0, false, false
	}
	_, valid := Registry.Authorize(uuid, secret)
	if !valid {
		return 0, true, false
	}
	s, ok := singleton.ServerShared.Get(d.ServerID)
	return d.ServerID, true, ok && s != nil && s.UUID == uuid
}

func deleteServer(owner uint64, uuid string) error {
	var s model.Server
	err := singleton.DB.Where("uuid = ?", uuid).First(&s).Error
	if errors.Is(err, gorm.ErrRecordNotFound) {
		return nil
	}
	if err != nil {
		return err
	}
	if s.GetUserID() != owner {
		return errors.New("probe identity belongs to another owner")
	}
	return singleton.DeleteServers([]uint64{s.ID})
}
