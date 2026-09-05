<?php

declare(strict_types=1);

namespace UcpAgent;

use Db;

/**
 * Persists UCP-shaped checkout session documents (JSON) keyed by session id,
 * plus the PrestaShop cart backing each session.
 */
class SessionStore
{
    /** Load a session document by id, or null when absent. */
    public function get(string $id): ?array
    {
        $doc = Db::getInstance()->getValue(
            'SELECT doc FROM `' . _DB_PREFIX_ . "ucpagent_session` WHERE id = '" . pSQL($id) . "'"
        );
        return $doc ? json_decode((string) $doc, true) : null;
    }

    /** The PrestaShop cart id backing a session (0 when unknown). */
    public function cartId(string $id): int
    {
        return (int) Db::getInstance()->getValue(
            'SELECT cart_id FROM `' . _DB_PREFIX_ . "ucpagent_session` WHERE id = '" . pSQL($id) . "'"
        );
    }

    /** Insert or update a session document. */
    public function save(string $id, array $doc, int $cartId = 0): void
    {
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ucpagent_session` (id, cart_id, doc, updated_at) VALUES ('
            . "'" . pSQL($id) . "', " . $cartId . ", '" . pSQL(json_encode($doc), true) . "', '"
            . gmdate('Y-m-d H:i:s') . "') "
            . 'ON DUPLICATE KEY UPDATE doc = VALUES(doc), updated_at = VALUES(updated_at)'
            . ($cartId ? ', cart_id = VALUES(cart_id)' : '')
        );
    }
}
