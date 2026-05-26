<?php

namespace OzanKurt\Shield\Tests\Unit\Services\Scanner;

use Illuminate\Support\Facades\File;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\SignatureCategory;
use OzanKurt\Shield\Models\Signature;
use OzanKurt\Shield\Services\Scanner\Backends\NativeBackend;
use OzanKurt\Shield\Tests\TestCase;

class NativeBackendTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/shield_scan_test_' . uniqid();
        File::makeDirectory($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        File::deleteDirectory($this->tmpDir);
    }

    public function test_name_returns_native(): void
    {
        $backend = new NativeBackend();
        $this->assertSame('native', $backend->name());
    }

    public function test_is_available_returns_true(): void
    {
        $backend = new NativeBackend();
        $this->assertTrue($backend->isAvailable());
    }

    public function test_is_per_file_returns_true(): void
    {
        $backend = new NativeBackend();
        $this->assertTrue($backend->isPerFile());
    }

    public function test_scan_run_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        (new NativeBackend())->scanRun();
    }

    public function test_scan_file_returns_empty_for_nonexistent_file(): void
    {
        $backend = new NativeBackend();
        $result = $backend->scanFile('/nonexistent/path/file.php');
        $this->assertSame([], $result);
    }

    public function test_scan_file_detects_regex_signature(): void
    {
        $categoryId = SignatureCategory::updateOrCreate(
            ['name' => 'webshell'],
            ['label' => 'Web Shell', 'sort_order' => 30]
        )->id;

        $severityId = LogLevel::where('name', 'high')->value('id');

        Signature::create([
            'source' => 'builtin_native',
            'source_ref' => 'TEST-001',
            'name' => 'base64 decode obfuscation',
            'category_id' => $categoryId,
            'kind' => 'regex',
            'pattern' => '/base64_decode\s*\(/i',
            'severity_id' => $severityId,
            'is_enabled' => true,
        ]);

        $filePath = $this->tmpDir . '/evil.php';
        file_put_contents($filePath, '<?php $x = base64_decode("dGVzdA==");');

        $backend = new NativeBackend();
        $findings = $backend->scanFile($filePath);

        $this->assertNotEmpty($findings);
        $this->assertSame('high', $findings[0]['severity']);
        $this->assertNotNull($findings[0]['signature_id']);
    }

    public function test_scan_file_no_match_returns_empty(): void
    {
        $categoryId = SignatureCategory::updateOrCreate(
            ['name' => 'webshell'],
            ['label' => 'Web Shell', 'sort_order' => 30]
        )->id;

        $severityId = LogLevel::where('name', 'medium')->value('id');

        Signature::create([
            'source' => 'builtin_native',
            'source_ref' => 'TEST-002',
            'name' => 'c99 shell',
            'category_id' => $categoryId,
            'kind' => 'regex',
            'pattern' => '/c99\s*shell/i',
            'severity_id' => $severityId,
            'is_enabled' => true,
        ]);

        $filePath = $this->tmpDir . '/clean.php';
        file_put_contents($filePath, '<?php echo "Hello World";');

        $backend = new NativeBackend();
        $findings = $backend->scanFile($filePath);

        $this->assertSame([], $findings);
    }

    public function test_scan_file_skips_file_over_size_limit(): void
    {
        config(['shield.scanner.native.max_file_bytes' => 10]);

        $filePath = $this->tmpDir . '/big.php';
        file_put_contents($filePath, str_repeat('a', 100));

        $backend = new NativeBackend();
        $findings = $backend->scanFile($filePath);

        $this->assertSame([], $findings);
    }

    public function test_scan_file_detects_string_match_signature(): void
    {
        $categoryId = SignatureCategory::updateOrCreate(
            ['name' => 'webshell'],
            ['label' => 'Web Shell', 'sort_order' => 30]
        )->id;

        $severityId = LogLevel::where('name', 'critical')->value('id');

        Signature::create([
            'source' => 'builtin_native',
            'source_ref' => 'TEST-003',
            'name' => 'wso shell marker',
            'category_id' => $categoryId,
            'kind' => 'string_match',
            'pattern' => 'WSO Shell',
            'severity_id' => $severityId,
            'is_enabled' => true,
        ]);

        $filePath = $this->tmpDir . '/shell.php';
        file_put_contents($filePath, '<?php /* WSO Shell v2.0 */');

        $backend = new NativeBackend();
        $findings = $backend->scanFile($filePath);

        $this->assertNotEmpty($findings);
        $this->assertSame('critical', $findings[0]['severity']);
    }

    public function test_scan_file_detects_file_hash_signature(): void
    {
        $categoryId = SignatureCategory::updateOrCreate(
            ['name' => 'malware'],
            ['label' => 'Malware', 'sort_order' => 10]
        )->id;

        $severityId = LogLevel::where('name', 'critical')->value('id');

        $content = '<?php /* known malware */';
        $hash = hash('sha256', $content);

        Signature::create([
            'source' => 'builtin_native',
            'source_ref' => 'TEST-004',
            'name' => 'known malware hash',
            'category_id' => $categoryId,
            'kind' => 'file_hash',
            'pattern' => $hash,
            'severity_id' => $severityId,
            'is_enabled' => true,
        ]);

        $filePath = $this->tmpDir . '/malware.php';
        file_put_contents($filePath, $content);

        $backend = new NativeBackend();
        $findings = $backend->scanFile($filePath);

        $this->assertNotEmpty($findings);
    }

    public function test_disabled_signatures_are_not_matched(): void
    {
        $categoryId = SignatureCategory::updateOrCreate(
            ['name' => 'webshell'],
            ['label' => 'Web Shell', 'sort_order' => 30]
        )->id;

        $severityId = LogLevel::where('name', 'high')->value('id');

        Signature::create([
            'source' => 'builtin_native',
            'source_ref' => 'TEST-005',
            'name' => 'disabled sig',
            'category_id' => $categoryId,
            'kind' => 'string_match',
            'pattern' => 'EVIL_PATTERN',
            'severity_id' => $severityId,
            'is_enabled' => false,
        ]);

        $filePath = $this->tmpDir . '/target.php';
        file_put_contents($filePath, '<?php /* EVIL_PATTERN */');

        $backend = new NativeBackend();
        $findings = $backend->scanFile($filePath);

        $this->assertSame([], $findings);
    }
}
