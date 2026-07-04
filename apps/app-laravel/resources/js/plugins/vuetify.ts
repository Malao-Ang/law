import 'vuetify/styles';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import '@mdi/font/css/materialdesignicons.css';

// Palette lifted from resources/css/app.css :root custom properties so
// components can use semantic color names instead of raw hex.
export const vuetify = createVuetify({
  components,
  directives,
  icons: { defaultSet: 'mdi' },
  theme: {
    defaultTheme: 'light',
    themes: {
      light: {
        colors: {
          primary: '#123f8c',
          'primary-deep': '#0f2f68',
          accent: '#0f6b5d',
          warning: '#f9b74b',
          error: '#d74747',
          'elaw-navy': '#1a2e52',
          'elaw-gold': '#c9a935',
          surface: '#ffffff',
          background: '#f6f4ef',
        },
      },
    },
  },
  defaults: {
    VCard: { variant: 'flat', border: true, rounded: 'lg' },
    VBtn: { variant: 'flat' },
    VTextField: { variant: 'outlined', density: 'comfortable', hideDetails: 'auto' },
    VSelect: { variant: 'outlined', density: 'comfortable', hideDetails: 'auto' },
    VTextarea: { variant: 'outlined', density: 'comfortable', hideDetails: 'auto' },
  },
});
