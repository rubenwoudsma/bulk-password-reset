<?php
/**
 * Test-only WP-CLI preload harness for subprocess integration tests.
 *
 * Loaded exclusively via WP-CLI --require in tests/CliTest.php. Refuses to load
 * the plugin unless BPR_TESTING is enabled in wp-config.php, captures outgoing
 * wp_mail messages to a temporary JSON spool so subprocess runs never contact
 * an external mail server, and allows controlled failure/policy simulation.
 * Excluded from the installable plugin package.
 *
 * @package BulkPasswordReset
 */

\WP_CLI::add_wp_hook(
    'muplugins_loaded',
    static function () {
        if (!defined('BPR_TESTING') || BPR_TESTING !== true) {
            throw new \RuntimeException('Refusing to run CLI test harness without BPR_TESTING.');
        }
        $testLocale = getenv('BPR_CLI_TEST_LOCALE');
        if (is_string($testLocale) && $testLocale !== '') {
            add_filter('pre_load_textdomain', '__return_true');
            add_filter('get_available_languages', static fn ($languages) => array_unique(array_merge($languages, [$testLocale])));
        }
        require_once dirname(__DIR__) . '/bulk_password_reset.php';
        $spool = getenv('BPR_CLI_MAIL_SPOOL');
        add_filter(
            'pre_wp_mail',
            static function ($return, $attributes) use ($spool) {
                $mode = getenv('BPR_CLI_MAIL_MODE');
                if (is_string($spool) && $spool !== '') {
                    $existing = is_file($spool) ? json_decode((string) file_get_contents($spool), true) : [];
                    if (!is_array($existing)) {
                        $existing = [];
                    }
                    $existing[] = [
                        'to' => $attributes['to'] ?? '',
                        'subject' => $attributes['subject'] ?? '',
                        'message' => $attributes['message'] ?? '',
                        'headers' => $attributes['headers'] ?? [],
                        'locale' => get_locale(),
                    ];
                    file_put_contents($spool, json_encode($existing));
                }
                if ($mode === 'fail') {
                    return false;
                }
                if (is_string($mode) && str_starts_with($mode, 'fail-email:')) {
                    $blocked = substr($mode, strlen('fail-email:'));
                    if (($attributes['to'] ?? '') === $blocked) {
                        return false;
                    }
                }
                return true;
            },
            PHP_INT_MAX,
            2
        );
        if (getenv('BPR_CLI_RESET_POLICY') === 'deny') {
            add_filter('allow_password_reset', '__return_false');
        }
    }
);
