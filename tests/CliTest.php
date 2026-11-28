<?php
$GLOBALS['bpr_cli_loaded_at_bootstrap'] = class_exists(\BulkPasswordReset\Cli::class, false);
require_once dirname(__DIR__) . '/includes/class-cli.php';

use BulkPasswordReset\Cli;
use BulkPasswordReset\Email_Settings;
use BulkPasswordReset\Jobs;
use BulkPasswordReset\Service;
use PHPUnit\Framework\TestCase;

/** Real WP-CLI subprocess and fault-injection coverage for guarded CLI jobs. */
final class CliTest extends TestCase
{
    private static bool $loadedAtBootstrap = false;
    private Service $service;
    private Jobs $jobs;
    private array $users = [];
    private array $blogs = [];
    private array $filters = [];
    private array $mails = [];
    private array $options = [];
    private string $spool = '';
    private int $admin;
    private int $target;

    protected function setUp(): void
    {
        foreach ([Email_Settings::OPTION, Jobs::OPTION, Jobs::LOCK] as $name) {
            $this->options[$name] = get_option($name);
            delete_option($name);
        }
        $this->service = new Service();
        $this->jobs = new Jobs($this->service);
        $this->spool = rtrim(sys_get_temp_dir(), '/\\') . '/bpr-cli-mails-' . wp_generate_uuid4() . '.json';
        $this->filter('pre_wp_mail', function ($return, $attributes) {
            $this->mails[] = $attributes;
            return true;
        }, PHP_INT_MAX, 2);
        $this->admin = $this->user('administrator');
        $this->target = $this->user('subscriber', 'bpr_cli_' . wp_rand(100000, 999999) . '.');
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        foreach ($this->filters as [$hook, $callback, $priority]) {
            remove_filter($hook, $callback, $priority);
        }
        if ($this->spool !== '' && is_file($this->spool)) {
            unlink($this->spool);
        }
        wp_set_current_user(0);
        foreach ($this->blogs as $blog) {
            if (get_current_blog_id() === $blog) {
                restore_current_blog();
            }
            wpmu_delete_blog($blog, true);
        }
        foreach ($this->users as $id) {
            if (is_multisite()) {
                revoke_super_admin($id);
                wpmu_delete_user($id);
            } else {
                wp_delete_user($id);
            }
        }
        foreach ($this->options as $name => $value) {
            if ($value === false) {
                delete_option($name);
            } else {
                update_option($name, $value);
            }
        }
    }

    public static function setUpBeforeClass(): void
    {
        self::$loadedAtBootstrap = (bool) ($GLOBALS['bpr_cli_loaded_at_bootstrap'] ?? false);
    }

    public function testWebBootstrapDoesNotLoadCliAdapter(): void
    {
        self::assertFalse(defined('WP_CLI') && WP_CLI);
        self::assertFalse(self::$loadedAtBootstrap);
    }

    public function testSubprocessRequiresExplicitAuthorizedOperator(): void
    {
        $before = get_userdata($this->target)->user_pass;
        $editor = $this->user('editor');

        $noUser = $this->wp(['preview', '--roles=subscriber']);
        self::assertSame(Cli::EXIT_ERROR, $noUser['code']);
        self::assertStringContainsString('--user=', $noUser['stderr']);

        $asSubscriber = $this->wp(['start', '--roles=subscriber', '--mode=invalidate', '--yes', '--user=' . $this->target]);
        self::assertSame(Cli::EXIT_ERROR, $asSubscriber['code']);
        self::assertStringContainsString('cannot perform bulk password resets', $asSubscriber['stderr']);

        $asEditor = $this->wp(['status', '--user=' . $editor]);
        self::assertSame(Cli::EXIT_ERROR, $asEditor['code']);

        self::assertSame($before, get_userdata($this->target)->user_pass);
        self::assertFalse(get_option(Jobs::OPTION));
        self::assertSame([], $this->spooledMails());
    }

