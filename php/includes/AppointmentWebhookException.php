<?php

declare(strict_types=1);

final class AppointmentWebhookException extends RuntimeException
{
    private int $httpStatus;

    public function __construct(string $message, int $httpStatus = 502)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
