import './bootstrap';
import { loadThaiEditorFonts } from './utils/loadFonts';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router';
import { vuetify } from './plugins/vuetify';

loadThaiEditorFonts();

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.use(vuetify);

// Swap Vuetify theme by route: /admin/* → navy admin theme, everything else → gold user theme.
// ponytail: defensive on the v4 theme API (change() vs global.name ref); drop the fallback once verified.
router.afterEach((to) => {
  const name = to.path.startsWith('/admin') ? 'admin' : 'user';
  // Keep public text using the original font faces, including its synthesized weights.
  const fontLink = document.querySelector<HTMLLinkElement>('#app-sarabun-font');
  const weights = name === 'admin' ? '400;500;600;700;800' : '400;500;700';
  const fontHref = `https://fonts.googleapis.com/css2?family=Sarabun:wght@${weights}&display=swap`;
  if (fontLink && fontLink.href !== fontHref) fontLink.href = fontHref;
  const theme = vuetify.theme as unknown as {
    change?: (n: string) => void;
    global?: { name: { value: string } };
  };
  if (typeof theme.change === 'function') theme.change(name);
  else if (theme.global) theme.global.name.value = name;
});

app.mount('#app');
