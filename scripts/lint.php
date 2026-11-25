<?php
/**
 * First-party PHP syntax checks for development and CI.
 *
 * Usage: php scripts/lint.php
 * Runs the current PHP interpreter's syntax check on repository PHP files,
 * excluding vendor, build, dist, and Git internals. Exits nonzero if any fail.
 * This command-line development tool is excluded from the plugin package.
 *
 * @package BulkPasswordReset
 */

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failed = false;
foreach ($iterator as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if ($file->getExtension() !== 'php' || preg_match('~^(vendor|build|dist|\.git)/~', $relative)) { continue; }
    passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $code);
    $failed = $failed || $code !== 0;
}
exit($failed ? 1 : 0);
