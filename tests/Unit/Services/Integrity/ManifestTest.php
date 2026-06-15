<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Integrity;

use OzanKurt\Shield\Services\Integrity\Manifest;
use OzanKurt\Shield\Services\Integrity\ManifestLimitException;
use OzanKurt\Shield\Tests\TestCase;

class ManifestTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir() . '/shield_manifest_' . uniqid();
        @mkdir($this->base . '/app', 0777, true);
        @mkdir($this->base . '/vendor', 0777, true);
        @mkdir($this->base . '/storage/logs', 0777, true);

        file_put_contents($this->base . '/app/Foo.php', "<?php echo 'hello world';");
        file_put_contents($this->base . '/notes.txt', 'hello world long enough');
        file_put_contents($this->base . '/tiny.txt', 'ab');
        file_put_contents($this->base . '/vendor/lib.php', 'excluded');
        file_put_contents($this->base . '/storage/logs/x.log', 'excluded');
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->base);
        parent::tearDown();
    }

    private function manifest(array $overrides = []): Manifest
    {
        return new Manifest(array_merge([
            'roots' => [$this->base],
            'key_base' => $this->base,
            'include' => ['**/*'],
            'exclude' => ['vendor/**', 'storage/**'],
            'follow_symlinks' => false,
            'max_file_size' => 5, // small, so non-script files over 5 bytes go size_only
        ], $overrides));
    }

    public function test_builds_classified_sorted_manifest_respecting_globs(): void
    {
        $m = $this->manifest()->build();

        $this->assertArrayHasKey('app/Foo.php', $m);
        $this->assertArrayHasKey('notes.txt', $m);
        $this->assertArrayHasKey('tiny.txt', $m);
        $this->assertArrayNotHasKey('vendor/lib.php', $m);          // excluded
        $this->assertArrayNotHasKey('storage/logs/x.log', $m);      // excluded

        // .php is always hashed even though it is over max_file_size.
        $this->assertSame('hashed', $m['app/Foo.php']['kind']);
        $this->assertSame(hash_file('sha256', $this->base . '/app/Foo.php'), $m['app/Foo.php']['sha256']);

        // A large non-script file is recorded by size only.
        $this->assertSame('size_only', $m['notes.txt']['kind']);
        $this->assertNull($m['notes.txt']['sha256']);

        // A small file is hashed.
        $this->assertSame('hashed', $m['tiny.txt']['kind']);

        // Sorted by key.
        $this->assertSame(array_keys($m), array_values(array_keys($m)));
        $sorted = array_keys($m);
        $expected = $sorted;
        sort($expected);
        $this->assertSame($expected, $sorted);
    }

    public function test_diff_reports_new_modified_deleted(): void
    {
        $base = $this->manifest()->build();

        // modify a hashed file, add a new one, delete one
        file_put_contents($this->base . '/app/Foo.php', "<?php echo 'changed now';");
        file_put_contents($this->base . '/tiny2.txt', 'cd');
        unlink($this->base . '/tiny.txt');

        $current = $this->manifest()->build();
        $diff = Manifest::diff($base, $current);

        $this->assertArrayHasKey('app/Foo.php', $diff['modified']);
        $this->assertArrayHasKey('tiny2.txt', $diff['new']);
        $this->assertArrayHasKey('tiny.txt', $diff['deleted']);
    }

    public function test_incremental_carries_prior_hash_when_size_and_mtime_match(): void
    {
        $first = $this->manifest()->build();

        // Poison the prior hash; since the file is untouched (same size+mtime),
        // build() must carry the prior hash forward instead of re-hashing.
        $first['app/Foo.php']['sha256'] = 'POISONED';

        $second = $this->manifest()->build($first);

        $this->assertSame('POISONED', $second['app/Foo.php']['sha256']);
    }

    public function test_root_hash_is_stable_and_changes_with_content(): void
    {
        $a = $this->manifest()->build();
        $h1 = Manifest::rootHash($a);
        $h2 = Manifest::rootHash($this->manifest()->build());
        $this->assertSame($h1, $h2);

        file_put_contents($this->base . '/app/Foo.php', "<?php echo 'mutated';");
        $h3 = Manifest::rootHash($this->manifest()->build());
        $this->assertNotSame($h1, $h3);
    }

    public function test_hashes_view_returns_only_hashed_entries(): void
    {
        $hashes = Manifest::hashes($this->manifest()->build());

        $this->assertArrayHasKey('app/Foo.php', $hashes);
        $this->assertArrayHasKey('tiny.txt', $hashes);
        $this->assertArrayNotHasKey('notes.txt', $hashes); // size_only, no hash
    }

    public function test_file_cap_aborts_with_limit_exception(): void
    {
        $this->expectException(ManifestLimitException::class);

        $this->manifest(['max_files' => 1])->build();
    }

    public function test_symlink_recorded_without_following(): void
    {
        $linkOk = @symlink($this->base . '/app/Foo.php', $this->base . '/shortcut.php');
        if ($linkOk === false || ! is_link($this->base . '/shortcut.php')) {
            $this->markTestSkipped('Symlinks not supported in this environment.');
        }

        $m = $this->manifest()->build();

        $this->assertArrayHasKey('shortcut.php', $m);
        $this->assertSame('symlink', $m['shortcut.php']['kind']);
        $this->assertNull($m['shortcut.php']['sha256']);
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
