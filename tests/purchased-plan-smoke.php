<?php
require __DIR__.'/../app/Support/PurchasedEsimPlan.php';
use App\Support\PurchasedEsimPlan;

$cases = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
foreach ($cases as &$plan) {
    $plan = PurchasedEsimPlan::normalize($plan);
    $recipe = $plan['is_virtual'] ? ['target_duration_days' => $plan['duration_days'], 'target_data_bytes' => $plan['data_bytes'],
        'delivered_duration_days' => 30, 'delivered_data_bytes' => 20 * 1073741824,
        'duration_entitlement' => ['entitled_duration_days' => 40]] : null;
    PurchasedEsimPlan::assertCompatible($plan, $recipe, null);
    if (PurchasedEsimPlan::forDisplay($plan, $recipe, null) !== $plan) throw new RuntimeException('Snapshot not preserved');
    if ($recipe !== null) {
        $fallback = PurchasedEsimPlan::forDisplay(null, $recipe, null);
        if ($fallback['duration_days'] !== $plan['duration_days'] || $fallback['data_bytes'] !== $plan['data_bytes']) throw new RuntimeException('Provider terms leaked');
        try { PurchasedEsimPlan::assertCompatible(array_replace($plan, ['duration_days' => 15]), $recipe, null); }
        catch (RuntimeException $e) { if ($e->getCode() === 409) continue; throw $e; }
        throw new RuntimeException('Mismatched recipe accepted');
    }
}
unset($plan);
if (PurchasedEsimPlan::forDisplay(null, null, null) !== null) throw new RuntimeException('Legacy inference');
if (PurchasedEsimPlan::forDisplay(null, null, 30)['duration_days'] !== 30) throw new RuntimeException('Unlimited lost');
fwrite(STDERR, "SIM purchase/recipe compatibility tests passed.\n");
echo json_encode($cases, JSON_THROW_ON_ERROR);
