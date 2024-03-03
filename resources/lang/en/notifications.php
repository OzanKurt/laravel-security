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

];
