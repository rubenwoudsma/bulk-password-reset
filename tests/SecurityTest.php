<?php
use BulkPasswordReset\Admin;
use BulkPasswordReset\Jobs;
use BulkPasswordReset\Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private Service $service;
    private Jobs $jobs;
    private array $ids = [];
    private array $mails = [];
    private array $filters = [];
    private bool $mailResult = true;
    private int $admin;
    private int $target;

    protected function setUp(): void
    {
        delete_option(Jobs::OPTION);
        delete_option(Jobs::LOCK);
        $this->service = new Service();
        $this->jobs = new Jobs($this->service);
        $this->filter('pre_wp_mail', function ($return, $attributes) {
            $this->mails[] = $attributes;
            return $this->mailResult;
        }, PHP_INT_MAX, 2);
        $this->admin = $this->user('administrator');
        $this->target = $this->user('subscriber');
        wp_set_current_user($this->admin);
        $_POST = $_GET = $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        foreach ($this->filters as [$hook, $callback, $priority]) {
            remove_filter($hook, $callback, $priority);
        }
        wp_set_current_user(0);
        foreach ($this->ids as $id) {
            if (is_multisite()) {
                revoke_super_admin($id);
                wpmu_delete_user($id);
            } else {
                wp_delete_user($id);
            }
        }
        delete_option(Jobs::OPTION);
        delete_option(Jobs::LOCK);
        $_POST = $_GET = $_REQUEST = [];
    }

    private function user(string $role): int
    {
        $login = 'bpr_' . str_replace('-', '', wp_generate_uuid4());
        $id = wp_insert_user([
            'user_login' => $login,
            'user_email' => $login . '@example.test',
            'user_pass' => 'Original-unique-password-2026!',
            'role' => $role,
        ]);
        self::assertIsInt($id);
        $this->ids[] = $id;
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

    private function preview(string $mode = 'link', ?array $ids = null): array
    {
        $job = $this->jobs->create([], $ids ?? [$this->target], $mode, 'A note from your administrator.');
        self::assertIsArray($job);
        return $job;
    }

    private function link(int $mail = 0): array
    {
        self::assertSame(1, preg_match('~https?://[^\s]+action=rp[^\s]+~', $this->mails[$mail]['message'], $matches));
        parse_str(parse_url($matches[0], PHP_URL_QUERY), $query);
        return $query;
    }

    private function denied(callable $handler, int $status): void
    {
        $this->filter('wp_die_handler', static fn () => static function ($message, $title, $args) {
            throw new RuntimeException(strip_tags((string) $message), (int) ($args['response'] ?? 500));
        });
        try {
            $handler();
            self::fail('Expected the request to be denied.');
        } catch (RuntimeException $error) {
            self::assertSame($status, $error->getCode(), $error->getMessage());
        }
    }

    public function testPreviewHasNoPasswordOrMailSideEffects(): void
    {
        $before = get_userdata($this->target)->user_pass;
        $job = $this->preview();
        self::assertSame('ready', $job['status']);
        self::assertSame(1, $job['total']);
        self::assertSame($before, get_userdata($this->target)->user_pass);
        self::assertSame([], $this->mails);
    }

    public function testSubscriberCannotCreateProcessOrCallResetDirectly(): void
    {
        $job = $this->preview('invalidate');
        $before = get_userdata($this->admin)->user_pass;
        wp_set_current_user($this->target);
        self::assertFalse(Service::can_manage());
        self::assertNull($this->jobs->current());
        self::assertWPErrorCode('forbidden', $this->jobs->create([], [$this->admin], 'invalidate', ''));
        self::assertWPErrorCode('forbidden', $this->jobs->process($job['id'], true));
        self::assertSame('skipped', $this->service->reset_user($this->admin, 'invalidate')['status']);
        self::assertSame($before, get_userdata($this->admin)->user_pass);
        self::assertSame([], $this->mails);
    }

    public function testEditorCannotUsePlugin(): void
    {
        wp_set_current_user($this->user('editor'));
        self::assertFalse(Service::can_manage());
        self::assertSame([], (new Admin($this->jobs))->bulk_actions([]));
    }

    public function testOwnAndPrivilegedAccountsAreAlwaysProtected(): void
    {
        $other = $this->user('administrator');
        $custom = $this->user('subscriber');
        $user = new WP_User($custom);
        $user->add_cap('edit_users');
        $multi = new WP_User($other);
        $multi->add_role('subscriber');
        foreach ([$this->admin, $other, $custom] as $id) {
            $before = get_userdata($id)->user_pass;
            self::assertFalse($this->service->can_target(get_userdata($id)));
            self::assertSame('skipped', $this->service->reset_user($id, 'invalidate')['status']);
            self::assertSame($before, get_userdata($id)->user_pass);
        }
        self::assertSame([], $this->mails);
    }

    public function testPerTargetEditPermissionIsRequired(): void
    {
        $this->filter('map_meta_cap', function ($caps, $cap, $user, $args) {
            return $cap === 'edit_user' && ($args[0] ?? 0) === $this->target ? ['do_not_allow'] : $caps;
        }, 10, 4);
        self::assertSame('skipped', $this->service->reset_user($this->target, 'invalidate')['status']);
        self::assertSame([], $this->mails);
    }

    public function testWordPressResetPolicyIsHonoredBeforeInvalidation(): void
    {
        $before = get_userdata($this->target)->user_pass;
        $this->filter('allow_password_reset', '__return_false');
        self::assertSame('skipped', $this->service->reset_user($this->target, 'invalidate')['status']);
        self::assertSame($before, get_userdata($this->target)->user_pass);
        self::assertSame([], $this->mails);
    }

    public function testPolicyErrorsAreNotTreatedAsPermission(): void
    {
        $this->filter('allow_password_reset', static fn () => new WP_Error('external_auth', 'External identity provider.'));
        self::assertSame('skipped', $this->service->reset_user($this->target, 'invalidate')['status']);
        self::assertSame([], $this->mails);
    }

    public function testLinkModeKeepsPasswordAndSessionsAndUsesValidHashedCoreKey(): void
    {
        $before = get_userdata($this->target)->user_pass;
        $sessions = WP_Session_Tokens::get_instance($this->target);
        $token = $sessions->create(time() + HOUR_IN_SECONDS);
        $job = $this->preview();
        $result = $this->jobs->process($job['id'], true);
        self::assertSame(1, $result['sent']);
        self::assertSame(0, $result['invalidated']);
        self::assertSame($before, get_userdata($this->target)->user_pass);
        self::assertTrue($sessions->verify($token));
        $link = $this->link();
        self::assertSame($this->target, check_password_reset_key($link['key'], $link['login'])->ID);
        self::assertStringNotContainsString($link['key'], get_userdata($this->target)->user_activation_key);
        self::assertStringNotContainsString($link['key'], wp_json_encode(get_option(Jobs::OPTION)));
        self::assertSame(get_userdata($this->target)->user_email, $this->mails[0]['to']);
        self::assertStringContainsString('A note from your administrator.', $this->mails[0]['message']);
    }

    public function testInvalidationUsesUniqueUnknownPasswordsAndDestroysBrowserSessions(): void
    {
        $second = $this->user('subscriber');
        $sessions = WP_Session_Tokens::get_instance($this->target);
        $token = $sessions->create(time() + HOUR_IN_SECONDS);
        $before = get_userdata($this->target)->user_pass;
        $job = $this->preview('invalidate', [$this->target, $second]);
        $result = $this->jobs->process($job['id'], true);
        self::assertSame(2, $result['sent']);
        self::assertSame(2, $result['invalidated']);
        self::assertNotSame($before, get_userdata($this->target)->user_pass);
        self::assertNotSame(get_userdata($second)->user_pass, get_userdata($this->target)->user_pass);
        self::assertFalse(wp_check_password('Original-unique-password-2026!', get_userdata($this->target)->user_pass, $this->target));
        self::assertFalse($sessions->verify($token));
        $link = $this->link();
        self::assertSame($this->target, check_password_reset_key($link['key'], $link['login'])->ID);
        self::assertStringNotContainsString('Password:', $this->mails[0]['message']);
    }

    public function testResetLinksAreSingleUse(): void
    {
        $this->service->reset_user($this->target, 'link');
        $link = $this->link();
        $user = check_password_reset_key($link['key'], $link['login']);
        self::assertInstanceOf(WP_User::class, $user);
        reset_password($user, 'User-chosen-new-password-2026!');
        self::assertWPErrorCode('invalid_key', check_password_reset_key($link['key'], $link['login']));
    }

    public function testResetLinkExpiryUsesWordPressPolicy(): void
    {
        $this->service->reset_user($this->target, 'link');
        $link = $this->link();
        $this->filter('password_reset_expiration', static fn () => -1);
        self::assertWPErrorCode('expired_key', check_password_reset_key($link['key'], $link['login']));
    }

    public function testMailFailureIsCountedAndInvalidationIsReported(): void
    {
        $this->mailResult = false;
        $job = $this->preview('invalidate');
        $result = $this->jobs->process($job['id'], true);
        self::assertSame(0, $result['sent']);
        self::assertSame(1, $result['failed']);
        self::assertSame([$this->target], $result['failed_ids']);
        self::assertSame(1, $result['invalidated']);
    }

    public function testFailedLinkMailDoesNotChangePassword(): void
    {
        $this->mailResult = false;
        $before = get_userdata($this->target)->user_pass;
        self::assertSame('failed', $this->service->reset_user($this->target, 'link')['status']);
        self::assertSame($before, get_userdata($this->target)->user_pass);
    }

    public function testConfirmationIsRequired(): void
    {
        $job = $this->preview();
        self::assertWPErrorCode('confirmation', $this->jobs->process($job['id']));
        self::assertSame([], $this->mails);
    }

    public function testAnotherAdminCannotProcessOrCancelAnOwnedJob(): void
    {
        $job = $this->preview();
        wp_set_current_user($this->user('administrator'));
        self::assertWPErrorCode('job', $this->jobs->process($job['id'], true));
        self::assertWPErrorCode('job', $this->jobs->cancel($job['id']));
        self::assertSame([], $this->mails);
    }

    public function testRevokedOperatorAccessStopsSavedJob(): void
    {
        $job = $this->preview();
        (new WP_User($this->admin))->set_role('subscriber');
        if (is_multisite()) { revoke_super_admin($this->admin); }
        wp_set_current_user(0);
        wp_set_current_user($this->admin);
        self::assertWPErrorCode('forbidden', $this->jobs->process($job['id'], true));
        self::assertSame([], $this->mails);
    }

    public function testExpiredAndTamperedJobsAreRejected(): void
    {
        $job = $this->preview();
        self::assertWPErrorCode('job', $this->jobs->process('different-id', true));
        $job['expires'] = time() - 1;
        update_option(Jobs::OPTION, $job, false);
        self::assertWPErrorCode('job', $this->jobs->process($job['id'], true));
        self::assertSame([], $this->mails);
    }

    public function testCancelledAndCompletedJobsDoNotReplay(): void
    {
        $job = $this->preview();
        $this->jobs->cancel($job['id']);
        self::assertSame('cancelled', $this->jobs->process($job['id'], true)['status']);
        self::assertSame([], $this->mails);
        $job = $this->preview();
        $this->jobs->process($job['id'], true);
        $this->jobs->process($job['id'], true);
        self::assertCount(1, $this->mails);
    }

    public function testConcurrentRequestsCannotEnterHeldLock(): void
    {
        $job = $this->preview();
        add_option(Jobs::LOCK, 'another-worker', '', false);
        self::assertWPErrorCode('locked', $this->jobs->process($job['id'], true));
        self::assertWPErrorCode('locked', $this->jobs->cancel($job['id']));
        self::assertSame('another-worker', get_option(Jobs::LOCK));
        self::assertSame([], $this->mails);
    }

    public function testCheckpointFailureStopsBeforeSideEffects(): void
    {
        $job = $this->preview('invalidate');
        $before = get_userdata($this->target)->user_pass;
        $this->filter('pre_update_option_bpr_job', static fn ($value, $old) => $old, 10, 2);
        self::assertWPErrorCode('storage', $this->jobs->process($job['id'], true));
        self::assertSame([], $this->mails);
        self::assertSame($before, get_userdata($this->target)->user_pass);
    }

    public function testFailedPostMailCheckpointLeavesUncertainAccountWithoutRetry(): void
    {
        $job = $this->preview();
        $block = static fn ($value, $old) => $old;
        $this->filter('pre_wp_mail', function ($value) use ($block) {
            $this->filter('pre_update_option_bpr_job', $block, 10, 2);
            return $value;
        }, PHP_INT_MAX - 1);
        self::assertWPErrorCode('storage', $this->jobs->process($job['id'], true));
        self::assertCount(1, $this->mails);
        remove_filter('pre_update_option_bpr_job', $block, 10);
        $result = $this->jobs->process($job['id']);
        self::assertSame(1, $result['uncertain']);
        self::assertSame([$this->target], $result['uncertain_ids']);
        self::assertCount(1, $this->mails);
    }

    public function testSiteAdministratorCannotUseMultisiteTool(): void
    {
        if (!is_multisite()) { $this->markTestSkipped('Multisite-only policy; covered by the multisite CI job.'); }
        revoke_super_admin($this->admin);
        self::assertFalse(Service::can_manage());
        self::assertWPErrorCode('forbidden', $this->jobs->create([], [$this->target], 'link', ''));
    }

    public function testMultisiteDoesNotSelectNonmembers(): void
    {
        if (!is_multisite()) { $this->markTestSkipped('Multisite-only policy; covered by the multisite CI job.'); }
        remove_user_from_blog($this->target, get_current_blog_id());
        self::assertFalse($this->service->can_target(get_userdata($this->target)));
        $job = $this->preview();
        self::assertSame(0, $this->jobs->process($job['id'], true)['sent']);
        self::assertSame([], $this->mails);
    }

    public function testInterruptedAccountIsNotRepeated(): void
    {
        $second = $this->user('subscriber');
        $job = $this->preview('invalidate', [$this->target, $second]);
        $job['status'] = 'running';
        $job['cursor'] = $job['in_flight'] = $this->target;
        update_option(Jobs::OPTION, $job, false);
        $before = get_userdata($this->target)->user_pass;
        $result = $this->jobs->process($job['id']);
        self::assertSame(1, $result['uncertain']);
        self::assertSame(1, $result['sent']);
        self::assertSame($before, get_userdata($this->target)->user_pass);
        self::assertSame(get_userdata($second)->user_email, $this->mails[0]['to']);
    }

    public static function completionBoundaries(): array
    {
        return ['one' => [1], 'below batch' => [19], 'one full batch' => [20],
            'over one batch' => [21], 'two full batches' => [40]];
    }

    #[DataProvider('completionBoundaries')]
    public function testCompletionNeedsNoEmptyContinuation(int $size): void
    {
        $ids = [$this->target];
        for ($number = 1; $number < $size; ++$number) { $ids[] = $this->user('subscriber'); }
        $job = $this->preview('link', $ids);
        $requests = (int) ceil($size / Jobs::BATCH_SIZE);
        for ($request = 1; $request <= $requests; ++$request) {
            $result = $this->jobs->process($job['id'], $request === 1);
            self::assertSame(min($size, $request * Jobs::BATCH_SIZE), $result['sent']);
            self::assertSame($request === $requests ? 'complete' : 'running', $result['status']);
        }
        $this->jobs->process($job['id'], true);
        self::assertCount($size, $this->mails);
        self::assertCount($size, array_unique(array_column($this->mails, 'to')));
    }

    public function testLookaheadDoesNotInvalidateOrEmailTheNextRecipient(): void
    {
        $ids = [$this->target];
        for ($number = 1; $number <= Jobs::BATCH_SIZE; ++$number) { $ids[] = $this->user('subscriber'); }
        $last = end($ids);
        $before = get_userdata($last);
        $sessions = WP_Session_Tokens::get_instance($last);
        $token = $sessions->create(time() + HOUR_IN_SECONDS);
        $job = $this->preview('invalidate', $ids);
        $first = $this->jobs->process($job['id'], true);
        self::assertSame('running', $first['status']);
        self::assertSame(20, $first['invalidated']);
        self::assertCount(20, $this->mails);
        self::assertSame($before->user_pass, get_userdata($last)->user_pass);
        self::assertSame($before->user_activation_key, get_userdata($last)->user_activation_key);
        self::assertTrue($sessions->verify($token));
        self::assertNotContains($before->user_email, array_column($this->mails, 'to'));
        $final = $this->jobs->process($job['id']);
        self::assertSame('complete', $final['status']);
        self::assertSame(21, $final['invalidated']);
        self::assertFalse($sessions->verify($token));
    }

    public function testCompletedResultsStayOnStepThreeAndRetainReviewDetails(): void
    {
        $this->mailResult = false;
        $job = $this->preview();
        $this->jobs->process($job['id'], true);
        $html = $this->screen();
        $this->assertStep(3, $html);
        self::assertStringContainsString('Start a new reset', $html);
        self::assertStringContainsString('data-bpr-stat="failed"', $html);
        self::assertStringContainsString(get_userdata($this->target)->user_login, $html);
        self::assertStringNotContainsString('class="bpr-card bpr-reset-form"', $html);
    }

    public function testStartingNewResetIsReadOnlyUntilTheNextPreview(): void
    {
        $first = $this->preview();
        $before = $this->jobs->process($first['id'], true);
        $_GET['view'] = 'new';
        $html = $this->screen();
        $this->assertStep(1, $html);
        self::assertStringContainsString('class="bpr-card bpr-reset-form"', $html);
        self::assertStringContainsString('View previous reset results', $html);
        self::assertSame($before, $this->jobs->current());
        self::assertCount(1, $this->mails);
        $_GET = [];
        $this->assertStep(3, $this->screen());
        $second = $this->preview();
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame($second, $this->jobs->current());
        self::assertCount(1, $this->mails);
    }

    public function testNewResetNavigationCannotBypassAnActivePreviewOrItsOwner(): void
    {
        $job = $this->preview();
        $_GET['view'] = 'new';
        $this->assertStep(2, $this->screen());
        self::assertStringNotContainsString('class="bpr-card bpr-reset-form"', $this->screen());
        wp_set_current_user($this->user('administrator'));
        $html = $this->screen();
        self::assertStringContainsString('A reset is already in progress', $html);
        self::assertStringNotContainsString('class="bpr-card bpr-reset-form"', $html);
        self::assertSame($job, $this->jobs->current());
        self::assertSame([], $this->mails);
    }

    public function testTerminalStatusSurvivesTheResumeDeadlineButActiveJobsExpire(): void
    {
        $job = $this->preview();
        $complete = $this->jobs->process($job['id'], true);
        $complete['expires'] = time() - 1;
        update_option(Jobs::OPTION, $complete, false);
        self::assertStringContainsString('<h2>Complete</h2>', $this->screen());
        $this->assertStep(3, $this->screen());
        $complete['status'] = 'cancelled';
        update_option(Jobs::OPTION, $complete, false);
        self::assertStringContainsString('<h2>Cancelled</h2>', $this->screen());
        $complete['status'] = 'running';
        update_option(Jobs::OPTION, $complete, false);
        self::assertStringContainsString('<h2>Expired</h2>', $this->screen());
        self::assertStringContainsString('Start a new reset', $this->screen());
        self::assertWPErrorCode('job', $this->jobs->process($job['id']));
    }

    private function screen(): string
    {
        ob_start();
        try { (new Admin($this->jobs))->render(); return ob_get_contents(); }
        finally { ob_end_clean(); }
    }

    private function assertStep(int $number, string $html): void
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $current = (new DOMXPath($document))->query('//ol[@class="bpr-steps"]/li[@aria-current="step"]');
        self::assertCount(1, $current);
        self::assertSame((string) $number, $current->item(0)->getElementsByTagName('span')->item(0)->textContent);
    }

    public function testBatchesResumeWithoutDuplicatesBeyondOneHundredUsers(): void
    {
        $ids = [];
        for ($i = 0; $i < 105; ++$i) { $ids[] = $this->user('subscriber'); }
        $job = $this->preview('link', $ids);
        $result = $this->jobs->process($job['id'], true);
        self::assertSame(Jobs::BATCH_SIZE, $result['sent']);
        self::assertSame('running', $result['status']);
        for ($batch = 0; $batch < 8 && $result['status'] === 'running'; ++$batch) {
            $result = $this->jobs->process($job['id']);
        }
        self::assertSame('complete', $result['status']);
        self::assertSame(105, $result['sent']);
        self::assertCount(105, array_unique(array_column($this->mails, 'to')));
    }

    public function testRolesAreReevaluatedAndNewRegistrationsExcluded(): void
    {
        $job = $this->jobs->create(['subscriber'], [], 'link', '');
        self::assertIsArray($job);
        (new WP_User($this->target))->set_role('administrator');
        $new = $this->user('subscriber');
        $result = $this->jobs->process($job['id'], true);
        self::assertSame(0, $result['sent']);
        self::assertSame([], $this->mails);
        self::assertGreaterThan($job['selection']['max_id'], $new);
    }

    public function testMultipleRolesAndDuplicateIdsDoNotSendDuplicateMail(): void
    {
        (new WP_User($this->target))->add_role('editor');
        $job = $this->jobs->create(['subscriber', 'editor'], [], 'link', '');
        self::assertIsArray($job);
        self::assertSame(1, $this->jobs->process($job['id'], true)['sent']);
        $job = $this->preview('link', [$this->target, $this->target]);
        self::assertSame(1, $this->jobs->process($job['id'], true)['sent']);
    }

    public function testInvalidSelectionsAndModesAreRejected(): void
    {
        foreach ([[[], []], [["subscriber' OR 1=1 --"], []], [[], ['1 OR 1=1']], [['subscriber'], [$this->target]]] as [$roles, $ids]) {
            self::assertWPErrorCode('selection', $this->jobs->create($roles, $ids, 'link', ''));
        }
        self::assertWPErrorCode('settings', $this->jobs->create([], [$this->target], 'unknown', ''));
        self::assertSame([], $this->mails);
    }

    public function testAdminPostRejectsGetBeforeAnyMutation(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->denied(fn () => (new Admin($this->jobs))->preview(), 405);
        self::assertNull($this->jobs->current());
    }

    public function testSubscriberPostIsForbiddenEvenWithNonce(): void
    {
        wp_set_current_user($this->target);
        $_REQUEST['_wpnonce'] = wp_create_nonce('bpr_preview');
        $this->denied(fn () => (new Admin($this->jobs))->preview(), 403);
        self::assertFalse(get_option(Jobs::OPTION));
    }

    public function testMissingNonceBlocksPreviewAndExecution(): void
    {
        $this->denied(fn () => (new Admin($this->jobs))->preview(), 403);
        $job = $this->preview();
        $_POST['job'] = $job['id'];
        $this->denied(fn () => (new Admin($this->jobs))->process(), 403);
        self::assertSame([], $this->mails);
    }

    public function testValidNonceWithoutConfirmationCannotStartJob(): void
    {
        $job = $this->preview();
        $_POST['job'] = $job['id'];
        $_REQUEST['_wpnonce'] = wp_create_nonce('bpr_process_' . $job['id']);
        $this->denied(fn () => (new Admin($this->jobs))->process(), 400);
        self::assertSame([], $this->mails);
    }

    public function testUsersBulkActionOnlyCreatesPreviewAndRequiresNonce(): void
    {
        $admin = new Admin($this->jobs);
        $this->denied(fn () => $admin->bulk_preview('original', 'bpr_preview', [$this->target]), 403);
        $_REQUEST['_wpnonce'] = wp_create_nonce('bulk-users');
        self::assertSame(Admin::url(), $admin->bulk_preview('original', 'bpr_preview', [$this->target]));
        self::assertSame([], $this->mails);
        self::assertSame('original', $admin->bulk_preview('original', 'perform_reset', [$this->admin]));
    }

    public function testEarlyUsersPreviewRejectsMissingNonceAndSubscriber(): void
    {
        $admin = new Admin($this->jobs);
        $_REQUEST = ['action' => 'bpr_preview', 'users' => [$this->target]];
        $this->denied(fn () => $admin->users_preview(), 403);
        wp_set_current_user($this->target);
        $_REQUEST['_wpnonce'] = wp_create_nonce('bulk-users');
        $this->denied(fn () => $admin->users_preview(), 403);
        self::assertFalse(get_option(Jobs::OPTION));
        self::assertSame([], $this->mails);
    }

    public function testEarlyUsersPreviewChecksBottomActionAndIgnoresLegacyAction(): void
    {
        $admin = new Admin($this->jobs);
        $_REQUEST = ['action' => '-1', 'action2' => 'bpr_preview', 'users' => [$this->target]];
        $this->denied(fn () => $admin->users_preview(), 403);
        $_REQUEST = ['action' => 'perform_reset', 'users' => [$this->admin]];
        $admin->users_preview();
        self::assertFalse(get_option(Jobs::OPTION));
        self::assertSame([], $this->mails);
    }

    public function testLegacySettingsAreDeletedRatherThanImported(): void
    {
        update_option('bpr_options', ['custompassword' => 'shared-secret', 'customauthkey' => 'shared-key']);
        BulkPasswordReset\remove_legacy_settings();
        self::assertFalse(get_option('bpr_options'));
        self::assertFalse(class_exists('bulk_password_reset'));
    }

    public function testScreenEscapesAdministratorNoteAndUsername(): void
    {
        $job = $this->jobs->create([], [$this->target], 'link', '<script>alert(1)</script> A & B');
        self::assertIsArray($job);
        ob_start();
        (new Admin($this->jobs))->render();
        $html = ob_get_clean();
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('A &amp; B', $html);
        self::assertSame([], $this->mails);
    }

    public function testCustomRoleIsSupported(): void
    {
        add_role('bpr_customer', 'Customer', ['read' => true]);
        try {
            (new WP_User($this->target))->set_role('bpr_customer');
            $job = $this->jobs->create(['bpr_customer'], [], 'link', '');
            self::assertIsArray($job);
            self::assertSame(1, $this->jobs->process($job['id'], true)['sent']);
        } finally {
            remove_role('bpr_customer');
        }
    }

    public function testAdminAssetsAreLimitedToAuthorizedPluginScreen(): void
    {
        $handle = 'bulk-password-reset-admin';
        wp_dequeue_style($handle);
        wp_deregister_style($handle);
        $admin = new Admin($this->jobs);
        $admin->enqueue_assets('index.php');
        $admin->enqueue_assets('users.php');
        self::assertFalse(wp_style_is($handle, 'enqueued'));
        $admin->enqueue_assets('users_page_bulk_password_reset');
        self::assertTrue(wp_style_is($handle, 'enqueued'));
        self::assertStringEndsWith('/assets/admin.css', wp_styles()->registered[$handle]->src);
        wp_dequeue_style($handle);
        wp_set_current_user($this->target);
        $admin->enqueue_assets('users_page_bulk_password_reset');
        self::assertFalse(wp_style_is($handle, 'enqueued'));
        wp_deregister_style($handle);
    }

    private static function assertWPErrorCode(string $code, mixed $value): void
    {
        self::assertInstanceOf(WP_Error::class, $value);
        self::assertSame($code, $value->get_error_code());
    }
}
