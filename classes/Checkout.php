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

use Address;
use Cart;
use CartRule;
use Configuration;
use Context;
use Country;
use Customer;
use Db;
use Module;
use Product;
use State;
use StockAvailable;
use Tools;
use Validate;

/**
 * UCP checkout sessions. The session document is protocol-native (port of the
 * conformance-proven Woo/Magento implementation); each session is backed by a
 * real PrestaShop guest cart kept in sync: line items, delivery address,
 * delivery option (Cart::getDeliveryOptionList/setDeliveryOption), and cart
 * rules — so completion places a real order via PaymentModule::validateOrder.
 */
class Checkout
{
    public function __construct(
        private readonly SessionStore $sessions,
        private readonly Profile $profile,
        private readonly Payments $payments,
        private readonly Orders $orders,
    ) {
    }

    // -- storage ------------------------------------------------------------

    /** Load a checkout session document or fail with 404. */
    public function load(string $id): array
    {
        $doc = $this->sessions->get($id);
        if (!$doc) {
            throw new UcpError(404, 'RESOURCE_NOT_FOUND', 'Checkout session not found');
        }
        return $doc;
    }

    // -- endpoints ------------------------------------------------------------

    /** Create a new checkout session document from the request body. */
    public function create(array $body, string $ucpAgent): array
    {
        $doc = [
            'ucp'      => $this->profile->responseEnvelope(),
            'id'       => self::uuid(),
            'status'   => 'incomplete',
            'currency' => (string) Context::getContext()->currency->iso_code,
            'links'    => [],
            'payment'  => ['instruments' => $this->stripCredentials($body['payment']['instruments'] ?? [])],
        ];
        if (isset($body['buyer'])) {
            $doc['buyer'] = $body['buyer'];
        }
        $doc['line_items'] = array_map(
            fn($li) => [
                'id'       => self::uuid(),
                'item'     => ['id' => $li['item']['id'] ?? ''],
                'quantity' => (int) ($li['quantity'] ?? 1),
            ],
            array_values($body['line_items'] ?? [])
        );
        if (!empty($body['fulfillment']['methods'])) {
            $doc['fulfillment'] = [
                'methods' => array_map([$this, 'normalizeMethod'], array_values($body['fulfillment']['methods'])),
            ];
        }
        if (!empty($body['discounts']['codes'])) {
            $doc['discounts'] = ['codes' => array_values($body['discounts']['codes'])];
        }
        $this->attachWebhookUrl($doc, $ucpAgent);
        $this->injectKnownAddresses($doc);
        $cartId = $this->createCart();
        $this->recalculate($doc, $cartId);
        $this->sessions->save($doc['id'], $doc, $cartId);
        return $doc;
    }

    /** Apply partial updates to a session and recalculate it. */
    public function update(string $id, array $body, string $ucpAgent): array
    {
        $doc = $this->load($id);
        $this->assertModifiable($doc);
        if (isset($body['line_items'])) {
            $doc['line_items'] = array_map(
                fn($li) => [
                    'id'       => $li['id'] ?? self::uuid(),
                    'item'     => ['id' => $li['item']['id'] ?? ''],
                    'quantity' => (int) ($li['quantity'] ?? 1),
                ],
                array_values($body['line_items'])
            );
        }
        if (isset($body['buyer'])) {
            $doc['buyer'] = $body['buyer'];
        }
        if (isset($body['payment'])) {
            $doc['payment'] = ['instruments' => $this->stripCredentials($body['payment']['instruments'] ?? [])];
        }
        if (isset($body['fulfillment'])) {
            $doc['fulfillment'] = $this->mergeFulfillment(
                $doc['fulfillment'] ?? ['methods' => []],
                $body['fulfillment']
            );
        }
        if (isset($body['discounts'])) {
            $doc['discounts'] = ['codes' => array_values($body['discounts']['codes'] ?? [])];
        }
        $this->attachWebhookUrl($doc, $ucpAgent);
        $this->injectKnownAddresses($doc);
        $cartId = $this->sessions->cartId($id);
        $this->recalculate($doc, $cartId);
        $this->sessions->save($doc['id'], $doc, $cartId);
        return $doc;
    }

