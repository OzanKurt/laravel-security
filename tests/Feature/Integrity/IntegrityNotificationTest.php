<?php

namespace OzanKurt\Shield\Tests\Feature\Integrity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use OzanKurt\Shield\Models\IntegrityRun;
use OzanKurt\Shield\Notifications\IntegrityScanCompletedNotification;
use OzanKurt\Shield\Notifications\Notifiable;
use OzanKurt\Shield\Services\Integrity\IntegrityScanner;
use OzanKurt\Shield\Tests\TestCase;

class IntegrityNotificationTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(); // never hit a real webhook

        $this->src = sys_get_temp_dir() . '/shield_notif_src_' . uniqid();
        @mkdir($this->src . '/app', 0777, true);
        file_put_contents($this->src . '/app/Service.php', '<?php // v1');

        config([
            'shield.integrity.baseline.hmac_key' => 'test-key',
            'shield.integrity.disks.test' => [
                'roots' => [$this->src],
                'key_base' => $this->src,
                'include' => ['**/*'],
                'exclude' => [],
                'follow_symlinks' => false,
                'max_file_size' => 50 * 1024 * 1024,
            ],
            // Channel wired, event toggle off by default so baseline setup runs are quiet.
            'shield.notification_channels.discord.enabled' => true,
            'shield.notification_channels.discord.webhook_url' => 'https://discord.com/api/webhooks/1/abc',
            'shield.notifications.integrity_changed.enabled' => false,
        ]);

        $this->cleanArtifacts();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->src);
        $this->cleanArtifacts();
        parent::tearDown();
    }

    private function scanner(): IntegrityScanner
    {
        return new IntegrityScanner();
    }

    public function test_notification_is_sent_when_there_are_changes(): void
    {
        $this->scanner()->run('test', 'manual'); // baseline (event toggle off)

        Notification::fake();
        config(['shield.notifications.integrity_changed.enabled' => true]);

        file_put_contents($this->src . '/app/New.php', '<?php // new');
        $this->scanner()->run('test', 'scheduled');

        Notification::assertSentTo(
            new Notifiable(),
            IntegrityScanCompletedNotification::class,
            fn ($n) => (int) $n->run->count_new === 1
        );
    }

    public function test_notification_is_suppressed_when_no_changes(): void
    {
        $this->scanner()->run('test', 'manual'); // baseline

        Notification::fake();
        config(['shield.notifications.integrity_changed.enabled' => true]);

        $this->scanner()->run('test', 'scheduled'); // nothing changed -> delta 0

        Notification::assertNothingSent();
    }

    public function test_baseline_established_notifies_even_with_zero_delta(): void
    {
        Notification::fake();
        config(['shield.notifications.integrity_changed.enabled' => true]);

        $this->scanner()->run('test', 'manual'); // first run, security event

        Notification::assertSentTo(new Notifiable(), IntegrityScanCompletedNotification::class);
    }

    public function test_discord_card_renders_summary_and_severity_colour(): void
    {
        $this->scanner()->run('test', 'manual'); // baseline
        file_put_contents($this->src . '/app/New.php', '<?php // new');
        $run = $this->scanner()->run('test', 'manual');

        $embed = (new IntegrityScanCompletedNotification(IntegrityRun::find($run->id)))
            ->toDiscord()
            ->toArray()['embeds'][0];

        $this->assertStringContainsString('File integrity scan', $embed['title']);
        $this->assertStringContainsString('1 new', $embed['description']);
        $this->assertCount(3, $embed['fields']); // New / Modified / Deleted
        $this->assertIsInt($embed['color']);
    }

    private function cleanArtifacts(): void
    {
        $this->deleteTree(storage_path('shield/integrity/test'));
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
