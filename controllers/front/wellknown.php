<?php
/**
 * UCP/ACP Agent for PrestaShop
 *
 * @author    ooasis
 * @copyright 2026 ooasis
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/** GET /.well-known/ucp — merchant UCP business profile. */
if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpagentWellknownModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        parent::init();
        $config = new UcpAgent\Config();
        if (!$config->isEnabled()) {
            $this->emit(404, ['error' => 'not_found']);
        }
        $payments = new UcpAgent\Payments($config);
        $profile = new UcpAgent\Profile($config, $payments, new UcpAgent\SsrfGuard());
        try {
            $this->emit(200, $profile->businessProfile());
        } catch (Throwable $e) {
            $this->emit(500, ['error' => $e->getMessage()]);
        }
    }

    private function emit($status, array $body)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        exit(json_encode($body, JSON_UNESCAPED_SLASHES));
    }
}
