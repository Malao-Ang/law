# FIGMA.md — Design-System Rules for Figma → Code (MCP)

Rules for translating Figma designs into this app's code. Read this before
generating any component from a Figma frame. Stack facts are verified against
the codebase, not assumed.

**TL;DR stack:** Vue 3 + TypeScript (strict) · **Vuetify 4** (Material, MDI icons) ·
Pinia · vue-router 4 · TipTap editor · **Vite 6** via `laravel-vite-plugin`.
**No Tailwind, no CSS Modules, no styled-components.** Styling = Vuetify
components + one global stylesheet of CSS custom properties.

---

## 1. Token Definitions

Tokens live in **two places** — keep them in sync when adding a color:

**a) CSS custom properties** — `resources/css/app.css` `:root` (source of truth for bespoke/document styling)
```css
:root {
  --accent: #0f6b5d;       --border: #d8dde4;
  --law-primary: #123f8c;  --elaw-navy: #1a2e52;
  --doc-font-sarabun: "Sarabun", "Noto Sans Thai", sans-serif;
  --badge-prakat: #ea580c; --badge-prakat-bg: #ffedd5;   /* doc-type badges */
}
```
Prefixes: `--law-*` (legal reader UI), `--elaw-*` (e-law public site),
`--badge-*` (document-type chips), `--doc-font-*` (Thai font stacks).

**b) Vuetify theme colors** — `resources/js/plugins/vuetify.ts` (source of truth for Vuetify components)
```ts
theme: { themes: { light: { colors: {
  primary: '#ab7f29', secondary: 'rgba(52,48,40,1)',
  accent: '#0f6b5d', warning: '#f9b74b', error: '#d74747',
  'doc-prakat': '#FB923C', 'elaw-navy': '#1a2e52', surface: '#ffffff',
  background: '#f6f4ef',
}}}}
```

**Rule for Figma-mapped colors:**
- Inside a Vuetify component prop (`color="primary"`, `<v-btn color="accent">`) → use a **theme color name** from `vuetify.ts`.
- In bespoke CSS (document canvas, overlays, badges) → use a **`var(--token)`** from `app.css`.
- ⚠️ The two palettes overlap but **`primary` differs**: Vuetify `primary` = `#ab7f29` (gold), CSS `--accent` = `#0f6b5d` (teal). Don't assume a Figma "primary" maps to either — confirm the semantic role first. If a Figma token has no match, add it to **both** files, don't hardcode the hex in a component.
- No token transformation pipeline (no Style Dictionary / no build step). Tokens are plain CSS vars + a JS object.

---

## 2. Component Library

- **Base components: Vuetify 4** (`vuetify/components`, globally registered in `plugins/vuetify.ts`). Reach for a `<v-*>` component before writing a custom one.
- **App components:** `resources/js/components/<feature>/` — SFCs (`<script setup lang="ts">`).
  - `shared/` — cross-feature (`AppShell`, `LawspaceShell`, navbars, `GlobalSnackbar`, `UploadForm`)
  - `review/` `compose/` `rag/` `law/` `admin/` `permissions/` — feature-scoped
- **Component defaults** are set once in `vuetify.ts` — respect them, don't re-specify:
  ```ts
  VCard:      { variant: 'flat', border: true, rounded: 'lg' }
  VBtn:       { variant: 'flat' }
  VTextField/VSelect/VTextarea: { variant: 'outlined', density: 'comfortable', hideDetails: 'auto' }
  ```
  A Figma card → `<v-card>` (already flat+bordered+rounded-lg); only override when the design truly diverges.
- **Rich-text editing = TipTap** (`@tiptap/vue-3`), with custom extensions in `resources/js/extensions/` (`FontSizeExtension`, `IndentExtension`, `PageBreakExtension`, …). Any "document editor" frame maps here, not to a textarea.
- **No Storybook.** No component catalog — discover props by reading the SFC.

---

## 3. Frameworks & Libraries

| Concern | Choice |
|---|---|
| UI framework | Vue **3.5** (`<script setup lang="ts">`, Composition API) |
| Component lib | **Vuetify 4** (Material Design) |
| State | **Pinia** (`resources/js/stores/*.ts`) |
| Routing | **vue-router 4** (`resources/js/router/index.ts`) |
| Charts | ApexCharts (`vue3-apexcharts`) |
| Editor | TipTap 2 |
| Dialogs/toasts | SweetAlert2 + a Pinia `snackbarStore` / `GlobalSnackbar` |
| Sanitization | DOMPurify (client) — always sanitize injected HTML |
| Build/bundler | **Vite 6** + `laravel-vite-plugin`; entry `resources/js/main.ts` + `resources/css/app.css` |
| TS | **strict** (`tsconfig.json`) — no `any`/`unknown` in new code |

