<?php

namespace App\Exceptions;

use RuntimeException;

/** A business-rule failure that is safe to show to the client. */
class BookingException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
