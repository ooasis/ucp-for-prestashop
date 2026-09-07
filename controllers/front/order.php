<?php
/**
 * UCP/ACP Agent for PrestaShop
 *
 * @author    ooasis
 * @copyright 2026 ooasis
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * UCP order endpoints:
 *   GET/PUT /ucp/orders/{id}
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpagentOrderModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        $id = Tools::getValue('id') ?: null;
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $op = $id !== null ? (['GET' => 'get_order', 'PUT' => 'update_order'][$method] ?? null) : null;
        if ($op === null) {
            $this->emit(404, ['error' => 'not_found']);
        }

        [$status, $body] = UcpAgent\Dispatcher::create()
            ->dispatch($op, UcpAgent\Dispatcher::currentRequest(), $id);
        $this->emit($status, $body);
    }

    private function emit($status, array $body)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        exit(json_encode($body, JSON_UNESCAPED_SLASHES));
    }
}
