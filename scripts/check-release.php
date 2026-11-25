<?php
/**
 * Release metadata validation for development and CI.
 *
 * Usage: php scripts/check-release.php [--tag=MAJOR.MINOR.PATCH]
 * Checks the plugin headers, version constant, readme requirements, stable tag,
 * first changelog entry, and optional Git tag. Exits nonzero on a mismatch.
 * Also included by package.php before it builds an installable ZIP.
 * This command-line development tool is excluded from the plugin package.
 *
 * @package BulkPasswordReset
 */

$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/bulk_password_reset.php');
$readme = file_get_contents($root . '/readme.txt');
$errors = [];
$field = static function (string $text, string $name): string {
    preg_match('/^\s*(?:\*\s*)?' . preg_quote($name, '/') . ':\s*([^\r\n]+)$/mi', $text, $match);
    return trim($match[1] ?? '');
};
$version = $field($plugin, 'Version');
if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)) { $errors[] = 'Version must be numeric major.minor.patch.'; }
if ($field($readme, 'Stable tag') !== $version) { $errors[] = 'Stable tag and PHP version differ.'; }
foreach (['Requires at least', 'Requires PHP'] as $name) {
    if ($field($plugin, $name) === '' || $field($plugin, $name) !== $field($readme, $name)) { $errors[] = "$name differs between headers and readme."; }
}
if (!preg_match("/define\( 'BULK_PASSWORD_RESET_VERSION', '" . preg_quote($version, '/') . "' \);/", $plugin)) { $errors[] = 'BULK_PASSWORD_RESET_VERSION differs from the plugin header.'; }
preg_match('/== Changelog ==\s*=\s*([^=]+?)\s*=/i', $readme, $changelog);
if (trim($changelog[1] ?? '') !== $version) { $errors[] = 'First changelog entry must match the release.'; }
$blocks = preg_split('/\R\s*\R/', trim($readme));
if (strlen($blocks[1] ?? '') > 150) { $errors[] = 'The short description is longer than 150 bytes.'; }
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--tag=') && substr($argument, 6) !== $version) { $errors[] = 'Git tag must equal the plugin version (without a v prefix).'; }
}
if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo "Release metadata OK: $version\n";
