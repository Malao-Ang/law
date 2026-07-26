import 'vuetify/styles';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import '@mdi/font/css/materialdesignicons.css';

// Palette lifted from resources/css/app.css :root custom properties so
// components can use semantic color names instead of raw hex.
// Shared across both themes so every component color name resolves in either.
const sharedColors = {
  'admin-primary': '#1e3a8a',
  'doc-phrb': '#854D0E',        // พระราชบัญญัติ
  'doc-rabiap': '#3B82F6',      // ระเบียบ
  'doc-kho-bangkhab': '#10B981', // ข้อบังคับ
  'doc-prakat': '#FB923C',      // ประกาศ
  accent: '#0f6b5d',
  warning: '#f9b74b',
  error: '#d74747',
  'elaw-navy': '#1a2e52',
  'elaw-gold': '#c9a935',
  'detail-surface': 'rgba(255, 255, 255, 1)',
  surface: '#ffffff',
  'primary-deep': '#0f2f68',
};

export const vuetify = createVuetify({
  components,
  directives,
  icons: { defaultSet: 'mdi' },
  theme: {
    defaultTheme: 'user',
    themes: {
      // Public / user-facing (gold). Applied on all non-admin routes.
      user: {
        colors: {
          ...sharedColors,
          primary: '#ab7f29',
          secondary: 'rgba(52, 48, 40, 1)',
          background: '#f6f4ef',
        },
      },
      // Admin back-office (navy). Applied on /admin/* — see main.ts route guard.
      admin: {
        colors: {
          ...sharedColors,
          primary: '#1e3a8a',
          secondary: '#334155',
          background: '#f4f6fb',
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
    VAutocomplete: { variant: 'outlined', density: 'comfortable', hideDetails: 'auto' },
    VCombobox: { variant: 'outlined', density: 'comfortable', hideDetails: 'auto' },
    VList: { density: 'comfortable' },
    VListItem: { rounded: 'lg' },
    VChip: { rounded: 'lg' },
    VDataTable: { density: 'comfortable' },
    VAlert: { rounded: 'lg' },
    VDialog: { rounded: 'lg' },
  },
});
