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

use Db;

/**
 * Idempotency-key replay/conflict storage.
 * Same semantics as the conformance-proven Woo/Magento implementations:
 * replay identical requests, 409 on same key with a different request hash.
 */
class Idempotency
{
    /**
     * Look up a previously seen idempotency key.
     *
     * Returns ['status' => int, 'body' => array] to replay, ['conflict' => true]
     * on hash mismatch, or null when the key is new.
     */
    public function check(string $key, string $hash): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT request_hash, response_status, response_body FROM `' . _DB_PREFIX_ . 'ucpagent_idempotency`'
            . " WHERE idem_key = '" . pSQL($key) . "'"
        );
        if (!$row) {
            return null;
        }
        if ($row['request_hash'] !== $hash) {
            return ['conflict' => true];
        }
        return ['status' => (int) $row['response_status'], 'body' => json_decode((string) $row['response_body'], true)];
    }

    /** Persist the response to replay for an idempotency key. */
    public function store(string $key, string $hash, int $status, array $body): void
    {
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ucpagent_idempotency`'
            . ' (idem_key, request_hash, response_status, response_body, created_at) VALUES ('
            . "'" . pSQL($key) . "', '" . pSQL($hash) . "', " . $status . ", '"
            . pSQL(json_encode($body), true) . "', '" . gmdate('Y-m-d H:i:s') . "') "
            . 'ON DUPLICATE KEY UPDATE request_hash = VALUES(request_hash), response_status = VALUES(response_status),'
            . ' response_body = VALUES(response_body), created_at = VALUES(created_at)'
        );
        // ponytail: no purge job — add a cron deleting rows older than 48h when the table grows.
    }

    /** Request hash over operation, resource id, and raw body for conflict detection. */
    public function hash(string $operation, ?string $resourceId, string $rawBody): string
    {
        return hash('sha256', $operation . '|' . ($resourceId ?? '') . '|' . hash('sha256', $rawBody));
    }
}
