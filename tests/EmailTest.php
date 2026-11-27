<?php
use BulkPasswordReset\Admin;
use BulkPasswordReset\Email;
use BulkPasswordReset\Email_Settings;
use BulkPasswordReset\Email_View;
use BulkPasswordReset\Jobs;
use BulkPasswordReset\Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real WordPress coverage for preferences, rendering, recovery and isolation. */
final class EmailTest extends TestCase
{
    private array $users = [];
    private array $attachments = [];
    private array $posts = [];
    private array $filters = [];
    private array $mails = [];
    private array $options = [];
    private int $admin;
    private int $target;

    protected function setUp(): void
    {
        foreach ([Email_Settings::OPTION, Jobs::OPTION, Jobs::LOCK, 'blogname', 'site_logo'] as $name) {
            $this->options[$name] = get_option($name);
        }
        delete_option(Email_Settings::OPTION);
        delete_option(Jobs::OPTION);
        delete_option(Jobs::LOCK);
        $this->admin = $this->user('administrator');
        $this->target = $this->user('subscriber', 'bpr_html_' . wp_rand(100000, 999999) . '.');
        wp_set_current_user($this->admin);
        $this->filter('pre_wp_mail', function ($value, $attributes) {
            $this->mails[] = $attributes;
            return true;
        }, PHP_INT_MAX, 2);
        $_POST = $_GET = $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        foreach ($this->filters as [$hook, $callback, $priority]) { remove_filter($hook, $callback, $priority); }
        foreach ($this->attachments as $id) { wp_delete_attachment($id, true); }
        foreach ($this->posts as $id) { wp_delete_post($id, true); }
        foreach ($this->users as $id) {
            if (is_multisite()) { revoke_super_admin($id); wpmu_delete_user($id); }
            else { wp_delete_user($id); }
        }
        foreach ($this->options as $name => $value) {
            if ($value === false) { delete_option($name); } else { update_option($name, $value); }
        }
        wp_set_current_user(0);
        $_POST = $_GET = $_REQUEST = [];
    }

    private function user(string $role, ?string $login = null): int
    {
        $login ??= 'bpr_html_' . str_replace('-', '', wp_generate_uuid4());
        $id = wp_insert_user(['user_login'=>$login, 'user_email'=>rtrim($login, '.') . '@example.test', 'user_pass'=>'Original-test-password!', 'role'=>$role]);
        self::assertIsInt($id);
        $this->users[] = $id;
        if (is_multisite() && $role === 'administrator') { grant_super_admin($id); }
        return $id;
    }

    private function filter(string $name, callable $callback, int $priority = 10, int $args = 1): void
    {
        add_filter($name, $callback, $priority, $args);
        $this->filters[] = [$name, $callback, $priority];
    }

    private function image(): int
    {
        $file = wp_upload_bits('bpr-logo-' . wp_generate_uuid4() . '.png', null, file_get_contents(dirname(__DIR__) . '/assets/plugin-icon.png'));
        self::assertEmpty($file['error']);
        $id = wp_insert_attachment(['post_title'=>'Example site logo', 'post_mime_type'=>'image/png', 'post_status'=>'inherit'], $file['file']);
        self::assertIsInt($id);
        $size = getimagesize($file['file']);
        wp_update_attachment_metadata($id, ['width'=>$size[0], 'height'=>$size[1], 'file'=>_wp_relative_upload_path($file['file']), 'sizes'=>[]]);
        $this->attachments[] = $id;
        return $id;
    }

    private function settings(string $format = 'html', string $logo = 'none', int $id = 0): array
    {
        $result = Email_Settings::save(['format'=>$format, 'logo'=>$logo, 'logo_id'=>$id]);
        self::assertIsArray($result);
        return $result;
    }

    private function state(int $id): array
    {
        $user = get_userdata($id);
        return [$user->user_pass, $user->user_activation_key, get_user_meta($id, 'session_tokens', true), get_option(Jobs::OPTION)];
    }

    public function testDefaultPlainTextRemainsUnchanged(): void
    {
        self::assertSame(Email_Settings::defaults(), Email_Settings::get());
        $message = Email::example();
        self::assertSame('', $message['html']);
        self::assertStringContainsString('This is a test email.', $message['text']);
        self::assertStringContainsString('example.invalid', $message['text']);
    }

