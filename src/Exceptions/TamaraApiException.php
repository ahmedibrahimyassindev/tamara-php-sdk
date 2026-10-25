<?php

namespace TamaraPhpSdk\Exceptions;

class TamaraApiException extends TamaraException
{
    /** @var int */
    protected $httpStatusCode;

    /** @var array */
    protected $responseBody;

    public function __construct(string $message, int $httpStatusCode = 0, array $responseBody = [])
    {
        parent::__construct($message, $httpStatusCode);
        $this->httpStatusCode = $httpStatusCode;
        $this->responseBody   = $responseBody;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}

