<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tamara API Merchant Token
    |--------------------------------------------------------------------------
    |
    | Your Tamara Merchant API Token provided in the Tamara Partner Portal.
    |
    */
    'merchant_token' => env('TAMARA_MERCHANT_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Tamara Webhook Notification Token
    |--------------------------------------------------------------------------
    |
    | Secret token used to verify webhook notifications from Tamara.
    |
    */
    'notification_token' => env('TAMARA_NOTIFICATION_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Sandbox / Testing Mode
    |--------------------------------------------------------------------------
    |
    | Set to true for development/staging testing on api-sandbox.tamara.co
    | Set to false for live production on api.tamara.co
    |
    */
    'sandbox' => env('TAMARA_SANDBOX', false),

    'auto_authorise' => env('TAMARA_AUTO_AUTHORISE', false),

    'requires_shipping_address' => env('TAMARA_REQUIRES_SHIPPING_ADDRESS', true),

    /*
    |--------------------------------------------------------------------------
    | Default Locale
    |--------------------------------------------------------------------------
    |
    | Default language and country code for checkout screen (e.g. 'ar_SA', 'en_SA')
    |
    */
    'default_locale' => env('TAMARA_DEFAULT_LOCALE', 'ar_SA'),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | Supported currencies: SAR, AED, KWD, BHD, etc.
    |
    */
    'currency' => env('TAMARA_CURRENCY', 'SAR'),
];

