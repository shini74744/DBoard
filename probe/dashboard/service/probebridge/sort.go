package probebridge

import (
	"errors"
	"github.com/nezhahq/nezha/model"
	"github.com/nezhahq/nezha/service/singleton"
	"github.com/shini74744/DBoard/probe/bridge"
	"gorm.io/gorm"
)

// JC sorts ascending; Nezha display weights sort descending.
func sortServers(owner uint64, items []bridge.SortItem) error {
	servers := make([]model.Server, len(items))
	err := singleton.DB.Transaction(func(tx *gorm.DB) error {
		for i, item := range items {
			if err := tx.Where("uuid = ?", item.UUID).First(&servers[i]).Error; err != nil {
				return err
			}
			if servers[i].GetUserID() != owner {
				return errors.New("probe identity belongs to another owner")
			}
		}
		for i, item := range items {
			servers[i].DisplayIndex = -item.Sort
			if err := tx.Model(&servers[i]).Update("display_index", servers[i].DisplayIndex).Error; err != nil {
				return err
			}
		}
		return nil
	})
	if err != nil {
		return err
	}
	for i := range servers {
		s := &servers[i]
		if live, ok := singleton.ServerShared.Get(s.ID); ok {
			s.CopyFromRunningServer(live)
		}
		singleton.ServerShared.Update(s, "")
	}
	return nil
}