    /** Mark a session as canceled. */
    public function cancel(string $id): array
    {
        $doc = $this->load($id);
        $this->assertModifiable($doc);
        $doc['status'] = 'canceled';
        $this->sessions->save($doc['id'], $doc);
        return $doc;
    }

    /** Charge the payment and mark the session completed. */
    public function complete(string $id, array $body): array
    {
        $doc = $this->load($id);
        $this->assertModifiable($doc);
        if (!$this->isCompletable($doc)) {
            throw new UcpError(
                400,
                'INVALID_REQUEST',
                'Fulfillment address and option must be selected before completion',
                'requires_buyer_input'
            );
        }
        $instrument = $body['payment']['instruments'][0] ?? null;
        if (!$instrument || empty($instrument['handler_id']) || empty($instrument['credential'])) {
            throw new UcpError(
                400,
                'INVALID_REQUEST',
                'A payment instrument with handler_id and credential is required'
            );
        }
        // Re-check stock before charging so payment isn't taken for unfillable items.
        foreach ($doc['line_items'] as $li) {
            $this->assertInStock($this->productId($li['item']['id']), $li['item']['id'], $li['quantity'], 409);
        }
        $total = $this->totalOf($doc['totals']);
        $transactionId = $this->payments->charge($instrument, $total, $doc['currency']);

        $orderUuid = self::uuid();
        $cartId = $this->sessions->cartId($id);
        // Store the entity (keyed by backing cart) before validateOrder: the
        // actionValidateOrderAfter hook resolves it by cart id, attaches the
        // PrestaShop order id, and pushes the order_placed webhook.
        $this->orders->store($orderUuid, $this->orders->buildEntity($doc, $orderUuid), 0, $cartId);
        $this->createPsOrder($doc, $cartId, $instrument, $transactionId);

        $doc['status'] = 'completed';
        $doc['order'] = ['id' => $orderUuid, 'permalink_url' => $this->profile->endpoint() . '/orders/' . $orderUuid];
        $this->sessions->save($doc['id'], $doc);
        return $doc;
    }

    /** Convert the backing cart into a paid PrestaShop order ("Payment accepted"). */
    private function createPsOrder(array $doc, int $cartId, array $instrument, ?string $transactionId): void
    {
        $cart = new Cart($cartId);
        if (!$cart->id || !$cart->id_customer || !$cart->id_address_delivery) {
            throw new UcpError(500, 'INTERNAL_ERROR', 'Backing cart is not ready for order placement');
        }
        $module = Module::getInstanceByName('ucpagent');
        if (!$module || !$module->active) {
            throw new UcpError(500, 'INTERNAL_ERROR', 'ucpagent module unavailable');
        }
        $customer = new Customer((int) $cart->id_customer);
        $ctx = Context::getContext();
        $ctx->cart = $cart;
        $ctx->customer = $customer;
        $method = ($instrument['handler_id'] ?? '') === 'google_pay' ? 'Google Pay via Stripe (UCP)' : 'UCP Agent';
        $note = 'UCP agent checkout ' . $doc['id']
            . ($transactionId ? ' — transaction ' . $transactionId : '');
        // ponytail: PrestaShop's own cart totals may differ from the UCP-agreed
        // totals (discount base includes fulfillment protocol-side) — the UCP
        // entity is authoritative for the agent; reconcile when real merchant
        // accounting needs it. validateOrder gets the cart total so the order
        // never lands in "Payment error".
        $module->validateOrder(
            (int) $cart->id,
            (int) Configuration::get('PS_OS_PAYMENT'),
            (float) $cart->getOrderTotal(true, Cart::BOTH),
            $method,
            $note,
            [],
            (int) $cart->id_currency,
            false,
            $customer->secure_key
        );
    }

    /** Reject modification of completed or canceled sessions. */
    private function assertModifiable(array $doc): void
    {
        if (in_array($doc['status'], ['completed', 'canceled'], true)) {
            throw new UcpError(
                409,
                'CHECKOUT_NOT_MODIFIABLE',
                'Checkout is ' . $doc['status'] . ' and cannot be modified'
            );
        }
    }

    /** Spec: payment credentials must never be echoed back or persisted. */
    private function stripCredentials(array $instruments): array
    {
        return array_map(function ($i) {
            unset($i['credential']);
            return $i;
        }, array_values($instruments));
    }

    // -- PrestaShop product/cart adapter ---------------------------------------

