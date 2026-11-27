<?php
/** Load a disposable, already-installed WordPress instance. */
$root = getenv('BPR_WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) {
    throw new RuntimeException('Set BPR_WP_ROOT to a disposable WordPress installation. See CONTRIBUTING.md.');
}
$_SERVER['HTTP_HOST'] = 'bpr.test';
// Multisite's site-creation notification reads the request's client address.
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'POST';
require_once $root . '/wp-load.php';
if (!defined('BPR_TESTING') || BPR_TESTING !== true) {
    throw new RuntimeException('Refusing to run against a site without define("BPR_TESTING", true) in wp-config.php.');
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
if (is_multisite()) {
    require_once ABSPATH . 'wp-admin/includes/ms.php';
}
require_once dirname(__DIR__) . '/bulk_password_reset.php';

// Record the settings in the actual PHPUnit process, not only a WP-CLI process.
$debugReport = getenv('BPR_CI_DEBUG_REPORT');
if ($debugReport) {
    file_put_contents($debugReport, json_encode([
        'debug' => defined('WP_DEBUG') && WP_DEBUG === true,
        'display' => defined('WP_DEBUG_DISPLAY') ? WP_DEBUG_DISPLAY : null,
        'configured_log' => defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : null,
        'error_log' => ini_get('error_log'),
        'log_errors' => (bool) ini_get('log_errors'),
        'display_errors' => (bool) ini_get('display_errors'),
        'all_errors' => error_reporting() === E_ALL,
    ], JSON_PRETTY_PRINT) . "\n");
    error_log('BPR CI debug logging probe');
}