Type-check with `npm run typecheck` (`tsc --noEmit`); production build `npm run build`.

---

## 4. Asset Management

- Static assets served from Laravel **`public/`** (fonts under `public/fonts/`).
- Document **images/thumbnails are dynamic**, served by the Python service (`/data/poc/images/...`, `/data/poc/pages/...`) — not bundled. Don't import these into JS.
- No CDN, no image-optimization pipeline. Vite fingerprints bundled assets on build; `public/` files are served as-is.
- Figma-exported raster assets → drop in `public/`, reference by absolute path (`/fonts/...`, `/img/...`). Prefer SVG/inline over PNG for icons (see §5).

---

## 5. Icon System

- **Material Design Icons** via `@mdi/font` (`icons: { defaultSet: 'mdi' }`).
- Usage: `<v-icon icon="mdi-file-document" />` or the `icon`/`prepend-icon`/`append-icon` prop on Vuetify components.
- Naming: **`mdi-*` kebab-case**. Map a Figma icon to the closest MDI name — do **not** add one-off SVG files when an MDI glyph exists. Browse names at materialdesignicons.com.
- Only add a raw SVG (in the SFC or `public/`) when no MDI equivalent exists.

---

## 6. Styling Approach

- **Scoped SFC `<style scoped>`** for component-local styles; **`resources/css/app.css`** for global tokens + shared document/editor classes (`.document-rich-editor`, `.doc-block`, `.overlay-block`, table styling, indent utilities `.doc-indent-0..10`).
- **Vuetify utility/props** (`density`, `variant`, `rounded`, `elevation`, spacing) over hand-rolled CSS.
- **Responsive:** plain CSS media queries (breakpoints seen: `1180px`, `960px`) + Vuetify's grid/display utilities. Match Figma breakpoints to these.
- **Thai typography is first-class.** Body font `"Sarabun", "Noto Sans Thai"`. Document canvas uses `--doc-font-*` stacks; the TipTap editor loads Thai fonts at runtime via `resources/js/utils/loadFonts.ts` (`loadThaiEditorFonts()`, called in `main.ts`) + `@font-face` in `app.css` (TH Sarabun New / PSK / IT9, in `public/fonts/`). When a Figma text layer is Thai, apply a `--doc-font-*` stack — never a bare Latin font.

---

## 7. Project Structure

```
apps/app-laravel/
├─ resources/
│  ├─ css/app.css              # global tokens (:root) + shared doc/editor CSS
│  └─ js/
│     ├─ main.ts               # app bootstrap (pinia, router, vuetify, apexcharts, fonts)
│     ├─ App.vue router/       # vue-router routes
│     ├─ pages/<feature>/      # route-level views (admin, review, rag, law, preview, public, …)
│     ├─ components/<feature>/ # SFCs grouped by feature (+ shared/)
│     ├─ stores/               # Pinia stores (one per domain)
│     ├─ extensions/           # TipTap editor extensions
│     ├─ composables/          # use* hooks
│     ├─ plugins/vuetify.ts    # theme + component defaults
│     ├─ utils/ · types/ · api/client.ts · constants/
└─ public/fonts/               # Thai TTFs
```

**Feature-first organization.** A new screen = a `pages/<feature>/XxxPage.vue`
+ route + a Pinia store for its data + feature components under
`components/<feature>/`. Follow the existing feature's shape (e.g. review →
`ReviewPage` → `DocumentEditorShell` + `documentStore`).

---

## Figma → code checklist (apply every time)
1. Screen or component? Screen → `pages/<feature>/`; reusable → `components/<feature>/` (or `shared/`).
2. Map every element to a Vuetify `<v-*>` first; custom SFC only if none fits.
3. Colors → Vuetify theme name (in props) or `var(--token)` (in CSS); add missing tokens to **both** files, never hardcode hex.
4. Icons → `mdi-*`.
5. Thai text → `--doc-font-*` stack.
6. Respect the `vuetify.ts` component defaults; don't restate them.
7. `<script setup lang="ts">`, strict types, no `any`.
8. Run `npm run typecheck` before calling it done.