    public function testSubprocessPreviewIsReadOnlyAndPreservesExistingReports(): void
    {
        $saved = $this->jobs->create([], [$this->target], 'link', 'Saved browser preview.');
        self::assertIsArray($saved);
        $otherAdmin = $this->user('administrator');
        (new WP_User($otherAdmin))->add_role('subscriber');
        $capUser = $this->user('subscriber');
        (new WP_User($capUser))->add_cap('edit_users');
        Email_Settings::save(['format' => 'html', 'logo' => 'none', 'logo_id' => 0]);

        $beforePass = get_userdata($this->target)->user_pass;
        $beforeKey = get_userdata($this->target)->user_activation_key;

        $human = $this->wp(['preview', '--roles=subscriber', '--mode=invalidate', '--user=' . $this->admin]);
        self::assertSame(Cli::EXIT_OK, $human['code']);
        self::assertStringContainsString('Read-only preview', $human['stdout']);
        self::assertStringContainsString('Immediate invalidation cannot be undone', $human['stderr']);
        self::assertStringNotContainsString('@example.test', $human['stdout'] . $human['stderr']);

        $jsonRun = $this->wp(['preview', '--roles=subscriber', '--format=json', '--user=' . $this->admin]);
        self::assertSame(Cli::EXIT_OK, $jsonRun['code']);
        $payload = $this->decodeJson($jsonRun['stdout']);
        self::assertSame('html', $payload['email_format']);
        self::assertGreaterThanOrEqual(3, $payload['total']);
        $byId = array_column($payload['sample'], 'eligibility', 'id');
        self::assertSame('eligible', $byId[$this->target] ?? null);
        self::assertSame('protected', $byId[$otherAdmin] ?? null);
        self::assertSame('protected', $byId[$capUser] ?? null);

        $blocked = $this->wp(
            ['preview', '--users=' . $this->target, '--format=json', '--user=' . $this->admin],
            '',
            ['BPR_CLI_RESET_POLICY' => 'deny']
        );
        self::assertSame(Cli::EXIT_OK, $blocked['code']);
        self::assertSame('policy_blocked', $this->decodeJson($blocked['stdout'])['sample'][0]['eligibility']);

        wp_cache_delete(Jobs::OPTION, 'options');
        self::assertSame($saved, get_option(Jobs::OPTION));
        self::assertFalse(get_option(Jobs::LOCK));
        self::assertSame($beforePass, get_userdata($this->target)->user_pass);
        self::assertSame($beforeKey, get_userdata($this->target)->user_activation_key);
        self::assertSame([], $this->spooledMails());
    }

    public function testSubprocessStartRequiresConfirmationAndLeavesPreviousReportWhenDeclined(): void
    {
        $prior = $this->jobs->create([], [$this->target], 'link', '');
        self::assertIsArray($prior);
        $completed = $this->jobs->process($prior['id'], true);
        self::assertSame('complete', $completed['status']);
        $this->mails = [];

        $jsonNoYes = $this->wp(['start', '--users=' . $this->target, '--mode=link', '--format=json', '--user=' . $this->admin]);
        self::assertSame(Cli::EXIT_UNCONFIRMED, $jsonNoYes['code']);
        self::assertSame('', trim($jsonNoYes['stdout']));
        self::assertStringContainsString('--yes', $jsonNoYes['stderr']);

        $declined = $this->wp(['start', '--users=' . $this->target, '--mode=invalidate', '--user=' . $this->admin], "n\n");
        self::assertSame(Cli::EXIT_UNCONFIRMED, $declined['code']);
        self::assertStringContainsString('Immediate invalidation cannot be undone', $declined['stderr']);

        $eof = $this->wp(['start', '--users=' . $this->target, '--mode=link', '--user=' . $this->admin], '');
        self::assertSame(Cli::EXIT_UNCONFIRMED, $eof['code']);

        wp_cache_delete(Jobs::OPTION, 'options');
        self::assertSame($completed, get_option(Jobs::OPTION));
        self::assertSame([], $this->spooledMails());
    }