    public function testUntrustedSettingsAreRejectedWithoutReplacingSavedPreferences(): void
    {
        $saved = $this->settings();
        foreach ([[], ['format'=>[], 'logo'=>'none'], ['format'=>'html', 'logo'=>[]], ['format'=>'script', 'logo'=>'none'], ['format'=>'html', 'logo'=>'https://example.test/logo.png'], ['format'=>'html','logo'=>'custom','logo_id'=>'1 OR 1']] as $value) {
            self::assertInstanceOf(WP_Error::class, Email_Settings::save($value));
            self::assertSame($saved, Email_Settings::get());
        }
        update_option(Email_Settings::OPTION, ['format'=>[], 'logo'=>[], 'logo_id'=>[]]);
        self::assertSame(Email_Settings::defaults(), Email_Settings::get());
    }

    public function testSubscriberCannotSaveOrSendTestMailOrViewSettings(): void
    {
        wp_set_current_user($this->target);
        self::assertSame('forbidden', Email_Settings::save(['format'=>'html','logo'=>'none'])->get_error_code());
        self::assertSame('forbidden', Email::send_test('link')->get_error_code());
        ob_start(); (new Email_View())->render(); $html = ob_get_clean();
        self::assertSame('', $html);
        self::assertFalse(get_option(Email_Settings::OPTION));
        self::assertSame([], $this->mails);
    }

    public function testSettingsAndTestEndpointsRequireAuthorizedPostAndNonce(): void
    {
        $admin = new Admin(new Jobs(new Service()));
        $this->filter('wp_die_handler', static fn () => static function ($message, $title, $args) {
            throw new RuntimeException('Request denied', (int) ($args['response'] ?? 403));
        });
        foreach (['email_save', 'email_test'] as $method) {
            foreach ([['GET', $this->admin, 405], ['POST', $this->target, 403], ['POST', $this->admin, 403]] as [$verb, $user, $status]) {
                $_SERVER['REQUEST_METHOD'] = $verb; wp_set_current_user($user);
                try { $admin->$method(); self::fail('Unprotected email endpoint'); }
                catch (RuntimeException $error) { self::assertSame($status, $error->getCode()); }
            }
        }
        self::assertFalse(get_option(Email_Settings::OPTION));
        self::assertSame([], $this->mails);
    }

    public function testSafePreviewAndTestMailDoNotChangeAnyRecoveryOrJobState(): void
    {
        $this->settings();
        $key = get_password_reset_key(get_userdata($this->target));
        $job = (new Jobs(new Service()))->create([], [$this->target], 'link', 'Original job');
        WP_Session_Tokens::get_instance($this->target)->create(time()+600);
        $before = $this->state($this->target);
        $_GET['view'] = 'email';
        ob_start(); (new Admin(new Jobs(new Service())))->render(); $html = ob_get_clean();
        self::assertStringContainsString('sandbox=""', $html);
        self::assertStringNotContainsString($key, $html);
        self::assertStringContainsString('example.invalid', $html);
        $_POST['to'] = 'untrusted@example.test';
        self::assertTrue(Email::send_test('invalidate'));
        self::assertCount(1, $this->mails);
        self::assertSame(get_userdata($this->admin)->user_email, $this->mails[0]['to']);
        self::assertStringNotContainsString($key, $this->mails[0]['message']);
        self::assertSame($before, $this->state($this->target));
        self::assertSame($job, get_option(Jobs::OPTION));
        self::assertInstanceOf(WP_User::class, check_password_reset_key($key, get_userdata($this->target)->user_login));
        self::assertSame('test', Email::send_test('unsupported')->get_error_code());
    }

    public function testHtmlEscapesAllContentAndHasNoAuthorBranding(): void
    {
        update_option('blogname', 'Site &quot;name&quot; <script>alert(1)</script>');
        $message = Email::message('user.<img src=x onerror=alert(2)>', 'link', 'café <script>alert(3)</script> & "note"', 'https://example.invalid/reset?a=1&b=2', $this->settings());
        $html = $message['html'];
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('user.&lt;img', $html);
        self::assertStringContainsString('café', $html);
        self::assertStringContainsString('a=1&#038;b=2', $html);
        foreach (['Ruben', 'rubenwoudsma', 'tracking', '<form', '<iframe', 'javascript:'] as $forbidden) { self::assertStringNotContainsString($forbidden, $html); }
        self::assertStringContainsString('role="presentation"', $html);
        self::assertStringContainsString('max-width:600px', $html);
    }

    public function testCustomAndSiteLogoAreConstrainedAndSnapshotted(): void
    {
        $id = $this->image();
        $this->settings('html', 'custom', $id);
        $image = Email_Settings::image($id);
        self::assertNotNull($image);
        self::assertLessThanOrEqual(180, $image['width']);
        self::assertLessThanOrEqual(72, $image['height']);
        self::assertStringContainsString(esc_url($image['url']), Email::example()['html']);
        update_option('site_logo', $id);
        $this->settings('html', 'site');
        $job = (new Jobs(new Service()))->create([], [$this->target], 'link', '');
        self::assertSame($id, $job['email']['logo_id']);
        delete_option('site_logo');
        self::assertSame($id, $job['email']['logo_id']);
        wp_update_post(['ID'=>$id, 'post_status'=>'private']);
        self::assertNull(Email_Settings::image($id));
        self::assertSame('logo', Email_Settings::save(['format'=>'html','logo'=>'custom','logo_id'=>$id])->get_error_code());
        self::assertStringNotContainsString('<img', Email::message('example', 'link', '', 'https://example.invalid', $job['email'])['html']);
    }

