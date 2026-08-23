<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\PhpStan\Fixtures;

use ArrayObject;

final class Wallet
{
    public function __construct(
        public readonly string $currency,
        public readonly int $balance,
    ) {
    }

    public function transfer(int $amount, string $reason): void
    {
    }

    public function deposit(int $amount): void
    {
    }

    public function tag(string $label, string ...$extraLabels): void
    {
    }

    public static function compare(self $left, self $right): int
    {
        return $left->balance <=> $right->balance;
    }
}

function addFunds(Wallet $wallet, int $amount): void
{
}

function consume(Wallet $first, Wallet $second): void
{
    new Wallet('EUR', 100); // flagged: positional constructor call
    new Wallet(currency: 'EUR', balance: 100); // ok: fully named
    new Wallet(...['currency' => 'EUR', 'balance' => 100]); // ok: spread

    $first->transfer(50, 'rent'); // flagged: positional method call
    $first->transfer(50, reason: 'rent'); // flagged: partially named
    $first->transfer(amount: 50, reason: 'rent'); // ok: fully named
    $first->deposit(50); // ok: single argument
    $first->tag('a', 'b', 'c'); // ok: variadic signature

    Wallet::compare($first, $second); // flagged: positional static call
    Wallet::compare(left: $first, right: $second); // ok: fully named

    addFunds($first, 5); // flagged: positional call to own function
    addFunds(wallet: $first, amount: 5); // ok: fully named

    new ArrayObject([], ArrayObject::ARRAY_AS_PROPS); // ok: vendor callee
    str_pad('x', 5); // ok: vendor function
}
