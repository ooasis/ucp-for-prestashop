<?php
/**
 * UCP/ACP Agent for PrestaShop
 *
 * @author    ooasis
 * @copyright 2026 ooasis
 * @license   https://opensource.org/licenses/MIT MIT License
 */
/**
 * RFC 9421 HTTP Message Signatures for UCP.
 *
 * Plain PHP 7.2.5+, no dependencies beyond ext-openssl and ext-sodium.
 * Implements the UCP profile of RFC 9421 per spec/docs/specification/signatures.md:
 *   - ES256 (baseline, MUST verify) / ES384 / EdDSA (Ed25519)
 *   - ECDSA signatures in fixed-width raw r||s (P1363), NOT DER
 *   - JWK keys (EC + OKP), alg/use optional; unsupported keys skipped, not fatal
 *   - Content-Digest per RFC 9530 (sha-256 over raw body bytes)
 *   - verifyRestRequest / verifyRestResponse coverage rules
 *
 * ponytail: default-UCP signatures only — no RFC 9421 §2.1.2 dictionary-member
 * component selection (;key=), so WBA-shape (tag="web-bot-auth") signatures are
 * not verifiable yet; add §2.1.2 parsing when WBA interop is needed.
 */

declare(strict_types=1);

namespace UcpAgent;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Rfc9421
{
    /** Curve params: [per-integer width, openssl digest alg]. */
    public const EC_CURVES = [
        'P-256' => [32, OPENSSL_ALGO_SHA256],
        'P-384' => [48, OPENSSL_ALGO_SHA384],
    ];

    // -----------------------------------------------------------------------
    // Encoding helpers
    // -----------------------------------------------------------------------

    /**
     * Base64url-encode without padding.
     *
     * @param string $bin
     * @return string
     */
    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * Base64url-decode, throwing on malformed input.
     *
     * @param string $s
     * @return string
     */
    public static function b64urlDecode(string $s): string
    {
        $bin = base64_decode(strtr($s, '-_', '+/'), true);
        if ($bin === false) {
            throw new SignatureException('signature_invalid', 'bad base64url');
        }
        return $bin;
    }

    /**
     * DER ECDSA-Sig-Value -> fixed-width r||s (P1363). $w = per-integer width in bytes.
     *
     * @param string $der
     * @param int $w
     * @return string
     */
    public static function ecdsaDerToP1363(string $der, int $w): string
    {
        $read_int = function (string $der, int &$off): string {
            if (ord($der[$off]) !== 0x02) {
                throw new SignatureException('signature_invalid', 'DER: expected INTEGER');
            }
            $len = ord($der[$off + 1]);
            $val = substr($der, $off + 2, $len);
            $off += 2 + $len;
            return ltrim($val, "\x00"); // strip sign padding
        };
        if (ord($der[0]) !== 0x30) {
            throw new SignatureException('signature_invalid', 'DER: expected SEQUENCE');
        }
        // Skip SEQUENCE header (len < 128 always holds for P-256/P-384 sigs).
        $off = (ord($der[1]) & 0x80) ? 2 + (ord($der[1]) & 0x7f) : 2;
        $r = $read_int($der, $off);
        $s = $read_int($der, $off);
        if (strlen($r) > $w || strlen($s) > $w) {
            throw new SignatureException('signature_invalid', 'DER integer wider than curve');
        }
        return str_pad($r, $w, "\x00", STR_PAD_LEFT) . str_pad($s, $w, "\x00", STR_PAD_LEFT);
    }

    /**
     * Fixed-width r||s (P1363) -> DER ECDSA-Sig-Value (for openssl_verify).
     *
     * @param string $p1363
     * @param int $w
     * @return string
     */
    public static function ecdsaP1363ToDer(string $p1363, int $w): string
    {
        if (strlen($p1363) !== 2 * $w) {
            throw new SignatureException('signature_invalid', 'wrong r||s length');
        }
        $encode_int = function (string $v): string {
            $v = ltrim($v, "\x00");
            if ($v === '') {
                $v = "\x00";
            }
            if (ord($v[0]) & 0x80) {
                $v = "\x00" . $v; // keep positive
            }
            return "\x02" . chr(strlen($v)) . $v;
        };
        $body = $encode_int(substr($p1363, 0, $w)) . $encode_int(substr($p1363, $w));
        $len = strlen($body);
        $hdr = $len < 128 ? chr($len) : "\x81" . chr($len);
        return "\x30" . $hdr . $body;
    }

