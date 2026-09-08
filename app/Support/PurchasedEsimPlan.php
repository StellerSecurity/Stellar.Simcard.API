<?php

namespace App\Support;

use RuntimeException;

/** Customer purchase terms; never a provider package or a mutable top-up balance. */
final class PurchasedEsimPlan
{
    public static function rules(): array
    {
        return [
            'purchased_plan' => ['nullable', 'array'],
            'purchased_plan.variant_id' => ['required_with:purchased_plan', 'uuid'],
            'purchased_plan.sku' => ['required_with:purchased_plan', 'string', 'max:180'],
            'purchased_plan.name' => ['required_with:purchased_plan', 'string', 'max:180'],
            'purchased_plan.duration_days' => ['required_with:purchased_plan', 'integer', 'min:1', 'max:3650'],
            'purchased_plan.data_bytes' => ['nullable', 'integer', 'min:1', 'max:9007199254740991'],
            'purchased_plan.plan_type' => ['required_with:purchased_plan', 'in:fixed,unlimited'],
            'purchased_plan.is_virtual' => ['required_with:purchased_plan', 'boolean'],
        ];
    }

    public static function normalize(?array $value): ?array
    {
        if ($value === null) return null;
        $id = $value['variant_id'] ?? null;
        $name = $value['name'] ?? null;
        $sku = $value['sku'] ?? null;
        $days = filter_var($value['duration_days'] ?? null, FILTER_VALIDATE_INT);
        $bytes = isset($value['data_bytes']) ? filter_var($value['data_bytes'], FILTER_VALIDATE_INT) : null;
        $type = $value['plan_type'] ?? null;
        $virtual = filter_var($value['is_virtual'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (! is_string($id) || ! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $id)
            || ! is_string($sku) || trim($sku) === '' || strlen($sku) > 180
            || ! is_string($name) || trim($name) === '' || strlen($name) > 720
            || $days === false || $days < 1 || $days > 3650
            || ! in_array($type, ['fixed', 'unlimited'], true)
            || ! array_key_exists('is_virtual', $value) || $virtual === null
            || ($type === 'fixed' && ($bytes === null || $bytes === false || $bytes < 1))
            || ($bytes !== null && ($bytes === false || $bytes < 1 || $bytes > 9007199254740991))
            || ($type === 'unlimited' && ($virtual || $days > 365))) {
            throw new RuntimeException('Invalid purchased eSIM plan.', 422);
        }
        return [
            'variant_id' => strtolower($id),
            'name' => trim($name),
            'sku' => trim($sku),
            'data_bytes' => $type === 'unlimited' ? null : $bytes,
            'duration_days' => $days,
            'plan_type' => $type,
            'is_virtual' => $virtual,
        ];
    }

    public static function assertCompatible(array $plan, ?array $recipe, ?int $periodNum): void
    {
        if ($plan['is_virtual'] !== ($recipe !== null)
            || ($recipe !== null && ($plan['duration_days'] !== (int) ($recipe['target_duration_days'] ?? 0)
                || $plan['data_bytes'] !== (int) ($recipe['target_data_bytes'] ?? 0)))
            || ($plan['plan_type'] === 'unlimited' && $plan['duration_days'] !== $periodNum)
            || ($plan['plan_type'] === 'fixed' && $periodNum !== null)) {
            throw new RuntimeException('Purchased plan does not match the persisted eSIM entitlement.', 409);
        }
    }

    public static function forDisplay(?array $snapshot, ?array $recipe, ?int $periodNum): ?array
    {
        if ($snapshot !== null) return self::normalize($snapshot);
        // Historical virtual purchases have authoritative targets, even when the
        // original Commerce variant is no longer in the catalog. Never use delivered_*.
        $days = (int) ($recipe['target_duration_days'] ?? 0);
        $bytes = (int) ($recipe['target_data_bytes'] ?? 0);
        if ($recipe !== null && $days > 0 && $bytes > 0) {
            return ['variant_id' => null, 'name' => null, 'data_bytes' => $bytes,
                'duration_days' => $days, 'plan_type' => 'fixed', 'is_virtual' => true];
        }
        if ($periodNum !== null && $periodNum > 0) {
            return ['variant_id' => null, 'name' => null, 'data_bytes' => null,
                'duration_days' => $periodNum, 'plan_type' => 'unlimited', 'is_virtual' => false];
        }
        return null;
    }
}
