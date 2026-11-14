<?php

namespace Tests\Feature\Api;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\LoyaltyPointHistory;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\TransactionTender;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\PosSyncProcessRunner;
use Tests\TestCase;

class PosTransactionSyncConcurrencyTest extends TestCase
{
    private User $cashier;

    private Warehouse $warehouse;

    private Product $product;

    private Customer $customer;

    private int $shiftId;

    private string $fixtureSuffix;

    private string $dueDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertIsolatedPostgresTestDatabase();
        $this->fixtureSuffix = bin2hex(random_bytes(6));
        $this->dueDate = now()->addDays(7)->toDateString();
        $this->createFixtures();
    }

    public function test_service_address_validation_accepts_only_private_ipv4_and_ipv6_addresses(): void
    {
        foreach ([
            '10.20.30.40',
            '172.18.0.2',
            '192.168.1.10',
            'fc00::1',
            'fd12:3456:789a::1',
            '::ffff:172.18.0.2',
        ] as $address) {
            $this->assertTrue($this->isPrivateServiceAddress($address), $address);
        }

        foreach ([
            'not-an-ip',
            '8.8.8.8',
            '2001:4860:4860::8888',
            '0.0.0.0',
            '100.64.0.1',
            '192.0.2.1',
            '2001:db8::1',
            '169.254.1.1',
            '127.0.0.1',
            '127.42.0.1',
            '::',
            '::1',
            '::ffff:127.0.0.1',
            '::ffff:8.8.8.8',
            'fe80::1',
        ] as $address) {
            $this->assertFalse($this->isPrivateServiceAddress($address), $address);
        }
    }

    public function test_two_concurrent_sync_requests_with_the_same_uuid_commit_one_sale(): void
    {
        $uuid = 'e3a6935e-f27b-458e-b643-fbc312d1df48';
        $unrelatedCart = $this->createCart(qty: 1);
        $token = $this->cashier->createToken('pos-sync-concurrency-worker', ['*'])->plainTextToken;
        $barrierDirectory = $this->createTemporaryDirectory();
        $workerFailure = null;

        try {
            $results = (new PosSyncProcessRunner)->run(
                jobs: [
                    'worker-1' => $this->workerInput('worker-1', $uuid, $token, $barrierDirectory),
                    'worker-2' => $this->workerInput('worker-2', $uuid, $token, $barrierDirectory),
                ],
                workerScript: base_path('tests/Support/pos_sync_concurrency_worker.php'),
                workingDirectory: base_path(),
                outputDirectory: $barrierDirectory,
            );
        } catch (\Throwable $exception) {
            $workerFailure = $exception;
        }

        try {
            $this->removeTemporaryDirectory($barrierDirectory);
        } catch (\Throwable $cleanupFailure) {
            if ($workerFailure !== null) {
                throw new \RuntimeException(
                    $workerFailure->getMessage()
                    .PHP_EOL.'Temporary directory cleanup failure: '.$cleanupFailure->getMessage(),
                    0,
                    $workerFailure
                );
            }

            throw $cleanupFailure;
        }

        if ($workerFailure !== null) {
            throw $workerFailure;
        }

        $this->assertCount(2, $results);
        $this->assertNotSame($results['worker-1']['pid'], $results['worker-2']['pid']);
        $this->assertSame(200, $results['worker-1']['status_code']);
        $this->assertSame(200, $results['worker-2']['status_code']);

        $itemResults = [
            $results['worker-1']['body']['data']['results'][0],
            $results['worker-2']['body']['data']['results'][0],
        ];
        $synced = array_values(array_filter(
            $itemResults,
            static fn (array $result): bool => $result['status'] === 'synced'
        ));
        $duplicates = array_values(array_filter(
            $itemResults,
            static fn (array $result): bool => $result['status'] === 'duplicate'
        ));

        $this->assertCount(1, $synced, 'Exactly one concurrent request must create the sale.');
        $this->assertCount(1, $duplicates, 'The losing concurrent request must be a duplicate.');
        $this->assertSame($uuid, $synced[0]['client_uuid']);
        $this->assertSame($uuid, $duplicates[0]['client_uuid']);
        $this->assertSame($synced[0]['transaction_id'], $duplicates[0]['transaction_id']);

        $this->assertSingleCommittedSale($uuid, $unrelatedCart);
    }

    public function test_sequential_retry_with_an_existing_uuid_returns_the_same_transaction(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        $uuid = '2a0a9ce8-e8f8-49d5-8731-4cc4e66a98de';
        $payload = $this->syncPayload($uuid);

        $first = $this->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [$payload],
        ])->assertOk()
            ->assertJsonPath('data.results.0.status', 'synced');

        $second = $this->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [$payload],
        ])->assertOk()
            ->assertJsonPath('data.results.0.status', 'duplicate');

        $transactionId = $first->json('data.results.0.transaction_id');
        $this->assertSame($transactionId, $second->json('data.results.0.transaction_id'));
        $this->assertSingleCommittedSale($uuid);
    }

    public function test_unrelated_unique_constraint_violation_is_not_reported_as_uuid_duplicate(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        $this->createCart(qty: 1);

        $this->postJson('/api/v1/pos/checkout', [
            'payment_method' => 'cash',
            'cash' => 100000,
        ])->assertCreated();

        $existing = Transaction::query()
            ->where('cashier_shift_id', $this->shiftId)
            ->sole();
        $unrelatedCart = $this->createCart(qty: 1);
        $uuid = '32e65e4d-6d51-4a14-9eea-b4f3abdc4432';

        $productStockBefore = (int) $this->product->fresh()->stock;
        $warehouseStockBefore = (int) $this->warehouseStock();
        $transactionCountBefore = $this->shiftTransactions()->count();
        $detailCountBefore = $this->shiftTransactionDetails()->count();
        $loyaltyHistoryBefore = LoyaltyPointHistory::where('customer_id', $this->customer->id)->count();
        $customerPointsBefore = (int) $this->customer->fresh()->loyalty_points;
        $customerTransactionsBefore = (int) $this->customer->fresh()->loyalty_transaction_count;
        $receivableCountBefore = $this->shiftReceivables()->count();
        $tenderCountBefore = $this->shiftTenders()->count();
        $cartIdsBefore = $this->cashierCartIds();
        $shiftSummaryBefore = $this->shiftSummary();

        $dispatcher = Transaction::getEventDispatcher();
        $this->assertInstanceOf(Dispatcher::class, $dispatcher);
        $eventName = 'eloquent.creating: '.Transaction::class;
        $collisionListenerEnabled = true;
        $collisionListener = static function (Transaction $transaction) use (
            $existing,
            $uuid,
            &$collisionListenerEnabled
        ): void {
            if ($collisionListenerEnabled && $transaction->client_uuid === $uuid) {
                $transaction->access_token = $existing->access_token;
            }
        };
        $dispatcher->listen($eventName, $collisionListener);

        try {
            $response = $this->postJson('/api/v1/pos/transactions/sync', [
                'transactions' => [$this->syncPayload($uuid)],
            ])->assertOk()
                ->assertJsonPath('data.results.0.status', 'failed');
        } finally {
            $collisionListenerEnabled = false;
        }

        $this->assertNotSame('duplicate', $response->json('data.results.0.status'));
        $this->assertSame($transactionCountBefore, $this->shiftTransactions()->count());
        $this->assertSame(0, Transaction::where('client_uuid', $uuid)->count());
        $this->assertSame($detailCountBefore, $this->shiftTransactionDetails()->count());
        $this->assertSame($productStockBefore, (int) $this->product->fresh()->stock);
        $this->assertSame($warehouseStockBefore, (int) $this->warehouseStock());
        $this->assertSame($cartIdsBefore, $this->cashierCartIds());
        $this->assertContains($unrelatedCart->id, $this->cashierCartIds());
        $this->assertSame(
            $loyaltyHistoryBefore,
            LoyaltyPointHistory::where('customer_id', $this->customer->id)->count()
        );
        $this->assertSame($customerPointsBefore, (int) $this->customer->fresh()->loyalty_points);
        $this->assertSame(
            $customerTransactionsBefore,
            (int) $this->customer->fresh()->loyalty_transaction_count
        );
        $this->assertSame($receivableCountBefore, $this->shiftReceivables()->count());
        $this->assertSame($tenderCountBefore, $this->shiftTenders()->count());
        $this->assertSame($shiftSummaryBefore, $this->shiftSummary());
    }

    public function test_failure_during_receivable_creation_rolls_back_checkout_side_effects(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        $unrelatedCart = $this->createCart(qty: 1);
        $uuid = 'b7d515e2-7b35-4ae3-8ccf-88aa178d0e35';

        $productStockBefore = (int) $this->product->fresh()->stock;
        $warehouseStockBefore = (int) $this->warehouseStock();
        $transactionCountBefore = $this->shiftTransactions()->count();
        $detailCountBefore = $this->shiftTransactionDetails()->count();
        $tenderCountBefore = $this->shiftTenders()->count();
        $receivableCountBefore = $this->shiftReceivables()->count();
        $loyaltyHistoryBefore = LoyaltyPointHistory::where('customer_id', $this->customer->id)->count();
        $cartIdsBefore = $this->cashierCartIds();
        $shiftSummaryBefore = $this->shiftSummary();
        $customerBefore = $this->customer->fresh();
        $customerLoyaltyBefore = [
            'is_loyalty_member' => (bool) $customerBefore->is_loyalty_member,
            'member_code' => $customerBefore->member_code,
            'loyalty_tier' => $customerBefore->loyalty_tier,
            'loyalty_points' => (int) $customerBefore->loyalty_points,
            'loyalty_total_spent' => (int) $customerBefore->loyalty_total_spent,
            'loyalty_transaction_count' => (int) $customerBefore->loyalty_transaction_count,
            'loyalty_member_since' => $customerBefore->loyalty_member_since?->toISOString(),
            'last_purchase_at' => $customerBefore->last_purchase_at?->toISOString(),
        ];

        $dispatcher = Receivable::getEventDispatcher();
        $this->assertInstanceOf(Dispatcher::class, $dispatcher);
        $eventName = 'eloquent.creating: '.Receivable::class;
        $failureInjectionEnabled = true;
        $failureInjected = false;
        $failureListener = static function (Receivable $receivable) use (
            &$failureInjectionEnabled,
            &$failureInjected
        ): void {
            if ($failureInjectionEnabled && $receivable->transaction_id !== null) {
                $failureInjected = true;
                throw new \RuntimeException('Injected failure during receivable creation.');
            }
        };
        $dispatcher->listen($eventName, $failureListener);

        try {
            $response = $this->postJson('/api/v1/pos/transactions/sync', [
                'transactions' => [$this->syncPayload($uuid)],
            ])->assertOk()
                ->assertJsonPath('data.results.0.status', 'failed');
        } finally {
            $failureInjectionEnabled = false;
        }

        $this->assertTrue($failureInjected, 'The failure must occur during receivable creation.');
        $this->assertNotSame('duplicate', $response->json('data.results.0.status'));
        $this->assertSame($transactionCountBefore, $this->shiftTransactions()->count());
        $this->assertSame(0, Transaction::where('client_uuid', $uuid)->count());
        $this->assertSame($detailCountBefore, $this->shiftTransactionDetails()->count());
        $this->assertSame($tenderCountBefore, $this->shiftTenders()->count());
        $this->assertSame($receivableCountBefore, $this->shiftReceivables()->count());
        $this->assertSame($productStockBefore, (int) $this->product->fresh()->stock);
        $this->assertSame($warehouseStockBefore, (int) $this->warehouseStock());
        $this->assertSame($cartIdsBefore, $this->cashierCartIds());
        $this->assertContains($unrelatedCart->id, $this->cashierCartIds());
        $this->assertSame(
            $loyaltyHistoryBefore,
            LoyaltyPointHistory::where('customer_id', $this->customer->id)->count()
        );

        $customerAfter = $this->customer->fresh();
        $this->assertSame($customerLoyaltyBefore, [
            'is_loyalty_member' => (bool) $customerAfter->is_loyalty_member,
            'member_code' => $customerAfter->member_code,
            'loyalty_tier' => $customerAfter->loyalty_tier,
            'loyalty_points' => (int) $customerAfter->loyalty_points,
            'loyalty_total_spent' => (int) $customerAfter->loyalty_total_spent,
            'loyalty_transaction_count' => (int) $customerAfter->loyalty_transaction_count,
            'loyalty_member_since' => $customerAfter->loyalty_member_since?->toISOString(),
            'last_purchase_at' => $customerAfter->last_purchase_at?->toISOString(),
        ]);
        $this->assertSame($shiftSummaryBefore, $this->shiftSummary());
    }

    public function test_failure_after_split_tenders_are_inserted_rolls_back_checkout_side_effects(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        $cart = $this->createCart(qty: 2);
        Setting::set('tax_cash_rate', '0');
        Setting::set('tax_card_rate', '0');
        Setting::set('loyalty_enable_earn', '1');
        Setting::set('loyalty_earn_rate_amount', '10000');

        $productStockBefore = (int) $this->product->fresh()->stock;
        $warehouseStockBefore = (int) $this->warehouseStock();
        $transactionCountBefore = $this->shiftTransactions()->count();
        $detailCountBefore = $this->shiftTransactionDetails()->count();
        $tenderCountBefore = $this->shiftTenders()->count();
        $receivableCountBefore = $this->shiftReceivables()->count();
        $loyaltyHistoryBefore = LoyaltyPointHistory::where('customer_id', $this->customer->id)->count();
        $cartIdsBefore = $this->cashierCartIds();
        $shiftSummaryBefore = $this->shiftSummary();
        $customerBefore = $this->customer->fresh();
        $customerLoyaltyBefore = [
            'is_loyalty_member' => (bool) $customerBefore->is_loyalty_member,
            'member_code' => $customerBefore->member_code,
            'loyalty_tier' => $customerBefore->loyalty_tier,
            'loyalty_points' => (int) $customerBefore->loyalty_points,
            'loyalty_total_spent' => (int) $customerBefore->loyalty_total_spent,
            'loyalty_transaction_count' => (int) $customerBefore->loyalty_transaction_count,
            'loyalty_member_since' => $customerBefore->loyalty_member_since?->toISOString(),
            'last_purchase_at' => $customerBefore->last_purchase_at?->toISOString(),
        ];

        $dispatcher = LoyaltyPointHistory::getEventDispatcher();
        $this->assertInstanceOf(Dispatcher::class, $dispatcher);
        $eventName = 'eloquent.created: '.LoyaltyPointHistory::class;
        $failureInjectionEnabled = true;
        $failureInjected = false;
        $tenderCountAtFailure = 0;
        $tenderMethodsAtFailure = [];
        $historyCountAtFailure = 0;
        $historyRowVisibleAtFailure = false;
        $historyRowIdAtFailure = null;
        $detailCountAtFailure = 0;
        $productStockAtFailure = null;
        $warehouseStockAtFailure = null;
        $cartIdsAtFailure = [];
        $transactionIdAtFailure = null;
        $productId = (int) $this->product->id;
        $warehouseId = (int) $this->warehouse->id;
        $cashierId = (int) $this->cashier->id;
        $failureListener = static function (LoyaltyPointHistory $history) use (
            &$failureInjectionEnabled,
            &$failureInjected,
            &$tenderCountAtFailure,
            &$tenderMethodsAtFailure,
            &$historyCountAtFailure,
            &$historyRowVisibleAtFailure,
            &$historyRowIdAtFailure,
            &$detailCountAtFailure,
            &$productStockAtFailure,
            &$warehouseStockAtFailure,
            &$cartIdsAtFailure,
            &$transactionIdAtFailure,
            $productId,
            $warehouseId,
            $cashierId
        ): void {
            if (
                $failureInjectionEnabled
                && $history->type === LoyaltyPointHistory::TYPE_EARN
                && $history->transaction_id !== null
            ) {
                $transactionIdAtFailure = (int) $history->transaction_id;
                $historyRowIdAtFailure = (int) $history->getKey();
                $historyRowVisibleAtFailure = LoyaltyPointHistory::query()
                    ->whereKey($historyRowIdAtFailure)
                    ->where('transaction_id', $transactionIdAtFailure)
                    ->where('type', LoyaltyPointHistory::TYPE_EARN)
                    ->exists();
                $historyCountAtFailure = LoyaltyPointHistory::where(
                    'transaction_id',
                    $transactionIdAtFailure
                )->count();
                $tenderMethodsAtFailure = TransactionTender::where('transaction_id', $transactionIdAtFailure)
                    ->orderBy('id')
                    ->pluck('method')
                    ->all();
                $tenderCountAtFailure = count($tenderMethodsAtFailure);
                $detailCountAtFailure = TransactionDetail::where(
                    'transaction_id',
                    $transactionIdAtFailure
                )->count();
                $productStockAtFailure = (int) Product::whereKey($productId)->value('stock');
                $warehouseStockAtFailure = (int) DB::table('product_warehouse')
                    ->where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->value('stock');
                $cartIdsAtFailure = Cart::where('cashier_id', $cashierId)
                    ->where('warehouse_id', $warehouseId)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                if (! $historyRowVisibleAtFailure) {
                    throw new \RuntimeException('The earned loyalty history row was not visible after insertion.');
                }

                $failureInjected = true;
                throw new \RuntimeException('Injected failure after split tenders were inserted.');
            }
        };
        $dispatcher->listen($eventName, $failureListener);

        try {
            $this->postJson('/api/v1/pos/checkout', [
                'customer_id' => $this->customer->id,
                'tenders' => [
                    ['method' => 'cash', 'amount' => 10000, 'cash_received' => 10000],
                    ['method' => 'card', 'amount' => 10000],
                ],
            ])->assertInternalServerError();
        } finally {
            $failureInjectionEnabled = false;
        }

        $this->assertTrue($failureInjected, 'The failure must occur after loyalty finalization starts.');
        $this->assertSame(2, $tenderCountAtFailure, 'Both split tenders must exist before the injected failure.');
        $this->assertSame(['cash', 'card'], $tenderMethodsAtFailure);
        $this->assertTrue($historyRowVisibleAtFailure);
        $this->assertSame(1, $historyCountAtFailure);
        $this->assertSame(1, $detailCountAtFailure);
        $this->assertSame($productStockBefore - 2, $productStockAtFailure);
        $this->assertSame($warehouseStockBefore - 2, $warehouseStockAtFailure);
        $this->assertSame([], $cartIdsAtFailure);
        $this->assertNotNull($transactionIdAtFailure);
        $this->assertNotNull($historyRowIdAtFailure);
        $this->assertSame(0, Transaction::whereKey($transactionIdAtFailure)->count());
        $this->assertFalse(
            LoyaltyPointHistory::whereKey($historyRowIdAtFailure)
                ->where('transaction_id', $transactionIdAtFailure)
                ->where('type', LoyaltyPointHistory::TYPE_EARN)
                ->exists()
        );
        $this->assertSame($transactionCountBefore, $this->shiftTransactions()->count());
        $this->assertSame($detailCountBefore, $this->shiftTransactionDetails()->count());
        $this->assertSame($tenderCountBefore, $this->shiftTenders()->count());
        $this->assertSame(
            0,
            TransactionTender::where('transaction_id', $transactionIdAtFailure)->count()
        );
        $this->assertSame($receivableCountBefore, $this->shiftReceivables()->count());
        $this->assertSame($productStockBefore, (int) $this->product->fresh()->stock);
        $this->assertSame($warehouseStockBefore, (int) $this->warehouseStock());
        $this->assertSame($cartIdsBefore, $this->cashierCartIds());
        $this->assertContains($cart->id, $this->cashierCartIds());
        $this->assertSame(
            $loyaltyHistoryBefore,
            LoyaltyPointHistory::where('customer_id', $this->customer->id)->count()
        );

        $customerAfter = $this->customer->fresh();
        $this->assertSame($customerLoyaltyBefore, [
            'is_loyalty_member' => (bool) $customerAfter->is_loyalty_member,
            'member_code' => $customerAfter->member_code,
            'loyalty_tier' => $customerAfter->loyalty_tier,
            'loyalty_points' => (int) $customerAfter->loyalty_points,
            'loyalty_total_spent' => (int) $customerAfter->loyalty_total_spent,
            'loyalty_transaction_count' => (int) $customerAfter->loyalty_transaction_count,
            'loyalty_member_since' => $customerAfter->loyalty_member_since?->toISOString(),
            'last_purchase_at' => $customerAfter->last_purchase_at?->toISOString(),
        ]);
        $this->assertSame($shiftSummaryBefore, $this->shiftSummary());
    }

    private function assertIsolatedPostgresTestDatabase(): void
    {
        // These checks catch configuration mistakes; they do not cryptographically attest disposability.
        $this->assertSame('true', getenv('CI'));
        $this->assertSame('true', getenv('GITHUB_ACTIONS'));

        $runId = (string) getenv('GITHUB_RUN_ID');
        $runAttempt = (string) getenv('GITHUB_RUN_ATTEMPT');
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $runId);
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $runAttempt);

        $expectedDatabase = "bingopos_pos_sync_{$runId}_{$runAttempt}";
        $this->assertSame('pgsql', config('database.default'));

        $connection = config('database.connections.pgsql');
        $this->assertSame('127.0.0.1', $connection['host']);
        $this->assertSame('5432', (string) $connection['port']);
        $this->assertSame($expectedDatabase, $connection['database']);
        $this->assertSame('pos_sync_test', $connection['username']);

        $url = parse_url((string) $connection['url']);
        $this->assertIsArray($url);
        $this->assertSame('pgsql', $url['scheme'] ?? null);
        $this->assertSame('pos_sync_test', $url['user'] ?? null);
        $this->assertSame('127.0.0.1', $url['host'] ?? null);
        $this->assertSame('5432', (string) ($url['port'] ?? ''));
        $this->assertSame('/'.$expectedDatabase, $url['path'] ?? null);
        parse_str($url['query'] ?? '', $urlQuery);
        $this->assertSame('prefer', $urlQuery['sslmode'] ?? null);

        DB::purge('pgsql');
        $database = DB::connection('pgsql');
        $this->assertSame('pgsql', $database->getDriverName());
        $this->assertSame($expectedDatabase, $database->getDatabaseName());

        $identity = $database->selectOne(
            'SELECT current_database() AS database_name, current_user AS database_user, '
            .'inet_server_addr()::text AS server_address, inet_server_port() AS server_port'
        );
        $this->assertSame($expectedDatabase, $identity->database_name);
        $this->assertSame('pos_sync_test', $identity->database_user);
        $this->assertSame('5432', (string) $identity->server_port);
        $this->assertNotSame('127.0.0.1', $identity->server_address);
        $this->assertTrue(
            $this->isPrivateServiceAddress($identity->server_address),
            'The PostgreSQL service address must be private IPv4 or unique-local IPv6.'
        );

        $role = $database->selectOne(
            'SELECT CASE WHEN rolcanlogin THEN 1 ELSE 0 END AS can_login, '
            .'CASE WHEN rolsuper THEN 1 ELSE 0 END AS is_superuser, '
            .'CASE WHEN rolcreatedb THEN 1 ELSE 0 END AS can_create_database, '
            .'CASE WHEN rolcreaterole THEN 1 ELSE 0 END AS can_create_roles, '
            .'CASE WHEN rolreplication THEN 1 ELSE 0 END AS can_replicate, '
            .'CASE WHEN rolbypassrls THEN 1 ELSE 0 END AS can_bypass_rls '
            .'FROM pg_roles WHERE rolname = current_user'
        );
        $this->assertNotNull($role);
        $this->assertSame(1, (int) $role->can_login);
        $this->assertSame(0, (int) $role->is_superuser);
        $this->assertSame(0, (int) $role->can_create_database);
        $this->assertSame(0, (int) $role->can_create_roles);
        $this->assertSame(0, (int) $role->can_replicate);
        $this->assertSame(0, (int) $role->can_bypass_rls);

        $owner = $database->selectOne(
            'SELECT pg_get_userbyid(datdba) AS database_owner '
            .'FROM pg_database WHERE datname = current_database()'
        );
        $this->assertSame('pos_sync_test', $owner->database_owner);

        $otherDatabaseAccess = $database->select(
            "SELECT datname FROM pg_database WHERE datallowconn "
            ."AND datname <> current_database() "
            ."AND has_database_privilege(current_user, datname, 'CONNECT')"
        );
        $this->assertSame([], $otherDatabaseAccess);

        $packedServerAddress = inet_pton($identity->server_address);
        $this->assertNotFalse($packedServerAddress);
        $addressFamily = strlen($packedServerAddress) === 4 ? 'IPv4' : 'IPv6';
        $isIpv4MappedAddress = strlen($packedServerAddress) === 16
            && substr($packedServerAddress, 0, 12) === str_repeat("\0", 10)."\xff\xff";
        $addressClassification = strlen($packedServerAddress) === 4 || $isIpv4MappedAddress
            ? 'RFC1918'
            : 'ULA';

        fwrite(STDERR, "Isolated PostgreSQL server address family: {$addressFamily}\n");
        fwrite(STDERR, "Isolated PostgreSQL service address classification: {$addressClassification}\n");
    }

    private function isPrivateServiceAddress(?string $address): bool
    {
        if ($address === null) {
            return false;
        }

        $packedAddress = inet_pton($address);
        if ($packedAddress === false) {
            return false;
        }

        if (
            strlen($packedAddress) === 16
            && substr($packedAddress, 0, 12) === str_repeat("\0", 10)."\xff\xff"
        ) {
            $packedAddress = substr($packedAddress, 12);
        }

        if (strlen($packedAddress) === 4) {
            $firstOctet = ord($packedAddress[0]);
            $secondOctet = ord($packedAddress[1]);

            return $firstOctet === 10
                || ($firstOctet === 172 && $secondOctet >= 16 && $secondOctet <= 31)
                || ($firstOctet === 192 && $secondOctet === 168);
        }

        if (strlen($packedAddress) === 16) {
            return (ord($packedAddress[0]) & 0xfe) === 0xfc;
        }

        return false;
    }

    private function createFixtures(): void
    {
        $this->cashier = User::factory()->create();
        $this->warehouse = Warehouse::create([
            'code' => 'WH-PS-'.strtoupper($this->fixtureSuffix),
            'name' => 'POS Sync Test Warehouse',
            'status' => 'active',
        ]);
        $category = Category::create([
            'name' => 'POS Sync Test Category '.$this->fixtureSuffix,
            'image' => '',
            'description' => '',
        ]);
        $this->product = Product::create([
            'title' => 'POS Sync Test Product '.$this->fixtureSuffix,
            'barcode' => 'PS-'.$this->fixtureSuffix,
            'sku' => 'PS-'.$this->fixtureSuffix,
            'image' => '',
            'description' => '',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 50,
            'category_id' => $category->id,
            'tax_type' => 'exclusive',
            'tax_rate' => 0,
            'min_stock' => 0,
            'max_stock' => 100,
            'is_composite' => false,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 50]);

        $this->customer = Customer::create([
            'name' => 'POS Sync Test Loyalty Member '.$this->fixtureSuffix,
            'no_telp' => '62877'.random_int(10000000, 99999999),
            'address' => 'POS Sync Test',
            'is_loyalty_member' => true,
            'member_code' => 'PS-MEMBER-'.$this->fixtureSuffix,
            'loyalty_tier' => 'silver',
            'loyalty_points' => 50,
            'loyalty_member_since' => now()->subMonth(),
        ]);
        Setting::set('loyalty_enable_redeem', '1');
        Setting::set('loyalty_earn_rate_amount', '10000');

        $shift = app(CashierShiftService::class)->openShift(
            cashier: $this->cashier,
            actor: $this->cashier,
            openingCash: 100000,
            notes: null,
            warehouseId: $this->warehouse->id,
        );
        $this->shiftId = $shift->id;
    }

    private function createCart(int $qty): Cart
    {
        return Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'unit_id' => null,
            'conversion_factor' => 1,
            'qty' => $qty,
            'price' => $this->product->sell_price * $qty,
        ]);
    }

    private function syncPayload(string $uuid): array
    {
        return [
            'client_uuid' => $uuid,
            'customer_id' => $this->customer->id,
            'redeem_points' => 10,
            'pay_later' => true,
            'due_date' => $this->dueDate,
            'items' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_id' => null],
            ],
        ];
    }

    private function workerInput(
        string $workerId,
        string $uuid,
        string $token,
        string $barrierDirectory
    ): array {
        return [
            'worker_id' => $workerId,
            'uuid' => $uuid,
            'token' => $token,
            'barrier_directory' => $barrierDirectory,
            'payload' => $this->syncPayload($uuid),
        ];
    }

    private function assertSingleCommittedSale(string $uuid, ?Cart $unrelatedCart = null): void
    {
        $this->assertSame(1, $this->shiftTransactions()->where('client_uuid', $uuid)->count());
        $transaction = $this->shiftTransactions()->where('client_uuid', $uuid)->firstOrFail();
        $this->assertSame(1, $this->shiftTransactions()->count());
        $this->assertSame($this->cashier->id, $transaction->cashier_id);
        $this->assertSame($this->shiftId, (int) $transaction->cashier_shift_id);
        $this->assertSame($this->warehouse->id, (int) $transaction->warehouse_id);
        $this->assertSame($this->customer->id, (int) $transaction->customer_id);

        $details = TransactionDetail::where('transaction_id', $transaction->id)->get();
        $this->assertCount(1, $details);
        $this->assertSame($this->product->id, (int) $details->sole()->product_id);
        $this->assertSame(2, (int) $details->sole()->qty);

        $this->assertSame(0, TransactionTender::where('transaction_id', $transaction->id)->count());

        $receivable = Receivable::where('transaction_id', $transaction->id)->sole();
        $this->assertSame((int) $transaction->id, (int) $receivable->transaction_id);
        $this->assertSame($this->customer->id, (int) $receivable->customer_id);
        $this->assertSame($transaction->invoice, $receivable->invoice);
        $this->assertSame((int) $transaction->grand_total, (int) $receivable->total);
        $this->assertSame(0, (int) $receivable->paid);
        $this->assertSame('unpaid', $receivable->status);
        $this->assertSame($this->dueDate, $receivable->due_date->toDateString());

        $history = LoyaltyPointHistory::where('transaction_id', $transaction->id)
            ->get();
        $this->assertCount(2, $history);
        $history = $history
            ->keyBy('type');
        $this->assertSame(
            [LoyaltyPointHistory::TYPE_EARN, LoyaltyPointHistory::TYPE_REDEEM],
            $history->keys()->sort()->values()->all()
        );
        $this->assertSame(-10, (int) $history[LoyaltyPointHistory::TYPE_REDEEM]->points_delta);
        $this->assertSame(
            (int) $transaction->loyalty_points_earned,
            (int) $history[LoyaltyPointHistory::TYPE_EARN]->points_delta
        );

        $customer = $this->customer->fresh();
        $this->assertSame(1, (int) $customer->loyalty_transaction_count);
        $this->assertSame(
            40 + (int) $transaction->loyalty_points_earned,
            (int) $customer->loyalty_points
        );

        $this->assertSame(48, (int) $this->product->fresh()->stock);
        $this->assertSame(48, (int) $this->warehouseStock());

        $summary = $this->shiftSummary();
        $this->assertSame(1, $summary['transactions_count']);
        $this->assertSame((int) $transaction->grand_total, $summary['non_cash_sales_total']);
        $this->assertSame(100000, $summary['expected_cash']);

        if ($unrelatedCart) {
            $this->assertSame([$unrelatedCart->id], $this->cashierCartIds());
        } else {
            $this->assertSame([], $this->cashierCartIds());
        }
    }

    private function shiftTransactions()
    {
        return Transaction::query()->where('cashier_shift_id', $this->shiftId);
    }

    private function shiftTransactionDetails()
    {
        return TransactionDetail::query()->whereIn(
            'transaction_id',
            $this->shiftTransactions()->select('id')
        );
    }

    private function shiftReceivables()
    {
        return Receivable::query()->whereIn(
            'transaction_id',
            $this->shiftTransactions()->select('id')
        );
    }

    private function shiftTenders()
    {
        return TransactionTender::query()->whereIn(
            'transaction_id',
            $this->shiftTransactions()->select('id')
        );
    }

    private function cashierCartIds(): array
    {
        return Cart::query()
            ->where('cashier_id', $this->cashier->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function warehouseStock(): int
    {
        return (int) $this->product->warehouses()
            ->whereKey($this->warehouse->id)
            ->firstOrFail()
            ->pivot
            ->stock;
    }

    private function shiftSummary(): array
    {
        return app(CashierShiftService::class)->calculateSummary(
            CashierShift::findOrFail($this->shiftId)
        );
    }

    private function createTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'bingopos-pos-sync-'.bin2hex(random_bytes(12));

        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Unable to create the sync worker temporary directory.');
        }

        return $directory;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new \FilesystemIterator($directory) as $entry) {
            if (! $entry->isFile() && ! $entry->isLink()) {
                throw new \RuntimeException('Unexpected directory found in sync worker temporary data.');
            }

            if (! unlink($entry->getPathname())) {
                throw new \RuntimeException('Unable to remove a sync worker temporary file.');
            }
        }

        if (! rmdir($directory)) {
            throw new \RuntimeException('Unable to remove the sync worker temporary directory.');
        }
    }
}
