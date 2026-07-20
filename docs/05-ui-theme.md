# 05 — UI & Theme Integration

The client supplied 14 dual-theme HTML mockups. They are the **visual source of
truth** for IA, navigation, components, and the dark/light palette. This doc
captures the design system and how to port it into **Tailwind CSS v4 + Blade**.

> Stack: Tailwind v4 is already installed (`@tailwindcss/vite`, `tailwindcss
> ^4.0.0`) with CSS-first config in `resources/css/app.css`. No `tailwind.config.js`.
> We keep the mockups' palette by driving Tailwind's theme from runtime CSS
> variables (see "Tailwind v4 setup" below).

## Mockup → route map

| Mockup file | Module / screen |
|---|---|
| `mockup_00-Dashboard_dual_theme.v3.html` | Dashboard (operational summary) |
| `mockup_01-Connector_detail_dual_theme.v3.html` | Connectors (registry + detail) |
| `mockup_02-Capabilities_dual_theme.v3.html` | Capabilities (settings / matrix / conflicts) |
| `mockup_roles_policies_mockup.html` | Roles & Policies |
| `mockup_03-Routing_dual_theme.v3.html` | Routing (entity/event routes, fallbacks) |
| `mockup_04-Mappings_dual_theme.v3.html` | Mappings (field map, value rules, validation, preview) |
| `mockup_05-Canonical_dual_theme.v1.html` | Canonical (schema, dictionaries) |
| `mockup_06-Flows-Automations_dual_themev2.html` | Flows / Automations (builder, preview, runs) |
| `mockup_07.0-Queue_logs_dual_theme.v3.html` | Queue & Logs (monitor, logs, alerts, approvals) |
| `mockup_07.1-...Appr.Review.v2.html` | Approval Review (single-item drilldown) |
| `mockup_08.0-Settings_dual_theme.v2.html` | Settings (users, roles, policies, security, ...) |
| `mockup_08.9-...API_Settings...html` | Settings → API Settings |
| `mockup_08.10-Webhooks_dual_theme.html` | Settings → Webhooks |

> Store the raw mockups under `resources/mockups/` for reference; they are not served.

## Design tokens (CSS custom properties)

Both theme families share one palette. Put this in `resources/css/theme.css` and
import from `app.css`. Theme is selected by `data-theme` on `<html>`.

**Light (`html[data-theme="light"]`):**
```
--bg:#f4f7fb  --bg-soft:#eef3f9  --panel:#fff  --panel-2:#f9fbfe
--border:#dbe3ef  --border-strong:#c7d3e3  --text:#162132  --muted:#66758f
--nav:#0f172a  --nav-2:#13203a  --nav-text:#e7eefc  --nav-muted:#98a8c4
--blue:#2563eb  --green:#16a34a  --amber:#d97706  --red:#dc2626  --purple:#7c3aed
--table-head:#f2f6fb  --chip-bg:#eef4ff  --chip-text:#3051a6
```
**Dark (`html[data-theme="dark"]`, default):**
```
--bg:#0b1120  --bg-soft:#111827  --panel:#121a2b  --panel-2:#172238
--border:#263248  --border-strong:#31405a  --text:#ebf1ff  --muted:#9baccc
--nav:#08101f  --nav-2:#0d1830  --nav-text:#edf3ff  --nav-muted:#90a0be
--blue:#60a5fa  --green:#4ade80  --amber:#fbbf24  --red:#f87171  --purple:#a78bfa
--table-head:#0e1728  --chip-bg:#1b2740  --chip-text:#c4d7ff
```
Shared: `--radius:18px --radius-sm:12px --shadow:0 16px 36px rgba(15,23,42,.10)
--transition:180ms ease --font:Inter, Arial, Helvetica, sans-serif`.

Status colors use the semantic vars + a `-soft` background:
`ok→green · warn→amber · bad/critical→red · info→blue · violet/new→purple`.

## Tailwind v4 setup (how the palette becomes utilities)

Because the same token name has different values per theme and switches at runtime
via `data-theme`, we define the palette as **runtime CSS variables** and expose them
to Tailwind with `@theme inline` (which makes utilities emit `var(--…)` instead of a
baked-in value). Put this in `resources/css/app.css`:

