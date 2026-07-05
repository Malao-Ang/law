import { defineStore } from 'pinia';
import { nextTick, ref } from 'vue';

export type SnackbarColor = 'success' | 'error' | 'info';

export const useSnackbarStore = defineStore('snackbar', () => {
  const show = ref(false);
  const message = ref('');
  const color = ref<SnackbarColor>('info');

  function notify(msg: string, c: SnackbarColor): void {
    // Re-trigger the snackbar timeout even if one is already visible:
    // close now, reopen after Vue flushes so v-snackbar restarts its timer.
    show.value = false;
    void nextTick(() => {
      message.value = msg;
      color.value = c;
      show.value = true;
    });
  }

  function success(msg: string): void {
    notify(msg, 'success');
  }

  function error(msg: string): void {
    notify(msg, 'error');
  }

  return { show, message, color, success, error };
});
