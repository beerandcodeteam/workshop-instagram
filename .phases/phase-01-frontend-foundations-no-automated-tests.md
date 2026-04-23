## Phase 1 — Frontend Foundations (no automated tests)

Goal: establish the visual language and reusable UI primitives. Manual browser verification only — no Pest tests required.

### 1.1 Design tokens (Tailwind theme)
- [x] Extend `resources/css/app.css` `@theme` block with design tokens inspired by Instagram identity:
    - Neutral scale (`--color-neutral-0` white, `--color-neutral-950` near-black, 100/200/300/500/700 mids)
    - Brand gradient stops (`--color-brand-from: #F58529`, `--color-brand-via: #DD2A7B`, `--color-brand-to: #8134AF`) for primary CTAs
    - Semantic tokens: `--color-bg`, `--color-surface`, `--color-border`, `--color-text`, `--color-text-muted`, `--color-danger`, `--color-success`
    - Font: keep `Instrument Sans` as default sans stack
    - Radius tokens: `--radius-sm`, `--radius-md`, `--radius-lg`, `--radius-full`

### 1.2 Base Blade components (folder: `resources/views/components/ui/`)
- [x] **1.2.1** `<x-ui.button>` — variants: `primary` (gradient fill), `secondary` (outline), `ghost`, `danger`; sizes `sm`, `md`, `lg`; loading state slot
- [x] **1.2.2** `<x-ui.input>` — label, error slot, hint slot, `wire:model` compatible
- [x] **1.2.3** `<x-ui.textarea>` — same API as input + character counter slot
- [x] **1.2.4** `<x-ui.select>` — options slot, matching input styling
- [x] **1.2.5** `<x-ui.checkbox>` — checked state, label
- [x] **1.2.6** `<x-ui.radio>` — labelled radio + `<x-ui.radio-group>` wrapper
- [x] **1.2.7** `<x-ui.modal>` — Alpine-powered, teleported to body, `wire:model` compatible open/close, keyboard-dismissable

### 1.3 Layouts (folder: `resources/views/layouts/`)
- [x] **1.3.1** `layouts/guest.blade.php` — centered card layout for login/register, subtle gradient background, app logomark
- [x] **1.3.2** `layouts/app.blade.php` — authenticated layout with:
    - Top bar: logomark, "Create post" button, user menu (avatar initial + logout)
    - Centered single-column content area (Instagram feed width ~470–630px)
    - Flash message slot

### 1.4 Asset pipeline verification
- [x] **1.4.1** Replace default `resources/views/welcome.blade.php` usage so `/` redirects appropriately for Phase 2
- [x] **1.4.2** Run `vendor/bin/sail npm run build` and confirm no Tailwind/Vite errors

---

