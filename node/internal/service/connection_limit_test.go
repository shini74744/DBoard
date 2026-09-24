package service

import (
	"github.com/shini74744/DBoard/node/internal/model"
	"testing"
)

func TestConnectionLimitChangeTriggersUserSync(t *testing.T) {
	users := []model.UserSpec{{ID: 41, UUID: "a", ConnectionLimit: 3}}
	before := computeUserHash(users)
	users[0].ConnectionLimit = 4
	if before == computeUserHash(users) {
		t.Fatal("connection-only update was ignored")
	}
}
