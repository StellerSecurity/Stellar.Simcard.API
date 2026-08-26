# Unlimited / Daily eSIM backend support

## New order contract

Existing fixed-data orders are unchanged:

```json
{
  "plan_id": "1234567890123456",
  "packageCode": "DK_1_7"
}
```

Daily/Unlimited orders add `days`:

```json
{
  "plan_id": "1234567890123456",
  "packageCode": "DK_1_Daily",
  "days": 8
}
```

The Simcard API validates `days` as 1-365 and sends it to eSIMAccess as
`packageInfoList[0].periodNum`. If `days` is omitted, `periodNum` is not sent.

## Persistence / idempotency

Run migrations before enabling Daily/Unlimited orders:

```bash
php artisan migrate --force
```

The selected duration is stored in `simcards.provider_period_num`. A retry of the
same order/idempotency identity with a different duration is rejected with HTTP 409.

## Catalogue filtering

`GET /api/v1/sim/plans` now also forwards optional `slug` and `dataType` filters,
so Daily/Unlimited catalogues can be requested with `dataType=2`.

## Response additions

Order, query, and user-list responses can expose:

- `plan_type`: `fixed` or `unlimited`
- `duration_days`: selected Daily/Unlimited duration, otherwise `null`

## Compatibility

The fixed-data provider order JSON is unchanged when `days` is absent.
