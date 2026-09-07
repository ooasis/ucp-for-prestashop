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

use Configuration;
use Db;

/**
 * UCP order entities: build from a completed checkout, store (mapped to the
 * backing cart and the PrestaShop order), replace (PUT), shipping simulation
 * for the conformance suite, and signed push webhooks. Port of the
 * conformance-proven Magento implementation; hook entry points feed it the
 * PrestaShop order lifecycle (validated / status change / tracking set).
 */
class Orders
{
    /** @var Config */
    private $config;

    /** @var Profile */
    private $profile;

    /** @var SessionStore */
    private $sessions;

    /** @var SsrfGuard */
    private $ssrfGuard;

    public function __construct(Config $config, Profile $profile, SessionStore $sessions, SsrfGuard $ssrfGuard)
    {
        $this->config = $config;
        $this->profile = $profile;
        $this->sessions = $sessions;
        $this->ssrfGuard = $ssrfGuard;
    }

    /** Wire the default dependency graph (for module hooks and controllers). */
    public static function create(): self
    {
        $config = new Config();
        return new self(
            $config,
            new Profile($config, new Payments($config), new SsrfGuard()),
            new SessionStore(),
            new SsrfGuard()
        );
    }

    // -- entity ----------------------------------------------------------------

    /** Build the UCP order entity from a completed checkout doc. */
    public function buildEntity(array $doc, string $orderUuid): array
    {
        $lineItems = array_map(function ($li) {
            return [
                'id'       => $li['id'],
                'item'     => $li['item'],
                'quantity' => ['total' => $li['quantity'], 'fulfilled' => 0],
                'totals'   => $li['totals'],
                'status'   => 'processing',
            ];
        }, $doc['line_items']);

        $expectations = [];
        foreach ($doc['fulfillment']['methods'] ?? [] as $method) {
            $dest = null;
            foreach ($method['destinations'] ?? [] as $d) {
                if ($d['id'] === ($method['selected_destination_id'] ?? null)) {
                    $dest = $d;
                }
            }
            foreach ($method['groups'] ?? [] as $group) {
                if (empty($group['selected_option_id'])) {
                    continue;
                }
                $title = null;
                foreach ($group['options'] ?? [] as $opt) {
                    if ($opt['id'] === $group['selected_option_id']) {
                        $title = $opt['title'];
                    }
                }
                // Expectation line items: checkout items matching the group ids,
                // falling back to all items (group ids may reference client-side ids).
                $items = array_values(array_filter(
                    $doc['line_items'],
                    function ($li) use ($group) {
                        return in_array($li['id'], $group['line_item_ids'] ?? [], true);
                    }
                ));
                if (!$items) {
                    $items = $doc['line_items'];
                }
                $exp = [
                    'id'          => 'exp_' . Checkout::uuid(),
                    'line_items'  => array_map(function ($li) {
                        return ['id' => $li['id'], 'quantity' => $li['quantity']];
                    }, $items),
                    'method_type' => $method['type'] ?? 'shipping',
                    'description' => $title,
                ];
                if ($dest) {
                    $exp['destination'] = array_diff_key($dest, ['id' => 1, 'type' => 1]);
                }
                $expectations[] = $exp;
            }
        }

        return [
            'ucp' => [
                'version'      => Profile::VERSION,
                'capabilities' => [
                    'dev.ucp.shopping.checkout' => [
                        ['name' => 'dev.ucp.shopping.checkout', 'version' => Profile::VERSION],
                    ],
                ],
            ],
            'id'            => $orderUuid,
            'checkout_id'   => $doc['id'],
            'permalink_url' => $this->profile->endpoint() . '/orders/' . $orderUuid,
            'currency'      => $doc['currency'],
            'totals'        => $doc['totals'],
            'line_items'    => $lineItems,
            'fulfillment'   => ['expectations' => $expectations, 'events' => []],
        ];
    }

    // -- storage (ucpagent_order table) ------------------------------------------

