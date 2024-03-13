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
        'last_modified_files' => 'Last modified files',
        'last_modification' => 'Last Modification',
        'file' => 'File',
        'mail' => [
            'subject' => 'Security Report for :domain',
            'message' => 'This email was sent by your :domain site and contains a security report for the period :start - :end.',
        ],
    ],

];
