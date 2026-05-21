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
        'po_form_id_plus' => env('ERP_PO_FORM_ID_PLUS', 60169),
        'timeout'         => env('ERP_TIMEOUT', 30),
    ],

];