    /** Persist the order entity; cart/PrestaShop-order mappings stick once set. */
    public function store(string $orderUuid, array $entity, int $psOrderId = 0, int $cartId = 0): void
    {
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ucpagent_order` (id, cart_id, ps_order_id, entity, updated_at) VALUES ('
            . "'" . pSQL($orderUuid) . "', " . $cartId . ', ' . $psOrderId . ", '"
            . pSQL(json_encode($entity), true) . "', '" . gmdate('Y-m-d H:i:s') . "') "
            . 'ON DUPLICATE KEY UPDATE entity = VALUES(entity), updated_at = VALUES(updated_at)'
            . ($cartId ? ', cart_id = VALUES(cart_id)' : '')
            . ($psOrderId ? ', ps_order_id = VALUES(ps_order_id)' : '')
        );
    }

    /** Load a stored order entity, or null when unknown. */
    public function get(string $orderUuid): ?array
    {
        $entity = Db::getInstance()->getValue(
            'SELECT entity FROM `' . _DB_PREFIX_ . "ucpagent_order` WHERE id = '" . pSQL($orderUuid) . "'"
        );
        return $entity ? json_decode((string) $entity, true) : null;
    }

    /** Load a stored order entity, throwing a 404 UcpError when unknown. */
    public function load(string $orderUuid): array
    {
        $entity = $this->get($orderUuid);
        if (!is_array($entity)) {
            throw new UcpError(404, 'RESOURCE_NOT_FOUND', 'Order not found');
        }
        return $entity;
    }

    /** Replace (PUT) a stored order entity after shape validation. */
    public function replace(string $orderUuid, array $body): array
    {
        $this->load($orderUuid); // 404 when unknown
        $this->validateEntity($body);
        $this->store($orderUuid, $body);
        return $body;
    }

    /** Minimal shape validation for PUT — bad enums/containers must 422. */
    private function validateEntity(array $e): void
    {
        $requiredFields = [
            'ucp', 'id', 'checkout_id', 'permalink_url', 'line_items', 'fulfillment', 'currency', 'totals',
        ];
        foreach ($requiredFields as $k) {
            if (!isset($e[$k])) {
                throw new UcpError(422, 'INVALID_REQUEST', "Order field $k is required");
            }
        }
        if (isset($e['adjustments'])) {
            if (!is_array($e['adjustments']) || ($e['adjustments'] !== [] && array_keys($e['adjustments']) !== range(0, count($e['adjustments']) - 1))) {
                throw new UcpError(422, 'INVALID_REQUEST', 'adjustments must be a list');
            }
            foreach ($e['adjustments'] as $adj) {
                if (isset($adj['status']) && !in_array($adj['status'], ['pending', 'completed', 'failed'], true)) {
                    throw new UcpError(422, 'INVALID_REQUEST', 'Invalid adjustment status');
                }
            }
        }
        if (isset($e['fulfillment']['events']) && !is_array($e['fulfillment']['events'])) {
            throw new UcpError(422, 'INVALID_REQUEST', 'fulfillment.events must be a list');
        }
    }

    /** The order uuid mapped to a backing cart with no PrestaShop order yet. */
    private function uuidByCartId(int $cartId): ?string
    {
        $uuid = Db::getInstance()->getValue(
            'SELECT id FROM `' . _DB_PREFIX_ . 'ucpagent_order` WHERE cart_id = ' . $cartId . ' AND ps_order_id = 0'
        );
        return $uuid ? (string) $uuid : null;
    }

    /** The order uuid mapped to a PrestaShop order id. */
    private function uuidByPsOrderId(int $psOrderId): ?string
    {
        $uuid = Db::getInstance()->getValue(
            'SELECT id FROM `' . _DB_PREFIX_ . 'ucpagent_order` WHERE ps_order_id = ' . $psOrderId
        );
        return $uuid ? (string) $uuid : null;
    }

    /** The platform webhook URL discovered for the checkout that produced this order. */
    private function webhookUrlFor(array $entity): ?string
    {
        $doc = $this->sessions->get((string) ($entity['checkout_id'] ?? ''));
        return $doc['platform']['webhook_url'] ?? null;
    }

    /** Append a 'shipped' fulfillment event covering all line items. */
    private function appendShippedEvent(array &$entity, ?string $trackingNumber = null): void
    {
        $event = [
            'id'          => 'evt_' . Checkout::uuid(),
            'type'        => 'shipped',
            'occurred_at' => gmdate('c'),
            'line_items'  => array_map(
                function ($li) {
                    return ['id' => $li['id'], 'quantity' => $li['quantity']['total']];
                },
                $entity['line_items']
            ),
        ];
        if ($trackingNumber !== null && $trackingNumber !== '') {
            $event['tracking_number'] = $trackingNumber;
        }
        $entity['fulfillment']['events'][] = $event;
    }

    // -- PrestaShop order lifecycle (module hook entry points) --------------------

    /** actionValidateOrderAfter: bind the PrestaShop order and push order_placed. */
    public function onOrderValidated(int $cartId, int $psOrderId): void
    {
        $uuid = $this->uuidByCartId($cartId);
        if (!$uuid || !$psOrderId) {
            return;
        }
        $entity = $this->get($uuid);
        if (!$entity) {
            return;
        }
        $this->store($uuid, $entity, $psOrderId);
        $this->sendWebhook($entity, $this->webhookUrlFor($entity), 'order_placed');
    }

    /** actionOrderStatusPostUpdate: shipped state adds an event; every change pushes. */
    public function onOrderStatusChanged(int $psOrderId, int $statusId): void
    {
        $uuid = $this->uuidByPsOrderId($psOrderId);
        if (!$uuid) {
            return;
        }
        $entity = $this->get($uuid);
        if (!$entity) {
            return;
        }
        $eventType = 'order_updated';
        if ($statusId === (int) Configuration::get('PS_OS_SHIPPING')) {
            $this->appendShippedEvent($entity);
            $this->store($uuid, $entity);
            $eventType = 'order_shipped';
        }
        $this->sendWebhook($entity, $this->webhookUrlFor($entity), $eventType);
    }

    /** actionObjectOrderCarrierUpdateAfter: tracking number set on the order. */
    public function onTrackingSet(int $psOrderId, string $trackingNumber): void
    {
        $uuid = $this->uuidByPsOrderId($psOrderId);
        if (!$uuid || $trackingNumber === '') {
            return;
        }
        $entity = $this->get($uuid);
        if (!$entity) {
            return;
        }
        $this->appendShippedEvent($entity, $trackingNumber);
        $this->store($uuid, $entity);
        $this->sendWebhook($entity, $this->webhookUrlFor($entity), 'order_updated');
    }

    // -- shipping simulation (conformance test hook) -----------------------------

    /**
     * Mark every line item shipped and push the order_shipped webhook.
     *
     * @return array{0: int, 1: array} [http status, body]
     */
    public function simulateShipping(string $orderUuid): array
    {
        $entity = $this->get($orderUuid);
        if (!$entity) {
            return [404, ['error' => 'order not found']];
        }
        $this->appendShippedEvent($entity);
        $this->store($orderUuid, $entity);
        $this->sendWebhook($entity, $this->webhookUrlFor($entity), 'order_shipped');
        return [200, ['status' => 'shipped']];
    }

    // -- webhooks -----------------------------------------------------------------

    /**
     * POST the full order entity to the platform, signed per RFC 9421.
     *
     * Retries up to 3 times on transport error / 5xx with the same
     * Webhook-Id, Webhook-Timestamp, and body.
     */
    public function sendWebhook(array $entity, ?string $url, string $eventType): void
    {
        if (!$url) {
            return;
        }
        // Platform-supplied URL: refuse private/internal targets (SSRF)
        // outside test mode.
        if (!$this->config->simulationSecret() && !$this->ssrfGuard->isPublicUrl($url)) {
            return;
        }
        $body = (string) json_encode($entity);
        $webhookId = Checkout::uuid();
        $timestamp = (string) time();
        $parts = parse_url($url) ?: [];
        $scheme = (string) ($parts['scheme'] ?? 'http');
        $port = $parts['port'] ?? null;
        // @authority must omit default ports (RFC 3986 normalization).
        $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $authority = (string) ($parts['host'] ?? '') . ($port !== null && !$isDefaultPort ? ':' . $port : '');
        $path = (string) ($parts['path'] ?? '') ?: '/';
        $ucpAgent = 'profile="' . $this->profile->baseUrl() . '/.well-known/ucp"';
        $digest = Rfc9421::contentDigest($body);

        $key = $this->profile->privateKey();
        $kid = $this->profile->publicJwk()['kid'];
        $components = ['@method', '@authority', '@path', 'content-digest', 'content-type',
                       'ucp-agent', 'webhook-id', 'webhook-timestamp', 'x-event-type'];
        $params = ';keyid="' . $kid . '"';
        $base = Rfc9421::signatureBase(
            $components,
            ['method' => 'POST', 'authority' => $authority, 'path' => $path],
            [
                'content-digest'    => $digest,
                'content-type'      => 'application/json',
                'ucp-agent'         => $ucpAgent,
                'webhook-id'        => $webhookId,
                'webhook-timestamp' => $timestamp,
                'x-event-type'      => $eventType,
            ],
            $params
        );
        $list = implode(' ', array_map(function ($c) {
            return "\"$c\"";
        }, $components));

        $headers = [
            'Content-Type: application/json',
            'X-Event-Type: ' . $eventType,
            'Webhook-Id: ' . $webhookId,
            'Webhook-Timestamp: ' . $timestamp,
            'Idempotency-Key: ' . $webhookId,
            'UCP-Agent: ' . $ucpAgent,
            'Content-Digest: ' . $digest,
            'Signature-Input: ' . "sig1=($list)$params",
            'Signature: sig1=:' . base64_encode(Rfc9421::signBase($base, $key)) . ':',
        ];

        for ($attempt = 0, $delay = 500000; $attempt < 3; $attempt++, $delay *= 2) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $ok = curl_exec($ch) !== false;
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            // Delivered (2xx) or permanent failure (4xx) — no retry.
            if ($ok && $status < 500) {
                return;
            }
            usleep($delay);
        }
        // ponytail: in-request retry loop blocks the caller up to ~1.5s+timeouts;
        // move to a queued cron delivery (ucpagent_webhook table) when real
        // traffic arrives.
    }
}
