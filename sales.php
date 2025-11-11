<?php
require_once __DIR__ . '/inc/layout.php';

$errors = [];
$success = null;
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
    $paymentAmount = (float)($_POST['payment_amount'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $paymentDate = trim($_POST['payment_date'] ?? $saleDate);

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
        foreach ($items as $index => $item) {
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

    if ($paymentAmount < 0) {
        $errors[] = 'Payment amount cannot be negative.';
    }

    if ($paymentAmount > $totalAmount + 0.0001) {
        $errors[] = 'Payment cannot exceed the total sale amount.';
    }

    if ($paymentAmount > 0) {
        if ($paymentDate === '') {
            $errors[] = 'Payment date is required when recording a payment.';
        } else {
            try {
                new DateTime($paymentDate);
            } catch (Exception $e) {
                $errors[] = 'Payment date is invalid.';
            }
        }
    }

    $pendingCustomerName = $newCustomer !== '' ? $newCustomer : null;
    if ($customerId === 0) {
        $customerId = null;
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($pendingCustomerName !== null) {
                execute($pdo, 'INSERT INTO customers (name) VALUES (?)', [$pendingCustomerName]);
                $customerId = (int)$pdo->lastInsertId();
            }

            execute($pdo, 'INSERT INTO sales (customer_id, sale_date, total_amount, notes) VALUES (?, ?, ?, ?)', [$customerId, $saleDate, $totalAmount, $notes ?: null]);
            $saleId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');
            foreach ($preparedItems as $line) {
                $stmt->execute([$saleId, $line['product_id'], $line['quantity'], $line['unit_price'], $line['total']]);
            }

            if ($paymentAmount > 0) {
                execute($pdo, 'INSERT INTO payments (sale_id, payment_method, amount, payment_date) VALUES (?, ?, ?, ?)', [$saleId, $paymentMethod, $paymentAmount, $paymentDate ?: $saleDate]);
            }

            $pdo->commit();

            header('Location: receipts.php?sale_id=' . $saleId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Failed to record sale. Please try again.';
        }
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

render_header('Sales');
?>
<div class="bg-white border border-slate-200 rounded-lg p-6">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-slate-800">Record a Sale</h3>
        <a href="receipts.php" class="text-sm text-blue-600">View receipts</a>
    </div>
    <?php if (!empty($errors)): ?>
        <div class="mb-4 border border-rose-200 bg-rose-50 text-rose-700 text-sm px-3 py-2 rounded">
            <ul class="list-disc pl-4">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif ($success): ?>
        <div class="mb-4 border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm px-3 py-2 rounded">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>
    <?php if (empty($products)): ?>
        <p class="text-sm text-slate-500">Add products first before recording sales.</p>
    <?php else: ?>
        <form method="post" class="space-y-6" id="sale-form">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Sale date</label>
                    <input type="date" name="sale_date" value="<?= htmlspecialchars($_POST['sale_date'] ?? date('Y-m-d')) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Customer</label>
                    <select name="customer_id" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                        <option value="0">Walk-in</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= (int)$customer['id'] ?>" <?= isset($_POST['customer_id']) && (int)$_POST['customer_id'] === (int)$customer['id'] ? 'selected' : '' ?>><?= htmlspecialchars($customer['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Or create a new customer below.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">New customer name</label>
                    <input type="text" name="new_customer" value="<?= htmlspecialchars($_POST['new_customer'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Leave blank to use selected">
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-3">
                    <h4 class="text-sm font-semibold text-slate-700 uppercase">Sale items</h4>
                    <button type="button" id="add-line" class="text-sm text-blue-600">Add item</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm" id="items-table">
                        <thead>
                            <tr class="text-left text-xs uppercase text-slate-500">
                                <th class="pb-2">Product</th>
                                <th class="pb-2">Quantity</th>
                                <th class="pb-2">Unit price</th>
                                <th class="pb-2">Line total</th>
                                <th class="pb-2">Remove</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($oldItems as $index => $item): ?>
                                <?php
                                    $selectedProductId = $item['product_id'] !== '' ? (int)$item['product_id'] : null;
                                    $available = $selectedProductId ? ($stockLevels[$selectedProductId] ?? 0) : null;
                                ?>
                                <tr>
                                    <td class="py-2">
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
                                    <td class="py-2">
                                        <input type="number" step="0.01" min="0" name="items[<?= $index ?>][quantity]" value="<?= htmlspecialchars($item['quantity']) ?>" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm quantity" <?php if ($available !== null): ?>max="<?= htmlspecialchars(number_format($available, 2, '.', '')) ?>" placeholder="Max <?= number_format($available, 2) ?>"<?php endif; ?> required>
                                    </td>
                                    <td class="py-2">
                                        <input type="number" step="0.01" min="0" name="items[<?= $index ?>][unit_price]" value="<?= htmlspecialchars($item['unit_price']) ?>" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm price" required>
                                    </td>
                                    <td class="py-2 text-slate-700"><span class="line-total">0.00</span></td>
                                    <td class="py-2 text-center">
                                        <button type="button" class="text-rose-600 remove-line">Remove</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="py-2 text-right font-medium text-slate-700">Grand total</td>
                                <td class="py-2 font-semibold text-slate-900"><span id="grand-total">0.00</span></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Payment amount (₩)</label>
                    <input type="number" step="0.01" min="0" name="payment_amount" value="<?= htmlspecialchars($_POST['payment_amount'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="0 for full debt">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Method</label>
                    <select name="payment_method" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                        <option value="cash" <?= (($_POST['payment_method'] ?? '') === 'cash') ? 'selected' : '' ?>>Cash</option>
                        <option value="click" <?= (($_POST['payment_method'] ?? '') === 'click') ? 'selected' : '' ?>>Click</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Payment date</label>
                    <input type="date" name="payment_date" value="<?= htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700">Notes</label>
                <textarea name="notes" rows="3" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Optional remarks about this sale."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <div class="pt-2">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-emerald-600 text-white text-sm font-medium rounded-md hover:bg-emerald-500">Save sale</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
const products = <?= json_encode(array_map(function ($product) use ($stockLevels) {
    $available = (float)($stockLevels[$product['id']] ?? 0);
    return [
        'id' => (int)$product['id'],
        'price' => (float)$product['default_price'],
        'stock' => $available,
        'label' => $product['name'] . ' (' . $product['unit'] . ' · stock ' . number_format($available, 2) . ')'
    ];
}, $products)); ?>;
let lineIndex = <?= count($oldItems) ?>;

function updateTotals(row) {
    const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
    const price = parseFloat(row.querySelector('.price').value) || 0;
    const total = quantity * price;
    row.querySelector('.line-total').textContent = total.toFixed(2);
    refreshGrandTotal();
}

function refreshGrandTotal() {
    let sum = 0;
    document.querySelectorAll('#items-table tbody tr').forEach(tr => {
        const quantity = parseFloat(tr.querySelector('.quantity').value) || 0;
        const price = parseFloat(tr.querySelector('.price').value) || 0;
        sum += quantity * price;
    });
    document.getElementById('grand-total').textContent = sum.toFixed(2);
}

document.getElementById('add-line').addEventListener('click', () => {
    const tbody = document.querySelector('#items-table tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td class="py-2">
            <select name="items[${lineIndex}][product_id]" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm" required>
                <option value="">Select</option>
                ${products.map(p => `<option value="${p.id}" data-price="${p.price}" data-stock="${p.stock}">${p.label}</option>`).join('')}
            </select>
        </td>
        <td class="py-2">
            <input type="number" step="0.01" min="0" name="items[${lineIndex}][quantity]" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm quantity" required>
        </td>
        <td class="py-2">
            <input type="number" step="0.01" min="0" name="items[${lineIndex}][unit_price]" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm price" required>
        </td>
        <td class="py-2 text-slate-700"><span class="line-total">0.00</span></td>
        <td class="py-2 text-center">
            <button type="button" class="text-rose-600 remove-line">Remove</button>
        </td>`;
    tbody.appendChild(tr);
    lineIndex++;
});

function bindRowEvents(tbody) {
    tbody.addEventListener('change', event => {
        if (event.target.matches('select')) {
            const option = event.target.selectedOptions[0];
            const price = parseFloat(option?.dataset.price || '0');
            const stock = parseFloat(option?.dataset.stock || '0');
            const row = event.target.closest('tr');
            const priceInput = row.querySelector('.price');
            const quantityInput = row.querySelector('.quantity');
            if (priceInput && !priceInput.value) {
                priceInput.value = price.toFixed(2);
            }
            if (quantityInput) {
                quantityInput.max = stock > 0 ? stock : '';
                quantityInput.placeholder = stock > 0 ? `Max ${stock}` : '';
            }
        }
        if (event.target.matches('select, .quantity, .price')) {
            updateTotals(event.target.closest('tr'));
        }
    });
    tbody.addEventListener('input', event => {
        if (event.target.matches('.quantity, .price')) {
            updateTotals(event.target.closest('tr'));
        }
    });
    tbody.addEventListener('click', event => {
        if (event.target.matches('.remove-line')) {
            const tr = event.target.closest('tr');
            tr.remove();
            refreshGrandTotal();
        }
    });
}

bindRowEvents(document.querySelector('#items-table tbody'));
document.querySelectorAll('#items-table tbody tr').forEach(tr => updateTotals(tr));
</script>
<?php
render_footer();
?>
