<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'request_form' => [
        'url' => env('REQUEST_FORM_URL', '/request-form-system/requests/create'),
        'sso_enabled' => env('REQUEST_FORM_SSO_ENABLED', false),
        'sso_url' => env('REQUEST_FORM_SSO_URL', '/request-form-system/sso/menam/consume'),
        'sso_issuer' => env('REQUEST_FORM_SSO_ISSUER', 'menam-workflow'),
        'sso_audience' => env('REQUEST_FORM_SSO_AUDIENCE', 'request-form-system'),
        'sso_key_id' => env('REQUEST_FORM_SSO_KEY_ID'),
        'sso_private_key_path' => env('REQUEST_FORM_SSO_PRIVATE_KEY_PATH'),
        'sso_ttl_seconds' => (int) env('REQUEST_FORM_SSO_TTL_SECONDS', 120),
        'sso_allowed_email_domains' => array_values(array_filter(array_map(
            static fn ($domain) => strtolower(trim($domain)),
            explode(',', (string) env('REQUEST_FORM_SSO_ALLOWED_EMAIL_DOMAINS', 'menamstainless.co.th'))
        ))),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'erp' => [
        'base_url'        => env('ERP_BASE_URL', 'http://192.168.1.40/cpa/rp-forms.php'),
        'login_url'       => env('ERP_LOGIN_URL', 'http://192.168.1.40/cpa/login.php'),
        'phpsessid'       => env('ERP_PHPSESSID'),
        'po_form_id_wire' => env('ERP_PO_FORM_ID_WIRE', 60169),
        'po_form_id_plus' => env('ERP_PO_FORM_ID_PLUS', 26626),
        'timeout'         => env('ERP_TIMEOUT', 30),
        'saleorder_sync_script' => env('ERP_SALEORDER_SYNC_SCRIPT', 'E:\Film\python_code\saleorder.bat'),
        'workorder_sync_script' => env('ERP_WORKORDER_SYNC_SCRIPT', 'E:\Film\python_code\workorder.bat'),
        'saleorder_sync_timeout' => env('ERP_SALEORDER_SYNC_TIMEOUT', 900),
    ],

];
