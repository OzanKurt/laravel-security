# Web Application Firewall (WAF) package for Laravel

![Downloads](https://img.shields.io/packagist/dt/ozankurt/laravel-security)
![Tests](https://img.shields.io/github/actions/workflow/status/ozankurt/laravel-security/tests.yml?label=tests)
[![StyleCI](https://github.styleci.io/repos/197242392/shield?style=flat&branch=master)](https://styleci.io/repos/197242392)
[![License](https://img.shields.io/github/license/ozankurt/laravel-security)](LICENSE.md)

This package intends to protect your Laravel app from different type of attacks such as XSS, SQLi, RFI, LFI, User Agent, and a lot more. It will also block repeated attacks and send notification via email and/or slack when attack is detected. Furthermore, it will log failed logins and block the IP after a number of attempts.

Note: Some middleware classes (i.e. Xss) are empty as the `Middleware` abstract class that they extend does all of the job, dynamically. In short, they all works ;)

## Getting Started

### 1. Install

Run the following command:

```bash
composer require ozankurt/laravel-security
```

### 2. Publish

Publish configuration, language, and migrations

```bash
php artisan vendor:publish --tag=security
```

### 3. Database

Create db tables

```bash
php artisan migrate
```

### 4. Configure

You can change the security settings of your app from `config/security.php` file

## Usage

Middlewares are already defined so should just add them to routes. The `firewall.all` middleware applies all the middlewares available in the `all_middleware` array of config file.

```php
Route::group(['middleware' => 'firewall.all'], function () {
    Route::get('/', 'HomeController@index');
});
```

You can apply each middleware per route. For example, you can allow only whitelisted IPs to access admin:

```php
Route::group(['middleware' => 'firewall.whitelist'], function () {
    Route::get('/admin', 'AdminController@index');
});
```

Or you can get notified when anyone NOT in `whitelist` access admin, by adding it to the `inspections` config:

```php
Route::group(['middleware' => 'firewall.url'], function () {
    Route::get('/admin', 'AdminController@index');
});
```

Available middlewares applicable to routes:

```php
firewall.all

firewall.agent
firewall.bot
firewall.geo
firewall.ip
firewall.lfi
firewall.php
firewall.referrer
firewall.rfi
firewall.session
firewall.sqli
firewall.swear
firewall.url
firewall.whitelist
firewall.xss
firewall.keyword
```

You may also define `routes` for each middleware in `config/security.php` and apply that middleware or `firewall.all` at the top of all routes.

## Notifications

Firewall will send a notification as soon as an attack has been detected. Emails entered in `notifications.email.to` config must be valid Laravel users in order to send notifications. Check out the Notifications documentation of Laravel for further information.

## .env Variables

```sh
FIREWALL_ENABLED=true
FIREWALL_WHITELIST="127.0.0.0/24"

FIREWALL_DB_CONNECTION="${DB_CONNECTION}"
FIREWALL_DB_PREFIX=security_

FIREWALL_CRON_ENABLED=false
FIREWALL_CRON_EXPRESSION="* * * * *"

FIREWALL_BLOCK_VIEW=null
FIREWALL_BLOCK_REDIRECT=null
FIREWALL_BLOCK_ABORT=false
FIREWALL_BLOCK_CODE=403

FIREWALL_EMAIL_ENABLED=false
FIREWALL_EMAIL_NAME="${MAIL_FROM_NAME}"
FIREWALL_EMAIL_FROM="${MAIL_FROM_ADDRESS}"
FIREWALL_EMAIL_TO="webmaster@example.com"
FIREWALL_EMAIL_QUEUE=default

FIREWALL_SLACK_ENABLED=false
FIREWALL_SLACK_EMOJI=":fire:"
FIREWALL_SLACK_FROM="Laravel Security"
FIREWALL_SLACK_TO= # webhook url
FIREWALL_SLACK_CHANNEL=null
FIREWALL_SLACK_QUEUE=default

FIREWALL_DISCORD_ENABLED=false
FIREWALL_DISCORD_TO= # webhook url
FIREWALL_DISCORD_CHANNEL=null
FIREWALL_DISCORD_QUEUE=default

FIREWALL_MIDDLEWARE_IP_ENABLED=true
FIREWALL_MIDDLEWARE_AGENT_ENABLED=true
FIREWALL_MIDDLEWARE_BOT_ENABLED=true
FIREWALL_MIDDLEWARE_GEO_ENABLED=true
FIREWALL_MIDDLEWARE_LFI_ENABLED=true
FIREWALL_MIDDLEWARE_LOGIN_ENABLED=true
FIREWALL_MIDDLEWARE_PHP_ENABLED=true
FIREWALL_MIDDLEWARE_REFERRER_ENABLED=true
FIREWALL_MIDDLEWARE_RFI_ENABLED=true
FIREWALL_MIDDLEWARE_SESSION_ENABLED=true
FIREWALL_MIDDLEWARE_SQLI_ENABLED=true
FIREWALL_MIDDLEWARE_SWEAR_ENABLED=true
FIREWALL_MIDDLEWARE_URL_ENABLED=true
FIREWALL_MIDDLEWARE_WHITELIST_ENABLED=true
FIREWALL_MIDDLEWARE_XSS_ENABLED=true
FIREWALL_MIDDLEWARE_KEYWORD_ENABLED=true
```

## Changelog

Please see [Releases](../../releases) for more information on what has changed recently.

## Contributing

Pull requests are more than welcome. You must follow the PSR coding standards.

## Security

Please review [our security policy](https://github.com/ozankurt/laravel-security/security/policy) on how to report security vulnerabilities.

## Credits

- [ozankurt/laravel-security](https://github.com/ozankurt/laravel-security)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [LICENSE](LICENSE.md) for more information.
