<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        private readonly int $available,
        private readonly int $required,
        string $message = 'Solde insuffisant pour acheter ce forfait eSIM.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function available(): int
    {
        return $this->available;
    }

    public function required(): int
    {
        return $this->required;
    }

    public function businessCode(): string
    {
        return 'INSUFFICIENT_BALANCE';
    }

    public function statusCode(): int
    {
        return 402;
    }
}
