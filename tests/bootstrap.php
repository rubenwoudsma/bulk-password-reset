<?php
/** Load a disposable, already-installed WordPress instance. */
$root = getenv('BPR_WP_ROOT');
if (!$root || !is_file($root . '/wp-load.php')) {
    throw new RuntimeException('Set BPR_WP_ROOT to a disposable WordPress installation. See CONTRIBUTING.md.');
}
$_SERVER['HTTP_HOST'] = 'bpr.test';
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
