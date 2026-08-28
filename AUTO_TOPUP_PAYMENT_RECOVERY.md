# Auto Top-Up payment recovery

When Commerce reports a failed Auto Top-Up payment, Simcard API pauses the
configuration, requests a hosted card-update URL from Commerce, and sends the
URL through both the transactional notification API and the eSIM SMS channel.
Delivery state is independent per channel and is retried by the existing
scheduled Auto Top-Up processor.

Simcard API never receives card details. The recovery URL is treated as a bearer
secret and is encrypted at rest until Commerce confirms that the payment method
was updated.

## Commerce contract

Configure `STELLAR_COMMERCE_AUTO_TOPUP_PAYMENT_RECOVERY_URL`. Simcard API sends
an authenticated `POST` request with this body:

```json
{
  "parent_order_id": "uuid",
  "parent_order_item_id": "uuid",
  "simcard_id": "uuid",
  "commerce_unit": 1,
  "topup_session_id": "uuid",
  "attempt_key": "stable-charge-attempt-key",
  "idempotency_key": "esim_auto_topup_recovery_<attempt-uuid>"
}
```

Commerce must verify ownership, create or reuse a hosted Stripe Setup/Checkout
session, and return an HTTPS URL:

```json
{
  "data": {
    "update_payment_method_url": "https://checkout.stripe.com/...",
    "expires_at": "2026-08-28T22:30:00Z"
  }
}
```

After the new payment method is saved, Commerce calls the authenticated endpoint
`POST /api/v1/autotopupcontroller/payment-method-updated` with:

```json
{
  "topup_session_id": "uuid"
}
```

The callback is idempotent. It advances the configuration to a new `ARMED`
cycle; the normal usage refresh then creates a new payment attempt with a new
charge idempotency key. The declined attempt is retained as an audit record.

## Notification template

The Notifications service must define the transactional event
`esim_auto_topup_payment_failed`. Its payload includes
`update_payment_method_url`, `iccid_last4`, `auto_topup_cycle`, `manage_url`, and
`support_url`.
