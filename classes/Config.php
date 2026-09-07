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
use PhpEncryption;

/**
 * Admin settings (ps_configuration) + signing-key storage (ucpagent_key table).
 * Secrets and the signing key PEM are encrypted at rest via the core
 * PhpEncryption (Defuse) keyed by _NEW_COOKIE_KEY_.
 */
class Config
{
    /** @var PhpEncryption|null */
    private $encryptor;

    private function encryptor(): PhpEncryption
    {
        if ($this->encryptor === null) {
            $this->encryptor = new PhpEncryption(_NEW_COOKIE_KEY_);
        }
        return $this->encryptor;
    }

    public function encrypt(string $plain): string
    {
        return $plain === '' ? '' : (string) $this->encryptor()->encrypt($plain);
    }

    public function decrypt(string $cipher): string
    {
        if ($cipher === '') {
            return '';
        }
        $plain = $this->encryptor()->decrypt($cipher);
        return is_string($plain) ? $plain : '';
    }

    public function isEnabled(): bool
    {
        return (bool) Configuration::get('UCPAGENT_ENABLED');
    }

    /** Whether requests with missing signatures are rejected. */
    public function strictSignatures(): bool
    {
        return (bool) Configuration::get('UCPAGENT_STRICT_SIGNATURES');
    }

    /** Shared secret accepted for simulated (test) payment credentials. */
    public function simulationSecret(): string
    {
        return $this->decrypt((string) Configuration::get('UCPAGENT_SIMULATION_SECRET'));
    }

    public function stripeSecretKey(): string
    {
        return $this->decrypt((string) Configuration::get('UCPAGENT_STRIPE_SECRET_KEY'));
    }

    public function stripePublishableKey(): string
    {
        return (string) Configuration::get('UCPAGENT_STRIPE_PUBLISHABLE_KEY');
    }

    /** Dev/test override (e.g. stripe-mock); set via Configuration, no UI field. */
    public function stripeApiBase(): string
    {
        return rtrim((string) (Configuration::get('UCPAGENT_STRIPE_API_BASE') ?: 'https://api.stripe.com'), '/');
    }

    // -- signing keys (ucpagent_key table) -----------------------------------

    /**
     * Current signing key with the PEM decrypted, or null if none has been generated.
     *
     * @return array{pem: string, jwk: array}|null
     */
    public function signingKey(): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT pem, jwk FROM `' . _DB_PREFIX_ . "ucpagent_key` WHERE status = 'active'"
        );
        if (!$row) {
            return null;
        }
        return ['pem' => $this->decrypt((string) $row['pem']), 'jwk' => json_decode((string) $row['jwk'], true)];
    }

    /** Persist the signing key, encrypting the PEM at rest. */
    public function saveSigningKey(string $pem, array $jwk): void
    {
        Db::getInstance()->insert('ucpagent_key', [
            'kid'        => pSQL($jwk['kid']),
            'pem'        => pSQL($this->encrypt($pem)),
            'jwk'        => pSQL(json_encode($jwk)),
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function deleteSigningKey(): void
    {
        Db::getInstance()->delete('ucpagent_key', "status = 'active'");
    }

    /** Retired public JWKs kept so previously signed responses stay verifiable. */
    public function retiredKeys(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT jwk FROM `' . _DB_PREFIX_ . "ucpagent_key` WHERE status = 'retired' ORDER BY id_key ASC"
        ) ?: [];
        return array_map(function ($r) {
            return json_decode((string) $r['jwk'], true);
        }, $rows);
    }

    /** Persist the retired JWK list (replaces the previous list). */
    public function saveRetiredKeys(array $jwks): void
    {
        $db = Db::getInstance();
        $db->delete('ucpagent_key', "status = 'retired'");
        foreach ($jwks as $jwk) {
            $db->insert('ucpagent_key', [
                'kid'        => pSQL($jwk['kid']),
                'pem'        => null,
                'jwk'        => pSQL(json_encode($jwk)),
                'status'     => 'retired',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }
}
