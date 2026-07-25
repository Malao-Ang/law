import './bootstrap';
import { loadThaiEditorFonts } from './utils/loadFonts';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import VueApexCharts from 'vue3-apexcharts';
import App from './App.vue';
import { router } from './router';
import { vuetify } from './plugins/vuetify';

loadThaiEditorFonts();

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.use(vuetify);
app.use(VueApexCharts);

// Swap Vuetify theme by route: /admin/* → navy admin theme, everything else → gold user theme.
// ponytail: defensive on the v4 theme API (change() vs global.name ref); drop the fallback once verified.
router.afterEach((to) => {
  const name = to.path.startsWith('/admin') ? 'admin' : 'user';
  const theme = vuetify.theme as unknown as {
    change?: (n: string) => void;
    global?: { name: { value: string } };
  };
  if (typeof theme.change === 'function') theme.change(name);
  else if (theme.global) theme.global.name.value = name;
});

app.mount('#app');
