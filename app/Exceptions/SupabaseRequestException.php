<?php

namespace App\Exceptions;

use RuntimeException;

class SupabaseRequestException extends RuntimeException
{
    public function __construct(string $error, public int $status)
    {
        parent::__construct($error);
    }
}
