<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMail extends Command
{
    protected $signature = 'mail:test
                            {address? : Where to send the test message. Defaults to MAIL_FROM_ADDRESS.}
                            {--transport= : Override the mailer, e.g. smtp or log, for this run only.}';

    protected $description = 'Send a test email to prove the mail configuration actually delivers';

    public function handle(): int
    {
        $mailer = $this->option('transport') ?: config('mail.default');
        $from = config('mail.from.address');

        $this->line("  mailer    <info>{$mailer}</info>");
        $this->line("  host      ".config("mail.mailers.{$mailer}.host", '(n/a)'));
        $this->line("  port      ".config("mail.mailers.{$mailer}.port", '(n/a)'));
        $this->line("  encryption".config("mail.mailers.{$mailer}.scheme", '(n/a)'));
        $this->line("  from      <info>{$from}</info>");
        $this->newLine();

        $target = $this->argument('address') ?: $from;

        if (! $target) {
            $this->error('No address given and MAIL_FROM_ADDRESS is empty.');

            return self::FAILURE;
        }

        // Caught and reported rather than allowed to escape as a stack trace:
        // an SMTP failure is the whole point of this command, and a raw
        // exception tells you less than the message the transport gives.
        try {
            Mail::mailer($mailer)->raw(
                'This is a test message from TraMatch.',
                function ($message) use ($target, $from): void {
                    $message->to($target)->subject('TraMatch mail test');
                }
            );
        } catch (\Throwable $e) {
            $this->error('Delivery failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$target}.");

        if ($mailer === 'log') {
            $this->newLine();
            $this->warn("MAIL_MAILER is 'log'. Nothing was actually delivered --");
            $this->warn('it was written to storage/logs/laravel.log instead.');
            $this->warn('Set MAIL_MAILER=smtp before calling this a pass.');
        }

        return self::SUCCESS;
    }
}