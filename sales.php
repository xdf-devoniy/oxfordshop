<?php
require_once __DIR__ . '/inc/layout.php';

$errors = [];
$success = null;
$lastSale = null;
$products = fetchAll($pdo, 'SELECT * FROM products ORDER BY name');
$customers = fetchAll($pdo, 'SELECT * FROM customers ORDER BY name');
$stockLevels = productStockSnapshot($pdo);
$productLookup = [];
foreach ($products as $product) {
    $productLookup[(int)$product['id']] = $product;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saleDate = trim($_POST['sale_date'] ?? date('Y-m-d'));
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $newCustomer = trim($_POST['new_customer'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $items = $_POST['items'] ?? [];
    $paymentMode = $_POST['payment_mode'] ?? 'full_cash';
    $partialAmount = (float)($_POST['partial_amount'] ?? 0);
    $partialMethod = $_POST['partial_method'] ?? 'cash';
    $partialDate = trim($_POST['partial_date'] ?? $saleDate);

    if ($saleDate === '') {
        $errors[] = 'Sale date is required.';
    } else {
        try {
            new DateTime($saleDate);
        } catch (Exception $e) {
            $errors[] = 'Sale date is invalid.';
        }
    }

    $preparedItems = [];
    $totalAmount = 0;
    $requestedByProduct = [];

    if (empty($items)) {
        $errors[] = 'Add at least one item to the sale.';
    } else {
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0);
            $unitPrice = (float)($item['unit_price'] ?? 0);

            if ($productId <= 0 && ($quantity > 0 || $unitPrice > 0)) {
                $errors[] = 'Select a product for each line item.';
                continue;
            }

            if ($productId <= 0) {
                continue;
            }

            $name = $productLookup[$productId]['name'] ?? 'selected product';
            $unitLabel = $productLookup[$productId]['unit'] ?? '';

            if ($quantity <= 0) {
                $errors[] = 'Quantity must be greater than zero for ' . $name . '.';
                continue;
            }

            if ($unitPrice <= 0) {
                $errors[] = 'Unit price must be greater than zero for ' . $name . '.';
                continue;
            }

            $requestedByProduct[$productId] = ($requestedByProduct[$productId] ?? 0) + $quantity;
            $available = $stockLevels[$productId] ?? 0.0;
            if ($requestedByProduct[$productId] > $available + 0.0001) {
                $errors[] = sprintf(
                    'Insufficient stock for %s. Requested %s %s but only %s available.',
                    $name,
                    number_format($requestedByProduct[$productId], 2),
                    $unitLabel,
                    number_format($available, 2)
                );
            }

            $lineTotal = $quantity * $unitPrice;
            $totalAmount += $lineTotal;
            $preparedItems[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $lineTotal,
            ];
        }
    }

    if ($totalAmount <= 0) {
        $errors[] = 'Sale total must be greater than zero.';
    }

    $pendingCustomerName = $newCustomer !== '' ? $newCustomer : null;
    if ($customerId === 0) {
        $customerId = null;
    }

    $paymentAmount = 0;
    $paymentMethod = null;
    $paymentDate = $saleDate;

    switch ($paymentMode) {
        case 'full_cash':
            $paymentAmount = $totalAmount;
            $paymentMethod = 'cash';
            break;
        case 'full_click':
            $paymentAmount = $totalAmount;
            $paymentMethod = 'click';
            break;
        case 'debt':
            if ($pendingCustomerName === null && $customerId === null) {
                $errors[] = 'Select an existing customer or enter a new one for debt sales.';
            }
            $paymentAmount = 0;
            $paymentMethod = null;
            $paymentDate = null;
            break;
        case 'partial':
            if ($pendingCustomerName === null && $customerId === null) {
                $errors[] = 'Select an existing customer or enter a new one for partial payments.';
            }
            if ($partialAmount <= 0) {
                $errors[] = 'Partial payment amount must be greater than zero.';
            }
            if ($partialAmount >= $totalAmount - 0.0001) {
                $errors[] = 'Partial payment must be less than the total sale.';
            }
            if (!in_array($partialMethod, ['cash', 'click'], true)) {
                $errors[] = 'Select a valid payment method.';
            }
            if ($partialDate === '') {
                $errors[] = 'Partial payment date is required.';
            } else {
                try {
                    new DateTime($partialDate);
                } catch (Exception $e) {
                    $errors[] = 'Partial payment date is invalid.';
                }
            }
            $paymentAmount = $partialAmount;
            $paymentMethod = $partialMethod;
            $paymentDate = $partialDate;
            break;
        default:
            $errors[] = 'Select a valid payment option.';
            break;
    }

    if ($paymentAmount < 0) {
        $errors[] = 'Payment amount cannot be negative.';
    }

    if ($paymentAmount > $totalAmount + 0.0001) {
        $errors[] = 'Payment cannot exceed the total sale amount.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($pendingCustomerName !== null) {
                execute($pdo, 'INSERT INTO customers (name) VALUES (?)', [$pendingCustomerName]);
                $customerId = (int)$pdo->lastInsertId();
            }

            execute(
                $pdo,
                'INSERT INTO sales (customer_id, sale_date, total_amount, notes) VALUES (?, ?, ?, ?)',
                [$customerId, $saleDate, $totalAmount, $notes ?: null]
            );
            $saleId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');
            foreach ($preparedItems as $line) {
                $stmt->execute([$saleId, $line['product_id'], $line['quantity'], $line['unit_price'], $line['total']]);
            }

            if ($paymentAmount > 0) {
                execute(
                    $pdo,
                    'INSERT INTO payments (sale_id, payment_method, amount, payment_date) VALUES (?, ?, ?, ?)',
                    [$saleId, $paymentMethod, $paymentAmount, $paymentDate ?: $saleDate]
                );
            }

            $pdo->commit();

            header('Location: sales.php?recorded=' . $saleId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Failed to record sale. Please try again.';
        }
    }
}

