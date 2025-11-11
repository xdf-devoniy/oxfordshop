<?php
require_once __DIR__ . '/inc/layout.php';

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');

try {
    $startDate = new DateTime($start);
    $endDate = new DateTime($end);
} catch (Exception $e) {
    $startDate = new DateTime(date('Y-m-01'));
    $endDate = new DateTime(date('Y-m-d'));
}

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$start = $startDate->format('Y-m-d');
$end = $endDate->format('Y-m-d');

$purchasesTotal = fetchOne($pdo, 'SELECT IFNULL(SUM(quantity * unit_cost),0) AS total
    FROM purchases
    WHERE purchase_date BETWEEN ? AND ?', [$start, $end])['total'] ?? 0;

$salesSummary = fetchOne($pdo, 'SELECT IFNULL(SUM(si.total),0) AS revenue,
        IFNULL(SUM(si.quantity),0) AS units
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    WHERE s.sale_date BETWEEN ? AND ?', [$start, $end]);
$revenue = $salesSummary['revenue'] ?? 0;
$unitsSold = $salesSummary['units'] ?? 0;

$paymentsByMethod = fetchAll($pdo, 'SELECT payment_method, IFNULL(SUM(amount),0) AS total
    FROM payments
    WHERE payment_date BETWEEN ? AND ?
    GROUP BY payment_method', [$start, $end]);

$outstanding = fetchOne($pdo, 'SELECT IFNULL(SUM(s.total_amount - IFNULL(pay.total_paid,0)),0) AS balance
    FROM sales s
    LEFT JOIN (
        SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
    ) pay ON pay.sale_id = s.id
    WHERE s.sale_date BETWEEN ? AND ?', [$start, $end]);
$outstanding = $outstanding['balance'] ?? 0;

$salesByProduct = fetchAll($pdo, 'SELECT si.product_id, p.name, p.unit,
        SUM(si.quantity) AS quantity,
        SUM(si.total) AS revenue
    FROM sale_items si
    JOIN sales s ON s.id = si.sale_id
    JOIN products p ON p.id = si.product_id
    WHERE s.sale_date BETWEEN ? AND ?
    GROUP BY si.product_id, p.name, p.unit
    ORDER BY revenue DESC', [$start, $end]);

$cogs = 0;
foreach ($salesByProduct as $row) {
    $purchaseTotals = fetchOne($pdo, 'SELECT SUM(quantity * unit_cost) AS cost, SUM(quantity) AS qty
        FROM purchases
        WHERE product_id = ? AND purchase_date <= ?', [$row['product_id'], $end]);
    $cost = (float)($purchaseTotals['cost'] ?? 0);
    $qty = (float)($purchaseTotals['qty'] ?? 0);
    $avgCost = $qty > 0 ? $cost / $qty : 0;
    $cogs += $avgCost * (float)$row['quantity'];
}

$grossProfit = $revenue - $cogs;
$margin = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;

$stockOnHand = fetchAll($pdo, 'SELECT p.id, p.name, p.unit, p.default_price,
    IFNULL((SELECT SUM(quantity) FROM purchases WHERE product_id = p.id),0) +
    IFNULL((SELECT SUM(quantity_change) FROM adjustments WHERE product_id = p.id),0) -
    IFNULL((SELECT SUM(quantity) FROM sale_items WHERE product_id = p.id),0) AS stock
    FROM products p
    ORDER BY p.name');

render_header('Reports');
?>
<div class="bg-white border border-slate-200 rounded-lg p-6 mb-6">
    <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
        <div>
            <label class="block text-slate-600">Start date</label>
            <input type="date" name="start" value="<?= htmlspecialchars($start) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring focus:ring-slate-400">
        </div>
        <div>
            <label class="block text-slate-600">End date</label>
            <input type="date" name="end" value="<?= htmlspecialchars($end) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring focus:ring-slate-400">
        </div>
        <div class="flex items-end">
            <button type="submit" class="w-full md:w-auto inline-flex justify-center px-4 py-2 bg-slate-900 text-white rounded-md">Run report</button>
        </div>
        <div class="flex items-end">
            <a href="reports.php" class="w-full md:w-auto inline-flex justify-center px-4 py-2 border border-slate-300 rounded-md text-slate-600">Reset</a>
        </div>
    </form>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Financial Summary</h3>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between">
                <dt class="text-slate-500">Revenue</dt>
                <dd class="text-slate-800 font-semibold"><?= number_format($revenue, 2) ?> so'm</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">COGS</dt>
                <dd class="text-slate-600"><?= number_format($cogs, 2) ?> so'm</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">Gross profit</dt>
                <dd class="text-emerald-600 font-semibold"><?= number_format($grossProfit, 2) ?> so'm</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">Profit margin</dt>
                <dd class="text-slate-700"><?= number_format($margin, 2) ?>%</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">Units sold</dt>
                <dd class="text-slate-700"><?= number_format($unitsSold, 2) ?></dd>
            </div>
        </dl>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Payments Collected</h3>
        <ul class="space-y-2 text-sm">
            <?php if (empty($paymentsByMethod)): ?>
                <li class="text-slate-500">No payments recorded in this range.</li>
            <?php else: ?>
                <?php foreach ($paymentsByMethod as $row): ?>
                    <li class="flex justify-between">
                        <span class="uppercase text-slate-500"><?= htmlspecialchars($row['payment_method']) ?></span>
                        <span class="text-emerald-600 font-medium"><?= number_format((float)$row['total'], 2) ?> so'm</span>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
            <li class="flex justify-between pt-2 border-t border-slate-200 mt-2">
                <span class="text-slate-600">Outstanding</span>
                <span class="text-rose-600 font-medium"><?= number_format((float)$outstanding, 2) ?> so'm</span>
            </li>
        </ul>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Purchases</h3>
        <p class="text-sm text-slate-500">Stock investment for this period.</p>
        <p class="text-2xl font-semibold text-slate-800 mt-2"><?= number_format((float)$purchasesTotal, 2) ?> so'm</p>
        <p class="text-xs text-slate-500 mt-1">Compare against sales to monitor cashflow.</p>
    </section>
</div>

<div class="bg-white border border-slate-200 rounded-lg p-5 mt-6">
    <h3 class="text-lg font-semibold text-slate-800 mb-4">Top Products</h3>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="pb-2">Product</th>
                    <th class="pb-2">Quantity</th>
                    <th class="pb-2">Revenue</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($salesByProduct)): ?>
                    <tr>
                        <td colspan="3" class="py-6 text-center text-slate-400">No product sales in this range.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($salesByProduct as $row): ?>
                        <tr>
                            <td class="py-2 text-slate-700"><?= htmlspecialchars($row['name']) ?> (<?= htmlspecialchars($row['unit']) ?>)</td>
                            <td class="py-2 text-slate-600"><?= number_format((float)$row['quantity'], 2) ?></td>
                            <td class="py-2 text-slate-800"><?= number_format((float)$row['revenue'], 2) ?> so'm</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white border border-slate-200 rounded-lg p-5 mt-6">
    <h3 class="text-lg font-semibold text-slate-800 mb-4">Stock on Hand</h3>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="pb-2">Product</th>
                    <th class="pb-2">In Stock</th>
                    <th class="pb-2">Estimated Value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($stockOnHand)): ?>
                    <tr>
                        <td colspan="3" class="py-6 text-center text-slate-400">Add products to begin tracking stock.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($stockOnHand as $stock):
                        $value = (float)$stock['stock'] * (float)$stock['default_price'];
                    ?>
                        <tr>
                            <td class="py-2 text-slate-700"><?= htmlspecialchars($stock['name']) ?></td>
                            <td class="py-2 text-slate-600"><?= number_format((float)$stock['stock'], 2) ?> <?= htmlspecialchars($stock['unit']) ?></td>
                            <td class="py-2 text-slate-800"><?= number_format($value, 2) ?> so'm</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
render_footer();
?>
