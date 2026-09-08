<?php

namespace Tests\Feature;

use App\Models\Simcard;
use App\Services\SimcardService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class PurchasedPlanSnapshotTest extends TestCase
{
    private $previousFacades;
    private $previousResolver;
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacades = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $container = new Container();
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        $container->bind('db.schema', fn () => $this->database->getConnection()->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Schema::create('simcards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('commerce_order_id');
            $table->string('commerce_order_item_id');
            $table->integer('commerce_unit');
            $table->string('package_code');
            $table->integer('provider_period_num')->nullable();
            $table->json('virtual_fulfillment_recipe')->nullable();
            foreach (['plan_id_hash', 'provider', 'provider_account', 'external_order_id_enc',
                'external_order_id_hash', 'state', 'user_ref', 'user_ref_version', 'user_linked_at',
                'user_link_source', 'idempotency_key', 'purchased_on'] as $column) {
                $table->string($column)->nullable();
            }
        });
        $migration = require __DIR__.'/../../database/migrations/2026_09_08_000001_add_purchased_plan_to_simcards_table.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacades);
        if ($this->previousResolver !== null) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        \Mockery::close();
        parent::tearDown();
    }

    private function plan(int $days = 30, bool $virtual = false): array
    {
        return ['variant_id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Norway',
            'sku' => 'ESIM-NORWAY-5GB-'.$days.'D-CKH988', 'data_bytes' => 5368709120,
            'duration_days' => $days, 'plan_type' => 'fixed', 'is_virtual' => $virtual];
    }

    private function service(): SimcardService
    {
        // Uninitialized provider/crypto fields make accidental provider calls fail this test.
        return (new ReflectionClass(SimcardService::class))->newInstanceWithoutConstructor();
    }

    private function sim(array $extra = []): Simcard
    {
        $sim = new Simcard();
        $sim->forceFill(array_replace(['id' => '22222222-2222-4222-8222-222222222222',
            'commerce_order_id' => 'order', 'commerce_order_item_id' => 'item', 'commerce_unit' => 1,
            'package_code' => 'CKH988'], $extra))->save();
        return $sim;
    }

    public function test_backfill_is_idempotent_and_preserves_extended_virtual_entitlement(): void
    {
        $recipe = ['target_data_bytes' => 5368709120, 'target_duration_days' => 20,
            'duration_entitlement' => ['entitled_duration_days' => 27, 'customer_expires_at' => '2026-10-01T12:00:00Z']];
        $sim = $this->sim(['virtual_fulfillment_recipe' => $recipe]);
        self::assertSame('updated', $this->service()->backfillPurchasedPlan('order', 'item', 1, $this->plan(20, true)));
        self::assertSame('unchanged', $this->service()->backfillPurchasedPlan('order', 'item', 1, $this->plan(20, true)));
        self::assertSame($recipe, $sim->fresh()->virtual_fulfillment_recipe);
        self::assertSame('CKH988', $sim->fresh()->package_code);
        self::assertSame($this->plan(20, true), $sim->fresh()->purchased_plan);
    }

    public function test_conflicting_snapshot_rolls_back(): void
    {
        $sim = $this->sim(['purchased_plan' => $this->plan()]);
        try {
            $this->service()->backfillPurchasedPlan('order', 'item', 1, $this->plan(20));
            self::fail('Conflicting snapshot accepted');
        } catch (RuntimeException $e) {
            self::assertSame(409, $e->getCode());
        }
        self::assertSame($this->plan(), $sim->fresh()->purchased_plan);
    }

    public function test_wrong_order_unit_is_not_updated(): void
    {
        $sim = $this->sim();
        try {
            $this->service()->backfillPurchasedPlan('order', 'item', 2, $this->plan());
            self::fail('Wrong order unit accepted');
        } catch (RuntimeException $e) {
            self::assertSame(404, $e->getCode());
        }
        self::assertNull($sim->fresh()->purchased_plan);
    }

    public function test_ambiguous_order_unit_is_not_updated(): void
    {
        $sim = $this->sim();
        $other = $this->sim(['id' => '33333333-3333-4333-8333-333333333333']);
        try {
            $this->service()->backfillPurchasedPlan('order', 'item', 1, $this->plan());
            self::fail('Ambiguous order unit accepted');
        } catch (RuntimeException $e) {
            self::assertSame(409, $e->getCode());
        }
        self::assertNull($sim->fresh()->purchased_plan);
        self::assertNull($other->fresh()->purchased_plan);
    }

    public function test_wrong_virtual_terms_are_rejected_without_changing_recipe(): void
    {
        $recipe = ['target_data_bytes' => 5368709120, 'target_duration_days' => 20];
        $sim = $this->sim(['virtual_fulfillment_recipe' => $recipe]);
        try {
            $this->service()->backfillPurchasedPlan('order', 'item', 1, $this->plan(30, true));
            self::fail('Wrong virtual duration accepted');
        } catch (RuntimeException $e) {
            self::assertSame(409, $e->getCode());
        }
        self::assertSame($recipe, $sim->fresh()->virtual_fulfillment_recipe);
        self::assertNull($sim->fresh()->purchased_plan);
    }

    private function orderingService(bool $expectPurchase): SimcardService
    {
        $service = $this->service();
        $provider = \Mockery::mock(\App\Services\Esim\EsimProvider::class);
        if ($expectPurchase) {
            $provider->shouldReceive('createOrder')->once()->with('CKH988', 'primary', null)
                ->andReturn(new \App\Services\Esim\EsimProviderOrder('provider-fixture', []));
        } else $provider->shouldNotReceive('createOrder');
        $crypto = \Mockery::mock(\App\Services\Esim\EsimCryptoService::class);
        $crypto->shouldReceive('derivePlanHash')->andReturn('fixture-hash');
        $crypto->shouldReceive('encryptForPlan')->andReturn('encrypted-fixture');
        $crypto->shouldReceive('deriveExternalOrderHash')->andReturn('provider-hash-fixture');
        $crypto->shouldReceive('normalizeEmail')->with(null)->andReturn(null);
        (new \ReflectionProperty(SimcardService::class, 'provider'))->setValue($service, $provider);
        (new \ReflectionProperty(SimcardService::class, 'crypto'))->setValue($service, $crypto);
        return $service;
    }

    public function test_normal_order_persists_snapshot_and_retries_without_another_provider_purchase(): void
    {
        $service = $this->orderingService(true);
        $args = ['userId' => null, 'accountRef' => null, 'packageCode' => 'CKH988',
            'planId' => '1234567890123456', 'commerceOrderId' => 'order', 'commerceOrderItemId' => 'item',
            'commerceUnit' => 1, 'idempotencyKey' => 'fixture-key', 'purchasedPlan' => $this->plan()];
        $first = $service->orderEsim(...$args);
        $again = $service->orderEsim(...$args);
        self::assertSame($first->id, $again->id);
        self::assertSame(1, Simcard::query()->count());
        self::assertSame('CKH988', $again->package_code);
        self::assertSame($this->plan(), $again->purchased_plan);
    }

    public function test_virtual_order_keeps_provider_code_and_customer_snapshot_separate(): void
    {
        $recipe = ['target_data_bytes' => 5368709120, 'target_duration_days' => 20,
            'duration_entitlement' => ['target_duration_days' => 20, 'enforced' => true]];
        $sim = $this->orderingService(true)->orderEsim(userId: null, accountRef: null, packageCode: 'CKH988',
            planId: '1234567890123456', commerceOrderId: 'order', commerceOrderItemId: 'item', commerceUnit: 1,
            virtualFulfillmentRecipe: $recipe, purchasedPlan: $this->plan(20, true));
        self::assertSame('CKH988', $sim->package_code);
        self::assertSame($recipe, $sim->virtual_fulfillment_recipe);
        self::assertSame($this->plan(20, true), $sim->purchased_plan);
    }

    public function test_conflicting_virtual_purchase_is_rejected_before_provider_spend(): void
    {
        $this->expectExceptionCode(409);
        $this->orderingService(false)->orderEsim(userId: null, accountRef: null, packageCode: 'CKH988',
            planId: '1234567890123456', purchasedPlan: $this->plan(20, true),
            virtualFulfillmentRecipe: ['target_data_bytes' => 5368709120, 'target_duration_days' => 30]);
    }

    public function test_missing_migration_fails_without_writing(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_08_000001_add_purchased_plan_to_simcards_table.php';
        $migration->down();
        $this->expectExceptionCode(503);
        $this->service()->backfillPurchasedPlan('order', 'item', 1, $this->plan());
    }
}
