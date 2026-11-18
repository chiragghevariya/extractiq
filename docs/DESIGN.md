# ExtractIQ design rules

ExtractIQ should look like a **real, carefully made product**: simple, calm and readable. It must not look like a template or "AI-generated", and it must stay visibly different from the owner's other apps (SupportIQ has a white sidebar, Inter, pill tabs and a gradient logo). **Every screen in every phase follows these rules.** ExtractIQ has no sidebar: navigation is a dark top bar of areas over a light bar of section tabs.

## Avoid (these patterns read as AI-generated)

- Serif or "editorial" display fonts, italic accent words in headlines.
- Monospace UPPERCASE letter-spaced labels and "eyebrows" above titles (e.g. `WORKFLOW · LEDGER`).
- Glows, blurred gradient blobs, grid or line textures, hatch patterns, decorative animations
  (the sign-in invoice scan below is the one sanctioned exception).
- Marketing copy and invented metrics ("99% accuracy", "< 1s") anywhere in the app.
- Overly clever names, emoji, or a different accent colour on every element.

## Do

| Element | Rule |
|---|---|
| Typeface | **Geist only** for all UI text, headings and numbers. Use `.num` (tabular figures) for amounts and counts so columns line up. Geist Mono is used **only** for literal codes: SKUs, ERP references, raw OCR text, error output. |
| Headings | Page title: `text-2xl font-semibold tracking-tight`, set by `PageHeader`. Card titles: `text-[15px] font-semibold` / `CardTitle`. Sentence case everywhere. |
| Labels | Sentence case, `text-sm` or `text-xs`, `text-muted-foreground`. No uppercase, no letter-spacing tricks. |
| Frame | **Top bar + section tabs, no sidebar.** A deep navy top bar in both themes: logo, workspace, the five areas (Overview, Work, Records, ERP, Settings; active = white text with a 2px gold rule), then search (⌘K), upload, theme and account. Under it, a light section bar with the current area's pages as underline tabs and live counts. Content is full width (max 1400px) on the warm canvas. Phones get a drawer with every area and page. Defined once in `AppShell` (`AREAS`): add a page there, not in a page component. |
| Colour | **Navy and gold.** Warm ivory canvas (`#F7F4EE`), white cards, warm hairlines (`#E6DFD2`), navy ink text (`#1A2230`), deep navy frame (`#0F1B2D`). **Antique gold is the only accent**: `#8A6B30` in light mode for buttons, links and active tabs (passes WCAG AA both as text on ivory and with ivory text on it), light gold `#D4B26A` on navy and in dark mode (navy text on it). Utility classes use the `brand-50…900` gold scale and `navy-700…950`; never Tailwind's indigo/violet. Dark mode is night navy (`#0B1322`, cards `#111B2C`). Status colours (emerald, amber, rose, sky) are used only for states, always next to a label. |
| Shape | `--radius: 0.5rem`. Cards `rounded-lg`, tags and buttons `rounded-md`. Depth comes from hairline borders; only the canvas, popovers and dialogs cast shadows. |
| Logo | The `<Logo />` component: a light-gold tile with a navy document-and-check icon, plus the wordmark "ExtractIQ" in Geist semibold. |

## Components (`web/src/components/app/ui-bits.tsx`)

- `PageHeader`: title, one-line description, actions. Every page uses it.
- `FilterTabs`: underline tabs with plain counts, for every list filter. No pill or segmented tabs.
- `StatStrip`: one card with figures separated by hairlines, for summary numbers.
- `StatusBadge`: a small rounded-md tag with a dot and a label.
- `ConfidencePill`: a thin bar plus a percentage.
- `EmptyState` / `ErrorState` / `TableSkeleton`: every screen needs empty, loading and error states.
- Tables: `text-xs` muted column headers in sentence case on a muted band, right-aligned `.num` amounts.

## Sign-in illustration (the one brand animation)

`web/src/components/app/login-illustration.tsx` shows a realistic sample invoice being scanned. Each field highlights when the scan line reaches it and appears in an "Extracted" panel with its confidence, then "Validated" and "3-way matched" appear. It loops about every 7.6 seconds, and one timeline drives everything so the pieces stay in sync.

- Keep it on the sign-in page and keep it in the UI typeface (Geist, tabular figures). No glow.
- It shows a static final frame when `prefers-reduced-motion` is set.
- Don't add other ambient animation elsewhere in the app.

## Copy

Short, literal, helpful: say what the screen is for and what to do next. No slogans inside the app. The login page can carry a single plain sentence about the product.

## Landing page (`/`)

The public page follows the same rules: Geist, sentence case, gold as the only accent, real
screenshots of the app (no mock-ups or illustrations), one plain headline (the same sentence as the
sign-in page), literal descriptions of what the product does, and no numbers that aren't measured.
Integrations are named as text and labelled honestly ("Built in", "Ready via the connector
interface"). Server-rendered, no client JS beyond the shared providers; keep Lighthouse at 90+.

## Charts

Series colours are `--series-1` (gold `#B5862A` / dark `#B88A30`) and `--series-2` (steel blue `#2563A8` / dark `#3F7FC4`), validated for colour blindness in both themes (adjacent CVD ΔE 25.2 light, 22.9 dark) with the `dataviz` skill. Re-run the validator before adding series. Always include a legend, never use dual axes, and keep a 2px gap between stacked segments.

## Checklist for every new screen

1. `PageHeader` (no eyebrow), sentence-case labels, Geist only.
2. `FilterTabs` for filters, `StatStrip` for summaries, `StatusBadge` for states.
3. `.num` on amounts and counts; mono only for literal codes.
4. Empty, loading and error states.
5. Check light mode, dark mode and 390px mobile (no horizontal page scroll).
6. New pages are added to an area in `AREAS` (app-shell.tsx); sub-sections of a page go in the section bar, not in a second row of tabs.
