# Catalogue pricing, saved recipes and Custom Request customer modes

Implemented and verified on 3 October 2026 in the existing application. Existing unrelated workspace changes were preserved. This report covers the latest pasted request; earlier Orders and Payments reports remain separate.

## 1. Files changed

New files:

- `app/database/migrations/023_order_pricing_and_catalogue_snapshots.sql`
- `src/Services/OrderPricingService.php`
- `src/Services/CustomerModeService.php`
- `src/Controllers/PricingConfigurationController.php`
- `public/admin/orders/price-preview.php`
- `public/admin/catalogue/promotions.php`
- `app/Views/Pages/admin-catalogue/admin-catalogue-promotions.php`
- `app/Views/Components/Admin/product-recipe-lines.php`
- `tests/Integration/verify-order-pricing-snapshots.php`
- This report.

Existing files extended:

| Area | Files |
| --- | --- |
| Catalogue | `app/Bootstrap/admin/admin-catalogue.php`, `src/Controllers/AdminProductController.php`, `src/Services/AdminProductService.php`, `src/Services/PricingService.php`, `src/Repositories/PricingRepository.php`, `public/admin/catalogue/products.php`, `app/Views/Components/Admin/catalogue-tabs.php`, `app/Views/Pages/admin-catalogue/admin-catalogue-product-edit.php` |
| Manual orders and fulfilment | `src/Controllers/ManualOrderController.php`, `src/Services/ManualOrderService.php`, `src/Services/OrderEditService.php`, `src/Repositories/OrderRepository.php`, `public/admin/orders/create.php`, `public/admin/orders/edit.php`, `app/Views/Pages/admin-order/admin-order-create.php`, `app/Views/Pages/admin-order/admin-order-edit.php`, `app/Views/Pages/admin-order/admin-order-detail.php`, `app/Views/Pages/admin-order/admin-order-print.php` |
| Recipes, production and stock | `src/Repositories/OrderProductionRepository.php`, `src/Services/OrderProductionService.php`, `src/Repositories/OrderStatusRepository.php`, `src/Repositories/ProductionRepository.php`, `src/Services/ProductionService.php`, `app/Views/Pages/admin-production/admin-production-sheet.php`, `src/Repositories/ScentStockRepository.php`, `src/Services/ScentAvailabilityService.php`, `app/Views/Pages/admin-inventory/admin-inventory-recipe.php` |
| Requests and shared customers | `app/Bootstrap/admin/admin-custom-requests.php`, `src/Controllers/CustomRequestController.php`, `src/Services/CustomRequestService.php`, `src/Services/CustomRequestConversionService.php`, `src/Repositories/CustomRequestRepository.php`, `public/admin/requests/create.php`, `app/Views/Pages/admin-custom-request/admin-custom-request-create.php`, `app/Views/Components/Admin/order-customer-picker.php` |
| Checkout and customer order history | `app/Bootstrap/checkout.php`, `src/Services/CheckoutService.php`, `src/Repositories/CustomerAccountReadRepository.php`, `app/Views/Pages/customer-order.php`, `public/order-confirmation.php` |
| Presentation | `public/assets/js/admin-manual-order.js`, `public/assets/js/admin-order-details.js`, `public/assets/css/admin/orders.css` |
| Existing tests | `tests/Integration/verify-manual-order-customers.php`, `tests/Integration/verify-checkout.php`, `tests/Integration/verify-customer-payments.php`, `tests/Integration/verify-pricing-workflow.php`, `tests/Integration/verify-scent-availability.php`, `tests/Unit/verify-order-customer-form.js` |

No new order, customer, recipe, inventory or request tables were introduced. Pricing configuration reuses the existing pricing service/repository, with a sale calculation service to share previews, persistence, total arithmetic and deposits. Views format prepared values; JavaScript requests server-calculated previews.

## 2. Database migrations

Migration **023** adds the two missing configuration concepts confirmed absent in the existing database:

- `product_quantity_prices`: product, inclusive minimum/optional maximum quantity, fixed TND unit price and active state. Checks and a product-row locking trigger reject overlapping active ranges.
- `promotion_codes`: explicit code, percentage/fixed discount, active state, inclusive start/end dates and minimum merchandise subtotal.

It extends `orders` with applied promo code/type/value/amount, manual discount type/value/reason/amount and quantity savings. It extends `order_items` with catalogue price, quantity price/tier, override reason and recipe source. Existing effective unit/line/cost snapshots and `order_item_materials` remain the sale records. Database guards protect agreed discount/item audits and captured catalogue recipes. The order total constraint now subtracts saved discounts before adding delivery.