    public function testSubprocessLinkAndInvalidateModesPreserveHtmlLocaleAndTrailingPeriodLinks(): void
    {
        Email_Settings::save(['format' => 'html', 'logo' => 'none', 'logo_id' => 0]);
        update_user_meta($this->target, 'locale', 'de_DE');
        $beforePass = get_userdata($this->target)->user_pass;
        $sessions = WP_Session_Tokens::get_instance($this->target);
        $token = $sessions->create(time() + HOUR_IN_SECONDS);

        $linkRun = $this->wp([
            'start',
            '--users=' . $this->target,
            '--mode=link',
            '--note=CLI incident check',
            '--yes',
            '--format=json',
            '--user=' . $this->admin,
        ], '', ['BPR_CLI_TEST_LOCALE' => 'de_DE']);
        self::assertSame(Cli::EXIT_OK, $linkRun['code']);
        $linkReport = $this->decodeJson($linkRun['stdout']);
        self::assertSame('complete', $linkReport['status']);
        self::assertSame(1, $linkReport['sent']);
        self::assertSame(0, $linkReport['invalidated']);
        self::assertSame('html', $linkReport['email_format']);
        self::assertTrue($linkReport['has_note']);
        self::assertStringNotContainsString('@example.test', $linkRun['stdout'] . $linkRun['stderr']);
        self::assertStringNotContainsString('action=rp', $linkRun['stdout'] . $linkRun['stderr']);

        wp_cache_flush();
        self::assertSame($beforePass, get_userdata($this->target)->user_pass);
        self::assertTrue(WP_Session_Tokens::get_instance($this->target)->verify($token));

        $mails = $this->spooledMails();
        self::assertCount(1, $mails);
        self::assertSame('de_DE', $mails[0]['locale']);
        self::assertStringContainsString('lang="de-DE"', $mails[0]['message']);
        self::assertStringContainsString('CLI incident check', $mails[0]['message']);

        $interactiveInvalidate = $this->wp(
            ['start', '--users=' . $this->target, '--mode=invalidate', '--user=' . $this->admin],
            "y\n"
        );
        self::assertSame(Cli::EXIT_OK, $interactiveInvalidate['code']);
        self::assertStringContainsString('Success:', $interactiveInvalidate['stdout']);

        wp_cache_flush();
        self::assertNotSame($beforePass, get_userdata($this->target)->user_pass);
        self::assertFalse(WP_Session_Tokens::get_instance($this->target)->verify($token));

        $mails = $this->spooledMails();
        self::assertCount(2, $mails);
        self::assertSame(1, preg_match('/href="([^"]+action=rp)"/', $mails[1]['message'], $matches));
        $url = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(get_userdata($this->target)->user_login, $query['login']);
        $recovered = check_password_reset_key($query['key'], $query['login']);
        self::assertInstanceOf(WP_User::class, $recovered);
        reset_password($recovered, 'Recovered-after-cli-reset-2026!');
        self::assertWPErrorCode('invalid_key', check_password_reset_key($query['key'], $query['login']));
    }

