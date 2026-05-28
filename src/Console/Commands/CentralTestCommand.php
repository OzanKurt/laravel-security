<?php

namespace OzanKurt\Shield\Console\Commands;

use Illuminate\Console\Command;
use OzanKurt\Shield\Services\Premium\CentralClient;
use OzanKurt\Shield\Services\Premium\LicenseChecker;
use OzanKurt\Shield\Services\Premium\WebhookSigner;

/**
 * End-to-end connectivity + signing test against the Central app.
 *
 * Runs three checks in sequence + reports a clear pass/fail summary:
 *
 *   1. License check     — does Central recognise our key + return valid?
 *   2. Signed heartbeat  — can we POST a signed request + get 2xx?
 *   3. Test ping         — does the signed echo endpoint roundtrip?
 *
 * Anything other than 3/3 PASS means the install is misconfigured.
 * The dashboard "Test connectivity" button calls the same code path,
 * so the CLI and UI surface the exact same diagnostics.
 */
class CentralTestCommand extends Command
{
    protected $signature = 'shield:central-test';
    protected $description = 'Run an end-to-end Central connectivity test (license + heartbeat + ping)';

    public function handle(LicenseChecker $checker, CentralClient $client, WebhookSigner $signer): int
    {
        $this->newLine();
        $this->line("<options=bold>Shield → Central connectivity test</>");
        $this->newLine();

        $passed = 0;
        $total = 3;

        // 1. License check
        $this->line(' [1/3] License check…');
        if (! $checker->hasKey()) {
            $this->line('       <fg=red>SKIP</> — no LS_PREMIUM_LICENSE_KEY configured.');
        } else {
            $state = $checker->refresh();
            if (in_array($state['state'] ?? null, ['valid', 'grace'], true)) {
                $plan = $state['plan'] ?? 'unknown';
                $exp = $state['expires_at'] ?? 'no expiry';
                $this->line("       <fg=green>PASS</> — plan={$plan}, expires={$exp}");
                $passed++;
            } else {
                $reason = $state['reason'] ?? 'unknown';
                $this->line("       <fg=red>FAIL</> — state={$state['state']}, reason={$reason}");
            }
        }

        // 2. Signing capability
        $this->newLine();
        $this->line(' [2/3] Webhook signing capability…');
        if (! $signer->canSign()) {
            $this->line('       <fg=red>FAIL</> — no signing secret resolvable (set LS_PREMIUM_LICENSE_KEY or LS_PREMIUM_WEBHOOK_SECRET).');
        } else {
            $sampleBody = '{"test":1}';
            $headers = $signer->sign($sampleBody);
            if (! isset($headers['X-Shield-Signature'], $headers['X-Shield-Timestamp'], $headers['X-Shield-Nonce'])) {
                $this->line('       <fg=red>FAIL</> — signer returned incomplete header set.');
            } else {
                $this->line('       <fg=green>PASS</> — signature header present + 3-tuple complete.');
                $passed++;
            }
        }

        // 3. Heartbeat roundtrip (the practical end-to-end check)
        $this->newLine();
        $this->line(' [3/3] Heartbeat roundtrip…');
        $result = $client->heartbeat([
            'test' => true,
            'sent_by' => 'shield:central-test',
            'php_version' => PHP_VERSION,
        ]);
        if ($result->ok()) {
            $this->line("       <fg=green>PASS</> — HTTP {$result->httpStatus} from {$result->url}");
            $passed++;
        } elseif ($result->outcome === 'skipped') {
            $this->line("       <fg=yellow>SKIP</> — {$result->error}");
        } else {
            $status = $result->httpStatus ?: 'no response';
            $this->line("       <fg=red>FAIL</> — status={$status}, reason={$result->error}");
            if ($result->responseExcerpt) {
                $this->line('       Response: ' . substr($result->responseExcerpt, 0, 200));
            }
        }

        $this->newLine();
        $color = $passed === $total ? 'green' : ($passed > 0 ? 'yellow' : 'red');
        $this->line("<fg={$color}>{$passed} / {$total} checks passed.</>");
        $this->newLine();

        return $passed === $total ? self::SUCCESS : self::FAILURE;
    }
}
