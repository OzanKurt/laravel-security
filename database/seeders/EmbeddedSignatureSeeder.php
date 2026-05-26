<?php

namespace OzanKurt\Shield\Database\Seeders;

use Illuminate\Database\Seeder;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\SignatureCategory;
use OzanKurt\Shield\Models\Signature;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class EmbeddedSignatureSeeder extends Seeder
{
    public function run(): void
    {
        $lookups = app(LookupResolver::class);

        foreach ($this->signatures() as $sig) {
            $payload = [
                'name' => $sig['name'],
                'description' => $sig['description'] ?? null,
                'category_id' => $lookups->id(SignatureCategory::class, $sig['category']),
                'kind' => $sig['kind'] ?? 'regex',
                'pattern' => $sig['pattern'],
                'severity_id' => $lookups->id(LogLevel::class, $sig['severity'] ?? 'high'),
                'is_enabled' => true,
                'version' => 1,
            ];

            Signature::updateOrCreate(
                ['source' => 'builtin_native', 'source_ref' => $sig['ref']],
                $payload,
            );
        }
    }

    /** @return array<int, array{ref:string, name:string, category:string, kind?:string, pattern:string, severity?:string, description?:string}> */
    private function signatures(): array
    {
        return [
            // Eval / obfuscation
            ['ref' => 'eval.base64_decode',           'name' => 'eval(base64_decode())',           'category' => 'backdoor', 'pattern' => '/\beval\s*\(\s*base64_decode\s*\(/i', 'severity' => 'critical'],
            ['ref' => 'eval.gzinflate_base64',        'name' => 'eval(gzinflate(base64_decode()))', 'category' => 'backdoor', 'pattern' => '/\beval\s*\(\s*gzinflate\s*\(\s*base64_decode\s*\(/i', 'severity' => 'critical'],
            ['ref' => 'eval.str_rot13',               'name' => 'eval(str_rot13())',                'category' => 'backdoor', 'pattern' => '/\beval\s*\(\s*str_rot13\s*\(/i', 'severity' => 'critical'],
            ['ref' => 'eval.gzuncompress',            'name' => 'eval(gzuncompress())',             'category' => 'backdoor', 'pattern' => '/\beval\s*\(\s*gzuncompress\s*\(/i', 'severity' => 'critical'],
            ['ref' => 'eval.gzdecode',                'name' => 'eval(gzdecode())',                 'category' => 'backdoor', 'pattern' => '/\beval\s*\(\s*gzdecode\s*\(/i', 'severity' => 'critical'],
            ['ref' => 'eval.user_input',              'name' => 'eval on superglobal',              'category' => 'backdoor', 'pattern' => '/\beval\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i', 'severity' => 'critical'],

            // Assert as code-exec
            ['ref' => 'assert.user_input',            'name' => 'assert() on user input',           'category' => 'backdoor', 'pattern' => '/\bassert\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i', 'severity' => 'critical'],
            ['ref' => 'assert.base64',                'name' => 'assert(base64_decode())',          'category' => 'backdoor', 'pattern' => '/\bassert\s*\(\s*base64_decode\s*\(/i', 'severity' => 'critical'],

            // create_function (deprecated, classic backdoor)
            ['ref' => 'create_function.user_input',   'name' => 'create_function on user input',    'category' => 'backdoor', 'pattern' => '/\bcreate_function\s*\([^)]*\$_(GET|POST|REQUEST|COOKIE)/i', 'severity' => 'critical'],

            // preg_replace /e modifier (PHP 7+ removed but still in old shells)
            ['ref' => 'preg_replace.e_modifier',      'name' => 'preg_replace with /e modifier',    'category' => 'backdoor', 'pattern' => '/\bpreg_replace\s*\(\s*["\'][^"\']*\/[a-z]*e[a-z]*["\']/i', 'severity' => 'critical'],

            // Shell-exec wrappers on user input
            ['ref' => 'system.user_input',            'name' => 'system() on user input',           'category' => 'backdoor', 'pattern' => '/\bsystem\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i', 'severity' => 'critical'],
            ['ref' => 'exec.user_input',              'name' => 'exec() on user input',             'category' => 'backdoor', 'pattern' => '/\bexec\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i', 'severity' => 'critical'],
            ['ref' => 'passthru.user_input',          'name' => 'passthru() on user input',         'category' => 'backdoor', 'pattern' => '/\bpassthru\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i', 'severity' => 'critical'],
            ['ref' => 'shell_exec.user_input',        'name' => 'shell_exec() on user input',       'category' => 'backdoor', 'pattern' => '/\bshell_exec\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i', 'severity' => 'critical'],
            ['ref' => 'popen.user_input',             'name' => 'popen() on user input',            'category' => 'backdoor', 'pattern' => '/\bpopen\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i', 'severity' => 'critical'],
            ['ref' => 'proc_open.user_input',         'name' => 'proc_open() on user input',        'category' => 'backdoor', 'pattern' => '/\bproc_open\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i', 'severity' => 'critical'],
            ['ref' => 'backticks.user_input',         'name' => 'backtick-exec on user input',      'category' => 'backdoor', 'pattern' => '/`\s*\$_(GET|POST|REQUEST|COOKIE)/', 'severity' => 'critical'],

            // Known shell signatures
            ['ref' => 'shell.c99',                    'name' => 'c99 webshell',                     'category' => 'webshell', 'pattern' => '/c99shell|c99\s*v\d\./i', 'severity' => 'critical'],
            ['ref' => 'shell.r57',                    'name' => 'r57 webshell',                     'category' => 'webshell', 'pattern' => '/r57shell|R57\s+Shell/i', 'severity' => 'critical'],
            ['ref' => 'shell.wso',                    'name' => 'WSO webshell',                     'category' => 'webshell', 'pattern' => '/WSO\s+\d+\.\d+/', 'severity' => 'critical'],
            ['ref' => 'shell.b374k',                  'name' => 'b374k webshell',                   'category' => 'webshell', 'pattern' => '/b374k/i', 'severity' => 'critical'],
            ['ref' => 'shell.mini',                   'name' => 'mini-shell',                       'category' => 'webshell', 'pattern' => '/mini\s+shell\s+by/i', 'severity' => 'critical'],
            ['ref' => 'shell.alfa',                   'name' => 'AlfaShell',                        'category' => 'webshell', 'pattern' => '/AlfaTeam|AlfaShell/', 'severity' => 'critical'],
            ['ref' => 'shell.p0wny',                  'name' => 'p0wny shell',                      'category' => 'webshell', 'pattern' => '/p0wny\b/i', 'severity' => 'critical'],
            ['ref' => 'shell.indoxploit',             'name' => 'IndoXploit shell',                 'category' => 'webshell', 'pattern' => '/IndoXploit/i', 'severity' => 'critical'],

            // Suspicious file ops
            ['ref' => 'file.fputs_chr_chain',         'name' => 'fputs/file_put_contents with chr() chain', 'category' => 'heuristic', 'pattern' => '/(?:fputs|file_put_contents)\s*\([^)]*chr\s*\(\s*\d+\s*\)\s*\.\s*chr/i', 'severity' => 'high'],
            ['ref' => 'file.move_uploaded.exec',      'name' => 'move_uploaded_file to .php',       'category' => 'heuristic', 'pattern' => '/move_uploaded_file\s*\([^)]*,\s*[^)]*\.(?:php|phtml|phar)["\']/i', 'severity' => 'high'],

            // Long base64 blobs (compressed shell common pattern)
            ['ref' => 'heuristic.long_base64',        'name' => 'Long base64-encoded blob in PHP',  'category' => 'heuristic', 'pattern' => '/[\'"][A-Za-z0-9+\/]{500,}={0,2}[\'"]/', 'severity' => 'medium'],

            // FilesMan signature
            ['ref' => 'shell.filesman',               'name' => 'FilesMan / FilesMan shell',        'category' => 'webshell', 'pattern' => '/FilesMan|filesMan/', 'severity' => 'critical'],

            // Phishing scaffolding
            ['ref' => 'phish.fake_login_form',        'name' => 'Phishing fake login form sender',  'category' => 'phishing', 'pattern' => '/mail\s*\(\s*[^,]*,\s*["\']Result\s+:\s*Login/i', 'severity' => 'high'],
            ['ref' => 'phish.cc_grab',                'name' => 'Credit card harvest pattern',      'category' => 'phishing', 'pattern' => '/(?:cc_num|card_num|ccnumber|cardnumber)\s*=\s*\$_(GET|POST|REQUEST)/i', 'severity' => 'high'],

            // Obfuscated callbacks
            ['ref' => 'obfuscation.array_map_eval',   'name' => 'array_map("assert"/"eval", ...)',  'category' => 'backdoor', 'pattern' => '/\barray_map\s*\(\s*["\'](?:assert|eval)["\']/i', 'severity' => 'critical'],
            ['ref' => 'obfuscation.call_user_func',   'name' => 'call_user_func("assert"/"eval", $_*)',  'category' => 'backdoor', 'pattern' => '/\bcall_user_func\s*\(\s*["\'](?:assert|eval)["\']\s*,\s*\$_(GET|POST|REQUEST|COOKIE)/i', 'severity' => 'critical'],

            // include with remote URL
            ['ref' => 'rfi.include_http',             'name' => 'include() with http:// URL',       'category' => 'backdoor', 'pattern' => '/\b(?:include|require)(?:_once)?\s*\(\s*["\']https?:\/\//i', 'severity' => 'high'],
        ];
    }
}