Historical prices are not rewritten and no historical recipes are fabricated. Existing orders receive zero default discounts and nullable new audit fields.

The existing migration runner successfully applied **023** locally, together with pending **022** from the previously authorized Payments work. Versions 001–021 were skipped. No historical migration files were edited for this task. Before applying the migration, the local database had **zero** pending active order items without saved recipes.

## 3. Catalogue base price

The existing `PricingService` remains the authority for catalogue prices:

| Product | Preserved rule |
| --- | --- |
| Candle | Actual cost × 2 |
| Candle set | Non-accessory component cost × 2, plus accessory cost |
| Bouquet | Actual/component cost × 2 as its quotation base |
| Accessory | Original purchase cost |
| Diffuser | Equivalent candle price, otherwise candle-equivalent cost × 2; actual diffuser cost remains separate |
| Event favor | Existing minimum 10 and cost multipliers: 10–19 at 1.75, 20–39 at 1.50, 40+ at 1.30 |

Catalogue material/recipe changes continue to reprice products and enforce the established activation/readiness requirements. Normal order entry reads the stored authoritative catalogue selling price.

## 4. Quantity/bulk pricing

The Catalogue product editor has a Quantity pricing section with range/price forms and removal actions. This uses fixed effective unit prices; the example quantities/prices from the request are not seeded or hard-coded. New configurations require positive three-decimal TND prices no greater than the current base price, valid whole quantity bounds, and no overlapping active ranges. Removal and addition let an administrator replace a tier.

Event favors retain their existing documented cost multipliers rather than acquiring a competing pricing model. The table supports inactive tiers; the initial UI creates active tiers and removes unwanted ones.

## 5. Applicable tier selection

Validate product minimum quantity, then select the active tier whose inclusive minimum/maximum contains the ordered quantity. An omitted maximum means an open upper range. If none applies, use the catalogue base price. Overlaps are rejected in both service and database. Pricing is selected per order line, matching the existing line-based ordering model; separate lines/options are not silently combined into a bulk quantity.

## 6. Promo/collaboration codes

Catalogue → Promotions configures or updates an explicit uppercase code. `PERCENTAGE` and `FIXED_TND` are supported. Codes are checked against active state, dates and merchandise minimum. Code spelling does not imply a discount: a code containing “99” may explicitly give 2%. The order form submits a code, never an authoritative discount amount. Its preview shows the configured value and calculated reduction. Apply, Remove and Review prices have server POST/Redirect/Get support; JavaScript uses the read-only preview endpoint.

The model deliberately omits usage counters, customer limits and product/category scopes because no existing HOM’AS requirement establishes those policies.

## 7. Manual administrator discount

Additional order discount supports None, Percentage or Fixed TND, with an optional reason. A percentage applies to the merchandise subtotal remaining after the promo. Negative/malformed values, percentages above 100%, excessive reductions and overlong reasons fail server validation. Type, value, reason and calculated amount are saved separately from item prices.

The exceptional item override is a distinct, disclosed action. It requires a positive effective price and a reason; it preserves the original base/quantity price and the overridden effective price. General reductions can use the order-level discount.

## 8. Exact pricing formula

1. Catalogue base selling price.
2. Applicable quantity price, or explicit validated exceptional override.
3. Each effective unit price × its line quantity.
4. Sum the item lines into merchandise subtotal `S`.
5. Calculate one valid configured promo reduction `P` against `S`.
6. Calculate optional manual reduction `M` against `S − P`.
7. Add the existing fulfilment fee `D`.
8. Save final total `T = S − P − M + D`.

Quantity savings compare base and quantity prices and are informational; they are already included in the effective-priced subtotal. Overridden lines do not contribute to quantity savings.

Money uses integer millimes and half-up rounding to three decimals. Pickup requires zero fee. Manual delivery retains the existing minimum 9.000 TND and permitted higher fee; its initial value comes from checkout configuration. JavaScript switches the configured displayed fee without inventing a delivery rule. The preserved deposit rule is **40% of the final total when the final total is at least 100.000 TND**, otherwise zero. The request’s 50% example does not override this documented rule.

The same total/deposit arithmetic is used by manual creation, permitted fulfilment corrections, checkout and request conversion. Payment, D17 and balance workflows consume saved totals and verified payments.

## 9. Historical price snapshots

Every new normal item retains its catalogue price, applicable quantity price/range, effective price, line subtotal and cost. Orders retain their applied discount details and final total. Later catalogue, cost, tier or promo edits only affect future sales. Detail, print, confirmation and customer history display saved reductions rather than attempting to explain a discounted total as subtotal plus delivery alone.