    public function testSubprocessMultiBatchMaxBatchesOwnershipResumeAndCancel(): void
    {
        $ids = [$this->target];
        for ($i = 1; $i < 45; ++$i) {
            $ids[] = $this->user('subscriber');
        }
        $promoted = $ids[25];
        $secondAdmin = $this->user('administrator');

        $first = $this->wp([
            'start',
            '--roles=subscriber',
            '--mode=link',
            '--max-batches=1',
            '--yes',
            '--format=json',
            '--user=' . $this->admin,
        ]);
        self::assertSame(Cli::EXIT_OK, $first['code']);
        $report1 = $this->decodeJson($first['stdout']);
        self::assertSame('running', $report1['status']);
        self::assertSame(20, $report1['sent']);
        $jobId = $report1['id'];

        $ownerStatus = $this->wp(['status', '--format=json', '--user=' . $this->admin]);
        self::assertSame(Cli::EXIT_OK, $ownerStatus['code']);
        self::assertSame($jobId, $this->decodeJson($ownerStatus['stdout'])['id']);

        $otherStatus = $this->wp(['status', '--format=json', '--user=' . $secondAdmin]);
        self::assertSame(Cli::EXIT_OK, $otherStatus['code']);
        $otherPayload = $this->decodeJson($otherStatus['stdout']);
        self::assertSame('active_other_admin', $otherPayload['status']);
        self::assertArrayNotHasKey('id', $otherPayload);
        self::assertStringNotContainsString($jobId, $otherStatus['stdout']);

        $otherResume = $this->wp(['resume', $jobId, '--user=' . $secondAdmin]);
        self::assertSame(Cli::EXIT_ERROR, $otherResume['code']);
        $otherCancel = $this->wp(['cancel', $jobId, '--yes', '--user=' . $secondAdmin]);
        self::assertSame(Cli::EXIT_ERROR, $otherCancel['code']);

        $conflictStart = $this->wp(['start', '--roles=subscriber', '--mode=link', '--yes', '--user=' . $this->admin]);
        self::assertSame(Cli::EXIT_CONFLICT, $conflictStart['code']);

        // Grant privileged role alongside subscriber and register a new user after start.
        (new WP_User($promoted))->add_role('administrator');
        if (is_multisite()) {
            grant_super_admin($promoted);
        }
        $lateRegistration = $this->user('subscriber');

        $second = $this->wp(['resume', $jobId, '--max-batches=1', '--format=json', '--user=' . $this->admin]);
        self::assertSame(Cli::EXIT_OK, $second['code']);
        $report2 = $this->decodeJson($second['stdout']);
        self::assertSame('running', $report2['status']);
        self::assertSame(39, $report2['sent']);

        $cancelled = $this->wp(['cancel', $jobId, '--yes', '--format=json', '--user=' . $this->admin]);
        self::assertSame(Cli::EXIT_OK, $cancelled['code']);
        self::assertSame('cancelled', $this->decodeJson($cancelled['stdout'])['status']);
        self::assertStringContainsString('cannot be undone', $cancelled['stderr']);

        $afterCancel = $this->wp(['resume', $jobId, '--user=' . $this->admin]);
        self::assertSame(Cli::EXIT_ERROR, $afterCancel['code']);

        $recipients = array_column($this->spooledMails(), 'to');
        self::assertCount(39, $recipients);
        self::assertNotContains(get_userdata($promoted)->user_email, $recipients);
        self::assertNotContains(get_userdata($lateRegistration)->user_email, $recipients);
    }

    public function testSubprocessUnattendedAllBatchesLocksPartialFailuresAndInterruptedAccounts(): void
    {
        $second = $this->user('subscriber');
        add_option(Jobs::LOCK, 'browser-worker', '', false);
        $locked = $this->wp(['start', '--users=' . $this->target, '--mode=link', '--yes', '--user=' . $this->admin]);
        self::assertSame(Cli::EXIT_CONFLICT, $locked['code']);
        self::assertSame('browser-worker', get_option(Jobs::LOCK));
        delete_option(Jobs::LOCK);

        $partial = $this->wp(
            ['start', '--users=' . $this->target . ',' . $second, '--mode=link', '--yes', '--format=json', '--user=' . $this->admin],
            '',
            ['BPR_CLI_MAIL_MODE' => 'fail-email:' . get_userdata($this->target)->user_email]
        );
        self::assertSame(Cli::EXIT_PARTIAL, $partial['code']);
        $partialReport = $this->decodeJson($partial['stdout']);
        self::assertSame('complete', $partialReport['status']);
        self::assertSame(1, $partialReport['sent']);
        self::assertSame(1, $partialReport['failed']);
        self::assertSame([$this->target], $partialReport['failed_ids']);

        // Browser-created job interrupted mid-flight: CLI resume records uncertain without replaying.
        wp_cache_flush();
        $job = $this->jobs->create([], [$this->target, $second], 'invalidate', '');
        self::assertIsArray($job);
        $job['status'] = 'running';
        $job['cursor'] = $job['in_flight'] = $this->target;
        update_option(Jobs::OPTION, $job, false);
        $targetPassBefore = get_userdata($this->target)->user_pass;

        $resumed = $this->wp(['resume', $job['id'], '--format=json', '--user=' . $this->admin]);
        self::assertSame(Cli::EXIT_PARTIAL, $resumed['code']);
        $resumedReport = $this->decodeJson($resumed['stdout']);
        self::assertSame('complete', $resumedReport['status']);
        self::assertSame(1, $resumedReport['uncertain']);
        self::assertSame([$this->target], $resumedReport['uncertain_ids']);
        self::assertSame(1, $resumedReport['sent']);
        wp_cache_flush();
        self::assertSame($targetPassBefore, get_userdata($this->target)->user_pass);
    }

