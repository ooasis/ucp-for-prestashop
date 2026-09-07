# UCP/ACP Agent for PrestaShop

Universal Commerce Protocol (UCP, v2026-04-08) merchant implementation for PrestaShop 8 and 9.
Lets AI shopping agents discover your store, build a checkout, pay, and receive order updates,
without a human clicking through the storefront.
**Passes the official UCP conformance suite: 75/75 (2 expected skips) on PS 8.2.8 and PS 9.1.5.**

## What it does

- `/.well-known/ucp` discovery profile with published ES256 signing keys (JWK, RFC 7638 kid)
  and rotation grace for retired keys
- Checkout sessions with the full state machine (`incomplete → ready_for_complete → completed/canceled`)
  mapped onto real PrestaShop carts: products by `reference`, stock checks, guest customers per buyer,
  addresses, carriers via `Cart::getDeliveryOptionList()`, cart rules (percentage and fixed)
- Completion places a real order through `PaymentModule::validateOrder()` in "Payment accepted",
  payment method "UCP Agent"
- Payment handlers: Google Pay via Stripe (`google_pay`, advertised when a Stripe secret key is set)
  and the spec's `mock_payment_handler` (advertised only while a simulation secret is set)
- RFC 9421 HTTP Message Signatures: inbound verification whenever a request is signed, optional
  strict mode that rejects unsigned requests, signed outbound webhooks. No dependencies beyond
  ext-openssl and ext-sodium
- Idempotency-Key replay and 409 conflict detection, UCP-Agent date-based version negotiation,
  structured UCP error envelopes
- Order entities at `/ucp/orders/{id}` and push webhooks (full entity, signed, retried) on order
  validation, status change and tracking number, discovered from the agent platform's profile
- SSRF guard on every platform-supplied URL (profile fetch, webhook delivery)
- Admin settings under Modules: enable/disable, strict signatures, simulation secret, Stripe keys.
  Secrets are encrypted at rest with PrestaShop's core encryption

## Requirements

- PrestaShop 8.0 or newer (tested on 8.2.8 and 9.1.5)
- PHP 7.2.5 or newer with ext-openssl and ext-sodium (both bundled with PHP)
- Friendly URLs enabled (Shop Parameters → Traffic & SEO). The routes below are registered through
  the `moduleRoutes` hook; no `.htaccess` edits are needed

## Install

1. Build or download `ucpagent-<version>.zip` (a single top-level `ucpagent/` folder).
2. Back office → Modules → Module Manager → Upload a module, or unzip into `modules/ucpagent/`
   and run `php bin/console prestashop:module install ucpagent`.
3. Configure the module: leave **Enable UCP endpoints** on, set a Stripe secret key to accept
   Google Pay, and leave **Simulation secret** empty on a production store.
4. Verify: `curl https://your-store/.well-known/ucp` returns the profile JSON.

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/.well-known/ucp` | Merchant profile, capabilities, signing keys, payment handlers |
| POST | `/ucp/checkout-sessions` | Create a session (`line_items` or `cart_id`) |
| GET, PUT | `/ucp/checkout-sessions/{id}` | Read or update buyer, items, fulfillment, discounts |
| POST | `/ucp/checkout-sessions/{id}/complete` | Charge and place the order |
| POST | `/ucp/checkout-sessions/{id}/cancel` | Cancel the session |
| GET, PUT | `/ucp/orders/{id}` | Read or replace the UCP order entity |
| POST | `/testing/simulate-shipping/{id}` | Conformance hook, gated by the `Simulation-Secret` header |

All endpoints are public by protocol design: UCP has no merchant-issued credential. Authenticity
comes from RFC 9421 signatures (verified whenever present, mandatory in strict mode), session and
order ids are unguessable UUIDs, prices and stock are server-authoritative, and orders are created
only after a successful charge.

## Testing

The module is developed against the official UCP conformance suite with the `flower_shop`
fixture set. With a simulation secret configured on the store:

```sh
SERVER_URL=https://your-store SIMULATION_SECRET=<secret> uv run pytest -q
```

Expected: 75 passed, 2 skipped (no free-shipping item SKU configured; remote ucp.dev schemas not
yet published).

## Known ceilings

Deliberate simplifications, each marked with a `ponytail:` comment in the source:

- Default UCP signatures only. RFC 9421 §2.1.2 dictionary-member components and multi-signature
  inputs are not supported
- Webhook delivery retries in-request (about 1.5 s plus timeouts). A queue table exists for a cron
  worker when traffic needs it
- Platform profile cache is per request; no idempotency-row purge job yet
- Buyer-less sessions share one guest customer account

## License

Licensed under the [PolyForm Shield License 1.0.0](LICENSE).

In short: you may use, modify and run this module on any store you own or operate, for free,
including commercial stores. You may not use it to build, sell or offer a product or service that
competes with it, and any copy you pass on must keep the license and the copyright notice. See
the LICENSE file for the exact terms. Versions before the relicense commit were published under MIT.