Existing order editing remains limited to the established contacts, fulfilment, date and notes workflow. It does not unlock item quantities, prices or discounts. Permitted delivery corrections reuse stored discounts and retain item/recipe audits; completed consumption records are never regenerated.

## 10. Removed from manual-order Items UI

Removed normal-flow Agreed unit price, arbitrary custom product definition and the material/quantity-per-unit recipe editor. Items now present catalogue product, relevant options, quantity, catalogue price, quantity price only when different, line subtotal, notes, and removal/addition controls. Exceptional price override is contained in an explicit disclosure. Truly new products are directed to Custom Requests.

Browser-submitted normal prices, costs, totals, discounts and recipe lines cannot become sale authority. Existing trusted internal custom-item callers remain compatible; HTTP normal entry is catalogue-only.

## 11. Recipe management location

Catalogue product edit now embeds the existing recipe editor. It reuses `ProductRecipeController`, `ProductRecipeService`, `ProductRecipeRepository`, pricing and readiness. The existing Inventory recipe page uses the same extracted `product-recipe-lines.php` component, so validation/persistence remain shared. Actual recipes are production definitions; diffuser candle-equivalent recipes remain notional pricing inputs.

## 12. Recipe snapshots

Normal creation loads the current catalogue ACTUAL recipe on the server, applies the existing selected-fragrance substitution, and aggregates set-component quantities into one complete per-unit sold-item recipe. It stores material names, units, unit costs and per-unit quantities in existing `order_item_materials`, in the order transaction. New checkout orders also capture these recipes. Available linked-request catalogue recipes are captured during conversion without replacing the negotiated quote.

Normal-sale catalogue recipes are immutable and their order-detail editor is hidden. Converted custom requests remain CUSTOM production definitions: an available linked catalogue recipe is initially captured, but an explicit correction for agreed one-off specifications remains permitted before production is recorded. One-off/custom requests without a configured recipe retain their established explicit production-definition step, subject to pre-production restrictions. Any failure rolls back the order, items, recipes and newly created account/address together.

## 13. Quantity scaling and inventory

Production planning and PREPARED material deduction now read saved item material quantities × ordered quantity. For example 40 g per unit × 20 units = 800 g. They never regenerate material totals from today’s catalogue recipe. Set material snapshots already include the component counts, so components are not deducted a second time. Production groups show the sold item name/quantity and identify saved catalogue versus custom recipes. A sold set uses its complete saved material recipe as one production group, rather than rebuilding its material requirements from the current set composition.

Existing authoritative material movement records remain the stock ledger. Fragrance reservations include pending saved recipes and exclude already-deducted orders. The stock tests verify chosen fragrance, combined demand, set multiplication, restocking and actual PREPARED deductions.

## 14. Missing recipes

Normal catalogue creation/preview fails with a clear instruction to configure the production recipe in Catalogue; failed item validation provides a product edit link. It never exposes a normal-order recipe editor as a workaround.

An old order without a historical recipe cannot have its original recipe reliably reconstructed. Such pending items are excluded from production grouping and blocked from production until an administrator explicitly confirms the historical/agreed recipe through the established order-detail correction workflow. No current recipe is silently substituted. Already-consumed material history is preserved.

## 15. Custom Request customer modes

The request creation page reuses the existing customer-mode component and supporting customer search JavaScript. It asks “Who is this request for?” before request specifications. Existing mode shows search/selection, Guest shows contact snapshots, and New shows account details/address. Inactive panels are fully hidden and disabled. The shared script works without order-specific fulfilment controls.

The existing request description, quantity, date, preferences, budget, follow-up/status and negotiated conversion workflow remain intact. An enquiry budget is not treated as an agreed price. Existing negotiated unit or exactly divisible batch prices remain authoritative for conversion; catalogue bulk tiers are not forced onto a one-off quotation. No attachment infrastructure was invented where the existing creation workflow lacks it.

## 16. customer_id rules

| Active mode | Persisted link |
| --- | --- |
| Existing | Valid explicitly selected account ID; optional contact snapshot edits do not edit that profile |
| Guest | NULL, even when stale IDs or matching contact details are submitted |
| New | ID returned by the shared secure account/address creation workflow; prior selected IDs are ignored |

Server normalization enforces the active mode. Contacts never infer ownership. Duplicate email/primary/secondary contacts follow the existing account checks and database uniqueness. Historical contacts survive later account profile edits. New accounts use the existing undisclosed secure credential/password setup workflow.

## 17. Existing services/components reused

