package gateway

import (
	"crypto/aes"
	"crypto/cipher"
	"encoding/base64"
	"errors"
	"fmt"
)

func decryptPathToken(token, ivText string, key []byte) (string, error) {
	if len(ivText) != aes.BlockSize {
		return "", fmt.Errorf("X-IV must be %d bytes", aes.BlockSize)
	}
	outer, err := base64.StdEncoding.DecodeString(token)
	if err != nil {
		return "", errors.New("invalid outer base64")
	}
	ciphertext, err := base64.StdEncoding.DecodeString(string(outer))
	if err != nil {
		return "", errors.New("invalid encrypted payload")
	}
	if len(ciphertext) == 0 || len(ciphertext)%aes.BlockSize != 0 {
		return "", errors.New("invalid ciphertext length")
	}

	block, err := aes.NewCipher(key)
	if err != nil {
		return "", err
	}
	plain := make([]byte, len(ciphertext))
	cipher.NewCBCDecrypter(block, []byte(ivText)).CryptBlocks(plain, ciphertext)

	plain, err = pkcs7Unpad(plain, aes.BlockSize)
	if err != nil {
		return "", err
	}
	return string(plain), nil
}

func pkcs7Unpad(in []byte, blockSize int) ([]byte, error) {
	if len(in) == 0 || len(in)%blockSize != 0 {
		return nil, errors.New("invalid padded plaintext")
	}
	n := int(in[len(in)-1])
	if n < 1 || n > blockSize || n > len(in) {
		return nil, errors.New("invalid PKCS7 padding")
	}
	for _, b := range in[len(in)-n:] {
		if int(b) != n {
			return nil, errors.New("invalid PKCS7 padding")
		}
	}
	return in[:len(in)-n], nil
}
