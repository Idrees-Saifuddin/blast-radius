<?php

namespace App\Inject;

interface Gateway
{
    public function charge(): void;
}

class StripeGateway implements Gateway
{
    public function charge(): void
    {
    }
}

class PaypalGateway implements Gateway
{
    public function charge(): void
    {
    }
}

class Mailer
{
    public function send(): void
    {
    }
}

class Logger
{
    public function log(): void
    {
    }
}

function audit(): void
{
}

class Checkout
{
    private Logger $logger;

    public function __construct(private Gateway $gateway, private ?Mailer $mailer = null)
    {
    }

    public function pay(Mailer $direct, $untyped): void
    {
        $this->gateway->charge();
        $this->mailer?->send();
        $this->logger->log();
        $direct->send();
        $local = new Logger;
        $local->log();
        $untyped->send();
        audit();
        strlen('x');
        call_user_func([$this, 'pay']);
        $fn = 'audit';
        $fn();
        $local = 'reassigned';
        $local->log();
    }

    public static function build(): static
    {
        return new static(new StripeGateway);
    }
}

class SpecialCheckout extends Checkout
{
}
