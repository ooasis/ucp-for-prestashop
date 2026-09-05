<?php
/**
 * POST /testing/simulate-shipping/{id} — conformance test hook,
 * gated by the Simulation-Secret header.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpagentSimulateModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        $config = new UcpAgent\Config();
        $secret = $config->simulationSecret();
        if (!$secret || !$config->isEnabled()) {
            $this->emit(500, ['error' => 'simulation secret not configured']);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || !hash_equals($secret, (string) ($_SERVER['HTTP_SIMULATION_SECRET'] ?? ''))) {
            $this->emit(403, ['error' => 'forbidden']);
        }
        [$status, $body] = UcpAgent\Orders::create()->simulateShipping((string) Tools::getValue('id'));
        $this->emit($status, $body);
    }

    private function emit($status, array $body)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        exit(json_encode($body, JSON_UNESCAPED_SLASHES));
    }
}
