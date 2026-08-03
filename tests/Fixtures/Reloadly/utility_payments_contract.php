<?php

return [
    'paths' => [
        'oauth_token' => [
            'method' => 'POST',
            'path' => 'https://auth.reloadly.com/oauth/token',
        ],
        'balance' => [
            'method' => 'GET',
            'path' => '/accounts/balance',
        ],
        'billers' => [
            'method' => 'GET',
            'path' => '/billers',
        ],
        'pay' => [
            'method' => 'POST',
            'path' => '/pay',
        ],
        'transactions' => [
            'method' => 'GET',
            'path' => '/transactions',
        ],
        'transaction' => [
            'method' => 'GET',
            'path' => '/transactions/{id}',
        ],
    ],
    'pay' => [
        'required' => [
            'subscriberAccountNumber',
            'amount',
            'billerId',
        ],
        'optional' => [
            'amountId',
            'useLocalAmount',
            'referenceId',
            'additionalInfo.invoiceId',
        ],
        'fixed_denomination_amount_id' => true,
    ],
    'biller' => [
        'denomination_types' => ['FIXED', 'RANGE'],
        'fixed_amount_fields' => ['id', 'amount', 'description'],
    ],
];