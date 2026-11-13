# Hom’As admin: workflow and design proposal

Status: historical proposal. The approved workflow/design implementation is now
delivered locally; see the [current completion record and verified scope](completion-2026-11-10.md).

## Outcome

Make daily shop work easier to understand, resume and finish. The interface
should explain what needs attention, why it matters, what is blocking progress
and what action is available now. A polished Hom’As identity should support
that clarity throughout the application.

This review uses the current navigation, dashboard, order creation/detail,
request detail/conversion/correction, payment recording/review, product editor,
inventory, production queue/sheet, customer and report templates, their module
specifications, and the earlier audit. The functional repair batches are taken
into account. Markup and source establish the structures described below;
visual directions and usability benefits are proposals. No new live-screen
visual validation, measured task timings or user usability tests are claimed.

## Main diagnosis

The application is divided into modules, each with its own forms and status
controls. The navigation exposes eleven destinations at one level. Several
screens explain how data is stored or processed. To complete a business task,
the administrator must understand the relationships between those modules.

Existing strengths should be retained: dashboard attention links, linked
customer/request/order records, financial summaries, production feasibility,
product activation checks, pricing calculations and durable histories. The
redesign should make these capabilities easier to discover and use together.

## Problems and concrete changes

| Priority | Current structure or observed gap | Proposed experience | What would demonstrate improvement |
| --- | --- | --- | --- |
| First | Eleven equally grouped navigation destinations; letter abbreviations such as DB, OR and PY stand in for icons. | Group daily work, catalogue/stock and business administration. Use recognizable icons with full labels. Keep frequent work prominent; secondary administration remains reachable. | The owner can locate their next task without knowing which module stores it. Keyboard and mobile navigation remain clear. |
| First | Dashboard already has urgent-order links and summary counts, but its urgent list is focused on orders. | A **Today** workspace with separate attention groups: requests needing a response, payments awaiting review, orders approaching their deadline and production blockers. Each item explains urgency and opens the exact task. | An item identifies both the record and a useful action. Completed work leaves the relevant attention group; genuinely empty and unavailable states remain distinct. |
| First | Order details combine customer/fulfilment information, finances, items, editable recipes, payments, status history and notes in a long document. | A compact order overview plus focused sections for Products, Production, Payments and History. Keep customer, deadline, fulfilment, money owed and the current action visible. Preserve a clear print view. | The common next action is identifiable without scanning recipe/history content. Other details stay discoverable and accessible. |
| First | Order payments are read-only in order details, with a separate Manage payments destination. | Offer **Record payment** and relevant **Review D17 transfer** actions in the order context, reusing the existing payment rules. Retain the central payment queue for reviewing multiple orders. | Recording or reviewing a payment returns to the same order and refreshes its balances. Pending transfers remain distinct from verified money. |
| First | The status dropdown lists progress and correction destinations together. Advancing can execute intermediate steps. | Give the current permitted task a named action, such as Confirm order, Start preparation or Mark prepared. Show a short explanation and blockers. Put correction and cancellation in secondary actions. | A deposit blocker points to the payment that needs review; preparation explains stock use. Ordinary progress requires no knowledge of the full status vocabulary. |
| First | Request details contain separate assignment, contact and transition forms; agreement, conversion, production and catalogue drafting are separate stages. | A request workspace with an understandable progress indicator, the current brief, latest discussion and one prominent next task. Keep ownership/follow-up secondary. Give agreement a review step with products, prices, date and fulfilment together. | The owner understands whether they are discussing a brief, recording agreement or creating its order. Previously entered information carries forward where valid. |
| First | A production queue row shows order, customer, date, fulfilment, total, status and timing. It does not surface material/recipe blockers there. Feasibility and grouped preparation information exist elsewhere. | Show production readiness beside each order, with specific missing-recipe/shortage explanations and links to resolve them. Provide Today/This week/Blocked shortcuts and an obvious preparation-sheet action. | The owner can distinguish work ready to start from blocked work before opening several records. Shared material demand must not be advertised as guaranteed reserved stock. |
| Next | Product creation begins with configuration; the name belongs to later translation forms, while images/options unlock after initial creation. Different categories share many conditional fields. | A guided product workspace beginning with name, category and photo. Reveal relevant options for that category, then costing/pricing, then preview and publication checks. Place translation management and URL editing behind secondary controls. | A draft has an understandable identity early. Every publication blocker links to the relevant field. The existing calculated pricing rules remain clear. |
| Next | Recipes belong to the inventory area but also appear in order production editing; product configuration and price depend on them. | Keep reusable product recipes in the product workspace. Show an order's agreed production recipe in its Production section. Explicitly label the difference between changing a reusable product and a single order. | An operator can fix a missing product recipe and return to the blocked task without hunting through inventory. Historical order specifications and prices remain protected. |
| Next | Material details, stock actions and the movement ledger share the same broad administrative presentation. | Give daily actions direct labels: Receive stock, Record waste and Correct a count. Show current quantity with its unit and a before/after preview. Keep material identity and history secondary. | Recording a real stock event is short and understandable, while still preserving its required reason, actor and ledger entry. |
| Next | Customer editing uses a shared account-management route; the customer page exposes management actions before its heading and drops context when editing. | Keep the customer header first, with Edit details and Create order alongside the history. Preserve the directory search and return to the same customer after saving. Account suspension is a secondary action with its consequences explained. | Updating a phone number does not make the user navigate back through account management or repeat a customer search. |
| Next | Large forms lack consistent draft recovery and navigation protection. Error patterns and field association vary. | Use a shared form pattern: visible save state, preserved safe input, errors beside fields, a linked summary, and a warning before losing edits. Provide explicit draft recovery where supported. | An interrupted task can be resumed, and an error identifies the exact fix. Sensitive data and payment evidence are not silently stored in browser drafts. |
| Next | Search/filter forms expose many controls, including raw sort direction, before the work list. | Keep search and a few common task views visible. Move less frequent filters into an expandable area. Preserve chosen filters and show active filter chips. | Daily queues can be reached directly; advanced searches remain possible. Filters and pagination do not reset after inspecting a record. |
| Next | Reports lead with database-oriented language such as bounded queries and immutable records. Settings expose schema terminology. | Reports answer business questions: orders booked, verified money received, outstanding balances and material use for a stated period. Keep technical operational details in a separate settings section. | Every total explains its meaning and period. Order value and money received are visibly separate. Unavailable capabilities stay truthful. |
| Throughout | Active and historical information compete for attention; some actions use vague or misleading wording such as Delete order for moving to Trash. | Use a shared page hierarchy: identity and state, current task, supporting information, then history/advanced management. Label actions by their actual effect. | Restore, archive, deactivate, cancel and move to Trash have consistent labels and consequences. History is available without dominating routine work. |

