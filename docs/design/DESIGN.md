# August ERP Design Reference

Master UI reference for every August ERP build (Laravel + Filament v4/v5).
Give this file to whoever (or whichever agent) builds screens. Rules here win over Filament defaults.

Chosen config (from the style configurator, 2026-10-04):

```json
{ "font": "Geist", "nav": "floating", "surface": "layered", "tone": "warm",
  "density": "comfy", "table": "hover", "input": "outline", "radius": "14px", "accent": "#2f5bea" }
```

Character: modern and polished. Calm cool-grey canvas, white cards that float above it with soft layered shadows, one confident blue accent, Geist for everything, numbers in tabular figures.

---

## 1. Design tokens

All values are CSS custom properties. `theme.css` defines them; components only use tokens, never raw hex.

### Color (light, the default)

| Token | Value | Use |
|---|---|---|
| `--ae-canvas` | `#e3e7f2` | Page background behind cards (layered look) |
| `--ae-surface` | `#ffffff` | Cards, sidebar, table, modal, dropdown |
| `--ae-surface-sunken` | `#f6f6f3` | Table header row, read-only fields, code blocks |
| `--ae-ink` | `#1d2433` | Primary text, headings, numbers |
| `--ae-muted` | `#5d6475` | Labels, helper text, secondary column text (4.7:1 on the canvas, 5.9:1 on white) |
| `--ae-placeholder` | `#6b7280` | Input placeholders (4.8:1 on white) |
| `--ae-line` | `#e9e4da` | Dividers, table row lines |
| `--ae-card-edge` | `#f2efe9` | 1px card border (very faint, warm) |
| `--ae-input-edge` | `#d1d0ce` | Input border at rest |
| `--ae-accent` | `#2f5bea` | Primary buttons, active nav, links, focus |
| `--ae-accent-strong` | accent mixed 84% with black | Primary button hover |
| `--ae-on-accent` | `#ffffff` | Text and icons on an accent or danger fill |
| `--ae-accent-soft` | `#e6ebfc` | Active nav background, active tab, row hover, selected chips |
| `--ae-focus-ring` | `#dae1fb` | 3px soft ring around a focused input (with its accent border) |
| `--ae-backdrop` | `rgb(15 18 25 / .35)` | Behind modals and slide-overs |

A client's `primary` colour in `config/client.php` replaces `--ae-accent` (and the soft, focus and info
colours derived from it) as well as Filament's palette.

Status colors (badge background / text). These are separate from the accent; never use the accent to mean "success".

| Status | Background | Text | Used for |
|---|---|---|---|
| Success | `#e4f6ea` | `#166534` | Paid, Received, Posted, In stock |
| Warning | `#fff2d4` | `#8a5a00` | Unpaid, Partial, Low stock, Pending approval |
| Danger | `#fde7e7` | `#a11d1d` | Overdue, Rejected, Out of stock, Void |
| Neutral | `#eef0f4` | `#4b5563` | Draft, Cancelled, Archived |
| Info | `#e6ebfc` | `#2f5bea` | Sent, Processing, In transit |

Trend text: up `#15803d`, down `#b42318`.

### Workspace shell and module tiles

| Token | Value | Use |
|---|---|---|
| `--ae-rail-width` | `56px` | The icon rail on the left |
| `--ae-rail-bg` | `--ae-ink` | Rail background |
| `--ae-rail-icon` | `#c3cbdc` | Rail icons at rest |
| `--ae-rail-icon-active` | `#ffffff` | Rail icon open, hovered or focused; the 2px keyboard ring |
| `--ae-rail-hover` | `rgb(255 255 255 / .12)` | Rail button background when open or hovered |
| `--ae-topbar-height` | `56px` | Topbar of the workspace |
| `--ae-tabstrip-height` | `38px` | Strip of open screens under the topbar |
| `--ae-tabstrip-line` | `#cbd2e0` | The strip's bottom line; the active tab's 2px accent underline sits on it |
| `--ae-side-tabs-width` | `48px` | Column of icon tabs at the left of a form |

