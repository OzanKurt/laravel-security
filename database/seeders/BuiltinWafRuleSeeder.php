<?php

namespace OzanKurt\Shield\Database\Seeders;

use Illuminate\Database\Seeder;
use OzanKurt\Shield\Models\Lookups\LogLevel;
use OzanKurt\Shield\Models\Lookups\WafRuleAction;
use OzanKurt\Shield\Models\Lookups\WafRuleCategory;
use OzanKurt\Shield\Models\Lookups\WafRuleKind;
use OzanKurt\Shield\Models\Lookups\WafRuleTarget;
use OzanKurt\Shield\Models\WafRule;
use OzanKurt\Shield\Services\Lookups\LookupResolver;

class BuiltinWafRuleSeeder extends Seeder
{
    public function run(): void
    {
        $lookups = app(LookupResolver::class);

        $rules = $this->ruleDefinitions();

        foreach ($rules as $rule) {
            WafRule::updateOrCreate(
                ['source' => 'builtin', 'source_ref' => $rule['ref']],
                [
                    'name' => $rule['name'],
                    'description' => $rule['description'] ?? null,
                    'category_id' => $lookups->id(WafRuleCategory::class, $rule['category']),
                    'kind_id' => $lookups->id(WafRuleKind::class, $rule['kind'] ?? 'regex'),
                    'target_id' => $lookups->id(WafRuleTarget::class, $rule['target'] ?? 'request_input'),
                    'pattern' => $rule['pattern'],
                    'action_id' => $lookups->id(WafRuleAction::class, $rule['action'] ?? 'block'),
                    'severity_id' => $lookups->id(LogLevel::class, $rule['severity'] ?? 'medium'),
                    'score' => $rule['score'] ?? 10,
                    'is_enabled' => true,
                    'version' => 1,
                ],
            );
        }
    }

