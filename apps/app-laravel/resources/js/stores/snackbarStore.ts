import { defineStore } from 'pinia';
import { ref } from 'vue';

export type SnackbarColor = 'success' | 'error' | 'info';

export const useSnackbarStore = defineStore('snackbar', () => {
  const show = ref(false);
  const message = ref('');
  const color = ref<SnackbarColor>('info');

  function notify(msg: string, c: SnackbarColor): void {
    message.value = msg;
    color.value = c;
    // Re-trigger the snackbar timeout even if one is already visible.
    show.value = false;
    show.value = true;
  }

  function success(msg: string): void {
    notify(msg, 'success');
  }

  function error(msg: string): void {
    notify(msg, 'error');
  }

  return { show, message, color, success, error };
});