Tiles in a module menu are coloured by what the screen is for (`MenuKey::kind()`):

| Kind | Background | Edge | Icon | Screens |
|---|---|---|---|---|
| Setup | `#eaf0fd` | `#bfd0f6` | `#2f5bea` | Masters and settings: customers, items, accounts, tax codes |
| Work | `#e7f6ec` | `#b7e0c3` | `#166534` | Documents and processes: orders, invoices, payments, month-end |
| Tool | `#f2edfd` | `#d7c9f6` | `#6941c6` | Inquiries, tools and reports: stock inquiries, calendar, activity log, reports |

### Color (dark mode)

Light is the default. Each user picks Light, Dark or System in the user menu (Filament's theme switcher);
the choice is kept in the browser and every open workspace tab follows at once. Printed documents stay
white. Every text and badge pair below meets WCAG AA (6:1 or better).

Dark is quiet on purpose: neutrals do the work, and colour keeps its roles (action, selection, status, kind)
at about 60% of the light theme's chroma, so nothing glows on the dark canvas. Same hues, never neon.

| Token | Value | Note |
|---|---|---|
| `--ae-canvas` | `#0f1219` | |
| `--ae-surface` | `#171b25` | |
| `--ae-surface-sunken` | `#1d2230` | |
| `--ae-ink` | `#e7eaf2` | |
| `--ae-muted` | `#9aa3b5` | 6.3:1 or better on every surface |
| `--ae-placeholder` | `#8a93a6` | |
| `--ae-line` / `--ae-card-edge` / `--ae-input-edge` | `#262c3a` / `#232937` / `#343b4d` | |
| `--ae-accent` | `#97afee` | Text, links, active tab and focus |
| `--ae-accent-fill` | `#435fa7` | Primary button fill (white text 6.1:1); in light it is the accent |
| `--ae-accent-soft` / `--ae-focus-ring` | `#21293e` / `#2f3c5d` | |
| `--ae-danger-fill` | `#8d3936` | Danger buttons (light: `#a11d1d`) |
| `--ae-rail-bg` | `#0b0e14` | The rail stays dark in both themes (light: `#1d2433`) |
| `--ae-tabstrip-line` | `#2a3142` | |

Status badges (background / text): success `#18291c` / `#94cea3`, warning `#302414` / `#e5c287`, danger
`#331d1c` / `#e9a29f`, neutral `#232937` / `#c0c7d4`, info `#1f2638` / `#a6b9ec`. Tiles keep their kind as a
tint and an icon colour, not a colour block: setup `#1a1e29` / `#2c3242` / `#a3b6e8`, work `#172119` /
`#28372c` / `#94c6a0`, tool `#1f1c28` / `#343041` / `#baafe0`. Shadows are black-based.

Filament's own colours follow: its darkest grays (`--gray-950`, `--gray-900`, `--gray-800`) point at the
canvas, surface and sunken tokens, and its primary, info, success, warning and danger shades 300 to 700 are
redefined at the same lightness with lower chroma (OKLCH, in `theme.css`). A table row's actions name
themselves in ink and keep their meaning in the icon's colour.

### Typography

- Family: **Geist** (UI and headings), **Geist Mono** (codes like SKU, document numbers in print, API keys).
- Self-host both (npm `@fontsource-variable/geist` and `@fontsource-variable/geist-mono`). Do not load from Google Fonts: clients on intranet or in China will get a fallback font.
- Fallback stack: `"Geist Variable", Geist, ui-sans-serif, system-ui, "Segoe UI", sans-serif`.
- All numeric table cells, KPI values and totals: `font-variant-numeric: tabular-nums`.

| Role | Size / line-height | Weight | Notes |
|---|---|---|---|
| Page title | 22px / 28px | 600 | letter-spacing -0.01em |
| Section / card title | 15px / 22px | 600 | |
| Body / table cell | 13.5px / 20px | 400 | Base size of the panel |
| Label / helper | 12px / 16px | 500 | `--ae-muted` |
| Table header | 11px / 16px | 600 | UPPERCASE, letter-spacing 0.04em, `--ae-muted` |
| KPI value | 22px / 28px | 700 | tabular-nums, letter-spacing -0.01em |
| Badge | 11px / 16px | 600 | with 6px status dot before text |

### Radius

Base radius `--ae-radius: 14px`. Derived sizes keep shapes nested correctly:

| Token | Value | Use |
|---|---|---|
| `--ae-radius` | 14px | Cards, sidebar, modal, table container |
| `--ae-radius-md` | 10px | Buttons, inputs, selects, nav items |
| `--ae-radius-sm` | 8px | Tabs, chips, small icon buttons |
| `--ae-radius-full` | 999px | Badges, avatars, toggles |

### Spacing (comfortable density)

4px grid. Page padding 22px. Gap between cards 12px. Card inner padding 16px. Table cell padding 11px vertical, 14px horizontal. Form field gap 12px. Sidebar item padding 8px 9px.

### Elevation (layered surfaces)

| Token | Value | Use |
|---|---|---|
| `--ae-shadow-card` | `0 1px 1px rgb(20 30 60 / .04), 0 12px 28px -8px rgb(20 30 60 / .14)` | Cards, table container, floating sidebar |
| `--ae-shadow-pop` | `0 4px 8px rgb(20 30 60 / .06), 0 24px 48px -12px rgb(20 30 60 / .22)` | Dropdowns, modals, slide-overs |
| `--ae-shadow-btn` | `inset 0 1px 0 rgb(255 255 255 / .2), 0 2px 6px rgb(47 91 234 / .35)` | Primary button only |

Every card = white surface + 1px `--ae-card-edge` border + `--ae-shadow-card`. Nothing else gets a shadow.

### Motion

The transitions.dev token scale (`.claude/skills/transitions-polish`), in `theme.css`. Pick the token by what the
motion does, never by the nearest number. Closing is quicker than opening; nothing bounces.

| Token | Value | Used for |
|---|---|---|
| `--duration-micro` | 80ms | Tooltip intent delay |
| `--duration-quick` | 150ms | Dropdown and modal close, tooltip in, hovers and presses |
| `--duration-fast` | 250ms | Dropdown and modal open, tabs sliding into place |
| `--duration-medium` / `--duration-slow` | 350ms / 400ms | Slide-over close / open |
| `--ease-smooth-out` | `cubic-bezier(0.22, 1, 0.36, 1)` | Every surface that opens, closes or moves |
| `--scale-large` / `-medium` / `-small` / `-tiny` | .96 / .97 / .98 / .99 | Modal and press / dropdown open / tooltip / dropdown close |

- **Module menu**: a menu dropdown growing from the rail (scale .97 to 1, 250ms; out to .99, 150ms).
- **Rail labels**: one tooltip shared by the rail buttons: 80ms intent delay, out at once, and it travels
  between buttons instead of popping again. Keyboard focus shows it too; touch does not.
- **Workspace tabs**: when a tab opens or closes, the others slide to their places (GSAP Flip, 250ms). The one
  JavaScript animation, because CSS cannot move siblings when one leaves; GSAP is bundled by Vite, never a CDN.
- **Press**: buttons, tiles and rail buttons scale to .96 while pressed.
- **Reduced motion**: the state still changes (colour, visibility), travel and scale do not.

---

## 2. Layout and placement

### App shell

```
+--------------------------------------------------------------------------+
| +----+ Topbar: company name ......... [search] [bell] Name/Role [avatar]|
| |RAIL| [Dashboard] [Sales Invoices x] [Items & Services x] [Warehouses x]|  <- tab strip
| |    | +---------------------------------------------------------------+ |
| |home| | the active tab: a live screen in its own frame                | |
| |mod | |   Page header: title ................ [secondary][+ primary]  | |
| |mod | |   Content cards (12px gap)                                     | |
| |... | |                                                               | |
| +----+ +---------------------------------------------------------------+ |
+--------------------------------------------------------------------------+
```

- **Icon rail.** 56px, dark (`--ae-ink`), full height on the left: Dashboard first, then one icon per
  module group (`App\Filament\Modul`), the module name as a tooltip. A module with no screen the user may
  open is not shown.
- **Module menu.** Clicking a rail icon opens a white card (radius 14, pop shadow) next to the rail: the
  module name over an accent rule, then a grid of tiles (about 120px, two-line labels, the screen's own
  icon at 34px), coloured by kind. Escape or a click elsewhere closes it; arrow keys move between tiles.
- **Tabs.** Every screen opens as a tab in the strip under the topbar; the dashboard is the first,
  pinned tab. Tabs are text, not boxes: the active one is ink with a 2px accent underline on the strip's
  line, the others muted. Each tab is a live frame: switching tabs keeps what was typed. Up to 10 tabs; the list of
  tabs (not their typing) comes back after a reload. Closing a tab with unsaved changes asks first. A link
  to another screen opens a new tab; list → record → back stays inside its tab. A page opened on its own
  (bookmark, email link) opens as a tab of the workspace.
- **Inside a tab** the page has no topbar or rail of its own: page header, then content.
- **Narrow screens** (< 1024px): the rail is hidden; a menu button in the topbar opens it as a sheet.
- Topbar sits on the canvas (no white bar): company name left; global search, the approvals bell (the
  documents waiting for this user's approval, each opening as a tab; red count when there are any), the
  user's name over their role (access groups, or Administrator; from 768px wide) and the user menu right.
- Page header: title (and one-line context such as period or warehouse) on the left, actions on the right. Max one primary button per page, placed rightmost.
- Content max width: full width for list and report pages; 1100px for create/edit forms.

### Navigation groups (standard module map)

1. **Dashboard**
2. **Sales**: Customers, Quotations, Sales Orders, Deliveries, Invoices, Returns
3. **Purchasing**: Suppliers, Purchase Requests, Purchase Orders, Goods Receipts, Bills
4. **Inventory**: Products / SKU, Stock Card, Stock Movements, Transfers, Stock Opname, Warehouses
5. **Finance**: Receivables, Payables, Payments, Cash and Bank, Journal, Chart of Accounts
6. **Reports**: Sales, Purchasing, Inventory, Finance
7. **Settings**: Company, Users and Roles, Numbering, Taxes, Printers

### Page types

**A. List page** (Sales Orders, Products, Invoices…)

```
[Page header: title + context ............ Export | + New]
[KPI row: 4 cards, equal width]                      <- optional, only for key lists
[Table card]
  [Tabs: All 48 | Draft 5 | Unpaid 14 | Overdue 3]   <- status filters as tabs with counts
  [Toolbar: search | filters | column toggle]
  [Table rows ........................................]
  [Pagination]
```

Optional right panel (230px card) for a quick-create form or a selected-row preview.

**B. Document page** (one Sales Order, PO, Invoice)

```
[Header: SO-2610-0412  [Status badge]  ...... Print | More | Primary action]
[2 columns: 2/3 | 1/3]
  Left:  Line items table card  ->  Notes card
  Right: Customer card -> Summary card (subtotal, discount, tax, total, paid, balance)
         -> Activity / history card
```

The primary action follows the document status: Draft → "Confirm", Confirmed → "Create delivery", Delivered → "Create invoice", Invoiced → "Record payment".

**C. Form page** (create/edit)

Sections as cards, max width 1100px, 2-column field grid on desktop and 1 column on phone. Sticky footer bar with Cancel (secondary) and Save (primary).

**D. Dashboard**

Row 1: 4 KPI cards. Row 2: revenue chart (2/3) and top customers or low stock (1/3). Row 3: recent orders table and overdue invoices table side by side.

**E. Report page**

Filter card on top (period, warehouse, customer…; "Apply" button), then summary KPIs, then table with totals row pinned at the bottom. Export to Excel/PDF in the page header.

**F. Print view** (invoice, delivery note, PO)

White A4, no canvas, no shadows. Company header left, document title and number right. Geist Mono for document numbers. Status badge hidden in print.

---

## 3. Components

### Buttons
- Primary: accent fill, white text, `--ae-shadow-btn`, radius 10, padding 8px 14px, weight 600.
- Secondary: white fill, 1px `--ae-line` border, ink text.
- Danger: `#a11d1d` fill (only in confirm modals).
- Ghost/icon: no fill, muted icon, `--ae-accent-soft` on hover.

### Inputs (outlined)
- White fill, 1px `--ae-input-edge` border, radius 10, padding 8px 10px.
- Focus: accent border + 3px `--ae-focus-ring`.
- Label above field (12px, 500, muted). Helper/error text below; error text `#a11d1d`.
- Money inputs: right-aligned, "Rp" prefix, tabular-nums.

### Tables (clean + hover)
- Container is a card (white, radius 14, card shadow). Header row on `--ae-surface-sunken`.
- No zebra stripes and no row lines by default. Row hover and selected row = `--ae-accent-soft`.
- First column: document number or name, weight 500. Optional second line in muted 12px (city, SKU).
- Numbers right-aligned, Indonesian format: `18.450.000` (thousands `.`, decimals `,`). Currency in header: "Total (Rp)".
- Status column uses badges. Row actions appear on hover at the right end.
- Dates: `17 Okt 2026` in tables, `17/10/2026` in inputs.

### KPI card
Label (12px muted) → value (22px bold, tabular) → trend line (12px, green or red, with period: "+12,3% vs Sep").

### Tabs
Pill tabs: active = `--ae-accent-soft` fill + accent text; inactive = muted text. Show counts in a lighter weight.
On a phone the form's icon tabs run across the top of the form instead of down its side.
Form tabs (lines, other info, other charges, addresses…) stand down the left side of the form as a 48px
column of icons, the tab name as a tooltip (`App\Filament\Support\SideTabIcons` holds one icon per tab
name). Status tabs above a list stay as pills on top. Preferences keeps its tab names visible.

### Badges
Pill, 3px 9px padding, 6px colored dot before label, status colors from section 1. A count (on a tab or a
filter button) is not a status: no dot.

### Modals and slide-overs
Surface white, radius 14, `--ae-shadow-pop`, backdrop `rgb(15 18 25 / .35)` with 2px blur. Destructive confirm: title states the action ("Void invoice INV-0412?"), body states the effect, buttons "Cancel" and "Void invoice".

---

## 4. Copy and formatting rules

- Interface language default: Indonesian or English per client; keep one language per panel.
- Buttons are verbs that say what happens: "Save order", "Record payment", "Post journal". Never "Submit" or "OK".
- Empty states name the record: "No purchase orders yet." A list narrowed by a search, filter or tab says
  "No purchase orders match" and how to see more (the panel's default for every resource list).
- Document numbers: `SO-YYMM-####`, `PO-YYMM-####`, `INV-YYMM-####`, `DO-YYMM-####`, `GR-YYMM-####`.

---

## 5. Filament mapping

| Design rule | Filament setting |
|---|---|
| Accent `#2f5bea` | `->colors(['primary' => Color::hex('#2f5bea')])` |
| Gray scale | `'gray' => Color::Slate` |
| Geist font | self-hosted, set in `theme.css` (see file) |
| Icon rail, module menu, tabs | `->navigation(false)`, render hooks in `AdminPanelProvider`, `App\Filament\Pages\Workspace`, `resources/js/shell/*.js`, CSS in `theme.css` |
| Status colors | `->colors(['success' => …, 'warning' => …, 'danger' => …, 'info' => …])` and `Badge::color()` per status enum |
| Full-width lists | `->maxContentWidth(Width::Full)` |
| Layered canvas, cards, tables, inputs | `theme.css` |

Files in this pack:

- `DESIGN.md`: this reference.
- `theme.css`: Filament custom theme implementing the tokens.
- `AdminPanelProvider.snippet.php`: panel settings.
- `reference.html`: open in a browser to see the chosen style live.

Note: Filament CSS class names (`.fi-sidebar`, `.fi-ta-ctn`…) can change between versions. After install, check each selector in browser devtools and adjust `theme.css` if one does not match.
