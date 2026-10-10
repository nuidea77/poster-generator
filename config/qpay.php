<?php

return [

    // https://merchant-sandbox.qpay.mn for testing.
    'base_url' => env('QPAY_BASE_URL', 'https://merchant.qpay.mn'),
    'client_id' => env('QPAY_CLIENT_ID'),
    'client_secret' => env('QPAY_CLIENT_SECRET'),
    'invoice_code' => env('QPAY_INVOICE_CODE'),

    // Public base URL QPay can reach for callbacks (defaults to APP_URL).
    'callback_base' => env('QPAY_CALLBACK_BASE'),

    // Local development without merchant credentials: invoices are simulated
    // and can be marked paid from the UI. Never enable in production.
    'fake' => (bool) env('QPAY_FAKE', false),

    'invoice_ttl_hours' => 24,

    // Minimum seconds between payment/check calls triggered by client polling.
    'check_interval' => 10,

];
