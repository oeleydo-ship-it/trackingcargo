<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Demo data in production
    |--------------------------------------------------------------------------
    |
    | DatabaseSeeder creates sample companies with accounts whose password is
    | SEED_DEFAULT_PASSWORD — including admin@cargoflow.test as a platform
    | admin. On a production database that is a known login to every tenant,
    | and it would also close the first-run /setup page before the real owner
    | reached it.
    |
    | So in production `db:seed` only installs the permission catalog and
    | system roles unless this is explicitly turned on (for a public demo
    | instance, say).
    |
    */

    'seed_demo_data' => (bool) env('SEED_DEMO_DATA', false),

];
