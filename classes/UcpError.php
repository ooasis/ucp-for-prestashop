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

/** Thrown to short-circuit a request into a UCP error envelope. */
class UcpError extends \Exception
{
    public function __construct(
        public readonly int $http,
        public readonly string $ucpCode,
        public readonly string $content,
        public readonly string $severity = 'unrecoverable',
    ) {
        parent::__construct($content);
    }
}
