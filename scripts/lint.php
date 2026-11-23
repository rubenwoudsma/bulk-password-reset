<?php
/** Syntax-check every first-party PHP file with the current interpreter. */
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
