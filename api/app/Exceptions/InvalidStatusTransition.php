<?php

namespace App\Exceptions;

use App\Enums\DocumentStatus;
use RuntimeException;

class InvalidStatusTransition extends RuntimeException
{
    public function __construct(public readonly DocumentStatus $from, public readonly DocumentStatus $to)
    {
        parent::__construct("Cannot move a document from {$from->value} to {$to->value}.");
    }
}
