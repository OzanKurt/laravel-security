<?php

return [

    'enabled' => env('FIREWALL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Bypass Mechanism
    |--------------------------------------------------------------------------
    |
    | LS_BYPASS_KEY is intentionally read via env() inside the middleware (not
    | here) so it is never baked into the config cache. Add IPs here (or via
    | LS_BYPASS_IPS) to whitelist them permanently — they cannot be removed via
    | the dashboard UI.
    |
    */
    'bypass' => [
        'ips' => array_filter(explode(',', env('LS_BYPASS_IPS', ''))),
    ],

    'whitelist' => explode(',', env('FIREWALL_WHITELIST', '127.0.0.0/24')),

    'dashboard' => [
        'enabled' => env('FIREWALL_DASHBOARD_ENABLED', true),
        'route_prefix' => 'shield',
        'route_name' => 'shield.',
        'date_format' => 'd/m/Y H:i:s',
        'middleware' => [
            'auth',
            OzanKurt\Shield\Http\Middleware\ShieldDashboardMiddleware::class,
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
            'model' => \OzanKurt\Shield\Models\AuthLog::class,
            'table' => 'auth_logs',
        ],

        'log' => [
            'model' => \OzanKurt\Shield\Models\Log::class,
            'table' => 'logs',
        ],

        'ip' => [
            'model' => \OzanKurt\Shield\Models\Ip::class,
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

        'failed_login' => [
            'enabled' => env('FIREWALL_MIDDLEWARE_FAILED_LOGIN_ENABLED', env('FIREWALL_ENABLED', true)),

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

    'scanner' => [
        'clamav' => [
            'enabled' => env('LS_CLAMAV_ENABLED', false),
            'socket' => env('LS_CLAMAV_SOCKET', '/var/run/clamav/clamd.ctl'),
            'timeout' => 30,
        ],
        'native' => [
            'max_file_bytes' => 5 * 1024 * 1024, // 5 MB
        ],
        'signatures' => [
            'url' => env('LS_SIGNATURE_URL', 'https://api.github.com/repos/OzanKurt/laravel-shield-signatures/releases/latest'),
            'pin' => env('LS_SIGNATURE_PIN'),
            'sync_cron' => '0 5 * * *',
        ],
        'quarantine' => [
            'path' => 'storage/shield/quarantine',
            'per_target' => [
                'public_uploads' => 'move_and_stub',
                'unknown_files' => 'move_and_stub',
            ],
        ],
        'watch' => [
            'enabled' => env('LS_WATCH_ENABLED', false),
            'paths' => [],
            'poll_interval_ms' => env('LS_WATCH_POLL_MS', 3000),
        ],
    ],

    'headers' => [
        'enabled' => env('LS_HEADERS_ENABLED', true),
        'hsts' => [
            'enabled' => env('LS_HSTS_ENABLED', false),
            'max_age' => 31536000,
            'include_subdomains' => true,
            'preload' => false,
        ],
        'csp' => [
            'enabled' => env('LS_CSP_ENABLED', false),
            'policy' => "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'",
            'use_nonce' => true,
            'report_only' => false,
            'report_uri' => null,
        ],
        'x_frame_options' => [
            'enabled' => true,
            'value' => 'SAMEORIGIN',
        ],
        'x_content_type_options' => true,
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'camera=(), microphone=(), geolocation=()',
    ],

    'honeypot' => [
        'enabled' => env('LS_HONEYPOT_ENABLED', false),

        /*
         * Each entry registers a route that ALWAYS returns 404 + audit-logs
         * the hit + auto-blocks the source IP for shield.honeypot.block_duration
         * seconds.
         *
         * Wildcard subpaths are matched automatically — adding "wp-admin" also
         * catches /wp-admin, /wp-admin/, /wp-admin/install.php, etc.
         *
         * Paths below are high-confidence probes that have ZERO legitimate use
         * in a Laravel app. Do NOT add /api, /robots.txt, /favicon.ico,
         * /.well-known/*, /sitemap.xml, /health, /up — those are legitimate.
         */
        'paths' => [
            // WordPress core probes
            'wp-admin', 'wp-login.php', 'wp-config.php', 'wp-config.php.bak',
            'wp-config.php.old', 'wp-config.php.save', 'wp-config.php~',
            'wp-cron.php', 'wp-trackback.php', 'wp-mail.php', 'wp-signup.php',
            'wp-includes/wlwmanifest.xml', 'wp-includes/ID3/license.txt',
            'wp-content/debug.log', 'wp-content/plugins',
            'wordpress', 'blog/wp-admin', 'blog/wp-login.php',
            'xmlrpc.php',

            // phpMyAdmin variants
            'phpmyadmin', 'phpMyAdmin', 'pma', 'pMa', 'PMA', 'pmamy', 'pmamy2', 'pmd',
            'mysql', 'mysqladmin', 'dbadmin', 'myadmin',
            'adminer', 'adminer.php', 'sqlmanager', 'websql',
            'myadmin/scripts/setup.php',

            // Other admin panels
            'cpanel', 'whm', 'webmail', 'plesk', 'plesk-stat',
            'webmin', 'ispconfig', 'vesta', 'vestacp', 'directadmin',

            // Config + dotfile leaks
            '.env', '.env.bak', '.env.production', '.env.local', '.env.old',
            '.env.staging', '.env.save', '.env.dev', '.env.development', '.env.backup',
            '.git', '.git/config', '.git/HEAD', '.git/index', '.git/logs/HEAD',
            '.svn/entries', '.svn/wc.db',
            '.hg/hgrc', '.hg/store/00manifest.i', '.bzr',
            '.DS_Store',
            '.htaccess.bak', '.htpasswd',
            '.npmrc', '.pypirc',
            '.aws/credentials', '.aws/config',
            '.ssh/id_rsa', '.ssh/authorized_keys',
            '.composer/auth.json',

            // Backup probes
            'backup', 'backup.sql', 'backup.zip', 'backup.tar.gz', 'backup.tgz',
            'backup.7z', 'backup.rar', 'backup.tar', 'backup.tar.bz2',
            'db.sql', 'dump.sql', 'database.sql', 'mysql.sql',
            'site.zip', 'www.zip', 'html.zip', 'public.zip', 'public_html.zip',
            'master.zip', 'develop.zip', 'main.zip',

            // Backdoor common paths
            'shell.php', 'shellz.php', 'shell.aspx',
            'c99.php', 'c100.php', 'c99shell.php',
            'r57.php', 'r57shell.php',
            'wso.php', 'wso2.php',
            'alfa.php', 'alfa-shell.php',
            'mini.php', 'b374k.php', 'p0wny.php',
            'helper.php', 'hack.php', 'hax.php', 'bypass.php', 'pwn.php',
            'cmd.php', 'command.php',
            'byp.php', 'xx.php', 'xxx.php', 'shell-helper.php', 'small.php',

            // PHP info / debug probes
            'phpinfo.php', 'info.php', 'php.php', 'phpversion.php', 'ver.php',
            'test.php', 'i.php', 'pi.php', 'ph.php',
            'index_old.php', 'index_backup.php', 'index_bak.php', 'index.php.bak',
            '_profiler/phpinfo',

            // Other CMS probes
            'administrator/index.php',                  // Joomla
            'magento/admin', 'downloader', 'RELEASE_NOTES.txt',
            'prestashop/admin',
            'opencart/admin',
            'typo3conf', 'typo3conf/localconf.php',
            'moodle/admin',
            'vbulletin/admincp',
            'phpbb/install', 'phpbb/install/index.php',

            // Cred-leak / framework probes
            'credentials', 'credentials.txt', 'credentials.json',
            'secrets.json', 'secret.json', 'private.key',
            'actuator/env', 'actuator/heapdump',        // Spring Boot
            'jolokia',                                  // Java
            'console',                                  // PHP / Drupal
            'server-status', 'server-info',             // Apache mod_status (not on by default in Laravel)
            'xampp', 'lampp', 'wamp',
            'manager/html', 'manager/status',           // Tomcat
            'solr/admin',                               // Solr

            // Misc known-bad
            'etc/passwd', 'proc/self/environ',
            'phpinfo', 'info',
            'cgi-bin/test-cgi', 'cgi-bin/php5', 'cgi-bin/.env',
            '_all_dbs',                                 // CouchDB
            '_cat/indices',                             // Elasticsearch
        ],

        'block_duration' => 86400,                      // 24h
    ],

    'redaction' => [
        'keys' => [
            'password', 'password_confirmation', 'old_password', 'new_password',
            'token', '*_token', 'api_key', '*_key',
            'secret', '*_secret', 'authorization', 'cookie',
            'credit_card', 'card_number', 'cvv', 'cvc',
            'ssn', 'social_security_number',
        ],
        'placeholder' => '[redacted]',
        'use_regex' => true,
    ],

    'https' => [
        'enforce' => env('LS_ENFORCE_HTTPS', false),
        'production_only' => true,
    ],

    'disabled_routes' => [
        'enabled' => env('LS_DISABLED_ROUTES_ENABLED', false),
        'patterns' => [
            'install.php',
            '_ignition/*',
            'wp-config.php',
        ],
    ],

    'scoring' => [
        'enabled' => env('LS_SCORING_ENABLED', false),
        'threshold' => env('LS_SCORING_THRESHOLD', 100),
        'window' => env('LS_SCORING_WINDOW', 3600),
        'block_duration' => env('LS_SCORING_BLOCK_DURATION', 1800),
    ],

    'rate_limiters' => [
        'login' => ['enabled' => true, 'attempts' => 5, 'decay' => 60, 'by' => 'ip|email'],
        'password_reset' => ['enabled' => true, 'attempts' => 3, 'decay' => 60, 'by' => 'ip|email'],
        'api' => ['enabled' => false, 'attempts' => 60, 'decay' => 60, 'by' => 'user|ip'],
        'signup' => ['enabled' => true, 'attempts' => 3, 'decay' => 600, 'by' => 'ip'],
    ],

    'reports' => [
        'daily_digest' => [
            'enabled' => env('LS_REPORT_DAILY', false),
            'cron_expression' => '0 8 * * *',
            'channels' => ['mail'],
            'include_severities' => ['low', 'medium'],
            'group_by' => 'kind',
            'top_n' => 10,
        ],
        '3_day' => ['enabled' => env('LS_REPORT_3DAY', false), 'cron_expression' => '0 8 */3 * *', 'channels' => ['mail'], 'top_n' => 10],
        '7_day' => ['enabled' => env('LS_REPORT_7DAY', true),  'cron_expression' => '0 8 * * 1', 'channels' => ['mail'], 'top_n' => 10],
        '14_day' => ['enabled' => env('LS_REPORT_14DAY', false), 'cron_expression' => '0 8 1,15 * *', 'channels' => ['mail'], 'top_n' => 10],
        '30_day' => ['enabled' => env('LS_REPORT_30DAY', false), 'cron_expression' => '0 8 1 * *', 'channels' => ['mail'], 'top_n' => 10],
    ],

    'threat_feed' => [
        'providers' => [
            \OzanKurt\Shield\Services\ThreatFeed\Providers\SpamhausProvider::class,
            \OzanKurt\Shield\Services\ThreatFeed\Providers\AbuseIpDbProvider::class,
            \OzanKurt\Shield\Services\ThreatFeed\Providers\OwaspCrsProvider::class,
            \OzanKurt\Shield\Services\ThreatFeed\Providers\MaxMindGeoLite2Provider::class,
        ],
        'sync_cron' => '0 3 * * *',
        'spamhaus' => [
            'enabled' => env('LS_SPAMHAUS_ENABLED', true),
        ],
        'abuseipdb' => [
            'enabled' => env('LS_ABUSEIPDB_ENABLED', false),
            'key' => env('LS_ABUSEIPDB_KEY'),
            'confidence_minimum' => env('LS_ABUSEIPDB_CONFIDENCE_MINIMUM', 90),
        ],
        'owasp_crs' => [
            'enabled' => env('LS_OWASP_CRS_ENABLED', true),
        ],
        'maxmind' => [
            'enabled' => env('LS_MAXMIND_ENABLED', false),
            'license_key' => env('LS_MAXMIND_LICENSE_KEY'),
        ],
    ],

    'trusted_proxies' => [
        'cloudflare' => env('LS_TRUST_CLOUDFLARE', false),
        'extra' => [],
    ],

    'live_traffic' => [
        'enabled' => env('LS_LIVE_TRAFFIC_ENABLED', true),
        'sample_rate' => env('LS_LIVE_TRAFFIC_SAMPLE_RATE', 0.1),
        'skip_paths' => [
            '_debugbar/*', 'shield/*', 'vendor/shield/*',
            'horizon/*', 'telescope/*',
            'css/*', 'js/*', 'images/*', 'fonts/*',
            'favicon.ico',
        ],
        'real_time' => [
            'enabled' => env('LS_LIVE_TRAFFIC_REALTIME', false),
            'channel' => env('LS_LIVE_TRAFFIC_CHANNEL', 'shield.live-traffic'),
        ],
    ],

    'audit' => [
        'drift' => [
            'enabled' => env('SHIELD_DRIFT_ENABLED', true),
            'paths' => [
                'config/'       => '*.php',
                '.env'          => null,
                'composer.json' => null,
                'composer.lock' => null,
            ],
            'baseline_path' => 'storage/shield/baselines/files.json',
            'cron' => '0 4 * * *',
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

    /*
    |--------------------------------------------------------------------------
    | Premium license
    |--------------------------------------------------------------------------
    |
    | Runtime license-key check against the Central app at
    | laravel-shield.ozankurt.com. Modelled on Wordfence's wfLicense flow:
    | the key is validated remotely, the result is cached for 24h, and a
    | 7-day grace period applies if the Central app is unreachable. Premium
    | features that depend on this license must be gated via
    | Shield::isFeatureAvailable($feature) so the free fallback applies
    | when the license is missing, expired, or revoked.
    |
    | The license key is treated as a secret — it is NEVER logged into
    | ls_audit_log, NEVER appears in telemetry, and NEVER shows up in
    | rendered dashboard pages in clear text.
    |
    */
    'premium' => [
        'license_key' => env('LS_PREMIUM_LICENSE_KEY'),

        // Central license-check API. Override only in tests / staging.
        'check_url' => env(
            'LS_PREMIUM_LICENSE_CHECK_URL',
            'https://laravel-shield.ozankurt.com/api/license/check'
        ),

        // Number of days that previously-valid licenses keep working when
        // the Central app is unreachable. After this, premium features
        // deactivate and a banner is shown in the dashboard.
        'grace_period_days' => (int) env('LS_PREMIUM_LICENSE_GRACE_DAYS', 7),

        // Cache TTL for license-check results, in seconds.
        'cache_ttl' => (int) env('LS_PREMIUM_LICENSE_CACHE_TTL', 86400),

        // Cache key used by LicenseChecker. Change only if you operate
        // multiple Shield-protected sites that share the same Redis db
        // AND want them to maintain independent license-check caches.
        'cache_key' => env('LS_PREMIUM_LICENSE_CACHE_KEY', 'shield.premium.license'),

        // HTTP request timeout for the license check, in seconds.
        'http_timeout' => (int) env('LS_PREMIUM_LICENSE_HTTP_TIMEOUT', 10),

        // Webhook target on Central for event ingestion. Plugin pushes
        // ls_audit_log entries here when this URL is set AND the license
        // is active. Set to null to disable Central event push entirely.
        'webhook_ingest_url' => env(
            'LS_PREMIUM_WEBHOOK_INGEST_URL',
            'https://laravel-shield.ozankurt.com/api/webhooks/ingest'
        ),

        // Heartbeat — once per configured interval, plugin pings Central
        // with summary stats (request count, block count, version) so the
        // Central dashboard can show "last seen" for each protected site.
        'heartbeat' => [
            'enabled' => (bool) env('LS_PREMIUM_HEARTBEAT_ENABLED', true),
            'interval_minutes' => (int) env('LS_PREMIUM_HEARTBEAT_INTERVAL', 60),
            'url' => env(
                'LS_PREMIUM_HEARTBEAT_URL',
                'https://laravel-shield.ozankurt.com/api/heartbeat'
            ),
        ],
    ],

];
