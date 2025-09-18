<?php

namespace App\Message;

class ProcessWaczMessage
{
    public function __construct(
        private readonly int $waczRequestId
    ) {}

    public function getWaczRequestId(): int
    {
        return $this->waczRequestId;
    }
}