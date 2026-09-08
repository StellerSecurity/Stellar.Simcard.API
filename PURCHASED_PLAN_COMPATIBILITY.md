# Purchased plan metadata for existing mobile releases

The mobile app's catalog mapper treats a provider code as a unique product reference. Real and virtual offers deliberately share that code, so a 30-day purchase can acquire the 20-day offer's label. Fixing this must not require an app release or add fields to the mobile response.

Commerce sends an internal `purchased_plan` snapshot containing the paid variant ID, SKU, name, data allowance, duration, plan type and virtual flag. SIM API stores it in a nullable JSON column. The provider `package_code`, virtual recipe, quota enforcement, expiry, payment and top-up paths retain their current meaning.

SIM API includes the snapshot in server-to-server query, list and attach responses. Mobile API consumes only its SKU, maps that to the existing `package_code` field for fixed plans, and filters the snapshot out. Released apps already index SKUs. No mobile JSON keys or types change. Unlimited display is outside this fixed-plan correction and keeps its existing reference.

## Deployment order

1. Deploy SIM API and run migration `2026_09_08_000001_add_purchased_plan_to_simcards_table.php` before deploying Commerce. Requests containing snapshots fail before provider spend when the migration is absent. Old requests without snapshots continue working.
2. Deploy Commerce to send snapshots on ordinary and virtual eSIM fulfillment.
3. In Commerce, preview historical restoration with `php artisan esim:backfill-purchased-plans --order=<order-uuid>`. Apply the verified order with the same command plus `--apply`. Omit `--order` to process all orders. Investigate failed or ambiguous units rather than overriding them.
4. Deploy Mobile API's normalizer correction. Existing apps pick up the corrected reference on their next successful SIM refresh; no app update is required.
5. On an already installed app, verify ordinary 30-day and virtual 20-day purchases, a quota-capped virtual data plan, account and guest access, installation details, and a top-up selection. Retain historical purchased SKUs in the product catalog: older apps still resolve display metadata locally. A missing SKU cannot be repaired by inventing a provider match.

Historical repair uses the exact stored order/item/unit and updates only a missing snapshot. It cannot create a SIM, purchase provider data, change a duration recipe, or overwrite conflicting purchase terms. Missing legacy order references or missing paid metadata are reported for manual reconciliation. A recipe alone cannot recover a missing commercial SKU, so those purchases need the order snapshot before this compatibility mapping can fix their label.

Rollback Mobile API first to restore the previous reference mapping. Keep the nullable SIM column and stored snapshots while Commerce may send them; do not drop the column during a rolling rollback.

## Verification

PHPUnit coverage includes purchase persistence with a mocked provider, repeat-order idempotency, exact historical order-unit matching, immutable snapshots, virtual entitlement preservation, wrong/missing/ambiguous references and migration failure. Tests use isolated in-memory SQLite; no live purchases or customer changes.

Cross-repository smoke tests execute Commerce snapshot creation, SIM snapshot/recipe validation and Mobile API normalization, then run the unmodified released app mapper and top-up service using Node's built-in TypeScript transform. Forty display scenarios cover ready/active/expired/exhausted/top-up states and reversed catalog order. Guest and account top-up tests confirm the provider option code is sent, not the SIM display SKU. The current catalog audit checks 5,347 fixed-data SKU/duration matches. These are contract and service integration checks, not a native-device/payment-provider end-to-end run.
