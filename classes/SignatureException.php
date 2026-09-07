<?php
/**
 * UCP/ACP Agent for PrestaShop
 *
 * @author    ooasis
 * @copyright 2026 Hang Sun (https://github.com/ooasis)
 * @license   https://polyformproject.org/licenses/shield/1.0.0 PolyForm Shield 1.0.0
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
    /** @var string */
    public $reason;

    public function __construct(string $reason, string $detail = '')
    {
        $this->reason = $reason;
        parent::__construct($detail === '' ? $reason : "$reason: $detail");
    }
}
