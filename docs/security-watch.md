# `shield:watch` — Continuous File Change Monitor

`shield:watch` runs as a long-running process that detects file changes in real time (when `spatie/file-system-watcher` is installed) or via a polling loop (fallback for shared hosts without Node.js / chokidar).

Each change becomes:
- An audit log entry of kind `file.drift`
- A `FileChangeDetectedEvent` you can listen to for custom workflows (e.g. trigger a focused scan)

## Quick start

```bash
# Enable + configure
echo "LS_WATCH_ENABLED=true" >> .env

# In config/shield.php (after publish):
#   'scanner' => [
#       'watch' => [
#           'enabled' => env('LS_WATCH_ENABLED', false),
#           'paths' => ['app/', 'config/', 'routes/', '.env'],
#           'poll_interval_ms' => 3000,
#       ],
#   ],

# Optional — install spatie/file-system-watcher for chokidar-backed mode
composer require spatie/file-system-watcher
npm install chokidar

# Run in the foreground
php artisan shield:watch
```

## Production: supervisor

`/etc/supervisor/conf.d/shield-watch.conf`:

```ini
[program:shield-watch]
process_name=%(program_name)s
command=php /var/www/your-app/artisan shield:watch
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/shield-watch.log
stopwaitsecs=5
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start shield-watch
```

## Production: systemd

`/etc/systemd/system/shield-watch.service`:

```ini
[Unit]
Description=Laravel Shield file change watcher
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/your-app
ExecStart=/usr/bin/php artisan shield:watch
Restart=always
RestartSec=5
StandardOutput=append:/var/log/shield-watch.log
StandardError=inherit

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now shield-watch.service
sudo journalctl -u shield-watch.service -f
```

## Polling fallback

When `spatie/file-system-watcher` is not installed (or `--once` is used), `shield:watch` falls back to a polling loop:

- Bootstraps a baseline of SHA-256 hashes for every file under the configured paths
- Re-scans every `poll_interval_ms` ms, comparing hashes
- Emits events on create/update/delete

Polling is fine for most apps but uses more CPU than the chokidar-backed watcher. If you watch `vendor/` or any large directory, install spatie for real-time events.

## Listening to events

```php
use OzanKurt\Shield\Events\FileChangeDetectedEvent;

Event::listen(FileChangeDetectedEvent::class, function (FileChangeDetectedEvent $event) {
    // $event->path, $event->changeType ('created' | 'updated' | 'deleted'),
    // $event->hashBefore, $event->hashAfter

    if ($event->changeType !== 'deleted' && str_ends_with($event->path, '.php')) {
        // Trigger a focused scan on the changed PHP file
        Artisan::call('shield:scan', ['--target' => 'app_files']);
    }
});
```

## Testing

Use the `--once` flag to run a single polling pass and exit:

```bash
php artisan shield:watch --once
```

This is useful in CI to verify the baseline + change-detection flow without leaving the command running.
