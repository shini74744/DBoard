//go:build !agentcompat

package integrated

func fsWriteUTF8Data(content string) []byte {
	return []byte(content)
}