    public function testPrivateParentAndExecutableImageUrlAreNotRendered(): void
    {
        $id = $this->image();
        $post = wp_insert_post(['post_title'=>'Private test', 'post_type'=>'post', 'post_status'=>'private']);
        $this->posts[] = $post;
        wp_update_post(['ID'=>$id,'post_parent'=>$post]);
        self::assertNull(Email_Settings::image($id));
        wp_update_post(['ID'=>$id,'post_parent'=>0]);
        $this->filter('wp_get_attachment_image_src', static fn () => ['javascript:alert(1)',320,120,false]);
        self::assertNull(Email_Settings::image($id));
        self::assertSame('logo', Email_Settings::save(['format'=>'html','logo'=>'custom','logo_id'=>$id])->get_error_code());
    }

    public function testRootRelativeImageUrlIsResolvedAndProtocolRelativeUrlsAreRejected(): void
    {
        $id = $this->image();
        $override = '/wp-content/uploads/2026/11/relative-logo.png';
        $this->filter('wp_get_attachment_image_src', static function () use (&$override) {
            return [$override, 320, 120, false];
        });
        $resolved = Email_Settings::image($id);
        self::assertNotNull($resolved);
        self::assertSame(home_url('/wp-content/uploads/2026/11/relative-logo.png'), $resolved['url']);
        foreach (['//evil.example.test/logo.png', '///evil.example.test/logo.png'] as $bad) {
            $override = $bad;
            self::assertNull(Email_Settings::image($id));
        }
    }

    public function testMissingSiteLogoShowsWarningInHtmlEmailSettings(): void
    {
        delete_option('site_logo');
        $this->settings('html', 'site');
        ob_start();
        (new Email_View())->render();
        $html = ob_get_clean();
        self::assertStringContainsString('No compatible public PNG, JPEG or GIF site logo is currently configured.', $html);
    }

    public function testAccountWithoutValidEmailIsNotInvalidated(): void
    {
        global $wpdb;
        $id = $this->user('subscriber');
        $sessions = WP_Session_Tokens::get_instance($id);
        $token = $sessions->create(time() + 600);
        $wpdb->update($wpdb->users, ['user_email' => ''], ['ID' => $id]);
        clean_user_cache($id);
        $result = (new Service())->reset_user($id, 'invalidate');
        self::assertSame(['status' => 'failed', 'invalidated' => false], $result);
        self::assertTrue(wp_check_password('Original-test-password!', get_userdata($id)->user_pass));
        self::assertTrue($sessions->verify($token));
        self::assertSame([], $this->mails);
    }

    public function testNonImageAttachmentIsRejected(): void
    {
        $id = $this->image();
        foreach (['image/svg+xml', 'application/pdf', 'image/webp'] as $mime) {
            wp_update_post(['ID'=>$id,'post_mime_type'=>$mime]);
            self::assertNull(Email_Settings::image($id));
        }
    }

    public function testSettingsChangesDoNotAlterAConfirmedJobAndLegacyJobStaysPlain(): void
    {
        $this->settings();
        $jobs = new Jobs(new Service());
        $job = $jobs->create([], [$this->target], 'link', '');
        $this->settings('plain');
        self::assertSame('complete', $jobs->process($job['id'], true)['status']);
        self::assertStringContainsString('<!doctype html>', $this->mails[0]['message']);
        $legacy = $jobs->create([], [$this->target], 'link', '');
        unset($legacy['email']); update_option(Jobs::OPTION, $legacy);
        $this->settings();
        $jobs->process($legacy['id'], true);
        self::assertStringNotContainsString('<html', $this->mails[1]['message']);
        self::assertSame('', $this->mails[1]['headers']);
    }

    public static function recoveryModes(): array
    {
        return [['plain','link'], ['plain','invalidate'], ['html','link'], ['html','invalidate']];
    }

