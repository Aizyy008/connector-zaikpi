# Rule: Frontend & theme

**Tailwind CSS v4 + Blade + Vite.** Tailwind v4 is already installed
(`@tailwindcss/vite`, `tailwindcss ^4.0.0`) with CSS-first config in
`resources/css/app.css` (`@import 'tailwindcss'` + `@theme`). There is no
`tailwind.config.js` and we don't need one. The client mockups are the visual
source of truth — see [docs/05-ui-theme.md](../../docs/05-ui-theme.md).

## Do
- Style with **Tailwind utility classes** in Blade. Keep the mockups' palette by
  defining the theme colors as **runtime CSS variables** and mapping them into
  Tailwind via `@theme inline` (so utilities like `bg-panel`/`text-muted` switch
  with the theme). See the theme doc for the exact `app.css` setup.
- Dark/light is toggled via `data-theme="dark|light"` on `<html>` (default `dark`),
  persisted in `localStorage['app-theme']`. Register a custom Tailwind `dark`
  variant keyed to that attribute so `dark:` utilities work with the toggle.
- Build reusable Blade components (`x-app-layout`, `x-card`, `x-stat-card`, `x-badge`,
  `x-status`, `x-chip`, `x-subtabs`, `x-toggle`, `x-progress`, `x-btn`). Encapsulate
  repeated utility strings in these components (or `@apply`), not copy-paste.
- Drive nav `active` state and all data from the backend; replace mockup sample
  rows/counts with real Eloquent data.
- Bundle fonts and all assets through Vite. Inline/hotlink nothing external.
- Keep the sidebar/main responsive behavior (sidebar collapses on small screens).
- Gate action buttons with `@can` matching the permission model.

## Don't
- Don't add a second CSS framework (Bootstrap, etc.) or a heavy JS UI kit.
- Don't hardcode hex colors in markup — use the mapped theme tokens/utilities so
  both themes stay correct.
- Don't hotlink external fonts, CSS, or scripts.
- Don't duplicate the two mockup theme-toggle scripts — ship one unified toggle.
- Don't use `{!! !!}` for user-supplied content.

## Reference
- Raw mockups live in `resources/mockups/` (reference only, not served).
- Palette, Tailwind mapping, component inventory, and porting plan: docs/05-ui-theme.md.
