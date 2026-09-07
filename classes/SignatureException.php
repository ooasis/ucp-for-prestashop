<?php
/**
 * UCP/ACP Agent for PrestaShop
 *
 * @author    ooasis
 * @copyright 2026 ooasis
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace UcpAgent;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SignatureException extends \RuntimeException
{
    // Reasons mirror the spec's error registry: signature_missing, signature_invalid,
    // key_not_found, digest_mismatch, algorithm_unsupported, coverage_insufficient.
    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail === '' ? $reason : "$reason: $detail");
    }
}
