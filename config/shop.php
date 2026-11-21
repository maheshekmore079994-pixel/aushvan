<?php

return [
    // Business dates (bill date, financial year, day closing) use the shop's local time.
    // Timestamps in the database stay in UTC.
    'timezone' => env('SHOP_TIMEZONE', 'Asia/Kolkata'),

    'languages' => ['en', 'mr', 'hi'],

    'dosage_forms' => [
        'tablet', 'capsule', 'syrup', 'suspension', 'injection', 'cream', 'ointment', 'gel',
        'drops', 'inhaler', 'powder', 'sachet', 'lotion', 'spray', 'other',
    ],

    // Failed logins allowed per username + IP per minute.
    'login_attempts_per_minute' => 5,
];
