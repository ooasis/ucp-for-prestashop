<?php

declare(strict_types=1);

namespace UcpAgent;

/**
 * Request pipeline for every UCP endpoint: version negotiation, RFC 9421
 * verification, idempotency replay/409, op execution, UCP error envelopes.
 * Port of the conformance-proven Woo/Magento dispatch layer.
 *
 * $req is a plain request array: method, authority, path, query, body,
 * headers (lowercase-name map).
 */
class Dispatcher
{
    public function __construct(
        private readonly Config $config,
        private readonly Profile $profile,
        private readonly Idempotency $idempotency,
        private readonly Checkout $checkout,
        private readonly Orders $orders,
    ) {
    }

    /** Wire the default dependency graph. */
    public static function create(): self
    {
        $config = new Config();
        $payments = new Payments($config);
        $profile = new Profile($config, $payments, new SsrfGuard());
        $orders = new Orders($config, $profile, new SessionStore(), new SsrfGuard());
        $checkout = new Checkout(new SessionStore(), $profile, $payments, $orders);
        return new self($config, $profile, new Idempotency(), $checkout, $orders);
    }

    /** Build the request array from PHP globals (module front controller context). */
    public static function currentRequest(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $header) {
            if (isset($_SERVER[$key])) {
                $headers[$header] = $_SERVER[$key];
            }
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $body = (string) file_get_contents('php://input');
        return [
            'method'    => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'authority' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
            'path'      => (string) (parse_url($uri, PHP_URL_PATH) ?: '/'),
            'query'     => (string) (parse_url($uri, PHP_URL_QUERY) ?: ''),
            'body'      => $body === '' ? null : $body,
            'headers'   => $headers,
        ];
    }

    /**
     * Run a UCP operation, converting any failure into a UCP error envelope.
     *
     * @return array{0: int, 1: array} [http status, body]
     */
    public function dispatch(string $op, array $req, ?string $id): array
    {
        try {
            return $this->run($op, $req, $id);
        } catch (UcpError $e) {
            return $this->error($e);
        } catch (\Throwable $e) {
            return $this->error(new UcpError(500, 'INTERNAL_ERROR', $e->getMessage()));
        }
    }

    /**
     * The dispatch pipeline: enablement, version negotiation, signature
     * verification, idempotency replay/conflict, then the operation itself.
     *
     * @return array{0: int, 1: array} [http status, body]
     */
    private function run(string $op, array $req, ?string $id): array
    {
        if (!$this->config->isEnabled()) {
            throw new UcpError(404, 'RESOURCE_NOT_FOUND', 'UCP is not enabled on this store');
        }
        $ucpAgent = (string) ($req['headers']['ucp-agent'] ?? '');
        $this->checkVersion($ucpAgent);
        $this->verifySignatureIfPresent($req);
        $mutating = !str_starts_with($op, 'get_');
        $successStatus = $op === 'create_checkout' ? 201 : 200;

        if ($mutating && $op !== 'update_order') {
            $idemKey = (string) ($req['headers']['idempotency-key'] ?? '');
            if (!$idemKey) {
                throw new UcpError(422, 'INVALID_REQUEST', 'Idempotency-Key header is required');
            }
            $hash = $this->idempotency->hash($op, $id, (string) $req['body']);
            $stored = $this->idempotency->check($idemKey, $hash);
            if ($stored !== null) {
                if (isset($stored['conflict'])) {
                    throw new UcpError(
                        409,
                        'IDEMPOTENCY_CONFLICT',
                        'Idempotency key reused with different parameters'
                    );
                }
                return [$stored['status'], $stored['body']];
            }
            $body = $this->execute($op, $req, $id);
            $this->idempotency->store($idemKey, $hash, $successStatus, $body);
            return [$successStatus, $body];
        }
        return [$successStatus, $this->execute($op, $req, $id)];
    }

    /** Route the operation to its checkout handler with the decoded JSON body. */
    private function execute(string $op, array $req, ?string $id): array
    {
        $json = json_decode((string) $req['body'], true);
        if (!is_array($json)) {
            $json = [];
        }
        $agent = (string) ($req['headers']['ucp-agent'] ?? '');
        return match ($op) {
            'create_checkout'   => $this->checkout->create($this->requireItems($json), $agent),
            'get_checkout'      => $this->checkout->load($id),
            'update_checkout'   => $this->checkout->update($id, $json, $agent),
            'complete_checkout' => $this->checkout->complete($id, $this->requirePayment($json)),
            'cancel_checkout'   => $this->checkout->cancel($id),
            'get_order'         => $this->orders->load($id),
            'update_order'      => $this->orders->replace($id, $json),
        };
    }

    /** Require line_items or cart_id in a create_checkout body. */
    private function requireItems(array $json): array
    {
        if (empty($json['line_items']) && empty($json['cart_id'])) {
            throw new UcpError(422, 'INVALID_REQUEST', 'line_items or cart_id is required');
        }
        return $json;
    }

    /** Require the payment object in a complete_checkout body. */
    private function requirePayment(array $json): array
    {
        if (!isset($json['payment'])) {
            throw new UcpError(422, 'INVALID_REQUEST', 'payment is required');
        }
        return $json;
    }

    /** Date-based version negotiation from the UCP-Agent header. */
    private function checkVersion(string $ucpAgent): void
    {
        if (!$ucpAgent || !preg_match('/(?:^|;)\s*version=(?:"([^"]+)"|([^;]+))/i', $ucpAgent, $m)) {
            return; // no version param: compatible
        }
        $version = trim($m[1] !== '' ? $m[1] : $m[2]);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) || !strtotime($version)) {
            throw new UcpError(422, 'VERSION_INVALID_FORMAT', 'Invalid UCP version format: ' . $version);
        }
        if ($version > Profile::VERSION) {
            throw new UcpError(
                422,
                'VERSION_UNSUPPORTED',
                "Version $version is not supported. This merchant implements version " . Profile::VERSION . '.'
            );
        }
    }

    /**
     * RFC 9421 verification of inbound requests.
     *
     * Enforced only when the request carries signature headers (the spec permits
     * unsigned requests with alternative auth; strict mode is the admin toggle).
     */
    private function verifySignatureIfPresent(array $req): void
    {
        $headers = $req['headers'];
        if (empty($headers['signature-input']) || empty($headers['signature'])) {
            if ($this->config->strictSignatures()) {
                throw new UcpError(401, 'signature_missing', 'This merchant requires signed requests (RFC 9421)');
            }
            return;
        }
        $agent = (string) ($headers['ucp-agent'] ?? '');
        $platformProfile = $this->profile->fetchPlatformProfile($agent);
        $keys = $platformProfile['ucp']['keys'] ?? $platformProfile['signing_keys'] ?? [];
        if (!$keys) {
            throw new UcpError(424, 'profile_unreachable', 'Unable to fetch signer profile keys');
        }
        try {
            Rfc9421::verifyRestRequest($req, $keys);
        } catch (SignatureException $e) {
            $http = in_array($e->reason, ['digest_mismatch', 'algorithm_unsupported'], true) ? 400 : 401;
            throw new UcpError($http, $e->reason, $e->getMessage());
        }
    }

    /**
     * Convert a UcpError into [http status, UCP error envelope body].
     *
     * @return array{0: int, 1: array}
     */
    private function error(UcpError $e): array
    {
        return [$e->http, [
            'ucp'      => ['version' => Profile::VERSION, 'status' => 'error'],
            'messages' => [[
                'type'     => 'error',
                'code'     => $e->ucpCode,
                'content'  => $e->content,
                'severity' => $e->severity,
            ]],
        ]];
    }
}