    private function ruleDefinitions(): array
    {
        return [
            // XSS — extracted from old config/security.php
            ['ref' => 'xss.evil_attributes',         'category' => 'xss',  'name' => 'Evil starting attributes',
             'pattern' => '#(<[^>]+[\x00-\x20\"\'\/])(form|formaction|on\w*|style|xmlns|xlink:href)[^>]*>?#iUu', 'severity' => 'high'],
            ['ref' => 'xss.protocols',               'category' => 'xss',  'name' => 'javascript:/livescript:/vbscript:/mocha:/feed:/data: protocols',
             'pattern' => '!((java|live|vb)script|mocha|feed|data):(\w)*!iUu', 'severity' => 'high'],
            ['ref' => 'xss.moz_binding',             'category' => 'xss',  'name' => '-moz-binding CSS',
             'pattern' => '#-moz-binding[\x00-\x20]*:#u', 'severity' => 'medium'],
            ['ref' => 'xss.unneeded_tags',           'category' => 'xss',  'name' => 'Unneeded HTML tags',
             'pattern' => '#</*(applet|meta|xml|blink|link|style|script|embed|object|iframe|frame|frameset|ilayer|layer|bgsound|title|base|img)[^>]*>?#i', 'severity' => 'high'],

            // SQLi
            ['ref' => 'sqli.union',                  'category' => 'sqli', 'name' => 'UNION SELECT variants',
             'pattern' => '#[\d\W](union select|union join|union distinct)[\d\W]#is', 'severity' => 'critical'],
            ['ref' => 'sqli.keywords',               'category' => 'sqli', 'name' => 'SQL keyword tokens',
             'pattern' => '#[\d\W](union|union select|insert|from|where|concat|into|cast|truncate|select|delete|having)[\d\W]#is', 'severity' => 'high'],

            // LFI
            ['ref' => 'lfi.dotdot_slash',            'category' => 'lfi',  'name' => '../ path traversal',
             'pattern' => '#\.\.\/#is', 'severity' => 'high'],

            // RFI
            ['ref' => 'rfi.http_url_with_file',      'category' => 'rfi',  'name' => 'HTTP URL referencing a file',
             'pattern' => '#(http|https){1,1}://.*\..{2,4}/.*\..{2,4}#i', 'severity' => 'high'],
            ['ref' => 'rfi.ftp',                     'category' => 'rfi',  'name' => 'FTP/SFTP/FTPS protocol',
             'pattern' => '#(ftp|sftp|ftps){1,1}://.*#i', 'severity' => 'medium'],

            // PHP wrapper protocols
            ['ref' => 'php.bzip2',                   'category' => 'php_protocols', 'name' => 'bzip2://',  'pattern' => 'bzip2://', 'kind' => 'header_match', 'severity' => 'critical'],
            ['ref' => 'php.expect',                  'category' => 'php_protocols', 'name' => 'expect://', 'pattern' => 'expect://', 'kind' => 'header_match', 'severity' => 'critical'],
            ['ref' => 'php.glob',                    'category' => 'php_protocols', 'name' => 'glob://',   'pattern' => 'glob://',   'kind' => 'header_match', 'severity' => 'high'],
            ['ref' => 'php.phar',                    'category' => 'php_protocols', 'name' => 'phar://',   'pattern' => 'phar://',   'kind' => 'header_match', 'severity' => 'critical'],
            ['ref' => 'php.php',                     'category' => 'php_protocols', 'name' => 'php://',    'pattern' => 'php://',    'kind' => 'header_match', 'severity' => 'critical'],
            ['ref' => 'php.ogg',                     'category' => 'php_protocols', 'name' => 'ogg://',    'pattern' => 'ogg://',    'kind' => 'header_match', 'severity' => 'medium'],
            ['ref' => 'php.rar',                     'category' => 'php_protocols', 'name' => 'rar://',    'pattern' => 'rar://',    'kind' => 'header_match', 'severity' => 'high'],
            ['ref' => 'php.ssh2',                    'category' => 'php_protocols', 'name' => 'ssh2://',   'pattern' => 'ssh2://',   'kind' => 'header_match', 'severity' => 'critical'],
            ['ref' => 'php.zip',                     'category' => 'php_protocols', 'name' => 'zip://',    'pattern' => 'zip://',    'kind' => 'header_match', 'severity' => 'high'],
            ['ref' => 'php.zlib',                    'category' => 'php_protocols', 'name' => 'zlib://',   'pattern' => 'zlib://',   'kind' => 'header_match', 'severity' => 'medium'],

            // Session deserialization
            ['ref' => 'session.object_signature',    'category' => 'session', 'name' => 'PHP serialized object',
             'pattern' => '@[\|:]O:\d{1,}:"[\w_][\w\d_]{0,}":\d{1,}:{@i', 'severity' => 'high'],
            ['ref' => 'session.array_signature',     'category' => 'session', 'name' => 'PHP serialized array',
             'pattern' => '@[\|:]a:\d{1,}:{@i', 'severity' => 'medium'],

            // Keyword (path-based)
            ['ref' => 'kw.etc_slash',                'category' => 'keyword', 'name' => '/etc/ in path',  'pattern' => '#/etc/#i', 'target' => 'request_path', 'severity' => 'high'],
            ['ref' => 'kw.bak',                      'category' => 'keyword', 'name' => '.bak files',     'pattern' => '#\.bak#i', 'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.db',                       'category' => 'keyword', 'name' => '.db files',      'pattern' => '#\.db#i',  'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.env',                      'category' => 'keyword', 'name' => '.env file',      'pattern' => '#\.env#i', 'target' => 'request_path', 'severity' => 'critical'],
            ['ref' => 'kw.git',                      'category' => 'keyword', 'name' => '.git directory', 'pattern' => '#\.git#i', 'target' => 'request_path', 'severity' => 'high'],
            ['ref' => 'kw.log',                      'category' => 'keyword', 'name' => '.log files',     'pattern' => '#\.log#i', 'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.production',               'category' => 'keyword', 'name' => '.production',    'pattern' => '#\.production#i', 'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.remote',                   'category' => 'keyword', 'name' => '.remote',        'pattern' => '#\.remote#i',     'target' => 'request_path', 'severity' => 'low'],
            ['ref' => 'kw.sh',                       'category' => 'keyword', 'name' => '.sh files',      'pattern' => '#\.sh#i',         'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.sql',                      'category' => 'keyword', 'name' => '.sql files',     'pattern' => '#\.sql#i',        'target' => 'request_path', 'severity' => 'high'],
            ['ref' => 'kw.temp',                     'category' => 'keyword', 'name' => '.temp',          'pattern' => '#\.temp#i',       'target' => 'request_path', 'severity' => 'low'],
            ['ref' => 'kw.tmp',                      'category' => 'keyword', 'name' => '.tmp',           'pattern' => '#\.tmp#i',        'target' => 'request_path', 'severity' => 'low'],
            ['ref' => 'kw.cgi',                      'category' => 'keyword', 'name' => 'cgi paths',      'pattern' => '#cgi#i',          'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.etc_passwd',               'category' => 'keyword', 'name' => 'etc/passwd',     'pattern' => '#etc/passwd#i',   'target' => 'request_path', 'severity' => 'critical'],
            ['ref' => 'kw.license_md',               'category' => 'keyword', 'name' => 'license.md',     'pattern' => '#license\.md#i',  'target' => 'request_path', 'severity' => 'low'],
            ['ref' => 'kw.license_txt',              'category' => 'keyword', 'name' => 'license.txt',    'pattern' => '#license\.txt#i', 'target' => 'request_path', 'severity' => 'low'],
            ['ref' => 'kw.logs_dir',                 'category' => 'keyword', 'name' => 'logs/ dir',      'pattern' => '#logs/#i',        'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.logs_dot',                 'category' => 'keyword', 'name' => 'logs.',          'pattern' => '#logs\.#i',       'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.phpinfo',                  'category' => 'keyword', 'name' => 'phpinfo',        'pattern' => '#phpinfo#i',      'target' => 'request_path', 'severity' => 'high'],
            ['ref' => 'kw.readme_html',              'category' => 'keyword', 'name' => 'readme.html',    'pattern' => '#readme\.html#i', 'target' => 'request_path', 'severity' => 'low'],
            ['ref' => 'kw.readme_txt',               'category' => 'keyword', 'name' => 'readme.txt',     'pattern' => '#readme\.txt#i',  'target' => 'request_path', 'severity' => 'low'],
            ['ref' => 'kw.wlw_manifest',             'category' => 'keyword', 'name' => 'wlwmanifest',    'pattern' => '#wlwmanifest\.xml#i', 'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.wp_admin',                 'category' => 'keyword', 'name' => 'wp-admin probe', 'pattern' => '#wp-admin#i',     'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.wp_config',                'category' => 'keyword', 'name' => 'wp-config probe','pattern' => '#wp-config#i',    'target' => 'request_path', 'severity' => 'high'],
            ['ref' => 'kw.wp_content',               'category' => 'keyword', 'name' => 'wp-content',     'pattern' => '#wp-content#i',   'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.wp_includes',              'category' => 'keyword', 'name' => 'wp-includes',    'pattern' => '#wp-includes#i',  'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.xmlrpc',                   'category' => 'keyword', 'name' => 'xmlrpc.php',     'pattern' => '#xmlrpc#i',       'target' => 'request_path', 'severity' => 'medium'],
            ['ref' => 'kw.tilde',                    'category' => 'keyword', 'name' => 'tilde (~) in path','pattern' => '#~#i',          'target' => 'request_path', 'severity' => 'low'],
        ];
    }
}
