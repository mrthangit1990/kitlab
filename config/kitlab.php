<?php
return [
    'shipping' => (int) env('KITLAB_SHIPPING_FEE', 30000),
    'bank' => env('KITLAB_BANK_DETAILS'),
    'demo_password' => env('KITLAB_DEMO_PASSWORD'),
];
