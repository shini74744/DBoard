
import { useToast } from '@/composables/useToast';

const TOAST_DURATION = 3000;


export const showToast = (options) => {
  let message, type, duration;

  if (typeof options === 'string') {
    message = options;
    type = arguments[1] || 'info';
    duration = TOAST_DURATION;
  }
  else {
    message = options.message;
    type = options.type || 'info';
    duration = TOAST_DURATION;
  }

  const { showToast: createToast } = useToast();
  return createToast(message, type, duration);
};
