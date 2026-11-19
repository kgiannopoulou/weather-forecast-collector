<?php

namespace App\Weather\Exceptions;

use RuntimeException;

class ProviderException extends RuntimeException
{
    public static function misconfigured(string $provider, string $reason): self
    {
        return new self("{$provider} is misconfigured: {$reason}");
    }

    public static function httpError(string $provider, int $status, ?string $detail): self
    {
        return new self(trim("{$provider} responded with HTTP {$status}. ".($detail ?? '')));
    }

    public static function unreachable(string $provider, string $detail): self
    {
        return new self("{$provider} could not be reached: {$detail}");
    }

    public static function invalidPayload(string $provider, string $detail): self
    {
        return new self("{$provider} returned an unexpected payload: {$detail}");
    }
}
