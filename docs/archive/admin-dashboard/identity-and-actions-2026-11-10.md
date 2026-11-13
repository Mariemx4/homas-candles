# Hom’As identity, actions and workflow refinements

Subsequent refinement: [phone layouts, touch interactions and readability](mobile-refinement-2026-11-10.md).

Implemented in the workspace and applied to the running local `homas-test`
website on 10 November 2026. This builds on the earlier completion pass.

## Findings and changes

| Observed problem | Delivered change |
| --- | --- |
| The workspace stylesheet introduced an olive/gray theme over the existing warm brand foundation. Screens therefore mixed two identities. | Restored the original chocolate, brown, caramel and cream identity. Workspace surfaces, navigation, tabs, controls, cards, task panels and neutral states use the shared admin color roles. Foundation owns these roles; duplicate workspace token overrides were removed. Success/warning/error colors retain their outcome meanings. |
| Edit and status/deletion links ran together in directories. | Distinct 44px pencil and settings controls across products, materials, categories, product types, colors, scents, promotions, customers and team accounts. Each has a record-specific accessible name and a hover/focus label. Status management retains the existing confirmation and dependency checks. |
| Many full-size actions lacked recognizable visual landmarks. | Local icons accompany save, add, remove, edit, back, print and management actions, catalogue sections, record tabs and the New menu. Primary/destructive decisions keep visible wording. Admin icons share one SVG registry and no longer require the remote storefront icon loader. |
| Returning from standalone management often opened a general directory rather than the record being managed. | A shared allowlisted route helper returns to the exact product, material, taxonomy record, customer, request, promotion or administrator. Deleted records return to their directory. The existing modal preserves list filters and restores focus when cancelled. |
| A customer’s request-history action could say “View request” as non-clickable text. | Replaced it with a named View icon linking to that request. Agreed requests retain their Create order action. |
| The promotion form always said “Create or update”; saving discarded edit context. | Separate creation/edit headings, an identified read-only code in edit mode, retained edit ID/form anchor after saving, and an explicit Create another promotion route. The verified edit updates one existing code. |
| Request grouping and guided-form placement depended on heading wording or fieldset order. | Explicit `data-request-section`, `data-journey-step` and `data-order-discounts` contracts replace these heuristics. Changing copy no longer changes the flow. Back/Continue focus the new step and announce progress. |
| Record selection was remembered privately but not reflected in a copied page URL. | Selecting a tab updates its stable URL fragment. A copied section link reopens the same panel. Malformed fragments no longer interrupt initialization. |
| Resetting an applied search required finding the expanded filters. | Clear filters is available directly beside quick search and restores the list route without old query/page parameters. |
| UI responsibilities were scattered between styles, view fragments and JavaScript. | Centralized palette/action/navigation ownership, documented the progressive enhancement initialization order, and added [UI maintenance contracts](../../admin-dashboard/ui-maintenance.md), including examples, accessibility behavior and verification instructions. |

## Verification

- **133 assertions in a fresh real-Chrome authenticated browser run**, using
  generated synthetic records in a disposable schema. Includes draft product
  creation, order creation/review, failed payment recovery, customer/address
  saving and history, icon labels/44px targets, focus tooltips, modal cancellation,
  request section links, customer request navigation and promotion editing.
- **22 screens**, including all record tabs and form steps, fit 1024, 390 and
  320px without document overflow. Today also passed desktop/768px checks;
  mobile navigation focus isolation and browser unsaved-change warnings passed.
  No unhandled JavaScript exceptions occurred in the run. Remote icon loading
  was blocked during verification.
- **232 selected integration assertions** passed: admin redesign (55), workflow
  recovery (39), lifecycle protections (95), catalogue (43). The 55-assertion
  HTTP suite also passed in the newly built Docker image.
- Order workspace presentation checks passed. Layout, floating confirmations,
  manual pricing and request-conversion browser fixtures passed, including
  their responsive, cancellation and late-response behaviors.
- Every changed PHP/JavaScript file passed syntax checks. All **51 delivered
  source/test files** match the tested candidate hashes. Unrelated workspace
  modifications were preserved.
- Fingerprints of **16 current business tables**, including account change
  history, match immediately before and after this delivery. No database
  migrations, shop-record edits, stock movements or credential changes were
  required by this pass. Browser writes used synthetic preview records only.

These results describe the tested flows and viewports. The earlier completion
record retains its separate test history; its counts are not added to this run.

## Recovery and repeatability

Runtime source rollback copies and their manifest are retained inside
`homas-test` at `/tmp/homas-identity-runtime-backup-20261110`. Host source backups,
candidate files, logs and renders are under `%TEMP%\homas-identity-20261110`.
The disposable preview schema and containers were removed after verification;
temporary preview credentials/configuration duplicates were removed as well.

The tested image is `homas-candles:admin-identity-20261110`, built from this
workspace. The running local container received the verified file changes
without replacing its private configuration or uploads. Use the existing
[Docker operations guide](../../local-docker.md) when recreating a container and
preserve its runtime data as described there.

## Visual evidence

All displayed records are synthetic:

- [Today with the restored Hom’As palette](identity-and-actions-2026-11-10/today-desktop.png)
- [Edit/status controls and focus label](identity-and-actions-2026-11-10/record-actions.png)
- [Product sections and editing](identity-and-actions-2026-11-10/product-basics.png)
- [Promotion edit state](identity-and-actions-2026-11-10/promotion-edit.png)
- [Today at 320px](identity-and-actions-2026-11-10/today-320.png)
