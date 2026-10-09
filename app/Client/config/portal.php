<?php

/*
|--------------------------------------------------------------------------
| The buyer portal
|--------------------------------------------------------------------------
|
| The staff account in whose name the portal writes (orders, audit, the
| documents it renders), and how long an untouched cart is kept.
|
*/

return [

    // The Portal system user: operator, never signs in, member of the Portal access group.
    'actor_email' => env('PORTAL_ACTOR_EMAIL', 'portal@central.local'),

    // Carts untouched for this many days are pruned nightly.
    'cart_retention_days' => 90,

];