    // -----------------------------------------------------------------------
    // JWK handling
    // -----------------------------------------------------------------------

    /**
     * EC JWK -> PEM SubjectPublicKeyInfo.
     *
     * Only kty/crv/x/y are required (spec: alg and use are optional and MUST NOT cause rejection).
     *
     * @param array $jwk
     * @return string
     */
    public static function ecJwkToPem(array $jwk): string
    {
        $crv = $jwk['crv'] ?? '';
        // DER SPKI prefix: SEQUENCE( SEQUENCE(OID ecPublicKey, OID curve), BIT STRING )
        $oids = [
            'P-256' => "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
                . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00",
            'P-384' => "\x30\x76\x30\x10\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x05\x2b\x81\x04\x00\x22\x03\x62\x00",
        ];
        if (!isset($oids[$crv])) {
            throw new SignatureException('algorithm_unsupported', "curve $crv");
        }
        $w = self::EC_CURVES[$crv][0];
        $x = str_pad(self::b64urlDecode($jwk['x']), $w, "\x00", STR_PAD_LEFT);
        $y = str_pad(self::b64urlDecode($jwk['y']), $w, "\x00", STR_PAD_LEFT);
        $der = $oids[$crv] . "\x04" . $x . $y; // 0x04 = uncompressed point
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Export the public half of an openssl EC key as a JWK.
     *
     * @param resource|\OpenSSLAsymmetricKey $key
     * @param string $kid
     * @return array
     */
    public static function ecPemToJwk($key, string $kid): array
    {
        $d = openssl_pkey_get_details($key);
        $crv = ['prime256v1' => 'P-256', 'secp384r1' => 'P-384'][$d['ec']['curve_name']] ?? null;
        if ($crv === null) {
            throw new SignatureException('algorithm_unsupported', $d['ec']['curve_name']);
        }
        return [
            'kid' => $kid, 'kty' => 'EC', 'crv' => $crv,
            'x' => self::b64urlEncode($d['ec']['x']), 'y' => self::b64urlEncode($d['ec']['y']),
        ];
    }

    /**
     * True if this verifier can use the key.
     *
     * Per spec, unsupported kty/crv must skip the key, never reject the key set.
     * use:"enc" / key_ops w/o "verify" skipped too.
     *
     * @param array $jwk
     * @return bool
     */
    public static function keyUsableForVerify(array $jwk): bool
    {
        if (($jwk['use'] ?? 'sig') === 'enc') {
            return false;
        }
        if (isset($jwk['key_ops']) && !in_array('verify', $jwk['key_ops'], true)) {
            return false;
        }
        switch ($jwk['kty'] ?? '') {
            case 'EC':
                return isset(self::EC_CURVES[$jwk['crv'] ?? '']) && isset($jwk['x'], $jwk['y']);
            case 'OKP':
                return ($jwk['crv'] ?? '') === 'Ed25519' && isset($jwk['x']);
            default:
                return false;
        }
    }

    // -----------------------------------------------------------------------
    // RFC 9530 Content-Digest
    // -----------------------------------------------------------------------

    /**
     * RFC 9530 Content-Digest header value (sha-256 over raw body bytes).
     *
     * @param string $body
     * @return string
     */
    public static function contentDigest(string $body): string
    {
        return 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
    }

    // -----------------------------------------------------------------------
    // Signature base (RFC 9421 §2.5)
    // -----------------------------------------------------------------------

    /**
     * Build the signature base. $components: ordered list like ["@method", "ucp-agent"].
     * $ctx supplies derived values: method, authority, path, query, status.
     * $headers: lowercase-name => value (already combined per RFC 9110 if multi-valued).
     * $params: the raw serialized signature params (everything after the component list
     * in Signature-Input, e.g. ';keyid="k1";created=123') — signed verbatim.
     *
     * @param array $components
     * @param array $ctx
     * @param array $headers
     * @param string $params
     * @return string
     */
    public static function signatureBase(array $components, array $ctx, array $headers, string $params): string
    {
        $lines = [];
        foreach ($components as $c) {
            switch ($c) {
                case '@method':
                    $value = strtoupper($ctx['method']);
                    break;
                case '@authority':
                    $value = strtolower($ctx['authority']);
                    break;
                case '@path':
                    $value = $ctx['path'];
                    break;
                case '@query':
                    $value = '?' . ($ctx['query'] ?? '');
                    break;
                case '@status':
                    $value = (string) $ctx['status'];
                    break;
                default:
                    if (strpos($c, '@') === 0) {
                        throw new SignatureException('signature_invalid', "unsupported derived component $c");
                    }
                    if (!isset($headers[$c])) {
                        throw new SignatureException('signature_invalid', "missing signed header $c");
                    }
                    $value = trim($headers[$c]);
            }
            $lines[] = "\"$c\": $value";
        }
        $list = implode(' ', array_map(function ($c) {
            return "\"$c\"";
        }, $components));
        $lines[] = "\"@signature-params\": ($list)$params";
        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------------
    // Signature-Input parsing (minimal structured-field subset for UCP shapes)
    // -----------------------------------------------------------------------

    /**
     * Parse 'sig1=("@method" "@path");keyid="k";created=1;tag="x"' into
     * [label, components[], params-string, params-map]. Single signature only.
     * ponytail: no §2.1.2 ;key= component params, no multi-signature — first label wins.
     *
     * @param string $header
     * @return array
     */
    public static function parseSignatureInput(string $header): array
    {
        if (!preg_match('/^\s*([!#$%&\'*+\-.^_`|~0-9a-z]+)=\(([^)]*)\)(.*)$/s', $header, $m)) {
            throw new SignatureException('signature_invalid', 'unparseable Signature-Input');
        }
        [, $label, $inner, $params] = $m;
        preg_match_all('/"([^"]+)"/', $inner, $cm);
        $pmap = [];
        // params: ;name=value where value is "quoted" or bare token/int
        preg_match_all('/;\s*([a-z]+)=("([^"]*)"|[^;]+)/', $params, $pm, PREG_SET_ORDER);
        foreach ($pm as $p) {
            $pmap[$p[1]] = isset($p[3]) ? $p[3] : trim($p[2]);
        }
        return [$label, $cm[1], rtrim($params), $pmap];
    }

    /**
     * Parse 'sig1=:base64:' -> raw signature bytes.
     *
     * @param string $header
     * @param string $label
     * @return string
     */
    public static function parseSignatureHeader(string $header, string $label): string
    {
        if (!preg_match('/' . preg_quote($label, '/') . '=:([A-Za-z0-9+\/=]+):/', $header, $m)) {
            throw new SignatureException('signature_missing', 'no signature for label ' . $label);
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false) {
            throw new SignatureException('signature_invalid', 'bad base64 in Signature');
        }
        return $bin;
    }

    // -----------------------------------------------------------------------
    // Sign / verify primitives (alg from JWK kty/crv, per spec — never from `alg` param)
    // -----------------------------------------------------------------------

    /**
     * Sign a signature base string with a private key (Ed25519 or EC).
     *
     * @param string $base
     * @param array $privateKey
     * @return string
     */
    public static function signBase(string $base, array $privateKey): string
    {
        if ($privateKey['kty'] === 'OKP') {
            return sodium_crypto_sign_detached($base, $privateKey['ed25519_secret']);
        }
        [$w, $mdAlg] = self::EC_CURVES[$privateKey['crv']];
        if (!openssl_sign($base, $der, $privateKey['openssl_key'], $mdAlg)) {
            throw new SignatureException('signature_invalid', 'openssl_sign failed');
        }
        return self::ecdsaDerToP1363($der, $w); // spec: raw r||s, NOT DER
    }

    /**
     * Verify a signature over a base string against a JWK public key.
     *
     * @param string $base
     * @param string $sig
     * @param array $jwk
     * @return bool
     */
    public static function verifyBase(string $base, string $sig, array $jwk): bool
    {
        if (($jwk['kty'] ?? '') === 'OKP') {
            return strlen($sig) === 64
                && sodium_crypto_sign_verify_detached($sig, $base, self::b64urlDecode($jwk['x']));
        }
        [$w, $mdAlg] = self::EC_CURVES[$jwk['crv']];
        if (strlen($sig) !== 2 * $w) {
            return false; // enforce fixed-width r||s on the wire
        }
        $pem = self::ecJwkToPem($jwk);
        return openssl_verify($base, self::ecdsaP1363ToDer($sig, $w), $pem, $mdAlg) === 1;
    }

    // -----------------------------------------------------------------------
    // UCP request/response signing & verification (spec pseudocode, faithfully)
    // -----------------------------------------------------------------------

    /**
     * Sign a REST request. Returns the headers to attach.
     * $req: method, authority, path, query?, body?, headers (lowercase map, e.g. ucp-agent,
     * idempotency-key, content-type).
     *
     * @param array $req
     * @param array $privateKey
     * @param string $kid
     * @return array
     */
    public static function signRestRequest(array $req, array $privateKey, string $kid): array
    {
        $headers = $req['headers'] ?? [];
        $out = [];
        if (isset($req['body'])) {
            $headers['content-digest'] = $out['Content-Digest'] = self::contentDigest($req['body']);
        }
        $components = ['@method', '@authority', '@path'];
        if (!empty($req['query'])) {
            $components[] = '@query';
        }
        if (isset($headers['ucp-agent'])) {
            $components[] = 'ucp-agent';
        }
        if (isset($headers['idempotency-key'])) {
            $components[] = 'idempotency-key';
        }
        if (isset($req['body'])) {
            array_push($components, 'content-digest', 'content-type');
        }

        $params = ';keyid="' . $kid . '"'; // default UCP: no created/expires/alg (spec)
        $base = self::signatureBase($components, $req, $headers, $params);
        $list = implode(' ', array_map(function ($c) {
            return "\"$c\"";
        }, $components));
        $out['Signature-Input'] = "sig1=($list)$params";
        $out['Signature'] = 'sig1=:' . base64_encode(self::signBase($base, $privateKey)) . ':';
        return $out;
    }

    /**
     * Sign a REST response (@status instead of @method).
     *
     * @param int $status
     * @param string|null $body
     * @param string $contentType
     * @param array $privateKey
     * @param string $kid
     * @return array
     */
    public static function signRestResponse(
        int $status,
        ?string $body,
        string $contentType,
        array $privateKey,
        string $kid
    ): array {
        $out = [];
        $headers = [];
        $components = ['@status'];
        if ($body !== null) {
            $headers['content-digest'] = $out['Content-Digest'] = self::contentDigest($body);
            $headers['content-type'] = $contentType;
            array_push($components, 'content-digest', 'content-type');
        }
        $params = ';created=' . time() . ';keyid="' . $kid . '"';
        $base = self::signatureBase($components, ['status' => $status], $headers, $params);
        $list = implode(' ', array_map(function ($c) {
            return "\"$c\"";
        }, $components));
        $out['Signature-Input'] = "sig1=($list)$params";
        $out['Signature'] = 'sig1=:' . base64_encode(self::signBase($base, $privateKey)) . ':';
        return $out;
    }

    /**
     * VerifyRestRequest per the spec.
     *
     * $keySet is the signer's published keys[] (already fetched from the profile named
     * in UCP-Agent — profile fetching is out of scope here). Throws SignatureException
     * with a spec reason.
     *
     * @param array $req
     * @param array $keySet
     * @return void
     */
    public static function verifyRestRequest(array $req, array $keySet): void
    {
        $headers = $req['headers'] ?? [];
        if (!isset($headers['signature-input'], $headers['signature'])) {
            throw new SignatureException('signature_missing');
        }
        [$label, $components, $params, $pmap] = self::parseSignatureInput($headers['signature-input']);

        // 2. Resolve key: signature-capable keys only, matched by kid.
        $jwk = null;
        foreach ($keySet as $k) {
            if (($k['kid'] ?? null) === ($pmap['keyid'] ?? null) && self::keyUsableForVerify($k)) {
                $jwk = $k;
                break;
            }
        }
        if ($jwk === null) {
            // Distinguish unsupported-alg from absent kid, per the spec's error codes.
            foreach ($keySet as $k) {
                if (($k['kid'] ?? null) === ($pmap['keyid'] ?? null)) {
                    throw new SignatureException('algorithm_unsupported', $k['kty'] ?? '?');
                }
            }
            throw new SignatureException('key_not_found', $pmap['keyid'] ?? '(no keyid)');
        }

        // 2b. Coverage: everything integrity-relevant the request carries must be signed.
        $required = ['@method', '@authority', '@path'];
        if (!empty($req['query'])) {
            $required[] = '@query';
        }
        if (isset($req['body'])) {
            array_push($required, 'content-digest', 'content-type');
        }
        if (isset($headers['idempotency-key'])) {
            $required[] = 'idempotency-key';
        }
        if (isset($headers['ucp-agent'])) {
            $required[] = 'ucp-agent';
        }
        if (isset($headers['signature-agent'])) {
            $required[] = 'signature-agent';
        }
        foreach ($required as $c) {
            if (!in_array($c, $components, true)) {
                throw new SignatureException('coverage_insufficient', $c);
            }
        }

        // 3. Body digest over raw bytes.
        if (in_array('content-digest', $components, true)) {
            if (!hash_equals(self::contentDigest($req['body'] ?? ''), trim($headers['content-digest'] ?? ''))) {
                throw new SignatureException('digest_mismatch');
            }
        }

        // 4+5. Rebuild base, verify.
        $base = self::signatureBase($components, $req, $headers, $params);
        $sig = self::parseSignatureHeader($headers['signature'], $label);
        if (!self::verifyBase($base, $sig, $jwk)) {
            throw new SignatureException('signature_invalid');
        }
    }

    /**
     * VerifyRestResponse per the spec (@status; body must be covered if present).
     *
     * @param int $status
     * @param string|null $body
     * @param array $headers
     * @param array $keySet
     * @return void
     */
    public static function verifyRestResponse(int $status, ?string $body, array $headers, array $keySet): void
    {
        if (!isset($headers['signature-input'], $headers['signature'])) {
            throw new SignatureException('signature_missing');
        }
        [$label, $components, $params, $pmap] = self::parseSignatureInput($headers['signature-input']);
        $jwk = null;
        foreach ($keySet as $k) {
            if (($k['kid'] ?? null) === ($pmap['keyid'] ?? null) && self::keyUsableForVerify($k)) {
                $jwk = $k;
                break;
            }
        }
        if ($jwk === null) {
            throw new SignatureException('key_not_found');
        }

        $required = ['@status'];
        if ($body !== null) {
            array_push($required, 'content-digest', 'content-type');
        }
        foreach ($required as $c) {
            if (!in_array($c, $components, true)) {
                throw new SignatureException('signature_invalid', "uncovered $c");
            }
        }
        if (in_array('content-digest', $components, true)
            && !hash_equals(self::contentDigest($body ?? ''), trim($headers['content-digest'] ?? ''))) {
            throw new SignatureException('digest_mismatch');
        }
        $base = self::signatureBase($components, ['status' => $status], $headers, $params);
        if (!self::verifyBase($base, self::parseSignatureHeader($headers['signature'], $label), $jwk)) {
            throw new SignatureException('signature_invalid');
        }
    }
}
