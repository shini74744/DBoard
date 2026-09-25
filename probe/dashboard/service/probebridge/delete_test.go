package probebridge

import (
	"github.com/nezhahq/nezha/model"
	"github.com/nezhahq/nezha/service/singleton"
	"github.com/shini74744/DBoard/probe/bridge"
	"github.com/stretchr/testify/require"
	"gorm.io/driver/sqlite"
	"gorm.io/gorm"
	"path/filepath"
	"testing"
)

func TestDeleteServerRemovesInventoryByUUIDAndPreservesOthers(t *testing.T) {
	oldDB, oldServers, oldTransfers, oldRegistry := singleton.DB, singleton.ServerShared, singleton.ServerTransferShared, Registry
	db, err := gorm.Open(sqlite.Open(":memory:"), &gorm.Config{})
	require.NoError(t, err)
	sqlDB, err := db.DB()
	require.NoError(t, err)
	sqlDB.SetMaxOpenConns(1)
	require.NoError(t, db.AutoMigrate(&model.Server{}, &model.ServerGroupServer{}, &model.Transfer{}, &model.ServerTransfer{}))
	singleton.DB = db
	a := model.Server{Common: model.Common{ID: 1, UserID: 1}, UUID: "c9a2215c-a675-448e-b6ac-c96dd420a79b", Name: "111"}
	b := model.Server{Common: model.Common{ID: 2, UserID: 2}, UUID: "02c72ffc-d6c2-41c8-a761-fb0e4dbb4182", Name: "keep"}
	require.NoError(t, db.Create(&a).Error)
	require.NoError(t, db.Create(&b).Error)
	singleton.ServerShared = singleton.NewServerClass()
	singleton.ServerTransferShared = singleton.NewServerTransferClass()
	t.Cleanup(func() {
		singleton.ServerTransferShared.Stop()
		singleton.DB = oldDB
		singleton.ServerShared = oldServers
		singleton.ServerTransferShared = oldTransfers
		Registry = oldRegistry
		sqlDB.Close()
	})
	require.NoError(t, db.Create(&model.ServerGroupServer{ServerId: 1, ServerGroupId: 7}).Error)
	require.Error(t, deleteServer(2, a.UUID))
	require.NoError(t, deleteServer(1, a.UUID))
	require.NoError(t, deleteServer(1, a.UUID))
	var count int64
	require.NoError(t, db.Model(&model.Server{}).Where("id = ?", 1).Count(&count).Error)
	require.Zero(t, count)
	require.NoError(t, db.Model(&model.ServerGroupServer{}).Where("server_id = ?", 1).Count(&count).Error)
	require.Zero(t, count)
	_, ok := singleton.ServerShared.Get(1)
	require.False(t, ok)
	_, ok = singleton.ServerShared.UUIDToID(a.UUID)
	require.False(t, ok)
	_, ok = singleton.ServerShared.Get(2)
	require.True(t, ok)
	Registry, err = bridge.NewRegistry(filepath.Join(t.TempDir(), "devices.json"))
	require.NoError(t, err)
	require.NoError(t, Registry.Put(bridge.Device{UUID: a.UUID, ServerID: 1, Deleted: true}))
	_, handled, authorized := Authenticate(a.UUID, "old-or-global-secret")
	require.True(t, handled)
	require.False(t, authorized)
}
