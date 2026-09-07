<?php
/**
 * UCP/ACP Agent for PrestaShop
 *
 * @author    ooasis
 * @copyright 2026 ooasis
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * UCP checkout-session endpoints:
 *   POST /ucp/checkout-sessions
 *   GET/PUT /ucp/checkout-sessions/{id}
 *   POST /ucp/checkout-sessions/{id}/complete | /cancel
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpagentCheckoutModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        $id = Tools::getValue('id') ?: null;
        $ucpOp = Tools::getValue('ucp_op') ?: null;
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($id === null) {
            $op = $method === 'POST' ? 'create_checkout' : null;
        } elseif ($ucpOp !== null) {
            $op = ($method === 'POST' && in_array($ucpOp, ['complete', 'cancel'], true))
                ? $ucpOp . '_checkout' : null;
        } else {
            $op = ['GET' => 'get_checkout', 'PUT' => 'update_checkout'][$method] ?? null;
        }
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
