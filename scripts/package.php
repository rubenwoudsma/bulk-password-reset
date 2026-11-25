<?php
/**
 * Installable plugin ZIP generation for development and CI.
 *
 * Usage: php scripts/package.php
 * Validates release metadata, packages the explicit runtime file list plus
 * includes and translations, and prints the output path and SHA256 checksum.
 * Requires PHP's zip extension. Output: dist/bulk-password-reset-VERSION.zip.
 * Development tools, tests, workflows, and documentation are not packaged.
 *
 * @package BulkPasswordReset
 */

$root = dirname(__DIR__);
require __DIR__ . '/check-release.php';
if (!class_exists(ZipArchive::class)) { throw new RuntimeException('Enable the PHP zip extension.'); }
preg_match('/^ \* Version: (.+)$/m', file_get_contents($root . '/bulk_password_reset.php'), $match);
$version = trim($match[1]);
$directory = $root . '/dist';
if (!is_dir($directory)) { mkdir($directory, 0775, true); }
$path = "$directory/bulk-password-reset-$version.zip";
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Could not create ZIP.'); }
$files = ['bulk_password_reset.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'assets/admin.css', 'assets/ruben-woudsma-logo.png', 'assets/plugin-icon.png'];
foreach (['includes', 'languages'] as $subdirectory) {
    if (!is_dir("$root/$subdirectory")) { continue; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$subdirectory", FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) { $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)); }
    }
}
sort($files);
foreach ($files as $file) {
    if (!$zip->addFile("$root/$file", "bulk-password-reset/$file")) { throw new RuntimeException("Could not package $file"); }
}
$zip->close();
echo $path . PHP_EOL;
echo 'SHA256: ' . hash_file('sha256', $path) . PHP_EOL;
