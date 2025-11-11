<?php
require_once __DIR__ . '/inc/layout.php';

$errors = [];
$success = null;
$products = fetchAll($pdo, 'SELECT id, name, unit FROM products ORDER BY name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0);
    $unitCost = (float)($_POST['unit_cost'] ?? 0);
    $date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
    $notes = trim($_POST['notes'] ?? '');

    if ($productId <= 0) {
        $errors[] = 'Please select a product.';
    }
    if ($quantity <= 0) {
        $errors[] = 'Quantity must be greater than zero.';
    }
    if ($unitCost < 0) {
        $errors[] = 'Unit cost cannot be negative.';
    }
    if ($date === '') {
        $errors[] = 'Purchase date is required.';
    }

    if (empty($errors)) {
        execute($pdo, 'INSERT INTO purchases (product_id, quantity, unit_cost, purchase_date, notes) VALUES (?, ?, ?, ?, ?)', [$productId, $quantity, $unitCost, $date, $notes ?: null]);
        $success = 'Purchase recorded successfully.';
    }
}

$purchases = fetchAll($pdo, 'SELECT pu.*, pr.name AS product_name, pr.unit
    FROM purchases pu
    JOIN products pr ON pr.id = pu.product_id
    ORDER BY purchase_date DESC, pu.id DESC');

render_header('Purchases');
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Purchase History</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="pb-2">Date</th>
                        <th class="pb-2">Product</th>
                        <th class="pb-2">Quantity</th>
                        <th class="pb-2">Unit Cost</th>
                        <th class="pb-2">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($purchases)): ?>
                        <tr>
                            <td colspan="5" class="py-6 text-center text-slate-400">No purchases recorded yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($purchases as $purchase): ?>
                            <tr>
                                <td class="py-2 text-slate-600"><?= htmlspecialchars($purchase['purchase_date']) ?></td>
                                <td class="py-2 font-medium text-slate-800"><?= htmlspecialchars($purchase['product_name']) ?></td>
                                <td class="py-2 text-slate-600"><?= number_format((float)$purchase['quantity'], 2) ?> <?= htmlspecialchars($purchase['unit']) ?></td>
                                <td class="py-2 text-slate-600">₩<?= number_format((float)$purchase['unit_cost'], 2) ?></td>
                                <td class="py-2 text-slate-800 font-medium">₩<?= number_format((float)$purchase['quantity'] * (float)$purchase['unit_cost'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Record a Purchase</h3>
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
            <p class="text-sm text-slate-500">Add products first before recording purchases.</p>
        <?php else: ?>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Product</label>
                    <select name="product_id" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                        <option value="">Select product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= (int)$product['id'] ?>" <?= isset($_POST['product_id']) && (int)$_POST['product_id'] === (int)$product['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($product['name']) ?> (<?= htmlspecialchars($product['unit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Quantity</label>
                        <input type="number" step="0.01" min="0" name="quantity" value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Unit cost (₩)</label>
                        <input type="number" step="0.01" min="0" name="unit_cost" value="<?= htmlspecialchars($_POST['unit_cost'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Purchase date</label>
                    <input type="date" name="purchase_date" value="<?= htmlspecialchars($_POST['purchase_date'] ?? date('Y-m-d')) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Notes (optional)</label>
                    <textarea name="notes" rows="3" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Supplier, invoice number, etc."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
                <div class="pt-2">
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800">Save purchase</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php
render_footer();
?>
