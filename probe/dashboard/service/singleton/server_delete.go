package singleton

import (
	"github.com/nezhahq/nezha/model"
	"gorm.io/gorm"
)

// DeleteServers removes persisted records and the live inventory together.
// The caller must authorize all IDs before calling.
func DeleteServers(servers []uint64) error {
	err := DB.Transaction(func(tx *gorm.DB) error {
		if err := tx.Unscoped().Delete(&model.Server{}, "id in (?)", servers).Error; err != nil {
			return err
		}
		if err := tx.Unscoped().Delete(&model.ServerGroupServer{}, "server_id in (?)", servers).Error; err != nil {
			return err
		}
		return nil
	})

	if err != nil {
		return err
	}

	AlertsLock.Lock()
	for _, sid := range servers {
		for _, alert := range Alerts {
			if AlertsCycleTransferStatsStore[alert.ID] != nil {
				delete(AlertsCycleTransferStatsStore[alert.ID].ServerName, sid)
				delete(AlertsCycleTransferStatsStore[alert.ID].Transfer, sid)
				delete(AlertsCycleTransferStatsStore[alert.ID].NextUpdate, sid)
			}
		}
	}
	DB.Unscoped().Delete(&model.Transfer{}, "server_id in (?)", servers)
	AlertsLock.Unlock()

	// Cancel any in-flight transfers BEFORE the in-memory ServerShared
	// entry is dropped: the order shortens the window in which a
	// concurrent Retry/Register could install a fresh pending entry for
	// the same serverID and have it wiped by the cleanup. The
	// transferID-guarded delete inside OnServersDeleted is the
	// authoritative protection against that race; the ordering here is
	// belt and braces.
	ServerTransferShared.OnServersDeleted(servers)
	ServerShared.Delete(servers)
	return nil
}