    /** Resolve a UCP item id (product reference, falling back to numeric id) or fail. */
    private function productId(string $itemId): int
    {
        $id = (int) Product::getIdByReference($itemId);
        if (!$id && ctype_digit($itemId)) {
            $id = (int) $itemId;
        }
        if ($id) {
            $product = new Product($id);
            if ($product->id && $product->active) {
                return $id;
            }
        }
        throw new UcpError(400, 'INVALID_REQUEST', 'Product ' . $itemId . ' not found');
    }

    /** Fail unless the requested quantity is available (or backorder is allowed). */
    private function assertInStock(int $idProduct, string $itemId, int $qty, int $httpStatus): void
    {
        $available = StockAvailable::getQuantityAvailableByProduct($idProduct);
        if ($available >= $qty || Product::isAvailableWhenOutOfStock(StockAvailable::outOfStock($idProduct))) {
            return;
        }
        throw new UcpError($httpStatus, 'OUT_OF_STOCK', 'Item ' . $itemId . ' is out of stock');
    }

    /** Create the guest cart backing a new session. */
    private function createCart(): int
    {
        $ctx = Context::getContext();
        $cart = new Cart();
        $cart->id_currency = (int) $ctx->currency->id;
        $cart->id_lang = (int) $ctx->language->id;
        $cart->id_shop = (int) $ctx->shop->id;
        $cart->add();
        return (int) $cart->id;
    }

    /** Rebuild the backing cart's contents from the session line items. */
    private function syncCart(int $cartId, array $lineItems): void
    {
        $cart = new Cart($cartId);
        if (!$cart->id) {
            return; // cart lost; the protocol document stays authoritative
        }
        foreach ($cart->getProducts() as $p) {
            $cart->deleteProduct((int) $p['id_product'], (int) $p['id_product_attribute']);
        }
        foreach ($lineItems as $li) {
            $idProduct = $this->productId($li['item']['id']);
            $idAttr = (int) Product::getDefaultAttribute($idProduct);
            $added = $cart->updateQty($li['quantity'], $idProduct, $idAttr ?: null);
            if ($added !== true) {
                throw new UcpError(400, 'OUT_OF_STOCK', 'Cannot add ' . $li['item']['id'] . ' to cart');
            }
        }
    }

    // -- recalculation pipeline ----------------------------------------------

    /** Recompute line items, fulfillment options, discounts, and totals in place. */
    public function recalculate(array &$doc, int $cartId): void
    {
        $langId = (int) Context::getContext()->language->id;
        $subtotal = 0;
        foreach ($doc['line_items'] as &$li) {
            $idProduct = $this->productId($li['item']['id']);
            $this->assertInStock($idProduct, $li['item']['id'], $li['quantity'], 400);
            $product = new Product($idProduct, false, $langId);
            $price = (int) round(((float) Product::getPriceStatic($idProduct, true, null, 2)) * 100);
            $li['item'] = ['id' => $li['item']['id'], 'title' => (string) $product->name, 'price' => $price];
            $lineTotal = $price * $li['quantity'];
            $li['totals'] = [
                ['type' => 'subtotal', 'amount' => $lineTotal],
                ['type' => 'total', 'amount' => $lineTotal],
            ];
            $subtotal += $lineTotal;
        }
        unset($li);

        // Bind buyer + selected destination to the backing cart before syncing
        // products so PrestaShop computes carrier availability and rates
        // against the real delivery address.
        $cart = new Cart($cartId);
        $idAddress = ($cart->id) ? $this->bindCartDestination($cart, $doc) : 0;
        $this->syncCart($cartId, $doc['line_items']);

        $totals = [['type' => 'subtotal', 'amount' => $subtotal]];
        $fulfillmentTotal = 0;
        if (!empty($doc['fulfillment']['methods'])) {
            foreach ($doc['fulfillment']['methods'] as &$method) {
                $this->recomputeOptions($method, $cart, $idAddress);
                foreach ($method['groups'] ?? [] as $group) {
                    if (empty($group['selected_option_id'])) {
                        continue;
                    }
                    foreach ($group['options'] ?? [] as $opt) {
                        if ($opt['id'] === $group['selected_option_id']) {
                            $amount = $this->totalOf($opt['totals']);
                            $totals[] = ['type' => 'fulfillment', 'amount' => $amount];
                            $fulfillmentTotal += $amount;
                        }
                    }
                }
            }
            unset($method);
        }

        // Discounts: CartRule-backed, sequential on the shrinking
        // (subtotal + fulfillment) base — same math the Magento module passed
        // conformance with. Valid rules are mirrored onto the backing cart.
        if ($cart->id) {
            foreach ($cart->getCartRules() as $cr) {
                $cart->removeCartRule((int) $cr['id_cart_rule']);
            }
        }
        $running = $subtotal + $fulfillmentTotal;
        $applied = [];
        foreach ($doc['discounts']['codes'] ?? [] as $code) {
            $rule = $this->ruleForCode((string) $code, $cart);
            if ($rule === null) {
                continue; // unknown/invalid codes are silently ignored
            }
            [$canonicalCode, $title, $isPercent, $ruleAmount, $ruleId] = $rule;
            $amount = $isPercent
                ? (int) ($running * $ruleAmount / 100)
                : min($running, (int) round($ruleAmount * 100));
            if ($amount <= 0) {
                continue;
            }
            $running -= $amount;
            $applied[] = [
                'code'        => $canonicalCode,
                'title'       => $title,
                'amount'      => $amount,
                'allocations' => [['path' => "$.totals[?(@.type=='subtotal')]", 'amount' => $amount]],
            ];
            $totals[] = ['type' => 'discount', 'amount' => -$amount];
            if ($cart->id) {
                $cart->addCartRule($ruleId);
            }
        }
        if (isset($doc['discounts'])) {
            $doc['discounts']['applied'] = $applied;
        }

        $totals[] = ['type' => 'total', 'amount' => array_sum(array_map(
            fn($t) => $t['type'] === 'total' ? 0 : $t['amount'],
            $totals
        ))];
        $doc['totals'] = $totals;
        $doc['status'] = $this->isCompletable($doc) ? 'ready_for_complete' : 'incomplete';
    }