Reused `PricingService`/repository, `OrderRepository`, `OrderProductionService`/repository and `order_item_materials`, `OrderCustomerMode`, `CustomerAccountRepository::createWithPrimaryAddress`, duplicate lookup methods, `PasswordPolicy`, existing password reset flow, `TransactionManager`, `Money`, CSRF/flash/admin authorization, authenticated customer search endpoint, customer picker and JavaScript, recipe controller/service/repository, readiness, stock services, request quote/conversion, Payment service, and existing admin controls/tokens.

`CustomerModeService` extracts shared account/link preparation, duplicate checks and secure creation rather than creating a second request-specific account implementation. Production/status repositories share the saved recipe record rather than adding another material snapshot system.

## 18. Assumptions and practical limits

- General tiers use fixed prices. They are not automatically rewritten when costs/base prices change. Administrators should review configured tiers after repricing; quantity savings only display a valid positive base comparison.
- Existing fixed event-favor multipliers, minimum quantities, activation requirements, delivery rules and 40% deposit rule are preserved.
- Only one promotion applies. No unsupported limits or targeting policies were inferred.
- Price/discount editing was not broadened beyond existing permissions. Fulfilment corrections retain discount amounts already agreed, even if the promo has since expired.
- Set recipes are flattened into the sold item’s complete per-unit materials at sale. No new variant or historical component-roster system was introduced.
- Existing fragrance option substitution is reused. Cosmetic colors do not invent price/material changes.
- Historical missing recipes are deliberately not backfilled from current catalogue data. Other environments should check pending legacy items before rollout.
- The existing large set of uncommitted workspace edits was retained; this task did not commit or publish changes.

## 19. Automated verification executed

| Check | Result |
| --- | --- |
| `tests/Integration/verify-order-pricing-snapshots.php` | **68 assertions passed**: tiers/bounds/overlap, promo configuration and failures, stacking/rounding, forged values, snapshots, production scaling/groups, historical changes, discounted D17/deposit/balance, fulfilment corrections, modes/duplicates/rollback and custom quote conversion |
| `tests/Integration/verify-manual-order-customers.php` | **57 checks passed**, including real authenticated HTTP requests for manual modes, preview/CSRF/no-JavaScript review, Catalogue tiers/recipes, Promotions and all Custom Request modes |
| `tests/Integration/verify-order-edit.php` | **44 checks passed** |
| `tests/Integration/verify-pricing-workflow.php` | **18 checks passed**, preserving the existing base pricing/repricing rules |
| `tests/Integration/verify-scent-availability.php` | **19 checks passed**, including checkout snapshots and actual inventory deductions |
| `tests/Integration/verify-customer-payments.php` | **19 checks passed**, including proof/authorization/review/cash and saved authoritative amounts |
| `tests/Integration/verify-checkout.php` | Passed |
| `tests/Unit/verify-pricing-rules.php` | Passed |
| `tests/Unit/verify-order-customer-form.js` | Passed |
| PHP syntax | Passed for **103 changed/new PHP files** in the workspace, with follow-up lint of final edits |
| JavaScript syntax | Both updated scripts passed `node --check` |
| Existing local migration runner | 022 and 023 applied successfully; older versions skipped |

Database integration tests used random PostgreSQL schemas removed in `finally`; checkout’s existing in-memory SQLite fixture was retained and extended for the new audit columns. The older pricing/fragrance fixtures now use the isolated PostgreSQL helper. The customer payment fixture’s PHP 8.3 HTTP-header compatibility issue was corrected. These are automated service/HTTP/markup checks, not visual browser verification.

## 20. Manual browser verification still required

Browser visual verification was **not performed**: no usable browser surface was available in this session. Complete the following at desktop and narrow mobile widths:

1. Create/edit a Catalogue product.
2. Confirm its calculated/base price explanation.
3. Configure/remove quantity tiers, including an overlap error.
4. Add/update its per-unit production recipe.
5. Open Create manual order.
6. Confirm normal items contain no recipe editor or agreed-price field.
7. Select a product and its relevant options.
8. Move quantity across tier boundaries.
9. Verify base/tier/line amounts update and stale amounts clear on error.
10. Apply valid, invalid and expired promotion codes.
11. Add/remove percentage and fixed manual discounts.
12. Switch pickup/delivery and confirm address disclosure.
13. Verify final total, rounding and clean conditional summary rows.
14. Save the order and inspect price/discount/recipe audits.
15. Change Catalogue base price, tier, promo and recipe afterward.
16. Reopen the saved order and verify its historical prices/recipe remain intact.
17. Open Create Custom Request.
18. Exercise Existing, Guest and New Account, including customer search failures.
19. Confirm only the active panel is visible/enabled, stale IDs stay ignored, and keyboard/native radio focus works.
20. Check mobile tables, disclosures, long names/errors and add/remove items; disable JavaScript and verify Review prices, Apply/Remove promo and final server validation.