## How the journeys should connect

### A customer orders an existing product

1. Start a new order from Orders or from an existing customer's page.
2. Choose the customer or guest contact, then products and relevant options.
3. Enter date and pickup/delivery details; see the running customer total and
   required deposit in one consistent review area.
4. Create the order and arrive at its next task. If a deposit is needed, show
   the remaining verified deposit and the relevant payment actions.
5. Confirm only when the server's business rules permit it.
6. Move into production with readiness/blockers visible. Use the agreed recipe
   and existing stock rules for preparation.
7. Finish pickup/delivery with any remaining balance visible. Fulfilment and
   receipt confirmation remain explicit independent actions.

### A customer needs a bespoke design

1. Open the incoming enquiry or record an offline brief.
2. Review the customer's request, note the discussion, set follow-up if needed
   and refine separate product definitions while editing is allowed.
3. Record the agreement with all agreed products, prices, required date and
   fulfilment details together.
4. Create the linked order once. Carry valid existing information forward and
   retain the request, contact history and correction history.
5. Continue through the same payment, production and fulfilment experience as
   other orders. Show a direct task to complete a missing custom recipe.
6. After production, offer the existing optional catalogue-draft action with a
   clear explanation that the resulting draft still needs publication review.

The visible interface can simplify the language and presentation of stages.
It must not invent contact attempts, agreements, receipts or production history
to skip existing business requirements. Any change to those requirements needs
an explicit business decision.

### Daily production and stock work

1. Today highlights the orders due and the work blocked by missing inputs.
2. Production offers useful date presets and a preparation sheet using current
   order specifications and correctly grouped quantities.
