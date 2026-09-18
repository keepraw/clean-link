<?php

declare(strict_types=1);

namespace CleanLink;

use RuntimeException;

final class AppError extends RuntimeException
{
    /** @var string */
    public $errorCode;

    /** @var int */
    public $httpStatus;

    public function __construct(string $errorCode, string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }
}
