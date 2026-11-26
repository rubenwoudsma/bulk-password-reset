<?php
/**
 * Preserve CI setup messages without WP-CLI 2.12's deprecated color helper.
 *
 * Loaded only through --require during disposable core installation. The pinned
 * CLI's color helper uses null array offsets deprecated in PHP 8.5. Formatting
 * messages as plain text preserves success, warning, error and debug output
 * without changing PHP diagnostic settings or the CLI's failure exit codes.
 * Remove this adapter once a verified upstream release fixes the helper.
 * Excluded from the installable plugin package.
 *
 * @package BulkPasswordReset
 */

WP_CLI::set_logger(new class(false) extends \WP_CLI\Loggers\Regular {
    protected function _line($message, $label, $color, $handle = STDOUT) {
        $this->write($handle, "$label: $message\n");
    }
});
