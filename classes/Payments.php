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

use Configuration;
use Context;

/**
 * Payment handler registry (port of the conformance-proven Woo/Magento one).
 *
 * Handlers:
 *  - mock_payment_handler — token semantics for testing/conformance; advertised
 *    only while a simulation secret is configured (i.e. test mode).
 *  - google_pay (UCP com.google.pay) — Google Pay instruments tokenized with
 *    gateway=stripe; charged as a Stripe PaymentIntent via direct form-encoded
 *    HTTP (no Stripe SDK dependency).
 */
class Payments
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /** Charge an instrument. Returns a PSP transaction id, or null (mock). Throws UcpError. */
    public function charge(array $instrument, int $amount, string $currency): ?string
    {
        $cred = $instrument['credential'] ?? [];
        switch ($instrument['handler_id'] ?? '') {
            case 'mock_payment_handler':
                // Chargeable only while advertised (test mode): otherwise a
                // production agent could "pay" with mock tokens for free.
                if (!$this->config->simulationSecret()) {
                    throw new UcpError(400, 'INVALID_REQUEST', 'Unknown payment handler mock_payment_handler');
                }
                $this->chargeMock($cred);
                return null;
            case 'google_pay':
                // Two calls: tok_ -> PaymentMethod -> confirmed PaymentIntent.
                $pm = $this->stripeRequest('/v1/payment_methods', [
                    'type' => 'card',
                    'card' => ['token' => $this->gpayStripeToken($cred['token'] ?? '')],
                ]);
                return $this->chargeStripe(['payment_method' => $pm['id'] ?? ''], $amount, $currency);
        }
        throw new UcpError(400, 'INVALID_REQUEST', 'Unknown payment handler ' . ($instrument['handler_id'] ?? ''));
    }

    /** Mock handler token semantics: success, insufficient funds, fraud, unknown. */
    private function chargeMock(array $cred): void
    {
        if (($cred['type'] ?? '') === 'card') {
            return; // mock: any raw card succeeds
        }
        match ($cred['token'] ?? '') {
            'success_token' => null,
            'fail_token'    => throw new UcpError(
                402,
                'INSUFFICIENT_FUNDS',
                'Payment Failed: Insufficient Funds (Mock)'
            ),
            'fraud_token'   => throw new UcpError(403, 'FRAUD_DETECTED', 'Payment Failed: Fraud Detected (Mock)'),
            default         => throw new UcpError(402, 'UNKNOWN_TOKEN', 'Payment Failed: Unknown Token (Mock)'),
        };
    }

    /** GPay tokenizationData.token for gateway=stripe is a JSON Stripe Token object (or a bare tok_). */
    private function gpayStripeToken(string $token): string
    {
        $parsed = json_decode($token, true);
        return is_array($parsed) && isset($parsed['id']) ? $parsed['id'] : $token;
    }

    /** Create and confirm a Stripe PaymentIntent for the amount. */
    private function chargeStripe(array $params, int $amount, string $currency): string
    {
        $params += [
            'amount'   => $amount,
            'currency' => strtolower($currency),
            'confirm'  => 'true',
            // Agent checkouts are server-to-server: redirect-based methods are impossible.
            'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
        ];
        [$http, $body] = $this->stripeRequest('/v1/payment_intents', $params, true);
        return $this->mapStripeResponse($http, $body);
    }

    /**
     * Form-encoded POST to Stripe.
     * Returns [http_code, decoded_body] when $raw, else the body (throwing on any error).
     */
    private function stripeRequest(string $path, array $params, bool $raw = false): array
    {
        $key = $this->config->stripeSecretKey();
        if (!$key) {
            throw new UcpError(400, 'INVALID_REQUEST', 'Stripe is not configured on this store');
        }
        $ch = curl_init($this->config->stripeApiBase() . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new UcpError(502, 'PAYMENT_FAILED', 'Payment processor unreachable: ' . $err);
        }
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $body = json_decode((string) $response, true) ?: [];
        if ($raw) {
            return [$http, $body];
        }
        if ($http >= 400 || isset($body['error'])) {
            $this->mapStripeResponse($http, $body); // throws with the right classification
        }
        return $body;
    }

    /** Split out so decline mapping is unit-testable without a network. */
    public function mapStripeResponse(int $http, array $body): string
    {
        if ($http < 400 && isset($body['id'])) {
            $status = $body['status'] ?? 'succeeded';
            if (in_array($status, ['succeeded', 'processing', 'requires_capture'], true)) {
                return $body['id'];
            }
            throw new UcpError(
                402,
                'PAYMENT_DECLINED',
                'Payment not completed: intent status ' . $status,
                'requires_buyer_input'
            );
        }
        $err = $body['error'] ?? [];
        if (($err['type'] ?? '') === 'card_error') {
            $reason = $err['decline_code'] ?? $err['code'] ?? 'card_declined';
            throw new UcpError(
                402,
                'PAYMENT_DECLINED',
                'Payment declined: ' . $reason . ' — ' . ($err['message'] ?? ''),
                'requires_buyer_input'
            );
        }
        throw new UcpError(502, 'PAYMENT_FAILED', 'Payment processor error: ' . ($err['message'] ?? "HTTP $http"));
    }

    /** The payment_handlers map for the UCP business profile and checkout response envelope. */
    public function ucpHandlers(): array
    {
        $handlers = [];
        if ($this->config->simulationSecret()) {
            $handlers['dev.mock.payment_handler'] = [[
                'id'      => 'mock_payment_handler',
                'name'    => 'mock_payment_handler',
                'version' => Profile::VERSION,
                'spec'    => 'https://ucp.dev/' . Profile::VERSION . '/schemas/mock_payment_handler/spec',
                'config'  => ['mode' => 'mock'],
            ]];
        }
        if ($this->config->stripeSecretKey()) {
            $gateway = ['gateway' => 'stripe'];
            if ($this->config->stripePublishableKey()) {
                $gateway['stripe:version'] = '2018-10-31';
                $gateway['stripe:publishableKey'] = $this->config->stripePublishableKey();
            }
            $baseUrl = rtrim((string) Context::getContext()->shop->getBaseURL(true), '/');
            $handlers['com.google.pay'] = [[
                'id'                 => 'google_pay',
                'name'               => 'com.google.pay',
                'version'            => Profile::VERSION,
                'spec'               => 'https://developers.google.com/merchant/ucp/guides/google-pay-payment-handler',
                'config_schema'      => 'https://pay.google.com/gp/p/ucp/' . Profile::VERSION . '/schemas/config.json',
                'instrument_schemas' => [
                    'https://pay.google.com/gp/p/ucp/' . Profile::VERSION . '/schemas/card_payment_instrument.json',
                ],
                'config'             => [
                    'api_version'       => 2,
                    'api_version_minor' => 0,
                    'merchant_info'     => [
                        'merchant_name'   => (string) Configuration::get('PS_SHOP_NAME'),
                        'merchant_id'     => 'TEST',
                        'merchant_origin' => (string) parse_url($baseUrl, PHP_URL_HOST),
                    ],
                    'allowed_payment_methods' => [[
                        'type'       => 'CARD',
                        'parameters' => [
                            'allowedAuthMethods'  => ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
                            'allowedCardNetworks' => ['VISA', 'MASTERCARD', 'AMEX', 'DISCOVER'],
                        ],
                        'tokenization_specification' => [[
                            'type'       => 'PAYMENT_GATEWAY',
                            'parameters' => [$gateway],
                        ]],
                    ]],
                ],
            ]];
        }
        return $handlers;
    }
}
