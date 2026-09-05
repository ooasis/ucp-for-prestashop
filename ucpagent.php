<?php
/**
 * UCP/ACP Agent for PrestaShop — agentic checkout via the Universal Commerce
 * Protocol. Protocol core ported from the conformance-proven Magento module:
 * RFC 9421 signatures, profile + JWKs, checkout-session state machine,
 * idempotency replay/409, date-based version negotiation.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

spl_autoload_register(function ($class) {
    if (strpos($class, 'UcpAgent\\') === 0) {
        $file = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

class Ucpagent extends PaymentModule
{
    private const CONFIG_KEYS = [
        'UCPAGENT_ENABLED',
        'UCPAGENT_STRICT_SIGNATURES',
        'UCPAGENT_SIMULATION_SECRET',
        'UCPAGENT_STRIPE_SECRET_KEY',
        'UCPAGENT_STRIPE_PUBLISHABLE_KEY',
        'UCPAGENT_STRIPE_API_BASE',
    ];

    public function __construct()
    {
        $this->name = 'ucpagent';
        $this->tab = 'payments_gateways';
        $this->version = '0.2.0';
        $this->author = 'ooasis';
        $this->need_instance = 0;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'UCP/ACP Agent';
        $this->description = 'Agentic checkout via UCP: /.well-known/ucp profile, signed requests (RFC 9421), checkout sessions.';
    }

    public function install()
    {
        return parent::install()
            && $this->createTables()
            && $this->registerHook('moduleRoutes')
            && $this->registerHook('actionValidateOrderAfter')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('actionObjectOrderCarrierUpdateAfter')
            && Configuration::updateValue('UCPAGENT_ENABLED', 1);
    }

    public function uninstall()
    {
        foreach (self::CONFIG_KEYS as $key) {
            Configuration::deleteByName($key);
        }
        foreach (['ucpagent_session', 'ucpagent_idempotency', 'ucpagent_key', 'ucpagent_webhook', 'ucpagent_order'] as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }
        return parent::uninstall();
    }

    private function createTables()
    {
        $engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';
        $sql = [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ucpagent_session` (
                `id` VARCHAR(64) NOT NULL,
                `cart_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `doc` LONGTEXT NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ucpagent_idempotency` (
                `idem_key` VARCHAR(191) NOT NULL,
                `request_hash` VARCHAR(64) NOT NULL,
                `response_status` SMALLINT UNSIGNED NOT NULL,
                `response_body` LONGTEXT NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`idem_key`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ucpagent_key` (
                `id_key` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kid` VARCHAR(64) NOT NULL,
                `pem` TEXT NULL,
                `jwk` TEXT NOT NULL,
                `status` VARCHAR(16) NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_key`),
                KEY `status` (`status`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ucpagent_order` (
                `id` VARCHAR(64) NOT NULL,
                `cart_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `ps_order_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entity` LONGTEXT NOT NULL,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `cart_id` (`cart_id`),
                KEY `ps_order_id` (`ps_order_id`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
            // Outbound order webhook queue — unused; webhooks are delivered
            // in-request with retries (see Orders::sendWebhook).
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ucpagent_webhook` (
                `id_webhook` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `url` VARCHAR(2048) NOT NULL,
                `event_type` VARCHAR(64) NOT NULL,
                `body` LONGTEXT NOT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_webhook`)
            ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4',
        ];
        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return true;
    }

    /**
     * UCP REST surface via the Dispatcher:
     *   GET  /.well-known/ucp                       → wellknown
     *   POST /ucp/checkout-sessions                 → checkout (create)
     *   GET/PUT /ucp/checkout-sessions/{id}         → checkout (view/update)
     *   POST /ucp/checkout-sessions/{id}/complete   → checkout (complete)
     *   POST /ucp/checkout-sessions/{id}/cancel     → checkout (cancel)
     *   GET/PUT /ucp/orders/{id}                    → order (view/update)
     *   POST /testing/simulate-shipping/{id}        → simulate (test hook)
     */
    public function hookModuleRoutes()
    {
        $params = ['fc' => 'module', 'module' => 'ucpagent'];
        $id = ['regexp' => '[A-Za-z0-9\-]+', 'param' => 'id'];
        return [
            'module-ucpagent-wellknown' => [
                'rule' => '.well-known/ucp',
                'keywords' => [],
                'controller' => 'wellknown',
                'params' => $params,
            ],
            'module-ucpagent-checkout-create' => [
                'rule' => 'ucp/checkout-sessions',
                'keywords' => [],
                'controller' => 'checkout',
                'params' => $params,
            ],
            'module-ucpagent-checkout-id' => [
                'rule' => 'ucp/checkout-sessions/{id}',
                'keywords' => ['id' => $id],
                'controller' => 'checkout',
                'params' => $params,
            ],
            'module-ucpagent-checkout-op' => [
                'rule' => 'ucp/checkout-sessions/{id}/{ucp_op}',
                'keywords' => [
                    'id' => $id,
                    'ucp_op' => ['regexp' => 'complete|cancel', 'param' => 'ucp_op'],
                ],
                'controller' => 'checkout',
                'params' => $params,
            ],
            'module-ucpagent-orders' => [
                'rule' => 'ucp/orders/{id}',
                'keywords' => ['id' => $id],
                'controller' => 'order',
                'params' => $params,
            ],
            'module-ucpagent-simulate' => [
                'rule' => 'testing/simulate-shipping/{id}',
                'keywords' => ['id' => $id],
                'controller' => 'simulate',
                'params' => $params,
            ],
        ];
    }

    // -- order lifecycle → signed UCP order webhooks -----------------------------

    /** Order persisted (fires inside validateOrder, after full persistence). */
    public function hookActionValidateOrderAfter($params)
    {
        $order = $params['order'] ?? null;
        $cart = $params['cart'] ?? null;
        if ($order && $cart) {
            UcpAgent\Orders::create()->onOrderValidated((int) $cart->id, (int) $order->id);
        }
    }

    /** Order status changed (shipped adds a fulfillment event). */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $status = $params['newOrderStatus'] ?? null;
        if ($status && !empty($params['id_order'])) {
            UcpAgent\Orders::create()->onOrderStatusChanged((int) $params['id_order'], (int) $status->id);
        }
    }

    /** Tracking number set on the order's carrier record. */
    public function hookActionObjectOrderCarrierUpdateAfter($params)
    {
        $oc = $params['object'] ?? null;
        if ($oc && !empty($oc->id_order)) {
            UcpAgent\Orders::create()->onTrackingSet((int) $oc->id_order, (string) $oc->tracking_number);
        }
    }

    // -- admin configuration ---------------------------------------------------

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitUcpagent')) {
            $config = new UcpAgent\Config();
            Configuration::updateValue('UCPAGENT_ENABLED', (int) Tools::getValue('UCPAGENT_ENABLED'));
            Configuration::updateValue('UCPAGENT_STRICT_SIGNATURES', (int) Tools::getValue('UCPAGENT_STRICT_SIGNATURES'));
            foreach (['UCPAGENT_SIMULATION_SECRET', 'UCPAGENT_STRIPE_SECRET_KEY'] as $key) {
                Configuration::updateValue($key, $config->encrypt(trim((string) Tools::getValue($key))));
            }
            Configuration::updateValue(
                'UCPAGENT_STRIPE_PUBLISHABLE_KEY',
                trim((string) Tools::getValue('UCPAGENT_STRIPE_PUBLISHABLE_KEY'))
            );
            $output .= $this->displayConfirmation($this->l('Settings updated'));
        }
        return $output . $this->renderForm();
    }

    private function renderForm()
    {
        $config = new UcpAgent\Config();
        $switch = fn($label) => [
            'values' => [
                ['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')],
                ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
            ],
            'type' => 'switch',
            'label' => $label,
            'is_bool' => true,
        ];
        $form = [[
            'form' => [
                'legend' => ['title' => $this->l('UCP Agent'), 'icon' => 'icon-cogs'],
                'input' => [
                    $switch($this->l('Enable UCP endpoints')) + ['name' => 'UCPAGENT_ENABLED'],
                    $switch($this->l('Require signed requests')) + [
                        'name' => 'UCPAGENT_STRICT_SIGNATURES',
                        'desc' => $this->l('Reject requests without an RFC 9421 signature. Signed requests are always verified.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Simulation secret'),
                        'name' => 'UCPAGENT_SIMULATION_SECRET',
                        'desc' => $this->l('Enables the mock payment handler for conformance testing. Leave empty in production.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Stripe secret key'),
                        'name' => 'UCPAGENT_STRIPE_SECRET_KEY',
                        'desc' => $this->l('When set, the Google Pay (via Stripe) payment handler is advertised to agents.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Stripe publishable key'),
                        'name' => 'UCPAGENT_STRIPE_PUBLISHABLE_KEY',
                    ],
                ],
                'submit' => ['title' => $this->l('Save')],
            ],
        ]];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitUcpagent';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = [
            'UCPAGENT_ENABLED' => (int) Configuration::get('UCPAGENT_ENABLED'),
            'UCPAGENT_STRICT_SIGNATURES' => (int) Configuration::get('UCPAGENT_STRICT_SIGNATURES'),
            'UCPAGENT_SIMULATION_SECRET' => $config->simulationSecret(),
            'UCPAGENT_STRIPE_SECRET_KEY' => $config->stripeSecretKey(),
            'UCPAGENT_STRIPE_PUBLISHABLE_KEY' => $config->stripePublishableKey(),
        ];
        return $helper->generateForm($form);
    }
}
