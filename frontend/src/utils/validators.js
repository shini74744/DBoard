


export function isValidEmail(email) {
  if (!email) return false;

  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return emailRegex.test(email);
}


export const validateEmail = (email) => {
  return isValidEmail(email);
};


export const validatePassword = (password) => {
  const result = {
    valid: false,
    message: ''
  };

  if (!password) {
    result.message = '请输入密码';
    return result;
  }

  if (password.length < 8) {
    result.message = '密码长度至少为8个字符';
    return result;
  }

  const hasNumber = /\d/.test(password);
  const hasLowercase = /[a-z]/.test(password);
  const hasUppercase = /[A-Z]/.test(password);
  const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);

  const strength = [hasNumber, hasLowercase, hasUppercase, hasSpecial].filter(Boolean).length;

  if (strength < 3) {
    result.message = '密码强度不足，请包含数字、大小写字母和特殊字符';
    return result;
  }

  result.valid = true;
  return result;
};


export function passwordsMatch(password, confirmPassword) {
  if (!password || !confirmPassword) return false;
  return password === confirmPassword;
}


export const validateRequiredWithMessage = (value, fieldName) => {
  const result = {
    valid: false,
    message: ''
  };

  if (!value || (typeof value === 'string' && value.trim() === '')) {
    result.message = `${fieldName}不能为空`;
    return result;
  }

  result.valid = true;
  return result;
};


export const validateRequired = (value) => {
  return !!value && (typeof value !== 'string' || value.trim() !== '');
};


export function isStrongPassword(password) {
  if (!password) return false;

  const passwordRegex = /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/;
  return passwordRegex.test(password);
}
