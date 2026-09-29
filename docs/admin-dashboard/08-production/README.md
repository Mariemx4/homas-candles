# Module 08: Production

## Objective

Turn confirmed orders into a practical, deadline-driven production queue and calendar using existing order and inventory data.

## Build

- Production queue for applicable confirmed/preparing orders ordered by overdue state and required date.
- Calendar/list views with date navigation and filters for status, fulfilment method, product, scent, or color where practical.
- Preparation sheet grouping required products, quantities, scents, colors, and customization notes.
- Material-feasibility calculation based on actual recipes and current tracked stock.
- Clear blockers for missing recipes or insufficient materials; do not present estimates as guaranteed availability.
- Links to the secured order workflow for allowed actions rather than duplicating status logic.

## Acceptance criteria

1. Only relevant non-completed orders appear, using schema-defined statuses.
2. Overdue and near-due work sorts correctly across date boundaries and remains distinguishable without color alone.
3. Grouped production quantities match order items and set/component rules without double counting.
4. Feasibility uses actual recipe inputs, tracked stock, and correct units; missing recipe data is explicitly reported.
5. The module does not change stock directly or duplicate Module 03 transition logic.
6. Filters and calendar navigation are allow-listed, injection-safe, and preserve state.
7. Preparation output excludes unnecessary customer contact/payment data.
8. Calendar, list, and preparation views are printable, accessible, and responsive.
9. Empty production periods and partial/incomplete recipe data render safely.
10. Tests cover date boundaries, status inclusion, aggregation, material shortages, missing recipes, sets, and authorization.
