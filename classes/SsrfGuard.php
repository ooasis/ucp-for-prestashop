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

/**
 * Guards outbound requests to attacker-influenced URLs (platform profile
 * fetches, order webhooks) against SSRF into private/internal networks.
 */
class SsrfGuard
{
    /** Whether the URL is http(s) and resolves to a public (non-private, non-reserved) IP address. */
    public function isPublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $ip = gethostbyname($host);
            if ($ip === $host) {
                return false; // unresolvable
            }
        }
        // ponytail: resolve-then-fetch leaves a DNS-rebinding window; pin the
        // resolved IP into the HTTP client when hardening further.
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