    #[DataProvider('recoveryModes')]
    public function testBothFormatsPreserveRealRecoveryAndTrailingPeriodLogins(string $format, string $mode): void
    {
        $this->settings($format);
        $user = get_userdata($this->target);
        $sessions = WP_Session_Tokens::get_instance($this->target);
        $token = $sessions->create(time()+600);
        self::assertSame('sent', (new Service())->reset_user($this->target, $mode, 'café')['status']);
        $body = $this->mails[0]['message'];
        if ($format === 'html') {
            $doc = new DOMDocument(); @$doc->loadHTML($body);
            $links = (new DOMXPath($doc))->query('//a[contains(@href,"wp-login.php")]');
            self::assertSame(2, $links->length);
            $url = $links->item(0)->getAttribute('href');
            self::assertSame($url, $links->item(1)->getAttribute('href'));
        } else {
            self::assertSame(1, preg_match('~https?://[^\s]+/wp-login\.php\?[^\s]+~', $body, $match));
            $url = $match[0];
        }
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame($user->user_login, $query['login']);
        self::assertStringEndsWith('.', $query['login']);
        self::assertSame('rp', $query['action']);
        self::assertInstanceOf(WP_User::class, check_password_reset_key($query['key'], $query['login']));
        self::assertSame($mode === 'link', $sessions->verify($token));
        self::assertSame($mode === 'link', wp_check_password('Original-test-password!', get_userdata($this->target)->user_pass));
        reset_password(get_userdata($this->target), 'New-test-password!');
        self::assertInstanceOf(WP_Error::class, check_password_reset_key($query['key'], $query['login']));
        self::assertTrue(wp_check_password('New-test-password!', get_userdata($this->target)->user_pass));
        self::assertStringNotContainsString('Original-test-password!', $body);
        self::assertStringNotContainsString('New-test-password!', $body);
    }

    public function testHtmlUsesRecipientLocaleAndRestoresItOnMailFailure(): void
    {
        $this->settings();
        update_user_meta($this->target, 'locale', 'de_DE');
        $this->filter('pre_load_textdomain', static fn () => true);
        $this->filter('pre_wp_mail', static fn () => false, PHP_INT_MAX);
        // WordPress needs an installed locale; use its available-language filter.
        $this->filter('get_available_languages', static fn ($languages) => array_unique(array_merge($languages, ['de_DE'])));
        global $wp_locale_switcher;
        $previousSwitcher = $wp_locale_switcher;
        remove_filter('locale', [$previousSwitcher, 'filter_locale']);
        remove_filter('determine_locale', [$previousSwitcher, 'filter_locale']);
        $wp_locale_switcher = new WP_Locale_Switcher();
        $wp_locale_switcher->init();
        $before = get_locale();
        $seen = [];
        $this->filter('wp_mail', static function ($mail) use (&$seen) { $seen[] = get_locale(); return $mail; });
        try {
            self::assertSame('failed', (new Service())->reset_user($this->target, 'link')['status']);
            self::assertSame(['de_DE'], $seen);
            self::assertStringContainsString('lang="de-DE"', $this->mails[0]['message']);
            self::assertSame($before, get_locale());
        } finally {
            $wp_locale_switcher->restore_current_locale();
            remove_filter('locale', [$wp_locale_switcher, 'filter_locale']);
            remove_filter('determine_locale', [$wp_locale_switcher, 'filter_locale']);
            $wp_locale_switcher = $previousSwitcher;
            $wp_locale_switcher->init();
        }
    }

    public function testRtlAndMissingLogoRemainReadable(): void
    {
        $this->filter('locale', static fn () => 'ar');
        global $wp_locale;
        $before = $wp_locale->text_direction;
        $wp_locale->text_direction = 'rtl';
        try {
            $message = Email::message('example.', 'invalidate', '', 'https://example.invalid', ['format'=>'html','logo'=>'custom','logo_id'=>PHP_INT_MAX]);
            self::assertStringContainsString('dir="rtl"', $message['html']);
            self::assertStringNotContainsString('<img', $message['html']);
            self::assertStringContainsString('have been invalidated', $message['text']);
        } finally { $wp_locale->text_direction = $before; }
    }

    public function testMultisitePreferencesAndLogoIdsAreSiteLocal(): void
    {
        if (!is_multisite()) { self::markTestSkipped('Multisite only.'); }
        $this->settings();
        $site = wpmu_create_blog('bpr-email-' . wp_rand(10000,99999) . '.example.test', '/', 'Email isolation', $this->admin);
        self::assertIsInt($site);
        try {
            switch_to_blog($site);
            self::assertSame(Email_Settings::defaults(), Email_Settings::get());
            self::assertSame(0, Email_Settings::snapshot()['logo_id']);
            restore_current_blog();
            self::assertSame('html', Email_Settings::get()['format']);
        } finally {
            if (get_current_blog_id()===$site) { restore_current_blog(); }
            wpmu_delete_blog($site, true);
        }
    }
}
