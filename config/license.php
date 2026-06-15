<?php

return [
    'enforced' => false,
    'signing_secret' => env('LICENSE_SIGNING_SECRET', env('APP_KEY')),
    'issuer' => env('LICENSE_ISSUER', config('app.name', 'Portal Licensing')),
];
