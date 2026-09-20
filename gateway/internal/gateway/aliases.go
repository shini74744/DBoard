package gateway

import (
	"sort"
	"strings"
)

var forwardAliases = map[string]string{
	"/guest/comm/config":             "/g/conf",
	"/user/comm/config":              "/c/conf",
	"/passport/auth/login":           "/auth/login",
	"/passport/auth/register":        "/auth/reg",
	"/passport/auth/forget":          "/auth/forget",
	"/passport/auth/token2Login":     "/auth/token2Login",
	"/passport/comm/sendEmailVerify": "/mail/verify",
	"/user/checkLogin":               "/auth/check",
	"/user/info":                     "/u/info",
	"/user/changePassword":           "/u/pwd",
	"/user/resetSecurity":            "/u/reset",
	"/user/update":                   "/u/update",
	"/user/redeemgiftcard":           "/u/gift",
	"/user/gift-card/redeem":         "/u/gift2",
	"/user/getActiveSession":         "/u/session",
	"/user/getSubscribe":             "/sub/get",
	"/user/getStat":                  "/stat/get",
	"/user/stat/getTrafficLog":       "/traffic/log",
	"/user/plan/fetch":               "/plan/list",
	"/user/coupon/check":             "/coup/check",
	"/user/order/save":               "/order/new",
	"/user/order/fetch":              "/order/list",
	"/user/order/detail":             "/order/detail",
	"/user/order/cancel":             "/order/cancel",
	"/user/order/checkout":           "/order/pay",
	"/user/order/check":              "/order/check",
	"/user/order/getPaymentMethod":   "/pay/methods",
	"/user/server/fetch":             "/node/list",
	"/user/ticket/fetch":             "/ticket/list",
	"/user/ticket/save":              "/ticket/new",
	"/user/ticket/reply":             "/ticket/reply",
	"/user/ticket/close":             "/ticket/close",
	"/user/ticket/withdraw":          "/withdraw",
	"/user/invite/fetch":             "/inv/info",
	"/user/invite/save":              "/inv/new",
	"/user/invite/details":           "/inv/detail",
	"/user/transfer":                 "/comm/transfer",
	"/user/notice/fetch":             "/notice/list",
	"/user/knowledge/fetch":          "/knowledge/list",
}

var reverseAliases map[string]string
var reverseAliasKeys []string

func init() {
	reverseAliases = make(map[string]string, len(forwardAliases))
	for full, short := range forwardAliases {
		reverseAliases[short] = full
		reverseAliasKeys = append(reverseAliasKeys, short)
	}
	sort.Slice(reverseAliasKeys, func(i, j int) bool {
		return len(reverseAliasKeys[i]) > len(reverseAliasKeys[j])
	})
}

func restoreAlias(path string) string {
	for _, short := range reverseAliasKeys {
		if path == short {
			return reverseAliases[short]
		}
		if strings.HasPrefix(path, short+"/") {
			return reverseAliases[short] + path[len(short):]
		}
	}
	return path
}
