<?php
use BulkPasswordReset\Email;
use BulkPasswordReset\Email_Settings;
use PHPUnit\Framework\TestCase;

require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';

/** Assemble actual MIME while preventing every delivery attempt. */
final class BprMimeMailer extends PHPMailer\PHPMailer\PHPMailer
{
    public array $messages = [];
    public bool $fail = false;
    public function send(): bool
    {
        if ($this->fail) { throw new PHPMailer\PHPMailer\Exception('Synthetic mail transport failure'); }
        $ok = $this->preSend();
        $this->messages[] = ['mime'=>$this->getSentMIMEMessage(), 'html'=>$this->Body, 'text'=>$this->AltBody, 'type'=>$this->ContentType, 'to'=>$this->getToAddresses(), 'headers'=>$this->getCustomHeaders(), 'mailer'=>$this->Mailer];
        return $ok;
    }
}

final class EmailTransportTest extends TestCase
{
    private mixed $previous;
    private BprMimeMailer $mailer;
    private array $filters = [];

    protected function setUp(): void
    {
        global $phpmailer;
        $this->previous = $phpmailer ?? null;
        $phpmailer = $this->mailer = new BprMimeMailer(true);
        // A test site can use a numeric localhost URL, which is not a mail domain.
        $this->hook('wp_mail_from', static fn () => 'test-sender@example.test');
    }

    protected function tearDown(): void
    {
        foreach ($this->filters as [$name, $fn, $priority]) { remove_filter($name, $fn, $priority); }
        $GLOBALS['phpmailer'] = $this->previous;
    }

    private function hook(string $name, callable $fn, int $priority = 10, int $args = 1): void
    {
        add_filter($name, $fn, $priority, $args); $this->filters[] = [$name,$fn,$priority];
    }

    private function message(string $format = 'html'): array
    {
        return Email::message('example.', 'link', 'café', 'https://example.invalid/not-a-real-key', ['format'=>$format,'logo'=>'none','logo_id'=>0]);
    }

    public function testRealMultipartMimeAndNoStateLeakIntoLaterPlainOrUnrelatedMail(): void
    {
        $html = $this->message();
        self::assertTrue(Email::send('first@example.test', $html));
        $sent = $this->mailer->messages[0];
        self::assertStringContainsString('multipart/alternative', $sent['mime']);
        self::assertStringContainsString('Content-Type: text/plain;', $sent['mime']);
        self::assertStringContainsString('Content-Type: text/html;', $sent['mime']);
        self::assertSame($html['text'], $sent['text']);
        self::assertSame([], $sent['headers']);
        self::assertSame('', $this->mailer->AltBody);
        self::assertTrue(Email::send('second@example.test', $this->message('plain')));
        self::assertTrue(wp_mail('third@example.test', 'Unrelated site message', 'Unrelated body'));
        foreach ([1,2] as $index) {
            self::assertSame('', $this->mailer->messages[$index]['text']);
            self::assertSame('text/plain', $this->mailer->messages[$index]['type']);
            self::assertStringNotContainsString('multipart/alternative', $this->mailer->messages[$index]['mime']);
        }
        self::assertSame([['third@example.test','']], $this->mailer->messages[2]['to']);
        self::assertStringNotContainsString('example.invalid', $this->mailer->messages[2]['mime']);
    }

    public function testMailProviderTransportAndSenderHooksArePreserved(): void
    {
        $observedAltBody = null;
        $this->hook('wp_mail_from', static fn () => 'configured-sender@example.test');
        $this->hook('wp_mail_from_name', static fn () => 'Configured site sender');
        $this->hook('phpmailer_init', static function ($mailer) use (&$observedAltBody) {
            $mailer->isSMTP();
            $mailer->Host = 'localhost';
            $observedAltBody = $mailer->AltBody;
        }, 20);
        self::assertTrue(Email::send('recipient@example.test', $this->message()));
        self::assertSame('smtp', $this->mailer->messages[0]['mailer']);
        self::assertSame('configured-sender@example.test', $this->mailer->From);
        self::assertSame('Configured site sender', $this->mailer->FromName);
        self::assertSame($this->message()['text'], $observedAltBody);
        self::assertSame($this->message()['text'], $this->mailer->messages[0]['text']);
    }

    public function testFailureRemovesAlternativeAndInitializerBeforeNextMail(): void
    {
        $before = $GLOBALS['wp_filter']['phpmailer_init']->callbacks ?? [];
        $this->mailer->fail = true;
        self::assertFalse(Email::send('recipient@example.test', $this->message()));
        self::assertSame('', $this->mailer->AltBody);
        $after = $GLOBALS['wp_filter']['phpmailer_init']->callbacks ?? [];
        self::assertSame($before, $after);
        $this->mailer->fail = false;
        self::assertTrue(wp_mail('unrelated@example.test', 'Unrelated', 'No reset contents'));
        self::assertSame('', $this->mailer->messages[0]['text']);
        self::assertSame('text/plain', $this->mailer->messages[0]['type']);
    }

    public function testShortCircuitApiTransportReceivesHtmlAndNoInitializerRemains(): void
    {
        $captured = [];
        $this->hook('pre_wp_mail', static function ($return, $mail) use (&$captured) { $captured[]=$mail; return true; }, 10, 2);
        self::assertTrue(Email::send('api@example.test', $this->message()));
        self::assertSame([], $this->mailer->messages);
        self::assertSame($this->message()['html'], $captured[0]['message']);
        self::assertSame(['Content-Type: text/html; charset=UTF-8'], $captured[0]['headers']);
        self::assertTrue(wp_mail('unrelated@example.test', 'Unrelated', 'No reset contents'));
        self::assertSame('', $captured[1]['headers']);
    }
}
