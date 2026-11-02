<?php

return [

    /*
    |--------------------------------------------------------------------------
    | License enforcement
    |--------------------------------------------------------------------------
    |
    | LOCAL:  LICENSE_ENFORCED=false
    | CLIENT: LICENSE_ENFORCED=true
    |
    */
    'enforced' => filter_var(env('LICENSE_ENFORCED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Bind activation token to this machine fingerprint
    |--------------------------------------------------------------------------
    |
    | CLIENT: LICENSE_BIND_MACHINE=true
    | LOCAL:  LICENSE_BIND_MACHINE=false (optional while developing)
    |
    */
    'bind_machine' => filter_var(env('LICENSE_BIND_MACHINE', env('LICENSE_ENFORCED', false)), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Include hostname in machine fingerprint
    |--------------------------------------------------------------------------
    |
    | CLIENT: LICENSE_INCLUDE_HOSTNAME=true (stronger binding)
    | LOCAL:  LICENSE_INCLUDE_HOSTNAME=false
    |
    */
    'include_hostname' => filter_var(env('LICENSE_INCLUDE_HOSTNAME', false), FILTER_VALIDATE_BOOLEAN),

    'signing_secret' => env('LICENSE_SIGNING_SECRET', env('APP_KEY')),

    'issuer' => env('LICENSE_ISSUER', config('app.name', 'Portal Licensing')),

];
