import CryptoJS from "crypto-js";

const key = window.EZ_CONFIG.API_MIDDLEWARE_KEY;

// 获取或生成 IV
export const randomIv = () => {
  const saveIv = localStorage.getItem('temp_iv');
  if (saveIv) {
    return saveIv;
  } else {
    const b = new Uint8Array(8);
    crypto.getRandomValues(b);
    const hex = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
    localStorage.setItem('temp_iv', hex);

    return hex;
  }
};

// 加密方法
export function Encrypt(data, k, i) {
  try {
    const key = CryptoJS.enc.Utf8.parse(k); // 十六位十六进制数作为密钥
    const iv = CryptoJS.enc.Utf8.parse(i); // 十六位十六进制数作为密钥偏移量
    var srcs = CryptoJS.enc.Utf8.parse(data);
    var encrypted = CryptoJS.AES.encrypt(srcs, key, {
      iv: iv,
      mode: CryptoJS.mode.CBC,
      padding: CryptoJS.pad.Pkcs7,
    });
    return encrypted.toString();
  } catch (error) {
    console.log(error);
  }
}

// 解密方法
export function Decrypt(data, k, i) {
  try {
    const key = CryptoJS.enc.Utf8.parse(k); // 十六位十六进制数作为密钥
    const iv = CryptoJS.enc.Utf8.parse(i); // 十六位十六进制数作为密钥偏移量
    var decrypt = CryptoJS.AES.decrypt(data, key, {
      iv: iv,
      mode: CryptoJS.mode.CBC,
      padding: CryptoJS.pad.Pkcs7,
    });
    var decryptedStr = decrypt.toString(CryptoJS.enc.Utf8);
    return decryptedStr.toString();
  } catch (error) {
    console.log(error);
  }
}

export const getEncrypUrl = (url) => {
  const iv = randomIv();
  const encrypted = Encrypt(url, key, iv);
  return encrypted;
}
