<?php

return [

    'attack_detected' => [
        'mail' => [
            'subject' => '🔥 Possible attack on :domain',
            'message' => 'A possible :middleware attack on :domain has been detected from :ip address. The following URL has been affected: :url',
        ],

        'slack' => [
            'message' => 'A possible attack on :domain has been detected.',
        ],

        'discord' => [
            'message' => 'A possible attack on :domain has been detected.',
        ],
    ],

    'successful_login' => [
        'mail' => [
            'subject' => 'New login on :domain',
            'message' => 'There was a new login.',
        ],

        'slack' => [
            'message' => 'New login on :domain',
        ],

        'discord' => [
            'message' => 'New login on :domain',
        ],
    ],

    'security_report' => [
        'mail' => [
            'subject' => 'Security Report for :domain',
            'message' => 'This email was sent by your :domain site and contains a security report for the period :start - :end.',
        ],

        // Section titles
        'most_blocked_ips' => 'Most Blocked 10 IP Addresses',
        'most_blocked_countries' => 'Most Blocked 10 Countries',
        'most_failed_login_attempts' => 'Most Failed 10 Login Attempts',
        'last_modified_files' => 'Last Modified 15 Files',

        // Column titles
        'blocked_attacks' => 'Blocked Attacks',
        'ip' => 'Ip',
        'country' => 'Country',
        'total_blocks' => 'Total Blocks',
        'blocked_attacks' => 'Blocked Attacks',
        'country' => 'Country',
        'total_blocked_ips' => 'Total Blocked Ips',
        'total_blocks' => 'Total Blocks',
        'user' => 'User',
        'login_attempts' => 'Login Attempts',
        'user_exists' => 'User Exists',
        'last_modification' => 'Last Modification',
        'file' => 'File',
    ],

];
