<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Http\Resources\CashierShiftResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\TransactionResource;
use App\Http\Traits\ApiResponder;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\DiscountApprovalLog;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\CheckoutService;
use App\Services\LoyaltyService;
use App\Services\OutletAccessService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\PriceListService;
use App\Services\PricingService;
use App\Services\TransactionTenderService;
use App\Services\UnitConversionService;
use App\Support\Checkout\CheckoutContext;
use App\Support\Checkout\OfflineSyncCheckoutFailed;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosApiController extends Controller
{
    use ApiResponder;

    public function __construct(
        private readonly CashierShiftService $cashierShiftService,
        private readonly PricingService $pricingService,
        private readonly LoyaltyService $loyaltyService,
        private readonly UnitConversionService $unitConversionService,
        private readonly PriceListService $priceListService,
        private readonly TransactionTenderService $tenderService,
        private readonly OutletAccessService $outletAccessService,
        private readonly CheckoutService $checkoutService,
    ) {}

    /**
     * GET /api/v1/pos/shift
     * Get the current active shift, or null if no shift is active.
     */
    public function currentShift(Request $request): JsonResponse
    {
        $shift = $this->cashierShiftService->getActiveShiftForUser(
            $request->user()->id
        );

        return $this->ok([
            'shift' => $shift
                ? new CashierShiftResource($shift->load('warehouse'))
                : null,
        ]);
    }

    /**
     * POST /api/v1/pos/shift/open
     * Open a cashier shift.
     */
    public function openShift(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_cash' => ['required', 'numeric', 'min:0'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $warehouseId = $validated['warehouse_id'] ?? null;

        if (! $warehouseId) {
            $warehouse = $this->outletAccessService
                ->salesWarehousesFor($request->user())
                ->first();

            $warehouseId = $warehouse?->id;
        }

        if (! $this->outletAccessService->canSellAtWarehouse(
            $request->user(),
            $warehouseId ? Warehouse::find($warehouseId) : null
        )) {
            return $this->forbidden(
                'This outlet is not accessible to your account.'
            );
        }

        try {
            $shift = $this->cashierShiftService->openShift(
                cashier: $request->user(),
                actor: $request->user(),
                openingCash: (int) $validated['opening_cash'],
                notes: $validated['notes'] ?? null,
                warehouseId: $warehouseId,
            );
        } catch (ValidationException $e) {
            return $this->validationError(
                $e->errors(),
                $e->getMessage()
            );
        }

        return $this->created(
            new CashierShiftResource($shift->load('warehouse')),
            'Cashier shift opened successfully.'
        );
    }

    /**
     * POST /api/v1/pos/shift/close
     * Close the active shift using the actual cash count.
     */
    public function closeShift(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'closing_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = $this->cashierShiftService->getActiveShiftForUser(
            $request->user()->id
        );

        if (! $shift) {
            return $this->error('There is no active cashier shift.', 422);
        }

        try {
            $closed = $this->cashierShiftService->closeShift(
                shift: $shift,
                actor: $request->user(),
                actualCash: (int) $validated['closing_cash'],
                closeNotes: $validated['notes'] ?? null,
            );
        } catch (\Throwable $e) {
            report($e);

            return $this->error('Unable to close the cashier shift. Please try again.', 500);
        }

        return $this->ok(
            new CashierShiftResource($closed->load('warehouse')),
            'Cashier shift closed successfully.'
        );
    }

    /**
     * GET /api/v1/pos/products?search=&category_id=&page=&per_page=
     * Get a searchable product list for the POS product picker.
     */
    public function products(Request $request): JsonResponse
    {
        $shift = $this->cashierShiftService->getActiveShiftForUser(
            $request->user()->id
        );

        $warehouseId = $shift?->warehouse_id;
        $relations = ['category', 'units', 'components'];
        if ($warehouseId) {
            $relations['warehouses'] = fn ($query) => $query->where('warehouses.id', $warehouseId);
        }

        $products = Product::query()
            ->with($relations)
            ->where(function ($query) use ($warehouseId) {
                if ($warehouseId) {
                    $query->whereHas(
                        'warehouses',
                        fn ($warehouse) => $warehouse
                            ->where('product_warehouse.warehouse_id', $warehouseId)
                            ->where('product_warehouse.stock', '>', 0)
                    );
                } else {
                    $query->where('stock', '>', 0);
                }

                $query->orWhere('stock_mode', 'made_to_order');
            })
            ->when(
                $request->string('search')->toString(),
                function ($q, $search) {
                    $q->where(function ($sub) use ($search) {
                        $sub->where('title', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
                    });
                }
            )
            ->when(
                $request->integer('category_id'),
                fn ($q, $id) => $q->where('category_id', $id)
            )
            ->latest()
            ->paginate($this->perPage());

        // Attach warehouse-specific stock when a shift is active.
        $products->through(function (Product $product) use ($warehouseId) {
            $data = (new ProductResource($product))->resolve();

            if ($product->is_composite) {
                $data['sell_price'] = (int) $product->components->sum(
                    fn (Product $component) => $component->sell_price * (float) $component->pivot->qty
                );
            }
            $data['stock'] = $warehouseId
                ? (int) (
                    $product->warehouses->first()?->pivot->stock ?? 0
                )
                : (int) $product->stock;

            return $data;
        });

        return $this->paginated($products);
    }

    /**
     * Get categories which have products available to the active POS shift.
     */
    public function productCategories(Request $request): JsonResponse
    {
        $shift = $this->cashierShiftService->getActiveShiftForUser(
            $request->user()->id
        );
        $warehouseId = $shift?->warehouse_id;

        $categories = Category::query()
            ->whereHas('products', function ($query) use ($warehouseId) {
                $query->where(function ($available) use ($warehouseId) {
                    if ($warehouseId) {
                        $available->whereHas(
                            'warehouses',
                            fn ($warehouse) => $warehouse
                                ->where('product_warehouse.warehouse_id', $warehouseId)
                                ->where('product_warehouse.stock', '>', 0)
                        );
                    } else {
                        $available->where('stock', '>', 0);
                    }

                    $available->orWhere('stock_mode', 'made_to_order');
                });
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        return $this->ok($categories);
    }

    /**
     * POST /api/v1/pos/products/scan
     * Scan a barcode and return the product with its available stock.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string'],
        ]);

        $shift = $this->cashierShiftService->getActiveShiftForUser(
            $request->user()->id
        );

        $warehouseId = $shift?->warehouse_id;

        $product = Product::whereRaw(
            'LOWER(barcode) = ?',
            [strtolower($validated['barcode'])]
        )
            ->when(
                $warehouseId,
                fn ($q) => $q->whereHas(
                    'warehouses',
                    fn ($w) => $w->where(
                        'product_warehouse.warehouse_id',
                        $warehouseId
                    )
                )
            )
            ->first();

        if (! $product) {
            return $this->notFound(
                'No product was found with the specified barcode.'
            );
        }

        $stock = $warehouseId
            ? (int) (
                $product->warehouses()
                    ->where('warehouse_id', $warehouseId)
                    ->first()?->pivot->stock ?? 0
            )
            : (int) $product->stock;

        $data = (new ProductResource(
            $product->load('category')
        ))->resolve();

        $data['is_live_cooking'] = $product->stock_mode === 'made_to_order';
        $data['stock'] = $stock;

        $data['units'] = $product->units()->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'conversion_factor' => (float) $u->pivot->conversion_factor,
        ]);

        return $this->ok($data, 'Product found successfully.');
    }

    /**
     * GET /api/v1/pos/cart
     * Get the current active cart and checkout summary.
     */
    public function cart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tax_rate_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['nullable', 'in:cash,card,bank_transfer,midtrans,xendit,safepay,qris,pay_later'],
            'tenders' => ['nullable', 'array', 'max:2'],
            'tenders.*.method' => ['required_with:tenders', 'in:cash,card,bank_transfer,midtrans,xendit,safepay,qris'],
            'tenders.*.amount' => ['required_with:tenders', 'integer', 'min:1'],
        ]);
        $carts = Cart::with('product', 'unit')
            ->where('cashier_id', $request->user()->id)
            ->active()
            ->latest()
            ->get();

        $customer = $request->integer('customer_id')
            ? Customer::find($request->integer('customer_id'))
            : null;

        $activeShift = $this->cashierShiftService->getActiveShiftForUser(
            $request->user()->id
        );

        $activeShift?->loadMissing('warehouse.outlet');

        $outlet = $activeShift?->warehouse?->outlet;

        $preview = $this->pricingService->previewCart(
            $carts,
            $customer,
            null,
            $outlet
        );

        $checkout = $this->loyaltyService->previewCheckout(
            $preview,
            $customer,
            [
                'manual_discount' => (int) $request->integer('discount', 0),
                'shipping_cost' => (int) $request->integer('shipping_cost', 0),
                'redeem_points' => (int) $request->integer('redeem_points', 0),
                'tax_rate_override' => $validated['tax_rate_override'] ?? null,
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'tenders' => $validated['tenders'] ?? [],
            ],
            null,
            $outlet
        );

        return $this->ok([
            'items' => CartResource::collection($carts),
            'summary' => [
                'subtotal' => (float) data_get($checkout, 'summary.subtotal', 0),
                'subtotal_after_promo' => (float) data_get(
                    $checkout,
                    'summary.subtotal_after_promo',
                    0
                ),
                'discount_total' => (float) data_get(
                    $checkout,
                    'summary.discount_total',
                    0
                ),
                'voucher_discount' => (float) data_get(
                    $checkout,
                    'summary.voucher_discount_total',
                    0
                ),
                'loyalty_discount' => (float) data_get(
                    $checkout,
                    'summary.loyalty_discount_total',
                    0
                ),
                'manual_discount' => (float) data_get(
                    $checkout,
                    'summary.manual_discount_total',
                    0
                ),
                'shipping_cost' => (float) $request->integer(
                    'shipping_cost',
                    0
                ),
                'tax_rate' => (float) data_get(
                    $checkout,
                    'summary.tax_rate',
                    0
                ),
                'tax_total' => (float) data_get(
                    $checkout,
                    'summary.tax_total',
                    0
                ),
                'grand_total' => (float) data_get(
                    $checkout,
                    'summary.grand_total',
                    0
                ),
                'points_earned' => (int) data_get(
                    $checkout,
                    'summary.points_earned',
                    0
                ),
                'available_points' => (int) data_get(
                    $checkout,
                    'customer.available_points',
                    0
                ),
            ],
        ]);
    }

    /**
     * POST /api/v1/pos/cart
     * Add a product to the cart.
     */
    public function addToCart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'min:0.01'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $shift = $this->cashierShiftService->getActiveShiftForUser(
            $request->user()->id
        );

        if (! $shift) {
            return $this->error(
                'Please open a cashier shift before adding products to the cart.',
                422
            );
        }

        $warehouseId = $shift->warehouse_id;

        $product = Product::find($validated['product_id']);

        if (! $product) {
            return $this->notFound('Product not found.');
        }

        $isLiveCooking = $product->stock_mode === 'made_to_order';

        if ($product->is_composite) {
            $product->load('components');

            foreach ($product->components as $component) {
                $needed = (float) $component->pivot->qty
                    * $validated['qty'];

                $avail = (int) (
                    $component->warehouses()
                        ->where('warehouse_id', $warehouseId)
                        ->first()?->pivot->stock ?? 0
                );

                if ($avail < $needed) {
                    return $this->error(
                        "Insufficient stock for {$component->title}.",
                        422
                    );
                }
            }

            $sellPrice = (int) $product->components->sum(
                fn ($c) => $c->sell_price * (float) $c->pivot->qty
            );

            $unitId = null;
            $conversionFactor = 1;
        } else {
            $unitId = (int) (
                $validated['unit_id']
                ?? $product->baseUnit()?->id
                ?? 1
            );

            if (
                isset($validated['unit_id'])
                && ! $product->units()->whereKey($unitId)->exists()
            ) {
                return $this->error(
                    'The selected unit is not valid for this product.',
                    422
                );
            }

            $baseQty = $this->unitConversionService->toBaseUnit(
                $product,
                $unitId,
                $validated['qty']
            );

            $availableStock = $warehouseId
                ? (int) (
                    $product->warehouses()
                        ->where('warehouse_id', $warehouseId)
                        ->first()?->pivot->stock ?? 0
                )
                : (int) $product->stock;

            if (! $isLiveCooking && $availableStock < $baseQty) {
                return $this->error(
                    "Insufficient stock. Available quantity: {$availableStock}.",
                    422
                );
            }

            $sellPrice = $this->unitConversionService->getSellPrice(
                $product,
                $unitId
            );

            $pu = $product->units()
                ->where('unit_id', $unitId)
                ->first();

            $conversionFactor = $pu?->pivot->conversion_factor ?? 1;
        }

        $cart = Cart::with('product')
            ->where('product_id', $validated['product_id'])
            ->where('cashier_id', $request->user()->id)
            ->active()
            ->first();

        if ($cart) {
            $cart->increment('qty', $validated['qty']);
            $cart->price = $sellPrice * $cart->qty;
            $cart->save();
        } else {
            $cart = Cart::create([
                'cashier_id' => $request->user()->id,
                'warehouse_id' => $warehouseId,
                'product_id' => $validated['product_id'],
                'unit_id' => $unitId,
                'conversion_factor' => $conversionFactor,
                'qty' => $validated['qty'],
                'price' => $sellPrice * $validated['qty'],
            ]);
        }

        return $this->ok(
            new CartResource($cart->load('product', 'unit')),
            'Product added to cart successfully.'
        );
    }

    /**
     * PUT /api/v1/pos/cart/{cart}
     * Update the quantity of a cart item.
     */
    public function updateCart(Request $request, Cart $cart): JsonResponse
    {
        $validated = $request->validate([
            'qty' => ['required', 'numeric', 'min:0.01'],
        ]);

        if ($cart->cashier_id !== $request->user()->id) {
            return $this->forbidden(
                'You do not have permission to modify this cart.'
            );
        }

        $shift = $this->cashierShiftService->getActiveShiftForUser(
            $request->user()->id
        );

        if (! $shift) {
            return $this->error(
                'Please open a cashier shift before updating the cart.',
                422
            );
        }

        $warehouseId = $shift->warehouse_id;

        $product = $cart->product;

        $isLiveCooking = $product->stock_mode === 'made_to_order';

        if (! $product->is_composite && ! $isLiveCooking) {
            $baseQty = $this->unitConversionService->toBaseUnit(
                $product,
                $cart->unit_id,
                $validated['qty']
            );

            $availableStock = $warehouseId
                ? (int) (
                    $product->warehouses()
                        ->where('warehouse_id', $warehouseId)
                        ->first()?->pivot->stock ?? 0
                )
                : (int) $product->stock;

            if ($availableStock < $baseQty) {
                return $this->error(
                    "Insufficient stock. Available quantity: {$availableStock}.",
                    422
                );
            }
        }

        $cart->qty = $validated['qty'];

        // Preserve variation unit price; recalc for non-variations
        if ($cart->variation_id) {
            $unitPrice = (int) round($cart->price / max(1, (int) $cart->qty));
            $cart->price = $unitPrice * $validated['qty'];
        } else {
            $cart->price = $product->is_composite
                ? (int) $product->components->sum(
                    fn ($c) => $c->sell_price * (float) $c->pivot->qty
                ) * $validated['qty']
                : $this->unitConversionService->getSellPrice(
                    $product,
                    $cart->unit_id
                ) * $validated['qty'];
        }

        $cart->save();

        return $this->ok(
            new CartResource($cart->load('product', 'unit')),
            'Cart updated successfully.'
        );
    }

    /**
     * DELETE /api/v1/pos/cart/{cart}
     * Remove an item from the cart.
     */
    public function removeFromCart(
        Request $request,
        Cart $cart
    ): JsonResponse {
        if ($cart->cashier_id !== $request->user()->id) {
            return $this->forbidden(
                'You do not have permission to remove this cart item.'
            );
        }

        $cart->delete();

        return $this->noContent();
    }

    /**
     * POST /api/v1/pos/cart/clear
     * Clear all active cart items belonging to the current cashier.
     */
    public function clearCart(Request $request): JsonResponse
    {
        Cart::where('cashier_id', $request->user()->id)
            ->active()
            ->delete();

        return $this->ok(null, 'Cart cleared successfully.');
    }

    /**
     * POST /api/v1/pos/hold
     * Hold the current cart for later processing.
     */
    public function holdCart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
        ]);

        $userId = $request->user()->id;

        $activeCarts = Cart::where('cashier_id', $userId)
            ->active()
            ->get();

        if ($activeCarts->isEmpty()) {
            return $this->error(
                'The cart is empty. There is no transaction to hold.',
                422
            );
        }

        $holdId = 'HOLD-' . strtoupper(Str::random(10));

        $label = $validated['label']
            ?? 'Transaction ' . now()->format('H:i');

        Cart::where('cashier_id', $userId)
            ->active()
            ->update([
                'hold_id' => $holdId,
                'hold_label' => $label,
                'held_at' => now(),
            ]);

        return $this->ok(
            [
                'hold_id' => $holdId,
                'label' => $label,
            ],
            'Transaction held successfully.'
        );
    }

    /**
     * GET /api/v1/pos/holds
     * List all held carts belonging to the current cashier.
     */
    public function heldCarts(Request $request): JsonResponse
    {
        $held = Cart::with('product:id,title,sell_price,image')
            ->where('cashier_id', $request->user()->id)
            ->held()
            ->get()
            ->groupBy('hold_id')
            ->map(function ($items, $holdId) {
                $first = $items->first();

                return [
                    'hold_id' => $holdId,
                    'label' => $first->hold_label,
                    'held_at' => optional($first->held_at)->toISOString(),
                    'items_count' => (int) $items->sum('qty'),
                    'total' => (float) $items->sum('price'),
                ];
            })
            ->values();

        return $this->ok(['holds' => $held]);
    }

    /**
     * POST /api/v1/pos/holds/{holdId}/resume
     * Resume a previously held cart.
     */
    public function resumeHold(
        Request $request,
        string $holdId
    ): JsonResponse {
        $userId = $request->user()->id;

        $activeCount = Cart::where('cashier_id', $userId)
            ->active()
            ->count();

        if ($activeCount > 0) {
            return $this->error(
                'Please complete or hold the current transaction first.',
                422
            );
        }

        $held = Cart::where('cashier_id', $userId)
            ->forHold($holdId)
            ->get();

        if ($held->isEmpty()) {
            return $this->notFound('The held transaction was not found.');
        }

        Cart::where('cashier_id', $userId)
            ->forHold($holdId)
            ->update([
                'hold_id' => null,
                'hold_label' => null,
                'held_at' => null,
            ]);

        return $this->ok(null, 'Transaction resumed successfully.');
    }

    /**
     * DELETE /api/v1/pos/holds/{holdId}
     * Delete a held cart.
     */
    public function deleteHold(
        Request $request,
        string $holdId
    ): JsonResponse {
        $deleted = Cart::where('cashier_id', $request->user()->id)
            ->forHold($holdId)
            ->delete();

        if ($deleted === 0) {
            return $this->notFound('The held transaction was not found.');
        }

        return $this->noContent();
    }

    /**
     * POST /api/v1/pos/checkout
     * Complete a transaction using cash, pay-later, or a payment gateway.
     */
    public function checkout(
        Request $request,
        PaymentGatewayManager $paymentGatewayManager
    ): JsonResponse {
        $validated = $request->validate([
            'customer_id' => [
                'nullable',
                'integer',
                'exists:customers,id',
                'required_if:payment_method,pay_later',
            ],
            'customer_voucher_id' => [
                'nullable',
                'integer',
                'exists:customer_vouchers,id',
            ],
            'discount' => ['nullable', 'integer', 'min:0'],
            'shipping_cost' => ['nullable', 'integer', 'min:0'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            'tax_rate_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cash' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => [
                'nullable',
                'in:cash,card,bank_transfer,midtrans,xendit,safepay,qris,pay_later',
            ],
            'bank_account_id' => [
                'nullable',
                'integer',
                'exists:bank_accounts,id',
            ],
            'due_date' => [
                'nullable',
                'date',
                'required_if:payment_method,pay_later',
            ],
            'customer_npwp' => ['nullable', 'string', 'max:50'],
            'order_type' => [
                'nullable',
                'in:in_store,takeaway,delivery',
            ],
            'note' => ['nullable', 'string', 'max:1000'],
            'tenders' => ['nullable', 'array', 'max:2'],
            'tenders.*.method' => ['required', 'string'],
            'tenders.*.amount' => ['required', 'integer', 'min:1'],
            'tenders.*.cash_received' => ['nullable', 'integer', 'min:0'],
            'tenders.*.bank_account_id' => [
                'nullable',
                'integer',
                'exists:bank_accounts,id',
            ],
        ]);

        $paymentMethod = $validated['payment_method'] ?? 'cash';

        $isPayLater = $paymentMethod === 'pay_later';

        $tenderInput = $validated['tenders'] ?? [];

        $useTenders = ! $isPayLater && $tenderInput !== [];

        $paymentGateway = ! $isPayLater && $paymentMethod !== 'cash'
            ? $paymentMethod
            : null;

        $activeShift = $this->cashierShiftService->getActiveShiftForUser(
            $request->user()->id
        );

        $activeShift?->load('warehouse.outlet');

        $outlet = $activeShift?->warehouse?->outlet;

        if ($isPayLater && ! $request->filled('due_date')) {
            return $this->error(
                'A due date is required for pay-later transactions.',
                422
            );
        }

        if ($paymentGateway && $paymentGateway !== TransactionTender::METHOD_CARD) {
            $paymentSetting = PaymentSetting::forOutlet($outlet);

            $gatewayReady = $paymentSetting
                && (
                    $paymentGateway === 'qris'
                        ? $paymentSetting->isGatewayReady(
                            PaymentSetting::GATEWAY_MIDTRANS
                        )
                            || $paymentSetting->isGatewayReady(
                                PaymentSetting::GATEWAY_XENDIT
                            )
                        : $paymentSetting->isGatewayReady($paymentGateway)
                );

            if (! $gatewayReady) {
                return $this->error(
                    'The payment gateway has not been configured.',
                    422
                );
            }
        }

        $isCashPayment = ! $paymentGateway
            && ! $isPayLater
            && ! $useTenders;

        $cashAmount = $isCashPayment
            ? max(0, (int) ($validated['cash'] ?? 0))
            : 0;

        $customer = isset($validated['customer_id'])
            ? Customer::find($validated['customer_id'])
            : null;

        $voucher = isset($validated['customer_voucher_id'])
            ? CustomerVoucher::find($validated['customer_voucher_id'])
            : null;

        $manualDiscount = max(
            0,
            (int) ($validated['discount'] ?? 0)
        );

        $context = new CheckoutContext(
            userId: $request->user()->id,
            customer: $customer,
            voucher: $voucher,
            manualDiscount: $manualDiscount,
            shippingCost: max(
                0,
                (int) ($validated['shipping_cost'] ?? 0)
            ),
            requestedRedeemPoints: max(
                0,
                (int) ($validated['redeem_points'] ?? 0)
            ),
            isPayLater: $isPayLater,
            dueDate: $validated['due_date'] ?? null,
            orderType: $validated['order_type'] ?? null,
            note: isset($validated['note'])
                ? trim($validated['note']) ?: null
                : null,
            customerNpwp: $validated['customer_npwp'] ?? null,
            isCashPayment: $isCashPayment,
            cashAmount: $cashAmount,
            paymentGateway: $paymentGateway,
            useTenders: $useTenders,
            tenderInput: $tenderInput,
            outlet: $outlet,
            bankAccountId: $validated['bank_account_id'] ?? null,
            taxRateOverride: isset($validated['tax_rate_override'])
                ? (float) $validated['tax_rate_override']
                : null,
            clientUuid: $request->attributes->get('offline_sync_client_uuid'),
            cartIds: $request->attributes->get('offline_sync_cart_ids'),
        );

        try {
            $result = $this->checkoutService->execute($context);
            $transaction = $result->transaction;
        } catch (ValidationException $e) {
            return $this->validationError(
                $e->errors(),
                $e->getMessage()
            );
        } catch (HttpException $e) {
            // Return a clean JSON error when the cart is empty.
            if ($e->getMessage() === 'Keranjang kosong.') {
                return $this->error('The cart is empty.', 422);
            }

            throw $e;
        }

        // Handle discount approval.
        if (
            $transaction->discount > 0
            && $transaction->needsDiscountApproval()
        ) {
            $transaction->update([
                'discount_approval_status' => 'pending',
                'payment_status' => 'pending_approval',
            ]);

            DiscountApprovalLog::create([
                'transaction_id' => $transaction->id,
                'cashier_id' => $request->user()->id,
                'requested_discount' => $manualDiscount,
                'status' => 'pending',
            ]);

            return $this->ok(
                new TransactionResource(
                    $transaction->load(
                        'details.product',
                        'customer',
                        'cashier',
                        'tenders'
                    )
                ),
                'The transaction is awaiting supervisor approval.',
                202
            );
        }

        // Process payments through configured payment gateways.
        if ($useTenders) {
            $gatewayTenders = $transaction->tenders()
                ->whereIn('method', [
                    TransactionTender::METHOD_MIDTRANS,
                    TransactionTender::METHOD_XENDIT,
                    TransactionTender::METHOD_QRIS,
                ])
                ->where(
                    'payment_status',
                    TransactionTender::STATUS_PENDING
                )
                ->get();

            try {
                $paymentSetting ??= PaymentSetting::forOutlet($outlet);

                foreach ($gatewayTenders as $tender) {
                    $paymentResponse = $paymentGatewayManager
                        ->createTenderPayment(
                            $transaction,
                            $tender,
                            $paymentSetting
                        );

                    $tender->update([
                        'payment_reference' => $paymentResponse['reference'] ?? null,
                        'payment_url' => $paymentResponse['payment_url'] ?? null,
                        'qr_string' => $paymentResponse['qr_string'] ?? null,
                    ]);
                }

                if ($gatewayTenders->count() === 1) {
                    $transaction->update(
                        $gatewayTenders->first()->only([
                            'payment_reference',
                            'payment_url',
                            'qr_string',
                        ])
                    );
                }
            } catch (\Throwable $e) {
                // Keep the transaction valid if gateway initialization fails.
                $transaction->update([
                    'payment_status' => 'pending',
                ]);
            }
        } elseif ($paymentGateway && $paymentGateway !== TransactionTender::METHOD_CARD) {
            try {
                $paymentResponse = $paymentGateway === 'qris'
                    ? $paymentGatewayManager->createQrisPayment(
                        $transaction,
                        $paymentSetting
                    )
                    : $paymentGatewayManager->createPayment(
                        $transaction,
                        $paymentGateway,
                        $paymentSetting
                    );

                $transaction->update([
                    'payment_method' => $paymentGateway === 'qris'
                        ? ($paymentResponse['raw']['payment_type'] ?? 'qris')
                        : $paymentGateway,
                    'payment_reference' => $paymentResponse['reference'] ?? null,
                    'payment_url' => $paymentResponse['payment_url'] ?? null,
                    'qr_string' => $paymentResponse['qr_string'] ?? null,
                ]);
            } catch (\Throwable $e) {
                // Keep the transaction valid if gateway initialization fails.
                $transaction->update([
                    'payment_status' => 'pending',
                ]);
            }
        }

        $resource = new TransactionResource(
            $transaction->load(
                'details.product',
                'customer',
                'cashier',
                'warehouse',
                'tenders'
            )
        );

        if ($paymentGateway === 'qris') {
            $data = $resource->toArray(request());
            $data['payment_method'] = $transaction->payment_method;

            return $this->created(
                $data,
                'Transaction created successfully. Scan the QR code to pay.'
            );
        }

        return $this->created(
            $resource,
            'Transaction completed successfully.'
        );
    }

    /**
     * POST /api/v1/pos/transactions/sync
     * Synchronize offline transactions queued by the client.
     *
     * Each queued transaction includes its own items[] because server-side
     * carts are not available while offline. Prices are recalculated on the
     * server, and the client-provided grand_total is ignored.
     */
    public function syncTransactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transactions' => ['required', 'array', 'max:50'],
            'transactions.*.client_uuid' => ['required', 'uuid'],
            'transactions.*.customer_id' => [
                'nullable',
                'integer',
                'exists:customers,id',
            ],
            'transactions.*.discount' => ['nullable', 'integer', 'min:0'],
            'transactions.*.redeem_points' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'transactions.*.tax_rate_override' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'transactions.*.cash' => ['nullable', 'numeric', 'min:0'],
            'transactions.*.payment_gateway' => ['nullable', 'in:card'],
            'transactions.*.pay_later' => ['nullable', 'boolean'],
            'transactions.*.due_date' => ['nullable', 'date'],
            'transactions.*.items' => ['required', 'array', 'min:1'],
            'transactions.*.items.*.product_id' => [
                'required',
                'integer',
                'exists:products,id',
            ],
            'transactions.*.items.*.qty' => [
                'required',
                'numeric',
                'min:0.01',
            ],
            'transactions.*.items.*.unit_id' => [
                'nullable',
                'integer',
                'exists:units,id',
            ],
        ]);

        $normalizedUuids = array_map(
            static fn (array $transaction): string => Str::lower($transaction['client_uuid']),
            $validated['transactions']
        );
        if (count($normalizedUuids) !== count(array_unique($normalizedUuids))) {
            return $this->validationError(
                ['transactions' => ['Each client_uuid must be unique within the batch.']],
                'Duplicate client_uuid values are not allowed in one batch.'
            );
        }

        $results = [];

        foreach ($validated['transactions'] as $index => $payload) {
            $uuid = $normalizedUuids[$index];
            $payload['client_uuid'] = $uuid;

            // Return the existing transaction if this UUID was already synced.
            if ($existing = Transaction::where('client_uuid', $uuid)->first()) {
                $results[] = [
                    'client_uuid' => $uuid,
                    'status' => 'duplicate',
                    'transaction_id' => $existing->id,
                    'invoice' => $existing->invoice,
                ];

                continue;
            }

            $precheckBarrier = $request->attributes->get('offline_sync_uuid_precheck_barrier');
            if (is_callable($precheckBarrier)) {
                $precheckBarrier($uuid);
            }

            $response = $this->syncOne($request, $payload, $uuid);

            $results[] = array_merge(
                ['client_uuid' => $uuid],
                $response
            );
        }

        return $this->ok(['results' => $results]);
    }

    /**
     * Synchronize a single offline transaction.
     */
    private function syncOne(
        Request $request,
        array $payload,
        string $uuid
    ): array {
        $isPayLater = (bool) ($payload['pay_later'] ?? false);

        $user = $request->user();

        if (! $user) {
            return [
                'status' => 'failed',
                'reason' => 'Authentication is required.',
            ];
        }

        try {
            return DB::transaction(function () use ($payload, $user, $uuid, $isPayLater) {
                $shift = $this->cashierShiftService->getActiveShiftForUser($user->id);

                if (! $shift) {
                    throw new OfflineSyncCheckoutFailed(
                        'No active cashier shift. The offline transaction remains queued.'
                    );
                }

            $createdCartIds = [];

            foreach ($payload['items'] as $item) {
                $product = Product::find($item['product_id']);

                if (! $product) {
                    throw new OfflineSyncCheckoutFailed(
                        "Product #{$item['product_id']} was not found."
                    );
                }

                if ($product->is_composite) {
                    $product->load('components');

                    $sellPrice = (int) $product->components->sum(
                        fn ($c) => $c->sell_price * (float) $c->pivot->qty
                    );

                    $cart = Cart::create([
                        'cashier_id' => $user->id,
                        'warehouse_id' => $shift->warehouse_id,
                        'product_id' => $product->id,
                        'unit_id' => null,
                        'conversion_factor' => 1,
                        'qty' => $item['qty'],
                        'price' => $sellPrice * $item['qty'],
                    ]);
                } else {
                    $unitId = (int) (
                        $item['unit_id']
                        ?? $product->baseUnit()?->id
                        ?? 1
                    );

                    $pu = $product->units()
                        ->where('unit_id', $unitId)
                        ->first();

                    $conversionFactor = $pu?->pivot->conversion_factor ?? 1;

                    $sellPrice = $this->unitConversionService->getSellPrice(
                        $product,
                        $unitId
                    );

                    $cart = Cart::create([
                        'cashier_id' => $user->id,
                        'warehouse_id' => $shift->warehouse_id,
                        'product_id' => $product->id,
                        'unit_id' => $unitId,
                        'conversion_factor' => $conversionFactor,
                        'qty' => $item['qty'],
                        'price' => $sellPrice * $item['qty'],
                    ]);
                }

                $createdCartIds[] = $cart->id;
            }

            // Create a request for the existing checkout workflow.
            $checkoutRequest = new Request(array_filter([
                'customer_id' => $payload['customer_id'] ?? null,
                'discount' => $payload['discount'] ?? 0,
                'redeem_points' => $payload['redeem_points'] ?? 0,
                'cash' => $payload['cash'] ?? null,
                'payment_method' => $isPayLater
                    ? 'pay_later'
                    : (($payload['payment_gateway'] ?? null) === TransactionTender::METHOD_CARD
                        ? TransactionTender::METHOD_CARD
                        : 'cash'),
                'due_date' => $isPayLater
                    ? ($payload['due_date'] ?? null)
                    : null,
            ]));
            $checkoutRequest->merge([
                'tax_rate_override' => $payload['tax_rate_override'] ?? null,
            ]);
            $checkoutRequest->attributes->set('offline_sync_client_uuid', $uuid);
            $checkoutRequest->attributes->set('offline_sync_cart_ids', $createdCartIds);

            $checkoutRequest->setUserResolver(fn () => $user);

            $response = $this->checkout(
                $checkoutRequest,
                app(PaymentGatewayManager::class)
            );

            // Decode the checkout response.
            $json = json_decode($response->getContent(), true);

            if (! $response->isSuccessful()) {
                throw new OfflineSyncCheckoutFailed(
                    data_get($json, 'message', 'Checkout failed.')
                );
            }

            $transactionId = data_get($json, 'data.id');
            $invoice = data_get($json, 'data.invoice');
            $grandTotal = data_get($json, 'data.grand_total');
            if (
                ! is_numeric($transactionId)
                || (int) $transactionId < 1
                || ! is_string($invoice)
                || $invoice === ''
                || ! is_numeric($grandTotal)
                || ! Transaction::whereKey((int) $transactionId)
                    ->where('client_uuid', $uuid)
                    ->exists()
            ) {
                throw new OfflineSyncCheckoutFailed(
                    'Checkout returned an invalid transaction result.'
                );
            }

            return [
                'status' => $response->getStatusCode() === 202
                    ? 'pending_approval'
                    : 'synced',
                'transaction_id' => (int) $transactionId,
                'invoice' => $invoice,
                'grand_total' => (int) $grandTotal,
            ];
            });
        } catch (OfflineSyncCheckoutFailed $e) {
            return [
                'status' => 'failed',
                'reason' => $e->getMessage(),
            ];
        } catch (QueryException $e) {
            if ($this->isClientUuidUniqueViolation($e)) {
                $existing = Transaction::where('client_uuid', $uuid)->first();
                if ($existing) {
                    return [
                        'status' => 'duplicate',
                        'transaction_id' => $existing->id,
                        'invoice' => $existing->invoice,
                    ];
                }
            }

            report($e);
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'failed',
                'reason' => 'Checkout failed. The offline transaction remains queued.',
            ];
        }

        return [
            'status' => 'failed',
            'reason' => 'Checkout failed. The offline transaction remains queued.',
        ];
    }

    private function isClientUuidUniqueViolation(QueryException $exception): bool
    {
        $driver = DB::connection($exception->getConnectionName())->getDriverName();
        $errorInfo = $exception->errorInfo ?? [];
        $sqlState = (string) ($errorInfo[0] ?? $exception->getCode());
        $vendorCode = (int) ($errorInfo[1] ?? 0);
        $message = (string) ($errorInfo[2] ?? $exception->getPrevious()?->getMessage() ?? '');
        $indexName = 'transactions_client_uuid_unique';

        return match ($driver) {
            'mysql', 'mariadb' => $sqlState === '23000'
                && $vendorCode === 1062
                && preg_match('/for key [\'`"](?:[^\'`"]*\\.)?'.preg_quote($indexName, '/').'[\'`"]/i', $message) === 1,
            'pgsql' => $sqlState === '23505'
                && preg_match('/unique constraint [\'`"]'.preg_quote($indexName, '/').'[\'`"]/i', $message) === 1,
            'sqlite' => $sqlState === '23000'
                && $vendorCode === 19
                && trim($message) === 'UNIQUE constraint failed: transactions.client_uuid',
            'sqlsrv' => $sqlState === '23000'
                && in_array($vendorCode, [2601, 2627], true)
                && preg_match('/[\'`"]'.preg_quote($indexName, '/').'[\'`"]/i', $message) === 1,
            default => false,
        };
    }

    /**
     * GET /api/v1/pos/transactions?page=&per_page=&date_from=&date_to=
     * Get transaction history for the current cashier, or all accessible
     * transactions for a super administrator.
     */
    public function transactions(Request $request): JsonResponse
    {
        $query = Transaction::query()
            ->with([
                'cashier:id,name',
                'warehouse:id,code,name',
                'customer:id,name',
                'tenders',
            ])
            ->withSum('details as total_items', 'qty')
            ->orderByDesc('created_at');

        if (! $request->user()->isSuperAdmin()) {
            $query->where('cashier_id', $request->user()->id);
        }

        $warehouseIds = $this->outletAccessService
            ->warehousesFor($request->user())
            ->pluck('id');

        $query->whereIn('warehouse_id', $warehouseIds);

        $query
            ->when(
                $request->string('invoice')->toString(),
                fn ($q, $inv) => $q->where(
                    'invoice',
                    'like',
                    "%{$inv}%"
                )
            )
            ->when(
                $request->string('date_from')->toString(),
                fn ($q, $d) => $q->whereDate('created_at', '>=', $d)
            )
            ->when(
                $request->string('date_to')->toString(),
                fn ($q, $d) => $q->whereDate('created_at', '<=', $d)
            );

        $transactions = $query->paginate($this->perPage());

        return $this->paginated(
            $transactions,
            TransactionResource::collection($transactions)
        );
    }

    /**
     * GET /api/v1/pos/transactions/{transaction}
     * Get transaction details, including items and payment information.
     */
    public function transactionDetail(
        Request $request,
        Transaction $transaction
    ): JsonResponse {
        if (
            ! $request->user()->isSuperAdmin()
            && $transaction->cashier_id !== $request->user()->id
        ) {
            return $this->forbidden(
                'You do not have permission to view this transaction.'
            );
        }

        $warehouseIds = $this->outletAccessService
            ->warehousesFor($request->user())
            ->pluck('id');

        if (! $warehouseIds->contains($transaction->warehouse_id)) {
            return $this->notFound('Transaction not found.');
        }

        $transaction->load(
            'details.product',
            'customer',
            'cashier',
            'warehouse',
            'receivable',
            'bankAccount',
            'tenders'
        );

        return $this->ok(new TransactionResource($transaction));
    }
}
