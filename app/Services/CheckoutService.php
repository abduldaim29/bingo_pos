<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductWarehouse;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Support\Checkout\CheckoutContext;
use App\Support\Checkout\CheckoutResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly CashierShiftService $cashierShiftService,
        private readonly PricingService $pricingService,
        private readonly LoyaltyService $loyaltyService,
        private readonly PriceListService $priceListService,
        private readonly BatchService $batchService,
        private readonly TransactionTenderService $tenderService,
        private readonly RecipeInventoryService $recipeInventory,
    ) {}

    public function execute(CheckoutContext $ctx): CheckoutResult
    {
        return DB::transaction(function () use ($ctx) {
            $activeShift = $this->cashierShiftService->requireActiveShiftForUser(
                $ctx->userId,
                lockForUpdate: true,
            );

            if (($ctx->clientUuid === null) !== ($ctx->cartIds === null)) {
                throw new \LogicException('Offline sync checkout context is incomplete.');
            }

            $cartQuery = Cart::with('product.units')
                ->where('cashier_id', $ctx->userId)
                ->active();

            $syncCartIds = null;
            if ($ctx->cartIds !== null) {
                $syncCartIds = array_values(array_unique(array_map('intval', $ctx->cartIds)));
                if ($syncCartIds === [] || count($syncCartIds) !== count($ctx->cartIds)) {
                    throw new \LogicException('Offline sync cart scope is invalid.');
                }

                $cartQuery->whereIn('id', $syncCartIds);
                if ($activeShift->warehouse_id === null) {
                    $cartQuery->whereNull('warehouse_id');
                } else {
                    $cartQuery->where('warehouse_id', $activeShift->warehouse_id);
                }
                $cartQuery->lockForUpdate();
            }

            $carts = $cartQuery->get();

            if ($carts->isEmpty()) {
                abort(422, 'The cart is empty.');
            }

            if ($syncCartIds !== null && $carts->count() !== count($syncCartIds)) {
                throw new \LogicException('Offline sync carts do not match the active cashier context.');
            }

            $pricingPreview = $this->pricingService->previewCart($carts, $ctx->customer, null, $ctx->outlet);
            $checkoutPreview = $this->loyaltyService->previewCheckout(
                $pricingPreview,
                $ctx->customer,
                [
                    'manual_discount' => $ctx->manualDiscount,
                    'shipping_cost' => $ctx->shippingCost,
                    'redeem_points' => $ctx->requestedRedeemPoints,
                    'tax_rate_override' => $ctx->taxRateOverride,
                    'payment_method' => $ctx->useTenders
                        ? null
                        : ($ctx->isPayLater ? 'pay_later' : ($ctx->paymentGateway ?: 'cash')),
                    'tenders' => $ctx->useTenders ? $ctx->tenderInput : [],
                    'voucher' => $ctx->voucher,
                ],
                null,
                $ctx->outlet,
            );

            $grandTotal = (int) data_get($checkoutPreview, 'summary.grand_total', 0);
            $appliedManualDiscount = (int) data_get($checkoutPreview, 'summary.manual_discount_total', 0);
            $loyaltyDiscount = (int) data_get($checkoutPreview, 'summary.loyalty_discount_total', 0);
            $voucherDiscount = (int) data_get($checkoutPreview, 'summary.voucher_discount_total', 0);
            $subtotalAfterPromo = (int) data_get($pricingPreview, 'summary.subtotal_after_promo', 0);
            $invoiceDiscountTotal = $appliedManualDiscount + $loyaltyDiscount + $voucherDiscount;

            if ($ctx->isCashPayment && $ctx->cashAmount < $grandTotal) {
                throw ValidationException::withMessages([
                    'cash' => 'The cash amount is less than the total purchase amount.',
                ]);
            }

            $tenders = $ctx->useTenders
                ? $this->tenderService->normalize($ctx->tenderInput, $grandTotal, allowEmpty: false, outlet: $ctx->outlet)
                : [];

            $tenderCash = (int) collect($tenders)->where('method', TransactionTender::METHOD_CASH)->sum('cash_received');
            $tenderChange = (int) collect($tenders)->sum('change');
            $paymentMethod = $ctx->useTenders
                ? (count($tenders) > 1 ? 'split' : $tenders[0]['method'])
                : ($ctx->isPayLater ? 'pay_later' : ($ctx->paymentGateway ?: 'cash'));
            $paymentStatus = $ctx->useTenders
                ? $this->tenderService->aggregateStatus($tenders)
                : ($ctx->isCashPayment || $ctx->paymentGateway === TransactionTender::METHOD_CARD
                    ? 'paid'
                    : ($ctx->isPayLater ? 'unpaid' : 'pending'));
            $tenderBankAccountId = $ctx->useTenders
                ? (collect($tenders)->firstWhere('bank_account_id', '!==', null)['bank_account_id'] ?? null)
                : ($ctx->paymentGateway === 'bank_transfer' ? $ctx->bankAccountId : null);

            $transactionAttributes = [
                'cashier_id' => $ctx->userId,
                'cashier_shift_id' => $activeShift->id,
                'warehouse_id' => $activeShift->warehouse_id,
                'customer_id' => $ctx->customer?->id,
                'invoice' => 'TRX-'.strtoupper(Str::random(10)),
                'client_uuid' => $ctx->clientUuid,
                'cash' => $ctx->useTenders ? $tenderCash : $ctx->cashAmount,
                'change' => $ctx->useTenders ? $tenderChange : max(0, $ctx->cashAmount - $grandTotal),
                'discount' => $appliedManualDiscount,
                'loyalty_points_redeemed' => (int) data_get($checkoutPreview, 'summary.applied_redeem_points', 0),
                'loyalty_discount_total' => $loyaltyDiscount,
                'customer_voucher_discount' => $voucherDiscount,
                'customer_voucher_code' => data_get($checkoutPreview, 'voucher.code'),
                'customer_voucher_name' => data_get($checkoutPreview, 'voucher.name'),
                'shipping_cost' => $ctx->shippingCost,
                'grand_total' => $grandTotal,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'bank_account_id' => $tenderBankAccountId,
                'order_type' => $ctx->orderType,
                'note' => $ctx->note,
                'tax_rate' => data_get($checkoutPreview, 'summary.tax_rate'),
                'taxable_amount' => data_get($checkoutPreview, 'summary.taxable_amount'),
                'tax_total' => data_get($checkoutPreview, 'summary.tax_total', 0),
                'customer_npwp' => $ctx->customerNpwp,
                'price_list_id' => $this->priceListService->getApplicablePriceList($ctx->customer, $ctx->outlet)?->id,
            ];
            if (! Schema::hasColumn('transactions', 'taxable_amount')) {
                unset($transactionAttributes['taxable_amount']);
            }
            $transaction = Transaction::create($transactionAttributes);

            if ($ctx->useTenders) {
                $transaction->tenders()->createMany($tenders);
            }

            $this->processCartItems(
                $transaction,
                $carts,
                $pricingPreview,
                data_get($checkoutPreview, 'taxable_items', []),
                $ctx->outlet,
                $subtotalAfterPromo,
                $invoiceDiscountTotal,
                $activeShift->warehouse_id,
            );

            $cartDeleteQuery = Cart::where('cashier_id', $ctx->userId)->active();
            if ($syncCartIds !== null) {
                $cartDeleteQuery->whereIn('id', $syncCartIds);
            }
            $cartDeleteQuery->delete();

            $this->loyaltyService->finalizeTransaction($transaction, $ctx->customer, $checkoutPreview);

            if ($ctx->isPayLater) {
                Receivable::create([
                    'customer_id' => $ctx->customer?->id,
                    'transaction_id' => $transaction->id,
                    'invoice' => $transaction->invoice,
                    'total' => $grandTotal,
                    'paid' => 0,
                    'due_date' => $ctx->dueDate,
                    'status' => 'unpaid',
                ]);
            }

            $needsDiscountApproval = $appliedManualDiscount > 0 && $transaction->needsDiscountApproval();

            return new CheckoutResult(
                transaction: $transaction->fresh(['customer']),
                pricingPreview: $pricingPreview,
                checkoutPreview: $checkoutPreview,
                needsDiscountApproval: $needsDiscountApproval,
                appliedManualDiscount: $appliedManualDiscount,
                tenders: $tenders,
                paymentMethod: $paymentMethod,
                paymentStatus: $paymentStatus,
                tenderBankAccountId: $tenderBankAccountId,
            );
        });
    }

    private function processCartItems(
        Transaction $transaction,
        $carts,
        array $pricingPreview,
        array $taxableItems,
        $outlet,
        int $subtotalAfterPromo,
        int $invoiceDiscountTotal,
        ?int $warehouseId,
    ): void {
        $pricingItems = collect($pricingPreview['items']);
        $taxableItemsByCartId = collect($taxableItems)->keyBy('cart_id');
        $detailColumns = array_fill_keys(Schema::getColumnListing('transaction_details'), true);
        $cartItems = $carts->values();
        $allocatedDiscount = 0;
        $cumulativeLineTotal = 0;

        foreach ($cartItems as $index => $cart) {
            $pricingItem = $pricingItems->firstWhere('cart_id', $cart->id);
            $lineTotal = (int) data_get($pricingItem, 'line_total', $cart->price);
            $linePromoDiscount = (int) data_get($pricingItem, 'line_discount_total', 0);
            $baseUnitPrice = (int) data_get($pricingItem, 'base_unit_price', $cart->product->sell_price);
            $unitPrice = (int) data_get($pricingItem, 'effective_unit_price', $cart->product->sell_price);
            $unit = $cart->unit_id
                ? $cart->product->units->firstWhere('id', $cart->unit_id)
                : null;
            $taxableItem = $taxableItemsByCartId->get($cart->id, []);
            $product = $cart->product;
            $recipe = $product->stock_mode === 'made_to_order'
                ? $this->recipeInventory->recipeForSale($product, $cart->variation_id)
                : null;
            $totalBuyPrice = $recipe
                ? $this->recipeInventory->costForQuantity($recipe, (int) $cart->qty)
                : (int) ($unit?->pivot?->buy_price ?? $product->buy_price) * (int) $cart->qty;

            $cumulativeLineTotal += $lineTotal;
            $allocatedDiscountThroughLine = $index === $cartItems->count() - 1
                ? $invoiceDiscountTotal
                : (int) round(
                    $invoiceDiscountTotal
                    * $cumulativeLineTotal
                    / max(1, $subtotalAfterPromo)
                );
            $lineInvoiceDiscount = $allocatedDiscountThroughLine - $allocatedDiscount;
            $allocatedDiscount = $allocatedDiscountThroughLine;

            $detailAttributes = [
                'transaction_id' => $transaction->id,
                'product_id' => $cart->product_id,
                'unit_id' => $cart->unit_id,
                'conversion_factor' => $cart->conversion_factor,
                'qty' => $cart->qty,
                'base_unit_price' => $baseUnitPrice,
                'unit_price' => $unitPrice,
                'price' => $lineTotal,
                'cost_total' => $totalBuyPrice,
                'taxable_amount' => (int) data_get($taxableItem, 'taxable_amount', 0),
                'gross_sales_amount' => (int) data_get($taxableItem, 'gross_sales_amount', 0),
                'tax_total' => (int) data_get($taxableItem, 'tax_total', 0),
                'tax_rate' => data_get($taxableItem, 'tax_rate'),
                'tax_type' => data_get($taxableItem, 'tax_type'),
                'discount_total' => $linePromoDiscount,
                'pricing_rule_id' => data_get($pricingItem, 'pricing_rule.id'),
                'pricing_rule_name' => data_get($pricingItem, 'pricing_rule.name'),
                'pricing_rule_kind' => data_get($pricingItem, 'pricing_rule.kind'),
                'pricing_group_key' => data_get($pricingItem, 'pricing_group_key'),
                'pricing_group_label' => data_get($pricingItem, 'pricing_group_label'),
                'variation_id' => $cart->variation_id,
                'variation_name' => $cart->variation_name,
                'extras' => $cart->extras ?? [],
            ];
            $detailAttributes = array_intersect_key($detailAttributes, $detailColumns);
            $detail = $transaction->details()->create($detailAttributes);

            $embeddedTax = data_get($taxableItem, 'tax_type') === 'inclusive'
                ? (int) data_get($taxableItem, 'tax_total', 0)
                : 0;
            $netSellPrice = max(0, $lineTotal - $embeddedTax - $lineInvoiceDiscount);
            $profits = $netSellPrice - $totalBuyPrice;

            $transaction->profits()->create([
                'transaction_id' => $transaction->id,
                'total' => $profits,
            ]);

            if ($recipe) {
                $this->recipeInventory->consume(
                    detail: $detail,
                    recipe: $recipe,
                    soldQuantity: (int) $cart->qty,
                    warehouseId: $warehouseId,
                );
            } elseif ($product->is_composite) {
                $product->load('components');

                foreach ($product->components as $component) {
                    $componentQty = (int) round((float) $component->pivot->qty * $cart->qty);
                    $pw = $warehouseId
                        ? ProductWarehouse::where([
                            'product_id' => $component->id,
                            'warehouse_id' => $warehouseId,
                        ])->lockForUpdate()->first()
                        : null;

                    $available = $pw ? (int) $pw->stock : (int) $component->stock;

                    if ($available < $componentQty) {
                        throw ValidationException::withMessages([
                            'stock' => "Insufficient stock for component {$component->title}. Available: {$available}.",
                        ]);
                    }

                    if ($pw) {
                        $pw->decrement('stock', $componentQty);
                    }

                    $component->decrement('stock', $componentQty);
                }
            } elseif ($product->stock_mode !== 'made_to_order') {
                $baseQty = (int) round($cart->qty * (float) ($cart->conversion_factor ?? 1));

                $pw = $warehouseId
                    ? ProductWarehouse::where([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouseId,
                    ])->lockForUpdate()->first()
                    : null;

                $available = $pw ? (int) $pw->stock : (int) $product->stock;

                if ($available < $baseQty) {
                    throw ValidationException::withMessages([
                        'stock' => "Insufficient stock for {$product->title}. Available: {$available}.",
                    ]);
                }

                if ($pw) {
                    $pw->decrement('stock', $baseQty);
                }

                $product->decrement('stock', $baseQty);

                if ($warehouseId) {
                    $batches = ProductBatch::where('product_id', $product->id)
                        ->where('warehouse_id', $warehouseId)
                        ->where('stock', '>', 0)
                        ->where(function (Builder $q) {
                            $q->whereNull('expired_at')->orWhere('expired_at', '>', now());
                        })
                        ->orderBy('expired_at')
                        ->orderBy('received_at')
                        ->lockForUpdate()
                        ->get();

                    if ($batches->isNotEmpty()) {
                        $covered = (int) $batches->sum('stock');

                        if ($covered < $baseQty) {
                            throw ValidationException::withMessages([
                                'stock' => "Insufficient batch stock for {$product->title}. Available: {$covered}.",
                            ]);
                        }

                        $remaining = $baseQty;
                        $firstBatchId = null;

                        foreach ($batches as $batch) {
                            if ($remaining <= 0) {
                                break;
                            }

                            $take = min((int) $batch->stock, $remaining);
                            $batch->decrement('stock', $take);
                            $remaining -= $take;
                            $firstBatchId ??= $batch->id;

                            $detail->batchAllocations()->create([
                                'product_batch_id' => $batch->id,
                                'qty' => $take,
                            ]);
                        }

                        $detail->update(['product_batch_id' => $firstBatchId]);
                    }
                }
            }
        }
    }
}