    public function testInProcessStorageFailureAndExpiredJobHandling(): void
    {
        $before = get_userdata($this->target)->user_pass;
        $block = static fn ($value, $old) => $old;
        $this->filter('pre_update_option_bpr_job', $block, 10, 2);

        $run = $this->inProcess(fn (Cli $cli) => $cli->start([], [
            'users' => (string) $this->target,
            'mode' => 'invalidate',
            'yes' => true,
        ]));
        self::assertSame(Cli::EXIT_ERROR, $run['code']);
        self::assertSame($before, get_userdata($this->target)->user_pass);
        self::assertSame([], $this->mails);

        remove_filter('pre_update_option_bpr_job', $block, 10);
        $job = $this->jobs->create([], [$this->target], 'link', '');
        self::assertIsArray($job);
        $job['expires'] = time() - 1;
        update_option(Jobs::OPTION, $job, false);

        $status = $this->inProcess(fn (Cli $cli) => $cli->status([], ['format' => 'json']));
        self::assertSame(Cli::EXIT_OK, $status['code']);
        self::assertSame('expired', $this->decodeJson($status['lines'][0])['status']);

        $expiredResume = $this->inProcess(fn (Cli $cli) => $cli->resume([$job['id']], ['yes' => true]));
        self::assertSame(Cli::EXIT_ERROR, $expiredResume['code']);
    }

    public function testSubprocessMultisiteRequiresSuperAdminAndScopesToTargetSite(): void
    {
        if (!is_multisite()) {
            self::markTestSkipped('Multisite-only CLI checks; covered by the multisite CI matrix.');
        }

        $siteAdmin = $this->user('administrator');
        revoke_super_admin($siteAdmin);
        $denied = $this->wp(['preview', '--roles=subscriber', '--user=' . $siteAdmin]);
        self::assertSame(Cli::EXIT_ERROR, $denied['code']);

        $subsiteDomain = 'bpr-cli-' . wp_rand(10000, 99999) . '.example.test';
        $blogId = wpmu_create_blog($subsiteDomain, '/', 'CLI Subsite', $this->admin);
        self::assertIsInt($blogId);
        $this->blogs[] = $blogId;

        $rootOnlyMember = $this->user('subscriber');
        $subsiteMember = $this->user('subscriber');
        remove_user_from_blog($subsiteMember, get_current_blog_id());
        add_user_to_blog($blogId, $subsiteMember, 'subscriber');

        $sessions = WP_Session_Tokens::get_instance($subsiteMember);
        $token = $sessions->create(time() + HOUR_IN_SECONDS);
        $subsiteUrl = get_site_url($blogId);

        $run = $this->wp([
            'start',
            '--roles=subscriber',
            '--mode=invalidate',
            '--yes',
            '--format=json',
            '--user=' . $this->admin,
            '--url=' . $subsiteUrl,
        ]);
        self::assertSame(Cli::EXIT_OK, $run['code']);
        self::assertStringContainsString('Multisite:', $run['stderr']);
        $report = $this->decodeJson($run['stdout']);
        self::assertSame($blogId, $report['blog_id']);
        self::assertSame(1, $report['sent']);
        self::assertSame(1, $report['invalidated']);

        wp_cache_flush();
        self::assertFalse(WP_Session_Tokens::get_instance($subsiteMember)->verify($token));
        $recipients = array_column($this->spooledMails(), 'to');
        self::assertSame([get_userdata($subsiteMember)->user_email], $recipients);
        self::assertNotContains(get_userdata($rootOnlyMember)->user_email, $recipients);
    }