```css
@import 'tailwindcss';

@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../views';

/* Toggle dark/light via the mockups' attribute instead of prefers-color-scheme */
@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *));

/* Runtime, theme-switchable tokens (values from the palette above) */
:root, [data-theme="light"] {
  --bg:#f4f7fb; --bg-soft:#eef3f9; --panel:#fff; --panel-2:#f9fbfe;
  --border:#dbe3ef; --border-strong:#c7d3e3; --text:#162132; --muted:#66758f;
  --nav:#0f172a; --nav-2:#13203a; --nav-text:#e7eefc; --nav-muted:#98a8c4;
  --blue:#2563eb; --green:#16a34a; --amber:#d97706; --red:#dc2626; --purple:#7c3aed;
  --table-head:#f2f6fb; --chip-bg:#eef4ff; --chip-text:#3051a6;
}
[data-theme="dark"] {
  --bg:#0b1120; --bg-soft:#111827; --panel:#121a2b; --panel-2:#172238;
  --border:#263248; --border-strong:#31405a; --text:#ebf1ff; --muted:#9baccc;
  --nav:#08101f; --nav-2:#0d1830; --nav-text:#edf3ff; --nav-muted:#90a0be;
  --blue:#60a5fa; --green:#4ade80; --amber:#fbbf24; --red:#f87171; --purple:#a78bfa;
  --table-head:#0e1728; --chip-bg:#1b2740; --chip-text:#c4d7ff;
}

/* Expose tokens as Tailwind color utilities → bg-panel, text-muted, border-border… */
@theme inline {
  --color-bg: var(--bg);        --color-bg-soft: var(--bg-soft);
  --color-panel: var(--panel);  --color-panel-2: var(--panel-2);
  --color-border: var(--border);--color-border-strong: var(--border-strong);
  --color-text: var(--text);    --color-muted: var(--muted);
  --color-nav: var(--nav);      --color-nav-2: var(--nav-2);
  --color-nav-text: var(--nav-text); --color-nav-muted: var(--nav-muted);
  --color-blue: var(--blue);    --color-green: var(--green);
  --color-amber: var(--amber);  --color-red: var(--red);
  --color-purple: var(--purple);
  --color-table-head: var(--table-head);
  --color-chip-bg: var(--chip-bg); --color-chip-text: var(--chip-text);
  --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
  --radius-card: 18px; --radius-ctl: 12px;
}
```

Result: `bg-panel text-text border-border`, `text-muted`, `bg-blue/10`, the
`dark:` variant, etc. all track the active theme automatically. `<html>` sets
`class="text-text bg-bg font-sans"` and `data-theme` is set by the toggle script.

> **Font:** Vite currently loads *Instrument Sans* (Bunny) but the mockups use
> **Inter**. To match the mockups, swap the `bunny('Instrument Sans', …)` call in
> `vite.config.js` to `bunny('Inter', …)` and keep `--font-sans: 'Inter', …`.

## Layout

`.layout` = CSS grid `280px minmax(0,1fr)` → sticky gradient **sidebar** + scrolling
**main**. Sidebar collapses (`display:none`) under ~920px; a `.mobile-brand` header
shows instead.

## Component inventory (recurring, make these Blade components)

- `x-app-layout` — sidebar + main shell, theme toggle, breadcrumb, page title/subtitle.
- `x-nav` / nav item with optional `.pill` count badge.
- `x-card` (+ `.card-body`) — the base panel.
- `x-stat-card` — label / big value / meta (stat grids).
- `x-badge` / `x-status` — colored pill; variants `green|yellow|red|blue|gray` (badges)
  and `ok|warn|bad|info|violet` (status).
- `x-chip` — tag pill.
- `x-subtabs` — in-module tab strip.
- `x-toggle` (`.switch` / `.switch.on`) — on/off switch (display in mockups; wire to real state).
- `x-progress` — labeled progress bar.
- tables — shared `th/td` styling with uppercase muted headers.
- `x-btn` variants: `primary` (blue→purple gradient), `soft`, `ghost`, plus
  `approve` (green), `reject` (red), `warning` (amber) on the Approval Review screen.

## Theme toggle — unify the two variants

The mockups contain **two** toggle implementations; standardize on one in Blade:

- Family A uses buttons with `data-theme-btn="light|dark"`.
- Family B uses buttons with `data-theme-choice` and class `.theme-btn`.

Ship a single small script (`resources/js/theme.js`, imported from `app.js`): read
`localStorage['app-theme']` (default `dark`), set
`document.documentElement.dataset.theme`, and mark the active control. Keep the
`app-theme` localStorage key for continuity. Set the initial `data-theme` inline in
`<head>` before paint to avoid a flash.

## Porting plan

1. Configure `resources/css/app.css` per **Tailwind v4 setup** above (tokens as
   runtime variables + `@theme inline` mapping + `dark` custom variant + `@source`
   the Blade views so classes aren't purged).
2. Build `x-app-layout` from the sidebar + topbar markup using Tailwind utilities;
   drive nav `active` state from the current route.
3. Turn each recurring pattern into a Blade component; put shared utility strings in
   the component (or a small `@layer components { … @apply … }`) so markup stays lean.
4. Convert each mockup section into a Blade view backed by real data (replace the
   hardcoded sample rows/counts with Eloquent queries).
5. Point the Vite font at **Inter** to match the mockups (see note above).
6. Wire toggles, tabs, and toolbar buttons to real actions + permission `@can` gates.

## Accessibility / polish

- Preserve focus styles; ensure toggle buttons are real `<button>`s (they are).
- Keep contrast in both themes (tokens already tuned).
- Tables scroll horizontally on mobile (`table { display:block; overflow-x:auto }`).
