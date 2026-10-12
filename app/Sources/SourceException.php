<?php

namespace App\Sources;

class SourceException extends \RuntimeException
{
    public function __construct(string $message, public string $kind = 'erro', public ?int $status = null)
    {
        parent::__construct($message);
    }
}
