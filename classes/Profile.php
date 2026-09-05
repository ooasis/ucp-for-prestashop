<?php

declare(strict_types=1);

namespace UcpAgent;

use Context;

/**
 * Merchant UCP profile: signing keys, capability declarations, and the
 * platform-profile fetch used for webhook discovery and signature keys.
 * Port of the conformance-proven Magento implementation.
 */
class Profile
{
    public const VERSION = '2026-04-08';

    public const CAPABILITIES = [
        'dev.ucp.shopping.checkout'      => [],
        'dev.ucp.shopping.order'         => [],
        'dev.ucp.shopping.discount'      => ['extends' => ['dev.ucp.shopping.checkout']],
        'dev.ucp.shopping.fulfillment'   => ['extends' => 'dev.ucp.shopping.checkout'],
        'dev.ucp.shopping.buyer_consent' => ['extends' => 'dev.ucp.shopping.checkout'],
    ];

    /** @var array<string, ?array> per-request platform profile cache */
    private static array $profileCache = [];

    public function __construct(
        private readonly Config $config,
        private readonly Payments $payments,
        private readonly SsrfGuard $ssrfGuard,
    ) {
    }

    /** Generate + persist an ES256 signing key pair (JWK) on first use. */
    public function ensureSigningKey(): void
    {
        if ($this->config->signingKey()) {
            return;
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $jwk = Rfc9421::ecPemToJwk($key, '');
        // kid = RFC 7638 thumbprint (lexicographic members crv,kty,x,y)
        $thumb = ['crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x'], 'y' => $jwk['y']];
        $jwk['kid'] = Rfc9421::b64urlEncode(hash('sha256', json_encode($thumb, JSON_UNESCAPED_SLASHES), true));
        $this->config->saveSigningKey($pem, $jwk);
    }

    /** The active public signing key as a JWK. */
    public function publicJwk(): array
    {
        $this->ensureSigningKey();
        return $this->config->signingKey()['jwk'];
    }

    /** Active key first, then retired keys still inside their rotation grace period. */
    public function publishedKeys(): array
    {
        return array_merge([$this->publicJwk()], $this->config->retiredKeys());
    }

    /** Spec rotation: publish a fresh key, keep the old one verifying during grace. */
    public function rotateSigningKey(): void
    {
        $retired = $this->config->retiredKeys();
        array_unshift($retired, $this->publicJwk());
        $this->config->saveRetiredKeys(array_slice($retired, 0, 3));
        $this->config->deleteSigningKey();
        $this->ensureSigningKey();
    }

    /** The active private key in the shape Rfc9421::signBase expects. */
    public function privateKey(): array
    {
        $this->ensureSigningKey();
        $pem = $this->config->signingKey()['pem'];
        return ['kty' => 'EC', 'crv' => 'P-256', 'openssl_key' => openssl_pkey_get_private($pem)];
    }

    /** Store base URL without a trailing slash. */
    public function baseUrl(): string
    {
        return rtrim((string) Context::getContext()->shop->getBaseURL(true), '/');
    }

    /** The UCP REST endpoint URL. */
    public function endpoint(): string
    {
        return $this->baseUrl() . '/ucp';
    }

    /** Capability entries (version/spec/schema) for the business profile. */
    public function capabilityEntries(): array
    {
        $caps = [];
        foreach (self::CAPABILITIES as $name => $extra) {
            $short = str_replace('dev.ucp.shopping.', '', $name);
            $caps[$name] = [[
                'version' => self::VERSION,
                'spec'    => 'https://ucp.dev/' . self::VERSION . '/specification/' . str_replace('_', '-', $short),
                'schema'  => 'https://ucp.dev/' . self::VERSION . '/schemas/shopping/' . $short . '.json',
            ] + $extra];
        }
        return $caps;
    }

    /** The merchant business profile served at /.well-known/ucp. */
    public function businessProfile(): array
    {
        $keys = $this->publishedKeys();
        return [
            'ucp' => [
                'version'  => self::VERSION,
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => self::VERSION,
                        'spec'      => 'https://ucp.dev/' . self::VERSION . '/specification/overview',
                        'transport' => 'rest',
                        'endpoint'  => $this->endpoint(),
                        'schema'    => 'https://ucp.dev/' . self::VERSION . '/services/shopping/openapi.json',
                    ]],
                ],
                'capabilities'     => $this->capabilityEntries(),
                'payment_handlers' => $this->payments->ucpHandlers(),
                'keys'             => $keys,
            ],
            'signing_keys' => $keys,
        ];
    }

    /** The `ucp` envelope embedded in checkout responses. */
    public function responseEnvelope(): array
    {
        $caps = [];
        foreach (array_keys(self::CAPABILITIES) as $name) {
            $caps[$name] = [['name' => $name, 'version' => self::VERSION]];
        }
        return [
            'version'          => self::VERSION,
            'capabilities'     => $caps,
            'payment_handlers' => $this->payments->ucpHandlers(),
        ];
    }

    /**
     * Fetch + cache the platform profile named in the UCP-Agent header. Failure is non-fatal.
     * ponytail: per-request cache only — add a persistent 300s cache when profile
     * fetch latency shows up on real traffic.
     */
    public function fetchPlatformProfile(string $ucpAgent): ?array
    {
        if (!preg_match('/profile="([^"]+)"/', $ucpAgent, $m)) {
            return null;
        }
        $url = $m[1];
        // Agent-supplied URL: refuse private/internal targets (SSRF) outside
        // test mode — the conformance suite's mock servers are on private IPs.
        if (!$this->config->simulationSecret() && !$this->ssrfGuard->isPublicUrl($url)) {
            return null;
        }
        if (array_key_exists($url, self::$profileCache)) {
            return self::$profileCache[$url];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $profile = ($body !== false && $status === 200) ? json_decode((string) $body, true) : null;
        return self::$profileCache[$url] = (is_array($profile) ? $profile : null);
    }

    /** Extract the order webhook URL from a platform profile (first capability config that has one). */
    public function webhookUrlFromProfile(?array $profile): ?string
    {
        $caps = $profile['ucp']['capabilities'] ?? [];
        foreach ($caps as $entries) {
            foreach ((array) $entries as $entry) {
                if (!empty($entry['config']['webhook_url'])) {
                    return $entry['config']['webhook_url'];
                }
            }
        }
        return null;
    }
}