$recordedSaleId = isset($_GET['recorded']) ? (int)$_GET['recorded'] : null;
if ($recordedSaleId) {
    $lastSale = fetchOne(
        $pdo,
        'SELECT s.id, s.sale_date, s.total_amount, IFNULL(c.name, "Walk-in") AS customer_name
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE s.id = ?',
        [$recordedSaleId]
    );
    if ($lastSale) {
        $success = sprintf(
            'Sale #%d for %s so\'m saved successfully.',
            $lastSale['id'],
            number_format((float)$lastSale['total_amount'], 2)
        );
    }
}

$oldItems = [];
if (!empty($_POST['items']) && is_array($_POST['items'])) {
    foreach ($_POST['items'] as $postedItem) {
        $oldItems[] = [
            'product_id' => isset($postedItem['product_id']) ? (int)$postedItem['product_id'] : '',
            'quantity' => $postedItem['quantity'] ?? '',
            'unit_price' => $postedItem['unit_price'] ?? '',
        ];
    }
}
if (empty($oldItems)) {
    $oldItems[] = ['product_id' => '', 'quantity' => '', 'unit_price' => ''];
}

$currentMode = $_POST['payment_mode'] ?? 'full_cash';

render_header('Sales');
?>
<div class="space-y-6">
    <?php if (!empty($errors)): ?>
        <div class="border border-rose-200 bg-rose-50 text-rose-700 text-sm px-3 py-2 rounded">
            <ul class="list-disc pl-4 space-y-1">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif ($success): ?>
        <div class="border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm px-3 py-2 rounded flex items-center justify-between">
            <span><?= htmlspecialchars($success) ?></span>
            <?php if (!empty($lastSale)): ?>
                <a href="receipts.php?sale_id=<?= (int)$lastSale['id'] ?>" class="text-xs underline">Open receipt</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($products)): ?>
        <div class="bg-white border border-slate-200 rounded-lg p-6 text-sm text-slate-600">
            Add products before recording sales. Head over to <a class="text-blue-600" href="products.php">Products</a> to get started.
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-6">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800">Create a new sale</h3>
                        <p class="text-sm text-slate-500">Add items to the cart and capture payment or debt in one streamlined flow.</p>
                    </div>
                    <a href="receipts.php" class="text-sm text-blue-600">Receipt history</a>
                </div>

                <form method="post" class="space-y-6" id="sale-form">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Sale date</label>
                            <input type="date" name="sale_date" value="<?= htmlspecialchars($_POST['sale_date'] ?? date('Y-m-d')) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700">Notes</label>
                            <textarea name="notes" rows="2" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Optional remarks about this sale."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Sale cart</h4>
                            <div class="flex items-center gap-2 text-xs text-slate-500">
                                <span>Need a manual line?</span>
                                <button type="button" id="add-line" class="text-blue-600 text-sm">Add item</button>
                            </div>
                        </div>
                        <div class="border border-slate-200 rounded-lg overflow-hidden">
                            <div class="overflow-x-auto">
                                <div class="max-h-72 overflow-y-auto">
                                    <table class="min-w-full text-sm" id="items-table">
                                        <thead class="bg-slate-50">
                                            <tr class="text-left text-xs uppercase text-slate-500">
                                                <th class="px-3 py-2">Product</th>
                                                <th class="px-3 py-2">Quantity</th>
                                                <th class="px-3 py-2">Unit price</th>
                                                <th class="px-3 py-2">Line total</th>
                                                <th class="px-3 py-2">Remove</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100" id="cart-body">
                                            <?php foreach ($oldItems as $index => $item): ?>
                                                <?php
                                                    $selectedProductId = $item['product_id'] !== '' ? (int)$item['product_id'] : null;
                                                    $available = $selectedProductId ? ($stockLevels[$selectedProductId] ?? 0) : null;
                                                ?>
                                                <tr>
                                                    <td class="px-3 py-2">
                                                        <select name="items[<?= $index ?>][product_id]" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm" required>
                                                            <option value="">Select</option>
                                                            <?php foreach ($products as $product): ?>
                                                                <?php $optionStock = $stockLevels[$product['id']] ?? 0; ?>
                                                                <option value="<?= (int)$product['id'] ?>" data-price="<?= htmlspecialchars($product['default_price']) ?>" data-stock="<?= htmlspecialchars($optionStock) ?>" <?= $selectedProductId === (int)$product['id'] ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($product['name']) ?> (<?= htmlspecialchars($product['unit']) ?> · stock <?= number_format($optionStock, 2) ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <input type="number" step="0.01" min="0" name="items[<?= $index ?>][quantity]" value="<?= htmlspecialchars($item['quantity']) ?>" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm quantity" <?php if ($available !== null): ?>max="<?= htmlspecialchars(number_format($available, 2, '.', '')) ?>" placeholder="Max <?= number_format($available, 2) ?>"<?php endif; ?> required>
                                                    </td>
                                                    <td class="px-3 py-2">
                                                        <input type="number" step="0.01" min="0" name="items[<?= $index ?>][unit_price]" value="<?= htmlspecialchars($item['unit_price']) ?>" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm price" required>
                                                    </td>
                                                    <td class="px-3 py-2 text-slate-700"><span class="line-total">0.00</span> so'm</td>
                                                    <td class="px-3 py-2 text-center">
                                                        <button type="button" class="text-rose-600 remove-line">Remove</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="bg-slate-50">
                                            <tr>
                                                <td colspan="3" class="px-3 py-2 text-right font-medium text-slate-700">Grand total</td>
                                                <td class="px-3 py-2 font-semibold text-slate-900"><span id="grand-total">0.00</span> so'm</td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <section class="bg-slate-50 border border-slate-200 rounded-lg p-4 space-y-3">
                            <h4 class="text-sm font-semibold text-slate-700 uppercase">Customer & debt tracking</h4>
                            <p class="text-xs text-slate-500">Only required when an outstanding balance remains. Keep walk-in cash sales as-is.</p>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Customer</label>
                                <select name="customer_id" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                                    <option value="0">Walk-in</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= (int)$customer['id'] ?>" <?= isset($_POST['customer_id']) && (int)$_POST['customer_id'] === (int)$customer['id'] ? 'selected' : '' ?>><?= htmlspecialchars($customer['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">New customer name</label>
                                <input type="text" name="new_customer" value="<?= htmlspecialchars($_POST['new_customer'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Add if customer is new">
                            </div>
                        </section>
                        <section class="bg-white border border-slate-200 rounded-lg p-4 space-y-4">
                            <div>
                                <h4 class="text-sm font-semibold text-slate-700 uppercase">Payment</h4>
                                <p class="text-xs text-slate-500">Choose how this sale is paid. Totals update automatically.</p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="payment-card <?= $currentMode === 'full_cash' ? 'active' : '' ?>">
                                    <input type="radio" name="payment_mode" value="full_cash" <?= $currentMode === 'full_cash' ? 'checked' : '' ?>>
                                    <div>
                                        <p class="font-semibold text-slate-800">Paid in cash</p>
                                        <p class="text-xs text-slate-500">Full amount collected now.</p>
                                    </div>
                                </label>
                                <label class="payment-card <?= $currentMode === 'full_click' ? 'active' : '' ?>">
                                    <input type="radio" name="payment_mode" value="full_click" <?= $currentMode === 'full_click' ? 'checked' : '' ?>>
                                    <div>
                                        <p class="font-semibold text-slate-800">Paid via Click</p>
                                        <p class="text-xs text-slate-500">Full amount paid digitally.</p>
                                    </div>
                                </label>
                                <label class="payment-card <?= $currentMode === 'partial' ? 'active' : '' ?>">
                                    <input type="radio" name="payment_mode" value="partial" <?= $currentMode === 'partial' ? 'checked' : '' ?>>
                                    <div>
                                        <p class="font-semibold text-slate-800">Partial payment</p>
                                        <p class="text-xs text-slate-500">Collect a portion now, rest later.</p>
                                    </div>
                                </label>
                                <label class="payment-card <?= $currentMode === 'debt' ? 'active' : '' ?>">
                                    <input type="radio" name="payment_mode" value="debt" <?= $currentMode === 'debt' ? 'checked' : '' ?>>
                                    <div>
                                        <p class="font-semibold text-slate-800">Record as debt</p>
                                        <p class="text-xs text-slate-500">No payment today, track balance.</p>
                                    </div>
                                </label>
                            </div>
                            <div id="partial-fields" class="grid grid-cols-1 sm:grid-cols-3 gap-3 <?= $currentMode === 'partial' ? '' : 'hidden' ?>">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700">Paid now (so'm)</label>
                                    <input type="number" step="0.01" min="0" name="partial_amount" value="<?= htmlspecialchars($_POST['partial_amount'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700">Method</label>
                                    <select name="partial_method" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                                        <option value="cash" <?= (($_POST['partial_method'] ?? '') === 'cash') ? 'selected' : '' ?>>Cash</option>
                                        <option value="click" <?= (($_POST['partial_method'] ?? '') === 'click') ? 'selected' : '' ?>>Click</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700">Payment date</label>
                                    <input type="date" name="partial_date" value="<?= htmlspecialchars($_POST['partial_date'] ?? ($_POST['sale_date'] ?? date('Y-m-d'))) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                                </div>
                            </div>
                            <div class="bg-slate-50 border border-slate-200 rounded-md px-3 py-3 text-sm space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">Items in cart</span>
                                    <span class="font-semibold text-slate-800" id="item-count">0</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">Collect now</span>
                                    <span class="font-semibold text-emerald-600" id="payment-preview">0 so'm</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-slate-500">Balance left</span>
                                    <span class="font-semibold text-rose-600" id="balance-preview">0 so'm</span>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="pt-2 flex items-center justify-end">
                        <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-600 text-white text-sm font-medium rounded-md shadow-sm hover:bg-emerald-500">
                            Save sale
                        </button>
                    </div>
                </form>
            </section>
            <aside class="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
                <div>
                    <h3 class="text-lg font-semibold text-slate-800">Quick add products</h3>
                    <p class="text-sm text-slate-500">Tap an item to add it instantly. Quantities grow when tapped again.</p>
                </div>
                <div>
                    <label class="text-xs uppercase text-slate-500">Search catalog</label>
                    <input type="text" id="product-search" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Start typing to filter">
                </div>
                <div id="product-catalog" class="space-y-2 max-h-[32rem] overflow-y-auto pr-1">
                    <?php foreach ($products as $product): ?>
                        <?php $available = $stockLevels[$product['id']] ?? 0; ?>
                        <button type="button"
                                class="catalog-card"
                                data-product='<?= htmlspecialchars(json_encode([
                                    'id' => (int)$product['id'],
                                    'name' => $product['name'],
                                    'unit' => $product['unit'],
                                    'price' => (float)$product['default_price'],
                                    'stock' => (float)$available,
                                ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>'>
                            <div class="flex items-center justify-between">
                                <span class="font-medium text-slate-800"><?= htmlspecialchars($product['name']) ?></span>
                                <span class="text-sm text-emerald-600"><?= number_format((float)$product['default_price'], 2) ?> so'm</span>
                            </div>
                            <p class="text-xs text-slate-500">Stock: <?= number_format($available, 2) ?> <?= htmlspecialchars($product['unit']) ?></p>
                        </button>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>

<style>
    .payment-card {
        display: flex;
        gap: 0.75rem;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem;
        cursor: pointer;
        font-size: 0.875rem;
        align-items: flex-start;
        transition: all 0.2s ease;
        background-color: #f8fafc;
    }
    .payment-card input[type="radio"] {
        display: none;
    }
    .payment-card:hover {
        border-color: #34d399;
    }
    .payment-card.active {
        border-color: #34d399;
        background-color: #ecfdf5;
        box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.25);
    }
    .catalog-card {
        width: 100%;
        text-align: left;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.75rem;
        transition: all 0.2s ease;
        background-color: #ffffff;
    }
    .catalog-card:hover {
        border-color: #34d399;
        background-color: #ecfdf5;
    }