    private function wp(array $subcommandArgs, string $stdin = '', array $extraEnv = []): array
    {
        $wpBin = $this->locateWpCli();
        $repoRoot = dirname(__DIR__);
        $wpRoot = (string) getenv('BPR_WP_ROOT');
        $cmd = array_merge(
            [
                PHP_BINARY,
                $wpBin,
                '--path=' . $wpRoot,
                '--require=' . $repoRoot . '/.github/scripts/wp-cli-logger.php',
                '--require=' . $repoRoot . '/tests/cli-harness.php',
                'bulk-password-reset',
            ],
            $subcommandArgs
        );

        $env = array_merge(getenv(), [
            'BPR_CLI_MAIL_SPOOL' => $this->spool,
            'BPR_CLI_MAIL_MODE' => '',
            'BPR_CLI_RESET_POLICY' => '',
        ], $extraEnv);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $repoRoot, $env);
        self::assertIsResource($process, 'Could not start WP-CLI subprocess.');
        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function locateWpCli(): string
    {
        $override = getenv('BPR_WP_CLI_BIN');
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }
        $pathEnv = (string) getenv('PATH');
        foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
            foreach (['wp', 'wp.phar'] as $name) {
                $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
                if ($dir !== '' && is_file($candidate)) {
                    return $candidate;
                }
            }
        }
        self::fail('WP-CLI binary not found on PATH or in BPR_WP_CLI_BIN.');
    }

    private function spooledMails(): array
    {
        if (!is_file($this->spool)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->spool), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function inProcess(callable $invoke): array
    {
        require_once dirname(__DIR__) . '/includes/class-cli.php';
        $lines = [];
        $warnings = [];
        $errors = [];
        $code = Cli::EXIT_OK;
        $cli = new Cli($this->jobs, $this->service, static function (string $kind, mixed $value) use (&$lines, &$warnings, &$errors, &$code) {
            if ($kind === 'line' || $kind === 'success') {
                $lines[] = (string) $value;
            } elseif ($kind === 'warning') {
                $warnings[] = (string) $value;
            } elseif ($kind === 'error') {
                $errors[] = (string) $value;
            } elseif ($kind === 'halt') {
                $code = (int) $value;
                throw new RuntimeException('CLI_HALT', $code);
            }
            return false;
        });
        try {
            $invoke($cli);
        } catch (RuntimeException $halt) {
            if ($halt->getMessage() !== 'CLI_HALT') {
                throw $halt;
            }
        }
        return ['code' => $code, 'lines' => $lines, 'warnings' => $warnings, 'errors' => $errors];
    }

    private function decodeJson(string $raw): array
    {
        $decoded = json_decode(trim($raw), true);
        self::assertIsArray($decoded, 'Expected valid JSON on stdout, got: ' . $raw);
        return $decoded;
    }

    private function user(string $role, ?string $login = null): int
    {
        $login ??= 'bpr_cli_' . str_replace('-', '', wp_generate_uuid4());
        $id = wp_insert_user([
            'user_login' => $login,
            'user_email' => rtrim($login, '.') . '@example.test',
            'user_pass' => 'Original-cli-password-2026!',
            'role' => $role,
        ]);
        self::assertIsInt($id);
        $this->users[] = $id;
        if (is_multisite() && $role === 'administrator') {
            grant_super_admin($id);
        }
        return $id;
    }

    private function filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void
    {
        add_filter($hook, $callback, $priority, $args);
        $this->filters[] = [$hook, $callback, $priority];
    }

    private static function assertWPErrorCode(string $code, mixed $value): void
    {
        self::assertInstanceOf(WP_Error::class, $value);
        self::assertSame($code, $value->get_error_code());
    }
}
