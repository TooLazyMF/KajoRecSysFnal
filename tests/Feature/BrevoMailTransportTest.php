<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Tests\TestCase;

class BrevoMailTransportTest extends TestCase
{
    public function test_brevo_mailer_resolves_to_the_api_transport(): void
    {
        config(['mail.mailers.brevo.key' => 'test-api-key']);
        Mail::purge('brevo');

        $this->assertInstanceOf(
            BrevoApiTransport::class,
            Mail::mailer('brevo')->getSymfonyTransport(),
        );
    }

    public function test_brevo_mailer_requires_an_api_key(): void
    {
        config(['mail.mailers.brevo.key' => null]);
        Mail::purge('brevo');

        $this->expectException(InvalidArgumentException::class);

        Mail::mailer('brevo');
    }
}
