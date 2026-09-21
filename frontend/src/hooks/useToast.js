import { useToast as useAppToast } from '@/composables/useToast';


export default function useToast() {
  const { showToast } = useAppToast();


  const success = (message, duration = 3000) => {
    showToast(message, 'success', duration);
  };


  const error = (message, duration = 3000) => {
    showToast(message, 'error', duration);
  };


  const warning = (message, duration = 3000) => {
    showToast(message, 'warning', duration);
  };


  const info = (message, duration = 3000) => {
    showToast(message, 'info', duration);
  };

  return {
    success,
    error,
    warning,
    info
  };
}
