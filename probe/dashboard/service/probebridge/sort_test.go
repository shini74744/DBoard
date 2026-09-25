package probebridge

import (
	"github.com/nezhahq/nezha/model"
	"github.com/nezhahq/nezha/service/singleton"
	"github.com/shini74744/DBoard/probe/bridge"
	"github.com/stretchr/testify/require"
	"gorm.io/driver/sqlite"
	"gorm.io/gorm"
	"testing"
)

func TestSortServersIsAtomicAndPreservesMetadata(t *testing.T) {
	oldDB, oldServers := singleton.DB, singleton.ServerShared
	db, err := gorm.Open(sqlite.Open(":memory:"), &gorm.Config{})
	require.NoError(t, err)
	sqlDB, err := db.DB()
	require.NoError(t, err)
	sqlDB.SetMaxOpenConns(1)
	require.NoError(t, db.AutoMigrate(&model.Server{}))
	singleton.DB = db
	a := model.Server{Common: model.Common{ID: 1, UserID: 1}, UUID: "c9a2215c-a675-448e-b6ac-c96dd420a79b", Name: "A", HideForGuest: true, Note: "keep"}
	b := model.Server{Common: model.Common{ID: 2, UserID: 1}, UUID: "02c72ffc-d6c2-41c8-a761-fb0e4dbb4182", Name: "B"}
	foreign := model.Server{Common: model.Common{ID: 3, UserID: 2}, UUID: "152ae93c-46b9-40b0-b1b4-2571269bda4b", Name: "Other", DisplayIndex: -100}
	for _, s := range []*model.Server{&a, &b, &foreign} {
		require.NoError(t, db.Create(s).Error)
	}
	singleton.ServerShared = singleton.NewServerClass()
	t.Cleanup(func() { singleton.DB = oldDB; singleton.ServerShared = oldServers; sqlDB.Close() })
	require.Error(t, sortServers(1, []bridge.SortItem{{UUID: a.UUID, Sort: 8}, {UUID: foreign.UUID, Sort: 1}}))
	var saved model.Server
	require.NoError(t, db.First(&saved, 1).Error)
	require.Zero(t, saved.DisplayIndex)
	require.Error(t, sortServers(1, []bridge.SortItem{{UUID: a.UUID, Sort: 8}, {UUID: "missing", Sort: 1}}))
	require.NoError(t, sortServers(1, []bridge.SortItem{{UUID: b.UUID, Sort: 1}, {UUID: a.UUID, Sort: 2}}))
	require.NoError(t, db.First(&saved, 1).Error)
	require.Equal(t, -2, saved.DisplayIndex)
	require.Equal(t, "keep", saved.Note)
	require.True(t, saved.HideForGuest)
	sorted := singleton.ServerShared.GetSortedList()
	require.Equal(t, uint64(2), sorted[0].ID)
	require.Equal(t, uint64(1), sorted[1].ID)
	singleton.ServerShared = singleton.NewServerClass()
	sorted = singleton.ServerShared.GetSortedList()
	require.Equal(t, uint64(2), sorted[0].ID)
	require.NoError(t, sortServers(1, []bridge.SortItem{{UUID: a.UUID, Sort: 1}, {UUID: b.UUID, Sort: 2}}))
	require.Equal(t, uint64(1), singleton.ServerShared.GetSortedList()[0].ID)
}
