# Module 07: Materials and Inventory

## Objective

Manage materials, recipes, costing inputs, and an auditable stock ledger without direct untracked balance edits.

## Build

- Material list/detail with type, SKU, unit, current quantity, unit cost, stock-tracking state, and activity state.
- Material creation/editing with immutable or carefully controlled identity fields once referenced.
- Stock-in, adjustment-in, adjustment-out, and damaged/wasted actions through `material_stock_movements`.
- Movement history filterable by material, type, date, order, and administrator.
- Product recipe editor for actual and candle-equivalent recipes.
- Product cost calculation from current recipe quantities and material unit costs.
- Stock-attention view that uses a genuine configured threshold when available; otherwise label it as lowest stock rather than low stock.

## Acceptance criteria

1. Material balance changes are never made without a matching ledger movement.
2. Balance update and movement insert commit atomically with before/after values and administrator identity.
3. Movement type, sign, order relationship, note requirement, and arithmetic follow database constraints.
4. Adjustments and waste require a meaningful note; stock cannot become negative.
5. Repeated submissions cannot accidentally apply the same adjustment twice.
6. Recipe quantities are positive, use permitted material types, and cannot duplicate the same recipe key.
7. Product cost is derived consistently and not silently written over historical order cost snapshots.
8. Lists and history are paginated, injection-safe, and display unit labels consistently.
9. Referenced materials are deactivated rather than destructively removed.
10. Tests cover transaction rollback, movement direction, notes, insufficient stock, recipe validation, cost calculation, authorization, and CSRF.
