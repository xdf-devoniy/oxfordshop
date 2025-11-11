<?php
require_once __DIR__ . '/inc/layout.php';

$errors = [];
$success = null;
$products = fetchAll($pdo, 'SELECT * FROM products ORDER BY name');
$customers = fetchAll($pdo, 'SELECT * FROM customers ORDER BY name');

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
    }
    $preparedItems = [];
    $totalAmount = 0;
    if (empty($items)) {
        $errors[] = 'Add at least one item to the sale.';
    } else {
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0);
            $unitPrice = (float)($item['unit_price'] ?? 0);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
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

    if ($newCustomer !== '') {
        execute($pdo, 'INSERT INTO customers (name) VALUES (?)', [$newCustomer]);
        $customerId = (int)$pdo->lastInsertId();
    }
    if ($customerId === 0) {
        $customerId = null;
    }

    if (empty($errors)) {
        execute($pdo, 'INSERT INTO sales (customer_id, sale_date, total_amount, notes) VALUES (?, ?, ?, ?)', [$customerId, $saleDate, $totalAmount, $notes ?: null]);
        $saleId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');
        foreach ($preparedItems as $line) {
            $stmt->execute([$saleId, $line['product_id'], $line['quantity'], $line['unit_price'], $line['total']]);
        }
        if ($paymentAmount > 0) {
            execute($pdo, 'INSERT INTO payments (sale_id, payment_method, amount, payment_date) VALUES (?, ?, ?, ?)', [$saleId, $paymentMethod, $paymentAmount, $paymentDate ?: $saleDate]);
        }
        header('Location: receipts.php?sale_id=' . $saleId);
        exit;
    }
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
                            <tr>
                                <td class="py-2">
                                    <select name="items[0][product_id]" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm" required>
                                        <option value="">Select</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?= (int)$product['id'] ?>" data-price="<?= htmlspecialchars($product['default_price']) ?>">
                                                <?= htmlspecialchars($product['name']) ?> (<?= htmlspecialchars($product['unit']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="py-2">
                                    <input type="number" step="0.01" min="0" name="items[0][quantity]" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm quantity" required>
                                </td>
                                <td class="py-2">
                                    <input type="number" step="0.01" min="0" name="items[0][unit_price]" class="w-full border border-slate-300 rounded-md px-2 py-2 text-sm price" required>
                                </td>
                                <td class="py-2 text-slate-700"><span class="line-total">0.00</span></td>
                                <td class="py-2 text-center">
                                    <button type="button" class="text-rose-600 remove-line">Remove</button>
                                </td>
                            </tr>
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
const products = <?= json_encode(array_map(function ($product) {
    return [
        'id' => (int)$product['id'],
        'price' => (float)$product['default_price'],
        'label' => $product['name'] . ' (' . $product['unit'] . ')'
    ];
}, $products)); ?>;
let lineIndex = 1;

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
                ${products.map(p => `<option value="${p.id}" data-price="${p.price}">${p.label}</option>`).join('')}
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
            const price = parseFloat(event.target.selectedOptions[0]?.dataset.price || '0');
            const priceInput = event.target.closest('tr').querySelector('.price');
            if (priceInput && !priceInput.value) {
                priceInput.value = price.toFixed(2);
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
</script>
<?php
render_footer();
?>
