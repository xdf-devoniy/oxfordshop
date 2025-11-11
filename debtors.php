<?php
require_once __DIR__ . '/inc/layout.php';

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($name === '') {
        $errors[] = 'Customer name is required.';
    }

    if (empty($errors)) {
        execute($pdo, 'INSERT INTO customers (name, phone, notes) VALUES (?, ?, ?)', [$name, $phone ?: null, $notes ?: null]);
        $success = 'Customer added successfully.';
    }
}

$customerBalances = fetchAll($pdo, 'SELECT c.id, c.name, c.phone, c.notes,
        IFNULL(SUM(s.total_amount),0) AS total_sold,
        IFNULL(SUM(pay.total_paid),0) AS total_paid,
        IFNULL(SUM(s.total_amount - IFNULL(pay.total_paid,0)),0) AS balance
    FROM customers c
    LEFT JOIN sales s ON s.customer_id = c.id
    LEFT JOIN (
        SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
    ) pay ON pay.sale_id = s.id
    GROUP BY c.id
    HAVING balance > 0
    ORDER BY balance DESC');

$outstandingSales = fetchAll($pdo, 'SELECT s.id, s.customer_id, s.sale_date, s.total_amount, IFNULL(pay.total_paid,0) AS total_paid
    FROM sales s
    LEFT JOIN (
        SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
    ) pay ON pay.sale_id = s.id
    WHERE s.total_amount > IFNULL(pay.total_paid,0)
');

$aging = [];
foreach ($outstandingSales as $sale) {
    $customerId = $sale['customer_id'];
    if (!$customerId) {
        continue; // walk-in debts handled separately
    }
    $balance = (float)$sale['total_amount'] - (float)$sale['total_paid'];
    $days = (new DateTime($sale['sale_date']))->diff(new DateTime())->days;
    $bucket = '0-7';
    if ($days > 30) {
        $bucket = '30+';
    } elseif ($days > 7) {
        $bucket = '8-30';
    }
    $aging[$customerId][$bucket] = ($aging[$customerId][$bucket] ?? 0) + $balance;
}

$walkInOutstanding = fetchOne($pdo, 'SELECT IFNULL(SUM(s.total_amount - IFNULL(pay.total_paid,0)),0) AS outstanding
    FROM sales s
    LEFT JOIN (
        SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
    ) pay ON pay.sale_id = s.id
    WHERE s.customer_id IS NULL AND s.total_amount > IFNULL(pay.total_paid,0)');
$walkInOutstanding = $walkInOutstanding ? (float)$walkInOutstanding['outstanding'] : 0.0;

render_header('Debtors');
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-800">Customers with Outstanding Balances</h3>
            <?php if ($walkInOutstanding > 0): ?>
                <span class="text-xs bg-rose-100 text-rose-700 px-2 py-1 rounded-full">Walk-in debt: ₩<?= number_format($walkInOutstanding, 2) ?></span>
            <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="pb-2">Customer</th>
                        <th class="pb-2">Phone</th>
                        <th class="pb-2">Total Sold</th>
                        <th class="pb-2">Paid</th>
                        <th class="pb-2">Balance</th>
                        <th class="pb-2">0-7</th>
                        <th class="pb-2">8-30</th>
                        <th class="pb-2">30+</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($customerBalances)): ?>
                        <tr>
                            <td colspan="8" class="py-6 text-center text-slate-400">No outstanding balances. Great job!</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customerBalances as $customer):
                            $id = $customer['id'];
                        ?>
                            <tr>
                                <td class="py-2 font-medium text-slate-800">
                                    <?= htmlspecialchars($customer['name']) ?>
                                    <div class="text-xs text-slate-400">#<?= $id ?></div>
                                </td>
                                <td class="py-2 text-slate-600"><?= htmlspecialchars($customer['phone'] ?? '-') ?></td>
                                <td class="py-2 text-slate-600">₩<?= number_format((float)$customer['total_sold'], 2) ?></td>
                                <td class="py-2 text-emerald-600">₩<?= number_format((float)$customer['total_paid'], 2) ?></td>
                                <td class="py-2 text-rose-600 font-medium">₩<?= number_format((float)$customer['balance'], 2) ?></td>
                                <td class="py-2 text-slate-600">₩<?= number_format($aging[$id]['0-7'] ?? 0, 2) ?></td>
                                <td class="py-2 text-slate-600">₩<?= number_format($aging[$id]['8-30'] ?? 0, 2) ?></td>
                                <td class="py-2 text-slate-600">₩<?= number_format($aging[$id]['30+'] ?? 0, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Add Customer</h3>
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
        <form method="post" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-700">Customer name</label>
                <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Phone</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Optional">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Notes</label>
                <textarea name="notes" rows="3" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Favorite drinks, payment terms, etc."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>
            <div class="pt-2">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800">Save customer</button>
            </div>
        </form>
    </section>
</div>
<?php
render_footer();
?>
