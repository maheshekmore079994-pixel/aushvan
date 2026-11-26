<?php

return [
    // Business dates (bill date, financial year, day closing) use the shop's local time.
    // Timestamps in the database stay in UTC.
    'timezone' => env('SHOP_TIMEZONE', 'Asia/Kolkata'),

    'languages' => ['en', 'mr', 'hi'],

    /*
    | The shop the demo seeder creates (php artisan db:seed --class=DemoDataSeeder). Set DEMO_* in .env to
    | present a particular shop; the products, customers and bills are the same either way.
    */
    'demo' => [
        'shop_name' => env('DEMO_SHOP_NAME', 'Shree Medical & General Stores'),
        'owner_name' => env('DEMO_OWNER_NAME', 'Asha Patil'),
        'address' => env('DEMO_ADDRESS', 'Main Road, near Bus Stand'),
        'village' => env('DEMO_VILLAGE', 'Wadgaon'),
        'nearby' => env('DEMO_NEARBY', 'Kanhe,Takwe'), // villages some demo customers come from
        'taluka' => env('DEMO_TALUKA', 'Maval'),
        'district' => env('DEMO_DISTRICT', 'Pune'),
        'pincode' => env('DEMO_PINCODE', '412106'),
        'phone' => env('DEMO_PHONE', '9800000000'),
        'gstin' => env('DEMO_GSTIN', '27AAAAA0000A1Z5'),
        'drug_licence_20' => env('DEMO_DRUG_LICENCE_20', 'MH-PZ4-000001'),
        'drug_licence_21' => env('DEMO_DRUG_LICENCE_21', 'MH-PZ4-000002'),
    ],

    'dosage_forms' => [
        'tablet', 'capsule', 'syrup', 'suspension', 'injection', 'cream', 'ointment', 'gel',
        'drops', 'inhaler', 'powder', 'sachet', 'lotion', 'spray', 'other',
    ],

    // Failed logins allowed per username + IP per minute.
    'login_attempts_per_minute' => 5,

    // Written into every backup's manifest; the desktop build sets APP_VERSION from the package version.
    'version' => env('APP_VERSION', '0.9.0'),

    'backup' => [
        // Where backups are made and kept on this PC/server before (and besides) the backup folder.
        'local_dir' => env('BACKUP_LOCAL_DIR', storage_path('app/backups')),
        // Local copies kept; older files are removed (the rows and the folder copies stay).
        'keep_local' => (int) env('BACKUP_KEEP_LOCAL', 7),
        // The dashboard warns when the last verified backup is older than this.
        'due_after_hours' => (int) env('BACKUP_DUE_AFTER_HOURS', 48),
        // A backup folder set and no verified backup within this many hours → one is taken automatically.
        'auto_after_hours' => (int) env('BACKUP_AUTO_AFTER_HOURS', 20),
    ],
];