    /** Resolve a discount code to CartRule data when valid for this cart, else null. */
    private function ruleForCode(string $code, Cart $cart): ?array
    {
        $id = (int) CartRule::getIdByCode($code); // DB collation: case-insensitive
        if (!$id) {
            return null;
        }
        $ctx = Context::getContext();
        $rule = new CartRule($id, (int) $ctx->language->id);
        $valid = true;
        if ($cart->id) {
            $prevCart = $ctx->cart;
            $ctx->cart = $cart;
            $valid = $rule->checkValidity($ctx, false, false);
            $ctx->cart = $prevCart;
        }
        if ($valid !== true) {
            return null;
        }
        $isPercent = (float) $rule->reduction_percent > 0;
        $name = is_array($rule->name) ? (string) reset($rule->name) : (string) $rule->name;
        return [
            (string) $rule->code,
            $name !== '' ? $name : (string) $rule->code,
            $isPercent,
            $isPercent ? (float) $rule->reduction_percent : (float) $rule->reduction_amount,
            $id,
        ];
    }

    /** Whether a fulfillment destination and option have been selected for completion. */
    public function isCompletable(array $doc): bool
    {
        if (empty($doc['line_items'])) {
            return false;
        }
        foreach ($doc['fulfillment']['methods'] ?? [] as $method) {
            if (($method['type'] ?? 'shipping') === 'shipping' && empty($method['selected_destination_id'])) {
                continue;
            }
            foreach ($method['groups'] ?? [] as $group) {
                if (!empty($group['selected_option_id'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Extract the 'total' amount from a totals list. */
    private function totalOf(array $totals): int
    {
        foreach ($totals as $t) {
            if ($t['type'] === 'total') {
                return $t['amount'];
            }
        }
        return 0;
    }

    // -- fulfillment ----------------------------------------------------------

    /** Normalize an incoming fulfillment method to the response shape. */
    private function normalizeMethod(array $m): array
    {
        $method = [
            'id'            => $m['id'] ?? ('method_' . self::uuid()),
            'type'          => $m['type'] ?? 'shipping',
            'line_item_ids' => array_values($m['line_item_ids'] ?? []),
        ];
        if (isset($m['destinations'])) {
            $method['destinations'] = array_map([$this, 'normalizeDestination'], array_values($m['destinations']));
        }
        $method['selected_destination_id'] = $m['selected_destination_id'] ?? null;
        if (isset($m['groups'])) {
            $method['groups'] = array_map(fn($g) => [
                'id'                 => $g['id'] ?? ('group_' . self::uuid()),
                'line_item_ids'      => array_values($g['line_item_ids'] ?? []),
                'selected_option_id' => $g['selected_option_id'] ?? null,
            ], array_values($m['groups']));
        }
        return $method;
    }

    /** Accept SDK aliases (locality/region) and emit the response shape. */
    private function normalizeDestination(array $d): array
    {
        $out = [
            'id'               => $d['id'] ?? ('dest_' . self::uuid()),
            'type'             => 'shipping_address',
            'street_address'   => $d['street_address'] ?? '',
            'address_locality' => $d['address_locality'] ?? $d['locality'] ?? '',
            'address_region'   => $d['address_region'] ?? $d['region'] ?? '',
            'postal_code'      => $d['postal_code'] ?? '',
            'address_country'  => $d['address_country'] ?? '',
        ];
        foreach (['full_name', 'first_name', 'last_name', 'phone_number', 'extended_address'] as $extra) {
            if (isset($d[$extra])) {
                $out[$extra] = $d[$extra];
            }
        }
        return $out;
    }

    /** Hierarchical merge per the spec: match methods by id, replace-if-sent per field. */
    private function mergeFulfillment(array $existing, array $incoming): array
    {
        $result = ['methods' => []];
        $existingMethods = $existing['methods'] ?? [];
        foreach (array_values($incoming['methods'] ?? []) as $in) {
            $match = null;
            foreach ($existingMethods as $ex) {
                if (isset($in['id']) && $ex['id'] === $in['id']) {
                    $match = $ex;
                }
            }
            if ($match === null && !isset($in['id']) && count($existingMethods) === 1) {
                $match = $existingMethods[0];
            }
            $normalized = $this->normalizeMethod($in);
            if ($match) {
                $normalized['id'] = $match['id'];
                if (!isset($in['destinations'])) {
                    $normalized['destinations'] = $match['destinations'] ?? [];
                }
                if (!isset($in['groups']) && isset($match['groups'])) {
                    $normalized['groups'] = $match['groups'];
                }
                if (empty($normalized['line_item_ids'])) {
                    $normalized['line_item_ids'] = $match['line_item_ids'];
                }
            }
            $result['methods'][] = $normalized;
        }
        return $result;
    }

    /**
     * Compute shipping options for a method's selected destination from the
     * store's real carriers (Cart::getDeliveryOptionList against the backing
     * cart), and mirror the selected option onto the cart via
     * Cart::setDeliveryOption so cart totals stay real.
     */
    private function recomputeOptions(array &$method, Cart $cart, int $idAddress): void
    {
        if (($method['type'] ?? 'shipping') !== 'shipping' || empty($method['selected_destination_id'])) {
            return;
        }
        $hasDest = false;
        foreach ($method['destinations'] ?? [] as $d) {
            if ($d['id'] === $method['selected_destination_id']) {
                $hasDest = true;
            }
        }
        if (!$hasDest) {
            return;
        }
        $psOptions = ($cart->id && $idAddress) ? $this->deliveryOptions($cart, $idAddress) : [];
        $options = array_map(fn($o) => [
            'id'     => $o['id'],
            'title'  => $o['title'],
            'totals' => [
                ['type' => 'subtotal', 'amount' => $o['amount']],
                ['type' => 'total', 'amount' => $o['amount']],
            ],
        ], $psOptions);

        if (empty($method['groups'])) {
            $method['groups'] = [[
                'id'                 => 'group_' . self::uuid(),
                'line_item_ids'      => $method['line_item_ids'],
                'selected_option_id' => null,
            ]];
        }
        foreach ($method['groups'] as &$group) {
            $group['options'] = $options;
            // Mirror a real selection onto the cart (unknown ids contribute
            // nothing; PrestaShop falls back to the default carrier at order
            // time).
            foreach ($psOptions as $o) {
                if ($o['id'] === ($group['selected_option_id'] ?? null)) {
                    $cart->setDeliveryOption([$idAddress => $o['key']]);
                    $cart->update();
                }
            }
        }
        unset($group);
    }

    /**
     * The store's delivery options for the cart's address, cheapest first:
     * [id (stable carrier-name slug), title, amount (cents), key (PS option key)].
     */
    private function deliveryOptions(Cart $cart, int $idAddress): array
    {
        $options = [];
        foreach ($cart->getDeliveryOptionList(null, true)[$idAddress] ?? [] as $key => $opt) {
            $names = [];
            foreach ($opt['carrier_list'] ?? [] as $c) {
                $names[] = (string) $c['instance']->name;
            }
            if (!$names) {
                continue;
            }
            $options[] = [
                'id'     => implode('-', array_map([$this, 'slug'], $names)),
                'title'  => implode(' + ', $names),
                'amount' => !empty($opt['is_free'])
                    ? 0
                    : (int) round(((float) ($opt['total_price_with_tax'] ?? 0)) * 100),
                'key'    => (string) $key,
            ];
        }
        usort($options, fn($a, $b) => $a['amount'] <=> $b['amount']);
        return $options;
    }

    /** Stable lowercase-dashed id from a carrier name. */
    private function slug(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'carrier';
    }

    // -- buyer/address binding to the backing cart -----------------------------

    /**
     * Ensure the backing cart carries the buyer's customer and the selected
     * destination as its delivery/invoice address. Returns the address id
     * (0 when no destination is selected or resolvable).
     */
    private function bindCartDestination(Cart $cart, array $doc): int
    {
        $dest = null;
        foreach ($doc['fulfillment']['methods'] ?? [] as $method) {
            if (($method['type'] ?? 'shipping') !== 'shipping' || empty($method['selected_destination_id'])) {
                continue;
            }
            foreach ($method['destinations'] ?? [] as $d) {
                if ($d['id'] === $method['selected_destination_id']) {
                    $dest = $d;
                    break 2;
                }
            }
        }
        if ($dest === null) {
            return 0;
        }
        $customer = $this->ensureCustomer($doc);
        $idAddress = $this->ensureAddress($customer, $dest);
        if (!$idAddress) {
            return 0;
        }
        if ((int) $cart->id_customer !== (int) $customer->id
            || (int) $cart->id_address_delivery !== $idAddress) {
            $cart->id_customer = (int) $customer->id;
            $cart->secure_key = $customer->secure_key;
            $cart->id_address_delivery = $idAddress;
            $cart->id_address_invoice = $idAddress;
            $cart->update();
        }
        return $idAddress;
    }

    /** The PrestaShop customer for the buyer email (guest created on demand). */
    private function ensureCustomer(array $doc): Customer
    {
        $email = (string) ($doc['buyer']['email'] ?? '');
        if (!Validate::isEmail($email)) {
            $email = 'ucp-agent-guest@example.com'; // ponytail: one shared guest for buyer-less sessions
        }
        $rows = Customer::getCustomersByEmail($email);
        if ($rows) {
            return new Customer((int) $rows[0]['id_customer']);
        }
        $customer = new Customer();
        $customer->firstname = $this->psName($doc['buyer']['first_name'] ?? '', 'UCP');
        $customer->lastname = $this->psName($doc['buyer']['last_name'] ?? '', 'Agent');
        $customer->email = $email;
        $customer->passwd = Tools::hash(Tools::passwdGen(24)); // passwd validates as isHashedPassword
        $customer->is_guest = true;
        $customer->add();
        return $customer;
    }

    /** A valid PrestaShop person-name, or the fallback. */
    private function psName(string $value, string $fallback): string
    {
        return ($value !== '' && Validate::isName($value)) ? $value : $fallback;
    }

    /**
     * Resolve a UCP destination to a PrestaShop address id for the customer:
     * stored ids (addr_N) pass through, matching content is reused, anything
     * else is created. Returns 0 when the country is unknown.
     */
    private function ensureAddress(Customer $customer, array $dest): int
    {
        if (preg_match('/^addr_(\d+)$/', (string) $dest['id'], $m)) {
            $address = new Address((int) $m[1]);
            if ($address->id && (int) $address->id_customer === (int) $customer->id) {
                return (int) $address->id;
            }
        }
        $iso = (string) ($dest['address_country'] ?? '');
        // Country::getByIso throws on a malformed iso — treat it as unknown.
        $idCountry = Validate::isLanguageIsoCode($iso) ? (int) Country::getByIso($iso) : 0;
        if (!$idCountry) {
            return 0;
        }
        $street = (string) ($dest['street_address'] ?? '') !== '' ? (string) $dest['street_address'] : '-';
        $city = (string) ($dest['address_locality'] ?? '') !== '' ? (string) $dest['address_locality'] : '-';
        $postcode = (string) ($dest['postal_code'] ?? '');
        $existing = (int) Db::getInstance()->getValue(
            'SELECT id_address FROM `' . _DB_PREFIX_ . 'address` WHERE deleted = 0'
            . ' AND id_customer = ' . (int) $customer->id
            . " AND address1 = '" . pSQL($street) . "'"
            . " AND city = '" . pSQL($city) . "'"
            . " AND postcode = '" . pSQL($postcode) . "'"
            . ' AND id_country = ' . $idCountry
        );
        if ($existing) {
            return $existing;
        }
        $address = new Address();
        $address->alias = 'UCP';
        $address->id_customer = (int) $customer->id;
        $address->firstname = $this->psName((string) ($dest['first_name'] ?? ''), $customer->firstname ?: 'UCP');
        $address->lastname = $this->psName((string) ($dest['last_name'] ?? ''), $customer->lastname ?: 'Agent');
        $address->address1 = $street;
        $address->city = $city;
        $address->postcode = $postcode;
        $address->id_country = $idCountry;
        $address->id_state = (int) State::getIdByIso((string) ($dest['address_region'] ?? ''), $idCountry);
        $address->phone = (string) ($dest['phone_number'] ?? '');
        return $address->add() ? (int) $address->id : 0;
    }

    /** Known-customer address injection: PrestaShop customer matched by buyer email. */
    private function injectKnownAddresses(array &$doc): void
    {
        $email = (string) ($doc['buyer']['email'] ?? '');
        if (!$email || !Validate::isEmail($email) || empty($doc['fulfillment']['methods'])) {
            return;
        }
        // Only real accounts are "known customers" — guests auto-created by
        // ensureCustomer for past agent checkouts must not hijack agent-side
        // destination ids on later sessions.
        $customer = null;
        foreach (Customer::getCustomersByEmail($email) as $row) {
            $candidate = new Customer((int) $row['id_customer']);
            if (!$candidate->is_guest) {
                $customer = $candidate;
                break;
            }
        }
        if ($customer === null) {
            return;
        }
        $stored = [];
        foreach ($customer->getAddresses((int) Context::getContext()->language->id) as $a) {
            if (empty($a['address1'])) {
                continue;
            }
            $stored[] = [
                'id'               => 'addr_' . (int) $a['id_address'],
                'type'             => 'shipping_address',
                'street_address'   => (string) $a['address1'],
                'address_locality' => (string) $a['city'],
                'address_region'   => (string) ($a['state_iso'] ?? ''),
                'postal_code'      => (string) $a['postcode'],
                'address_country'  => (string) Country::getIsoById((int) $a['id_country']),
            ];
        }
        foreach ($doc['fulfillment']['methods'] as &$method) {
            if (($method['type'] ?? 'shipping') !== 'shipping') {
                continue;
            }
            if (empty($method['destinations'])) {
                if ($stored) {
                    $method['destinations'] = array_values($stored);
                }
            } else {
                $this->adoptStoredAddressIds($method, $stored);
            }
        }
        unset($method);
    }

    /** Content-duplicate destinations adopt the stored customer address id. */
    private function adoptStoredAddressIds(array &$method, array $stored): void
    {
        foreach ($method['destinations'] as &$d) {
            foreach ($stored as $s) {
                if ($s['street_address'] === $d['street_address'] && $s['postal_code'] === $d['postal_code']) {
                    if (($method['selected_destination_id'] ?? null) === $d['id']) {
                        $method['selected_destination_id'] = $s['id'];
                    }
                    $d['id'] = $s['id'];
                }
            }
        }
        unset($d);
    }

    /** Attach the webhook URL discovered from the agent's UCP platform profile. */
    private function attachWebhookUrl(array &$doc, string $ucpAgent): void
    {
        $url = $this->profile->webhookUrlFromProfile($this->profile->fetchPlatformProfile($ucpAgent));
        if ($url) {
            $doc['platform'] = ['webhook_url' => $url];
        }
    }

    /** RFC 4122 v4 UUID. */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $hex = bin2hex($b);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
