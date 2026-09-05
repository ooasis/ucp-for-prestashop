<?php

declare(strict_types=1);

namespace UcpAgent;

class SignatureException extends \RuntimeException
{
    // Reasons mirror the spec's error registry: signature_missing, signature_invalid,
    // key_not_found, digest_mismatch, algorithm_unsupported, coverage_insufficient.
    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail === '' ? $reason : "$reason: $detail");
    }
}
