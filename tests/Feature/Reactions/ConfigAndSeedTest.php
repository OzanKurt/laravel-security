<?php

namespace OzanKurt\Shield\Tests\Feature\Reactions;

use OzanKurt\Shield\Models\Lookups\AuditLogKind;
use OzanKurt\Shield\Services\Lookups\LookupResolver;
use OzanKurt\Shield\Tests\TestCase;

class ConfigAndSeedTest extends TestCase
{
    public function testReactionConfigDefaultsExist()
    {
        $this->assertIsArray(config('shield.reactions.self_detected_sources'));
        $this->assertContains('honeypot', config('shield.reactions.self_detected_sources'));
        $this->assertFalse(config('shield.reactions.cloudflare.enabled'));
        $this->assertFalse(config('shield.reactions.abuseipdb_report.enabled'));
        $this->assertIsArray(config('shield.honeypot.regex_paths'));
        $this->assertSame('redirect_back', config('shield.honeypot.form.response'));
    }

    public function testNewAuditKindsSeeded()
    {
        $resolver = app(LookupResolver::class);
        $this->assertNotNull($resolver->id(AuditLogKind::class, 'honeypot.form_trap'));
        $this->assertNotNull($resolver->id(AuditLogKind::class, 'reaction.cloudflare'));
        $this->assertNotNull($resolver->id(AuditLogKind::class, 'reaction.abuseipdb'));
    }
}
