<?php
/**
 * Spike: programmatic cart -> validateOrder() -> "Payment accepted" order.
 * Run inside the container: php /var/www/html/modules/ucpagent/order-test.php
 */
require '/var/www/html/config/config.inc.php';

// Boot the Symfony kernel: validateOrder() resolves services via the container.
// PS8: concrete AppKernel. PS9: AppKernel is abstract, use FrontKernel.
global $kernel;
require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
if (file_exists(_PS_ROOT_DIR_ . '/app/FrontKernel.php')) {
    require_once _PS_ROOT_DIR_ . '/app/FrontKernel.php';
    $kernel = new FrontKernel('prod', false);
} else {
    $kernel = new AppKernel('prod', false);
}
$kernel->boot();

Configuration::updateValue('PS_MAIL_METHOD', 3); // disable email sending (no SMTP in container)

Shop::setContext(Shop::CONTEXT_SHOP, (int) Configuration::get('PS_SHOP_DEFAULT'));
$context = Context::getContext();
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$context->link = new Link();

// Demo customer (pub@prestashop.com) ships with the fixture data.
$customer = Customer::getCustomersByEmail('pub@prestashop.com');
$customer = new Customer((int) ($customer[0]['id_customer'] ?? 0));
if (!$customer->id) {
    fwrite(STDERR, "demo customer not found\n");
    exit(1);
}
$context->customer = $customer;

$addresses = $customer->getAddresses((int) $context->language->id);
if (!$addresses) {
    fwrite(STDERR, "demo customer has no addresses\n");
    exit(1);
}
$idAddress = (int) $addresses[0]['id_address'];

// Build the cart server-side: product + addresses + carrier.
$cart = new Cart();
$cart->id_customer = (int) $customer->id;
$cart->id_address_delivery = $idAddress;
$cart->id_address_invoice = $idAddress;
$cart->id_lang = (int) $context->language->id;
$cart->id_currency = (int) $context->currency->id;
$cart->id_shop = (int) $context->shop->id;
$cart->secure_key = $customer->secure_key;
$cart->add();
$context->cart = $cart;

$idProduct = (int) Db::getInstance()->getValue(
    'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active=1 AND available_for_order=1 ORDER BY id_product'
);
$idAttr = (int) Product::getDefaultAttribute($idProduct);
$added = $cart->updateQty(1, $idProduct, $idAttr ?: null);
if ($added !== true) {
    fwrite(STDERR, "updateQty failed for product $idProduct attr $idAttr: " . var_export($added, true) . "\n");
    exit(1);
}

$options = $cart->getDeliveryOptionList();
$optionKeys = array_keys($options[$idAddress] ?? []);
if (!$optionKeys) {
    fwrite(STDERR, "no delivery options for address $idAddress\n");
    exit(1);
}
$cart->setDeliveryOption([$idAddress => $optionKeys[0]]);
$cart->update();

$module = Module::getInstanceByName('ucpagent');
if (!$module || !$module->active) {
    fwrite(STDERR, "ucpagent module not installed/active\n");
    exit(1);
}

$total = (float) $cart->getOrderTotal(true, Cart::BOTH);
$module->validateOrder(
    (int) $cart->id,
    (int) Configuration::get('PS_OS_PAYMENT'),
    $total,
    'UCP Agent',
    null,
    [],
    (int) $context->currency->id,
    false,
    $customer->secure_key
);

$order = new Order((int) $module->currentOrder);
$state = new OrderState((int) $order->getCurrentState(), (int) $context->language->id);
echo json_encode([
    'ps_version' => _PS_VERSION_,
    'cart_id' => (int) $cart->id,
    'order_id' => (int) $order->id,
    'reference' => $order->reference,
    'carrier_id' => (int) $order->id_carrier,
    'total_paid' => $order->total_paid,
    'state' => is_array($state->name) ? reset($state->name) : $state->name,
    'payment' => $order->payment,
], JSON_PRETTY_PRINT) . "\n";
