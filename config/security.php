<?php

return [

    'enabled' => env('FIREWALL_ENABLED', true),

    'whitelist' => explode(',', env('FIREWALL_WHITELIST', '127.0.0.0/24')),

    'dashboard' => [
        'enabled' => env('FIREWALL_DASHBOARD_ENABLED', true),
        'route_prefix' => 'security',
        'route_name' => 'security.',
        'date_format' => 'd/m/Y H:i:s',
        'middleware' => [
            'auth',
            OzanKurt\Security\Http\Middleware\SecurityDashboardMiddleware::class,
        ],
        'user_name_field' => 'full_name',
        'logo_target_route_name' => null,
    ],

    'database' => [
        'connection' => env('FIREWALL_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

        'table_prefix' => env('FIREWALL_DB_PREFIX', 'security_'),

        'max_request_data_size' => 2048,

        'user' => [
            'model' => \App\Models\User::class,
        ],

        'auth_log' => [
            'model' => \OzanKurt\Security\Models\AuthLog::class,
            'table' => 'auth_logs',
        ],

        'log' => [
            'model' => \OzanKurt\Security\Models\Log::class,
            'table' => 'logs',
        ],

        'ip' => [
            'model' => \OzanKurt\Security\Models\Ip::class,
            'table' => 'ips',
        ],
    ],

    'crons' => [
        'unblock_ips' => [
            'enabled' => env('FIREWALL_CRONS_UNBLOCK_IPS_ENABLED', true),
            'cron_expression' => env('FIREWALL_CRONS_UNBLOCK_IPS_EXPRESSION', '* * * * *'),
        ],
    ],

    'notifications' => [
        'attack_detected' => [
            'enabled' => env('FIREWALL_NOTIFICATIONS_ATTACK_DETECTED_ENABLED', false),
            // Only "slack" and "discord" channels are supported for now
            'channels' => [
                'slack',
                'discord',
            ],
        ],

        'security_report' => [
            'enabled' => env('FIREWALL_NOTIFICATIONS_SECURITY_REPORT_ENABLED', false),
            // Only "mail" channel is supported for now
            'channels' => [
                'mail',
            ],
            // Set to Monday 8:00 AM by default
            'cron_expression' => env('FIREWALL_NOTIFICATIONS_SECURITY_REPORT_CRON_EXPRESSION', '0 8 * * 1'),
        ],

        'successful_login' => [
            'enabled' => env('FIREWALL_NOTIFICATIONS_SUCCESSFUL_LOGIN_ENABLED', false),
            'channels' => [
                'mail',
                'slack',
                'discord',
            ],
        ],

        'failed_login' => [
            'enabled' => env('FIREWALL_NOTIFICATIONS_FAILED_LOGIN_ENABLED', false),
            'channels' => [
                'mail',
                'slack',
                'discord',
            ],
        ],
    ],

    'notification_channels' => [

        'mail' => [
            'enabled' => env('FIREWALL_NOTIFICATION_CHANNELS_EMAIL_ENABLED', false),
            'name' => env('FIREWALL_NOTIFICATION_CHANNELS_EMAIL_NAME', 'Laravel Security'),
            'from' => env('FIREWALL_NOTIFICATION_CHANNELS_EMAIL_FROM', 'security@example.com'),
            'to' => env('FIREWALL_NOTIFICATION_CHANNELS_EMAIL_TO', 'admin@example.com'),
            'queue' => env('FIREWALL_NOTIFICATION_CHANNELS_EMAIL_QUEUE', 'default'),
        ],

        'slack' => [
            'enabled' => env('FIREWALL_NOTIFICATION_CHANNELS_SLACK_ENABLED', false),
            'emoji' => env('FIREWALL_NOTIFICATION_CHANNELS_SLACK_EMOJI', ':fire:'),
            'from' => env('FIREWALL_NOTIFICATION_CHANNELS_SLACK_FROM', 'Laravel Security'),
            'to' => env('FIREWALL_NOTIFICATION_CHANNELS_SLACK_TO'), // webhook url
            'channel' => env('FIREWALL_NOTIFICATION_CHANNELS_SLACK_CHANNEL', null), // set null to use the default channel of webhook
            'queue' => env('FIREWALL_NOTIFICATION_CHANNELS_SLACK_QUEUE', 'default'),
        ],

        'discord' => [
            'enabled' => env('FIREWALL_NOTIFICATION_CHANNELS_DISCORD_ENABLED', false),
            'webhook_url' => env('FIREWALL_NOTIFICATION_CHANNELS_DISCORD_WEBHOOK_URL'),
            'queue' => env('FIREWALL_NOTIFICATION_CHANNELS_DISCORD_QUEUE', 'default'),

            // Embed Customizations
            'from' => env('FIREWALL_NOTIFICATION_CHANNELS_DISCORD_FROM', 'Laravel Security'),
            'from_img' => env('FIREWALL_NOTIFICATION_CHANNELS_DISCORD_FROM_IMG', 'https://ozankurt.com/laravel-security.png'),
            'route' => env('FIREWALL_NOTIFICATION_CHANNELS_DISCORD_ROUTE'), # Route name to your security dashboard
            'title' => env('FIREWALL_NOTIFICATION_CHANNELS_DISCORD_TITLE', 'Attack Detected'),
            'footer' => env('FIREWALL_NOTIFICATION_CHANNELS_DISCORD_FOOTER', 'Laravel Security'),
            'footer_img' => env('FIREWALL_NOTIFICATION_CHANNELS_DISCORD_FOOTER_IMG', 'https://ozankurt.com/laravel-security.png'),
        ],

    ],

    'all_middleware' => [
        'firewall.ip',
        'firewall.agent',
        'firewall.bot',
        'firewall.geo',
        'firewall.lfi',
        'firewall.php',
        'firewall.referrer',
        'firewall.rfi',
        'firewall.session',
        'firewall.sqli',
        'firewall.swear',
        'firewall.xss',
        'firewall.keyword',
        //'App\Http\Middleware\YourCustomRule',
    ],

    'middleware' => [

        'ip' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_IP_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['all'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],
        ],

        'agent' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_AGENT_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['all'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            // https://github.com/jenssegers/agent
            'browsers' => [
                'allow' => [], // i.e. 'Chrome', 'Firefox'
                'block' => [], // i.e. 'IE'
            ],

            'platforms' => [
                'allow' => [], // i.e. 'Ubuntu', 'Windows'
                'block' => [], // i.e. 'OS X'
            ],

            'devices' => [
                'allow' => [], // i.e. 'Desktop', 'Mobile'
                'block' => [], // i.e. 'Tablet'
            ],

            'properties' => [
                'allow' => [], // i.e. 'Gecko', 'Version/5.1.7'
                'block' => [], // i.e. 'AppleWebKit'
            ],

            'auto_block' => [
                'attempts' => 5,
                'frequency' => 1 * 60, // 1 minute
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'bot' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_BOT_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['all'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            // https://github.com/JayBizzle/Crawler-Detect/blob/master/raw/Crawlers.txt
            'crawlers' => [
                'allow' => [], // i.e. 'GoogleSites', 'GuzzleHttp'
                'block' => [], // i.e. 'Holmes'
            ],

            'auto_block' => [
                'attempts' => 5,
                'frequency' => 1 * 60, // 1 minute
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'geo' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_GEO_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['all'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            'continents' => [
                'allow' => [], // i.e. 'Africa'
                'block' => [], // i.e. 'Europe'
            ],

            'regions' => [
                'allow' => [], // i.e. 'California'
                'block' => [], // i.e. 'Nevada'
            ],

            'countries' => [
                'allow' => [], // i.e. 'Albania'
                'block' => [], // i.e. 'Madagascar'
            ],

            'cities' => [
                'allow' => [], // i.e. 'Istanbul'
                'block' => [], // i.e. 'London'
            ],

            // ipapi, extremeiplookup, ipstack, ipdata, ipinfo, ipregistry, ip2locationio
            'service' => 'ipapi',

            'auto_block' => [
                'attempts' => 3,
                'frequency' => 5 * 60, // 5 minutes
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'lfi' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_LFI_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['get', 'delete'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            'inputs' => [
                'only' => [], // i.e. 'first_name'
                'except' => [], // i.e. 'password'
            ],

            'patterns' => [
                '#\.\/#is',
            ],

            'auto_block' => [
                'attempts' => 3,
                'frequency' => 5 * 60, // 5 minutes
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'login' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_LOGIN_ENABLED', env('FIREWALL_ENABLED', true)),

            'auto_block' => [
                'attempts' => 5,
                'frequency' => 1 * 60, // 1 minute
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'successful_login' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_SUCCESSFUL_LOGIN_ENABLED', env('FIREWALL_ENABLED', true)),
        ],

        'php' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_PHP_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['get', 'post', 'delete'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            'inputs' => [
                'only' => [], // i.e. 'first_name'
                'except' => [], // i.e. 'password'
            ],

            'patterns' => [
                'bzip2://',
                'expect://',
                'glob://',
                'phar://',
                'php://',
                'ogg://',
                'rar://',
                'ssh2://',
                'zip://',
                'zlib://',
            ],

            'auto_block' => [
                'attempts' => 3,
                'frequency' => 5 * 60, // 5 minutes
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'referrer' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_REFERRER_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['all'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            'blocked' => [],

            'auto_block' => [
                'attempts' => 3,
                'frequency' => 5 * 60, // 5 minutes
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'rfi' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_RFI_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['get', 'post', 'delete'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            'inputs' => [
                'only' => [], // i.e. 'first_name'
                'except' => [], // i.e. 'password'
            ],

            'patterns' => [
                '#(http|https){1,1}://.*\..{2,4}/.*\..{2,4}#i',
                '#(ftp|sftp|ftps){1,1}://.*#i',
            ],

            'exceptions' => [],

            'auto_block' => [
                'attempts' => 3,
                'frequency' => 5 * 60, // 5 minutes
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'session' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_SESSION_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['get', 'post', 'delete'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            'inputs' => [
                'only' => [], // i.e. 'first_name'
                'except' => [], // i.e. 'password'
            ],

            'patterns' => [
                '@[\|:]O:\d{1,}:"[\w_][\w\d_]{0,}":\d{1,}:{@i',
                '@[\|:]a:\d{1,}:{@i',
            ],

            'auto_block' => [
                'attempts' => 3,
                'frequency' => 5 * 60, // 5 minutes
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'sqli' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_SQLI_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['get', 'delete'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            'inputs' => [
                'only' => [], // i.e. 'first_name'
                'except' => [], // i.e. 'password'
            ],

            'patterns' => [
                '#[\d\W](union select|union join|union distinct)[\d\W]#is',
                '#[\d\W](union|union select|insert|from|where|concat|into|cast|truncate|select|delete|having)[\d\W]#is',
            ],

            'auto_block' => [
                'attempts' => 3,
                'frequency' => 5 * 60, // 5 minutes
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'swear' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_SWEAR_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['post', 'put', 'patch'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            'inputs' => [
                'only' => [], // i.e. 'first_name'
                'except' => [], // i.e. 'password'
            ],

            'words' => [],

            'auto_block' => [
                'attempts' => 3,
                'frequency' => 5 * 60, // 5 minutes
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'url' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_URL_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['all'],

            'inspections' => [], // i.e. 'admin'

            'auto_block' => [
                'attempts' => 5,
                'frequency' => 1 * 60, // 1 minute
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'whitelist' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_WHITELIST_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['all'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],
        ],

        'xss' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_XSS_ENABLED', env('FIREWALL_ENABLED', true)),

            'mode' => 'block', // 'block', 'clean'

            'allow_blade_echoes' => false,

            'blade_echo_tags' => [
                ['{!!', '!!}'],
                ['{{', '}}'],
                ['{{{', '}}}'],
            ],

            'methods' => ['post', 'put', 'patch'],

            'routes' => [
                'only' => [], // i.e. 'contact'
                'except' => [], // i.e. 'admin/*'
            ],

            'inputs' => [
                'only' => [], // i.e. 'first_name'
                'except' => [], // i.e. 'password'
            ],

            'patterns' => [
                // Evil starting attributes
                '#(<[^>]+[\x00-\x20\"\'\/])(form|formaction|on\w*|style|xmlns|xlink:href)[^>]*>?#iUu',

                // javascript:, livescript:, vbscript:, mocha: protocols
                '!((java|live|vb)script|mocha|feed|data):(\w)*!iUu',
                '#-moz-binding[\x00-\x20]*:#u',

                // Unneeded tags
                '#</*(applet|meta|xml|blink|link|style|script|embed|object|iframe|frame|frameset|ilayer|layer|bgsound|title|base|img)[^>]*>?#i'
            ],

            'auto_block' => [
                'attempts' => 3,
                'frequency' => 5 * 60, // 5 minutes
                'period' => 30 * 60, // 30 minutes
            ],
        ],

        'keyword' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_KEYWORD_ENABLED', env('FIREWALL_ENABLED', true)),

            'methods' => ['all'],

            'patterns' => [
                '#/etc/#i',
                '#\.bak#i',
                '#\.db#i',
                '#\.env#i',
                '#\.git#i',
                '#\.log#i',
                '#\.production#i',
                '#\.remote#i',
                '#\.sh#i',
                '#\.sql#i',
                '#\.temp#i',
                '#\.tmp#i',
                '#cgi#i',
                '#etc/passwd#i',
                '#license\.md#i',
                '#license\.txt#i',
                '#logs/#i',
                '#logs\.#i',
                '#phpinfo#i',
                '#readme\.html#i',
                '#readme\.txt#i',
                '#wlwmanifest\.xml#i',
                '#wp-admin#i',
                '#wp-config#i',
                '#wp-content#i',
                '#wp-includes#i',
                '#xmlrpc#i',
                '#~#i',
            ],

            'auto_block' => [
                'attempts' => 3,
                'frequency' => 5 * 60, // 5 minutes
                'period' => 30 * 60, // 30 minutes
            ],
        ],

    ],

    'responses' => [

        'block' => [
            'view' => null,
            'redirect' => null,
            'abort' => true,
            'code' => 403,
            // 'exception' => \App\Exceptions\AccessDenied::class,
        ],

    ],

];
