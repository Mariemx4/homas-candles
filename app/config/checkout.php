<?php

declare(strict_types=1);



// WHAT: the business numbers used by the public cart and checkout.
// WHY:  the cart summary, the checkout form, and CheckoutService must all
//       agree on the same minimum, fee and deposit rule. Keeping them here
//       means one edit changes what the customer sees AND what is charged.
//       Money is written in thousandths of a dinar (millis) so the service
//       never does floating-point arithmetic on prices.
return [
    // Minimum value of the selected pieces (delivery excluded) — docs/database-requirements.md.
    'minimum_order_millis' => 40000,

    // Flat delivery fee across Tunisia; pickup orders are always 0.000 DT.
    'delivery_fee_millis' => 9000,

    // Orders whose total reaches this amount need a deposit before confirmation.
    // Must stay in step with chk_orders_required_deposit (migration 005).
    'deposit_threshold_millis' => 100000,
    'deposit_percent' => 40,

    // Every piece is handmade to order: the earliest selectable required date
    // is today + this many days, and customers cannot book further ahead
    // than the maximum (keeps the production calendar realistic).
    'preparation_days' => 3,
    'maximum_booking_days' => 90,

    // Free-text limits for the checkout form.
    'customer_notes_max_length' => 500, 
];
