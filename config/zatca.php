<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ZATCA E-Invoicing
    |--------------------------------------------------------------------------
    |
    | Sandbox (developer-portal) is for integration tests only.
    | Production / simulation use different base paths and real Fatoora OTP.
    |
    */

    'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),

    /** Master switch — when false, checkout will not queue ZATCA reporting. */
    'enabled' => (bool) env('ZATCA_ENABLED', true),

    /** Queue a simplified invoice report after successful guest check-out. */
    'auto_report_on_checkout' => (bool) env('ZATCA_AUTO_REPORT_ON_CHECKOUT', true),

    'base_urls' => [
        'sandbox' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
        'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
        'production' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
    ],

    'accept_version' => 'V2',

    /*
    | Sandbox OTP examples from ZATCA Compliance CSID docs: 123345, 111111, 222222
    | Production OTP must be generated from the Fatoora portal.
    */
    'otp' => env('ZATCA_OTP', '123345'),

    'csr' => [
        'common_name' => env('ZATCA_CSR_COMMON_NAME', 'Hotel System EGS'),
        'serial_number' => env('ZATCA_CSR_SERIAL_NUMBER', '1-HotelSystem|2-1.0.0|3-'.substr(md5((string) env('APP_KEY', 'local')), 0, 12)),
        'organization_identifier' => env('ZATCA_VAT_NUMBER', '300000000000003'),
        'organization_unit_name' => env('ZATCA_ORG_UNIT', 'Riyadh Branch'),
        'organization_name' => env('ZATCA_ORG_NAME', 'Hotel System Test'),
        'country_name' => 'SA',
        // 1100 = Standard + Simplified invoices
        'invoice_type' => env('ZATCA_INVOICE_TYPE', '1100'),
        'location' => env('ZATCA_LOCATION', 'Riyadh'),
        'industry' => env('ZATCA_INDUSTRY', 'Hospitality'),
        // Sandbox / developer-portal template
        'certificate_template' => env('ZATCA_CSR_TEMPLATE', 'TSTZATCA-Code-Signing'),
    ],

    'storage_path' => storage_path('app/zatca'),

];