</style>

<script>
const products = <?= json_encode(array_map(function ($product) use ($stockLevels) {
    $available = (float)($stockLevels[$product['id']] ?? 0);
    return [
        'id' => (int)$product['id'],
        'name' => $product['name'],
        'price' => (float)$product['default_price'],
        'stock' => $available,
        'unit' => $product['unit'],
        'label' => $product['name'] . ' (' . $product['unit'] . ' · stock ' . number_format($available, 2) . ')'
    ];
}, $products)); ?>;

const cartBody = document.getElementById('cart-body');
if (cartBody) {
    let lineIndex = <?= count($oldItems) ?>;

    const grandTotalEl = document.getElementById('grand-total');
    const itemCountEl = document.getElementById('item-count');
    const paymentPreview = document.getElementById('payment-preview');
    const balancePreview = document.getElementById('balance-preview');

    function updatePaymentPreview(sumOverride = null) {
        const sum = sumOverride ?? parseFloat(grandTotalEl?.textContent || '0') || 0;
        const modeInput = document.querySelector('input[name="payment_mode"]:checked');
        const mode = modeInput ? modeInput.value : 'full_cash';
        let paid = 0;
        if (mode === 'partial') {
            const partialField = document.querySelector('input[name="partial_amount"]');
            paid = partialField ? parseFloat(partialField.value) || 0 : 0;
        } else if (mode === 'debt') {
            paid = 0;
        } else {
            paid = sum;
        }
        if (paid > sum) {
            paid = sum;
        }
        const balance = Math.max(sum - paid, 0);
        if (paymentPreview) {
            paymentPreview.textContent = `${paid.toFixed(2)} so'm`;
        }
        if (balancePreview) {
            balancePreview.textContent = `${balance.toFixed(2)} so'm`;
        }
    }

    function refreshGrandTotal() {
        let sum = 0;
        let count = 0;
        cartBody.querySelectorAll('tr').forEach(row => {
            const quantityInput = row.querySelector('.quantity');
            const priceInput = row.querySelector('.price');
            if (!quantityInput || !priceInput) {
                return;
            }
            const quantity = parseFloat(quantityInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            if (quantity > 0) {
                count += 1;
            }
            const total = quantity * price;
            const lineTotal = row.querySelector('.line-total');
            if (lineTotal) {
                lineTotal.textContent = total.toFixed(2);
            }
            sum += total;
        });
        if (grandTotalEl) {
            grandTotalEl.textContent = sum.toFixed(2);
        }
        if (itemCountEl) {
            itemCountEl.textContent = count;
        }
        updatePaymentPreview(sum);
    }

    function bindRowEvents(row) {
        row.addEventListener('input', event => {
            if (event.target.matches('.quantity, .price')) {
                refreshGrandTotal();
            }
        });
        row.addEventListener('change', event => {
            if (event.target.matches('select')) {
                const option = event.target.selectedOptions[0];
                const priceInput = row.querySelector('.price');
                const quantityInput = row.querySelector('.quantity');
                if (option) {
                    const price = parseFloat(option.dataset.price || '0');
                    const stock = parseFloat(option.dataset.stock || '0');
                    if (priceInput && !priceInput.value) {
                        priceInput.value = price.toFixed(2);
                    }
                    if (quantityInput) {
                        quantityInput.max = stock > 0 ? stock : '';
                        quantityInput.placeholder = stock > 0 ? `Max ${stock}` : '';
                    }
                }
                refreshGrandTotal();
            }
            if (event.target.matches('.quantity, .price')) {
                refreshGrandTotal();
            }
        });
        const removeButton = row.querySelector('.remove-line');
        removeButton?.addEventListener('click', () => {
            row.remove();
            refreshGrandTotal();
        });
    }

    function createRow(preset = null) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="px-3 py-2">
                <select name="items[${lineIndex}][product_id]" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm" required>
                    <option value="">Select</option>
                    ${products.map(p => `<option value="${p.id}" data-price="${p.price}" data-stock="${p.stock}">${p.label}</option>`).join('')}
                </select>
            </td>
            <td class="px-3 py-2">
                <input type="number" step="0.01" min="0" name="items[${lineIndex}][quantity]" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm quantity" value="${preset ? 1 : ''}" required>
            </td>
            <td class="px-3 py-2">
                <input type="number" step="0.01" min="0" name="items[${lineIndex}][unit_price]" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm price" value="${preset ? preset.price.toFixed(2) : ''}" required>
            </td>
            <td class="px-3 py-2 text-slate-700"><span class="line-total">0.00</span> so'm</td>
            <td class="px-3 py-2 text-center">
                <button type="button" class="text-rose-600 remove-line">Remove</button>
            </td>`;
        cartBody.appendChild(tr);
        const select = tr.querySelector('select');
        if (preset) {
            select.value = String(preset.id);
            const quantityInput = tr.querySelector('.quantity');
            if (quantityInput) {
                quantityInput.max = preset.stock > 0 ? preset.stock : '';
                quantityInput.placeholder = preset.stock > 0 ? `Max ${preset.stock}` : '';
            }
        }
        bindRowEvents(tr);
        if (preset) {
            select.dispatchEvent(new Event('change'));
        }
        lineIndex++;
        refreshGrandTotal();
    }

    cartBody.querySelectorAll('tr').forEach(row => bindRowEvents(row));
    refreshGrandTotal();

    const addLineButton = document.getElementById('add-line');
    addLineButton?.addEventListener('click', () => {
        createRow();
    });

    const catalog = document.getElementById('product-catalog');
    catalog?.addEventListener('click', event => {
        const button = event.target.closest('button[data-product]');
        if (!button) {
            return;
        }
        const data = JSON.parse(button.dataset.product);
        const existing = Array.from(cartBody.querySelectorAll('select')).find(select => parseInt(select.value, 10) === data.id);
        if (existing) {
            const row = existing.closest('tr');
            const quantityInput = row.querySelector('.quantity');
            if (quantityInput) {
                const current = parseFloat(quantityInput.value) || 0;
                quantityInput.value = (current + 1).toFixed(2);
                refreshGrandTotal();
            }
            existing.dispatchEvent(new Event('change'));
        } else {
            createRow(data);
        }
    });

    const searchInput = document.getElementById('product-search');
    searchInput?.addEventListener('input', () => {
        const term = searchInput.value.toLowerCase();
        catalog?.querySelectorAll('button[data-product]').forEach(button => {
            const info = JSON.parse(button.dataset.product);
            const matches = info.name.toLowerCase().includes(term);
            button.classList.toggle('hidden', !matches);
        });
    });

    const paymentRadios = document.querySelectorAll('input[name="payment_mode"]');
    paymentRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            document.querySelectorAll('.payment-card').forEach(card => card.classList.remove('active'));
            const card = radio.closest('.payment-card');
            if (card) {
                card.classList.add('active');
            }
            const partialBox = document.getElementById('partial-fields');
            if (partialBox) {
                partialBox.classList.toggle('hidden', radio.value !== 'partial');
            }
            refreshGrandTotal();
        });
    });

    const partialAmountInput = document.querySelector('input[name="partial_amount"]');
    partialAmountInput?.addEventListener('input', () => refreshGrandTotal());
}
</script>
<?php
render_footer();
?>
