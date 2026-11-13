# Admin mobile layout and readability refinement

Applied to the running local Docker website on 10 November 2026, retaining the
Hom’As palette and the established desktop workspace.

## Problems addressed

| Observed issue | Change |
| --- | --- |
| Wide tables showed only the first columns on a phone; actions, payment state and production blockers required sideways scrolling. | Simple record tables become labeled cards at 768px and below. Every field remains present. Identity, forms, actions and long notes use the full card width. Desktop retains the original table. |
| Record tabs and form steps extended beyond the phone viewport. | At 640px and below, all choices form a visible two-column grid with touch-sized controls. No horizontal swipe is needed to discover another section or step. |
| Nested panel/fieldset padding squeezed editable fields. | Removed redundant inner frames on phones, reduced outer gutters, and retained clear section separators. Product editing passes a minimum 220px field-width check at 320px; customer entry exceeds 240px at 390px. |
| Supporting text, badges and control labels were too small; some labels and secondary controls were overly heavy. | Increased supporting text sizes, softened tab/label/control weight, and used 16px phone input text. Totals and important headings retain their emphasis. Touch input text remains 16px in landscape too. |
| Dashboard icons competed with labels inside narrow tiles. | Phone metric labels use the full tile width; decorative tile icons are removed at that size. The final odd tile spans both columns with a compact value layout. |
| Mobile action placement and filter controls felt cramped. | Back/Continue have explicit positions, progress occupies its own row, and Save draft stays available below them. Search remains visible. Applied filters reuse the existing reset control rather than displaying duplicate resets. |
| A display-only table conversion could weaken assistive navigation. | Kept real headers/captions and explicit table roles. Visual field labels are excluded from duplicate announcements. The scroll-specific tab stop/label is removed only while the region is a card layout. |

## Verification

**199 assertions passed in a fresh authenticated Chromium run.** Mobile metrics
and touch emulation were enabled, with hit-tested touch events for the phone
interactions. The run includes:

- Saving/resuming an order draft, opening a product picker by touch, selecting
  its product, advancing the steps, reviewing and creating the order on a phone.
- All record sections and form steps across **22 screens** at 320, 390, 430,
  768 and 1024px. No document overflow occurred in these checks.
- Labeled order-card fields without sideways scrolling; opening a card and
  switching to Payments by touch at 320, 390 and 430px.
- Product input width and font-size checks; visible, touch-sized section
  choices; retained table headers/cells in the browser accessibility tree.
- Existing customer/address/profile history, payment error recovery,
  promotion editing, filtered management dialogs and unsaved-edit protection.
- Today/mobile navigation checks and touch navigation in **844 × 390 landscape**.
- No unhandled JavaScript exceptions during the run.

The 55-assertion admin HTTP/integration suite passed. The layout, manual-pricing
and floating-confirmation browser fixtures passed their responsive checks.
Changed JavaScript passed syntax checks; the PHP layout passed PHP syntax checks.
This evidence comes from Chromium emulation, not physical iOS/Android devices.

## Delivery and maintenance

- All **10 delivered source/test files** match the tested candidate hashes.
- Fingerprints of **16 current business tables** match immediately before and
  after delivery. This pass required no database migration or business-record
  changes. Browser test writes used a separate synthetic fixture schema.
- Runtime rollback copies and their manifest are retained inside `homas-test`
  at `/tmp/homas-mobile-runtime-backup-20261110`.
- Host source backups, candidate files, logs and renders are retained under
  `%TEMP%\homas-mobile-20261110`. Temporary preview credentials/configuration
  duplicates and the disposable preview schema/containers were removed.
- The reproducible image is `homas-candles:admin-mobile-20261110`. Runtime
  private configuration and uploads were preserved in the current container.

The implementation is confined to the shared UI layer. Phone layout lives in
`admin/responsive.css`; `admin-responsive.js` annotates existing table cells
without cloning values or forms. Multi-level/matrix tables keep their native
layout, and `data-keep-table` provides an explicit opt-out. Without JavaScript,
the original scrollable tables remain available. Print keeps native tables.
See the [UI maintenance guide](../../admin-dashboard/ui-maintenance.md) for the detailed contracts.

## Screenshots

All records shown are synthetic:

- [Phone order list and labeled cards](mobile-refinement-2026-11-10/orders-320.png)
- [Phone record sections and payment form](mobile-refinement-2026-11-10/payment-320.png)
- [Phone product editing](mobile-refinement-2026-11-10/product-320.png)
- [Phone guided order entry](mobile-refinement-2026-11-10/new-order.png)
- [Phone Today dashboard](mobile-refinement-2026-11-10/today-320.png)
