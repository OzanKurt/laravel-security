<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Integrity;

use InvalidArgumentException;
use OzanKurt\Shield\Services\Integrity\Baseline;
use OzanKurt\Shield\Services\Integrity\BaselineCorruptException;
use OzanKurt\Shield\Services\Integrity\BaselineTamperException;
use OzanKurt\Shield\Tests\TestCase;

class BaselineTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/shield_baseline_' . uniqid();
        $this->path = $this->dir . '/baseline.ndjson.gz';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function sampleManifest(): array
    {
        return [
            'app/Foo.php' => ['sha256' => str_repeat('a', 64), 'size' => 10, 'mtime' => 111, 'kind' => 'hashed', 'target' => null],
            'public/cache/footer.php' => ['sha256' => str_repeat('b', 64), 'size' => 20, 'mtime' => 222, 'kind' => 'hashed', 'target' => null],
            'notes.txt' => ['sha256' => null, 'size' => 99, 'mtime' => 333, 'kind' => 'size_only', 'target' => null],
        ];
    }

    public function test_write_then_read_round_trips_manifest_and_meta(): void
    {
        $baseline = new Baseline('secret-key');
        $manifest = $this->sampleManifest();
        $meta = ['disk' => 'app', 'scope_fingerprint' => 'fp123', 'root_hash' => 'rh456', 'files_total' => 3];

        $baseline->write($this->path, $manifest, $meta);
        $read = $baseline->read($this->path);

        // Baseline canonically sorts by key; compare against the sorted form.
        ksort($manifest);
        $this->assertSame($manifest, $read['manifest']);
        $this->assertSame($meta, $read['meta']);
    }

    public function test_missing_artifact_reads_as_null(): void
    {
        $baseline = new Baseline('secret-key');

        $this->assertNull($baseline->read($this->path));
    }

    public function test_write_is_atomic_and_leaves_no_tmp_files(): void
    {
        (new Baseline('secret-key'))->write($this->path, $this->sampleManifest(), ['disk' => 'app']);

        $this->assertFileExists($this->path);
        $this->assertFileExists($this->path . '.sig');
        $this->assertFileDoesNotExist($this->path . '.tmp');
        $this->assertFileDoesNotExist($this->path . '.sig.tmp');
    }

    public function test_tampered_signature_throws_tamper_exception(): void
    {
        $baseline = new Baseline('secret-key');
        $baseline->write($this->path, $this->sampleManifest(), ['disk' => 'app']);

        // Rewrite the signature: artifact still decompresses and parses, but the
        // HMAC will not verify -> security signal, not corruption.
        file_put_contents($this->path . '.sig', 'deadbeef');

        $this->expectException(BaselineTamperException::class);
        $baseline->read($this->path);
    }

    public function test_a_different_key_fails_verification_as_tamper(): void
    {
        (new Baseline('key-one'))->write($this->path, $this->sampleManifest(), ['disk' => 'app']);

        $this->expectException(BaselineTamperException::class);
        (new Baseline('key-two'))->read($this->path);
    }

    public function test_corrupt_gzip_throws_corrupt_exception(): void
    {
        $baseline = new Baseline('secret-key');
        $baseline->write($this->path, $this->sampleManifest(), ['disk' => 'app']);

        file_put_contents($this->path, 'this is not gzip');

        $this->expectException(BaselineCorruptException::class);
        $baseline->read($this->path);
    }

    public function test_empty_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Baseline('');
    }

    public function test_placeholder_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Baseline(Baseline::PLACEHOLDER_KEY);
    }
}
