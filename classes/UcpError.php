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
    /** @var int */
    public $http;

    /** @var string */
    public $ucpCode;

    /** @var string */
    public $content;

    /** @var string */
    public $severity;

    public function __construct(int $http, string $ucpCode, string $content, string $severity = 'unrecoverable')
    {
        $this->http = $http;
        $this->ucpCode = $ucpCode;
        $this->content = $content;
        $this->severity = $severity;
        parent::__construct($content);
    }
}