3. A blocker opens the exact missing recipe or relevant material, with a return
   to the production task.
4. Receiving stock or recording waste is a short physical-stock operation,
   always recorded in the existing ledger.
5. Preparing an order explains the stock effect and retains the existing
   single-deduction and rollback rules. A displayed estimate does not reserve
   materials or guarantee availability for every order in a group.

## Navigation proposal

Keep access to all existing capabilities, grouped by purpose:

- **Daily work:** Today, Orders, Custom requests, Production, Payments.
- **Products and supplies:** Products, Materials and stock.
- **Business:** Customers, Reports.
- **Administration:** Team/accounts, Settings.

These are proposed labels/groupings. The appropriate order should be validated
against real daily tasks. A global New action could offer New order, Custom
request, Product and Stock receipt without adding a second implementation of
those operations.

## Visual direction across the whole admin

Aim for a warm, refined and practical Hom’As workspace:

- Use warm neutral page backgrounds, clear working surfaces, dark readable
  text and a restrained caramel accent for the main action. Assign status colors
  consistently and always pair them with text.
- Establish a deliberate type hierarchy. Current admin tokens use 14px body
  text and 12px small text at a 16px root; evaluate a more readable working scale
  and intentional font loading/fallbacks across real screens.
- Use recognizable icons with labels, stronger essential input boundaries,
  visible focus, generous usable targets and restrained decoration.
- Compose screens around their work: order summary, request brief, production
  queue, payment review and product editing should have appropriate layouts
  within a consistent shell.
- Use real product imagery for recognition where useful. Keep financial tables
  and production instructions efficient and easy to scan.
- Make primary, secondary and destructive actions visually distinct. Reduce
  repeated equal-weight panels, long explanatory paragraphs and large filter
  blocks competing with the work itself.
- Use short, purposeful feedback for saving, loading and completion, with
  reduced-motion support. Quality should survive long records, small screens,
  missing optional values and failed operations.

These choices require rendered validation; no screenshot-level claims about
current clipping, visual balance or final appearance are established here.

## Delivery order and acceptance

Design the complete connected workflow before changing individual screens.
Then implement manageable groups against that common design:

1. Shared shell, language, page hierarchy and Today workspace.
2. Connected request/order/payment tasks and clear progress/correction actions.
3. Production blockers and stock/recipe resolution with preserved return paths.
4. Guided product editing, customer continuity and business reports.
5. Full journey validation, responsive/keyboard review and relevant regressions.

For each journey, compare the existing and proposed number of page changes,
repeated inputs and decisions. Establish the baseline first; no numerical time
or click reduction is claimed by this review.

Acceptance should include both catalogue and custom orders, guest/existing/new
customers, pickup/delivery, deposit/no-deposit, pending/rejected/verified payment,
missing recipe, insufficient materials, stale edits, interrupted input and
completed/cancelled/archived records. The owner should always be able to answer:
What am I looking at? What needs doing? What blocks it? What happens if I act?
How do I return or recover?

## Source pointers

- `app/Views/Components/Admin/navigation.php`
- `app/Views/Pages/admin/dashboard/index.php`
- `app/Views/Pages/admin/orders/create.php`
- `app/Views/Pages/admin/orders/detail.php`
- `app/Views/Components/Admin/order-status-select.php`
- `app/Views/Pages/admin/requests/detail.php`
- `app/Views/Pages/admin/requests/convert.php`
- `app/Views/Pages/admin/payments/order.php`
- `app/Views/Pages/admin/payments/review.php`
- `app/Views/Pages/admin/production/queue.php`
- `app/Views/Pages/admin/production/sheet.php`
- `app/Views/Pages/admin/catalogue/product-edit.php`
- `app/Views/Pages/admin/inventory/material-edit.php`
- `app/Views/Pages/admin/customers/detail.php`
- `app/Views/Pages/admin/reports/index.php`
- `app/Views/Pages/admin/settings/index.php`
- `public/assets/css/admin/layout/foundation.css`
- `docs/admin-dashboard/audit-2026-11-09/README.md`
- `docs/admin-dashboard/audit-2026-11-09/repair-batch-2.md`
