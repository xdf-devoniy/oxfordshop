<?php
require_once __DIR__ . '/inc/layout.php';

$totalProducts = fetchOne($pdo, 'SELECT COUNT(*) AS cnt FROM products')['cnt'] ?? 0;
$totalCustomers = fetchOne($pdo, 'SELECT COUNT(*) AS cnt FROM customers')['cnt'] ?? 0;
$totalRevenue = fetchOne($pdo, 'SELECT IFNULL(SUM(total),0) AS total FROM sale_items')['total'] ?? 0;
$totalPurchases = fetchOne($pdo, 'SELECT IFNULL(SUM(quantity * unit_cost),0) AS total FROM purchases')['total'] ?? 0;
$totalPayments = fetchOne($pdo, 'SELECT IFNULL(SUM(amount),0) AS total FROM payments')['total'] ?? 0;
$outstanding = fetchOne($pdo, 'SELECT IFNULL(SUM(s.total_amount - IFNULL(p.paid,0)),0) AS outstanding
    FROM sales s
    LEFT JOIN (
        SELECT sale_id, SUM(amount) AS paid FROM payments GROUP BY sale_id
    ) p ON p.sale_id = s.id')['outstanding'] ?? 0;

$stockRows = fetchAll($pdo, 'SELECT p.id, p.name, p.unit,
    IFNULL((SELECT SUM(quantity) FROM purchases WHERE product_id = p.id),0) +
    IFNULL((SELECT SUM(quantity_change) FROM adjustments WHERE product_id = p.id),0) -
    IFNULL((SELECT SUM(quantity) FROM sale_items WHERE product_id = p.id),0) AS stock
    FROM products p');

$totalStockItems = 0;
$lowStock = [];
foreach ($stockRows as $row) {
    $qty = (float)$row['stock'];
    $totalStockItems += $qty;
    if ($qty <= 5) {
        $lowStock[] = $row;
    }
}

$latestSales = fetchAll($pdo, 'SELECT s.id, s.sale_date, s.total_amount,
    IFNULL(c.name, "Walk-in") AS customer,
    IFNULL(paid.total_paid,0) AS total_paid
    FROM sales s
    LEFT JOIN customers c ON c.id = s.customer_id
    LEFT JOIN (
        SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
    ) paid ON paid.sale_id = s.id
    ORDER BY s.sale_date DESC, s.id DESC
    LIMIT 5');

render_header('Dashboard');
?>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="bg-white border border-slate-200 rounded-lg p-5">
        <p class="text-sm text-slate-500">Total Products</p>
        <p class="text-3xl font-semibold text-slate-900"><?= number_format((float)$totalProducts) ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-lg p-5">
        <p class="text-sm text-slate-500">Total Customers</p>
        <p class="text-3xl font-semibold text-slate-900"><?= number_format((float)$totalCustomers) ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-lg p-5">
        <p class="text-sm text-slate-500">Revenue To Date</p>
        <p class="text-3xl font-semibold text-emerald-600"><?= number_format((float)$totalRevenue, 2) ?> so'm</p>
    </div>
    <div class="bg-white border border-slate-200 rounded-lg p-5">
        <p class="text-sm text-slate-500">Outstanding Debt</p>
        <p class="text-3xl font-semibold text-rose-600"><?= number_format((float)$outstanding, 2) ?> so'm</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Recent Sales</h3>
        <div class="overflow-x-auto">
            <div class="max-h-[22rem] overflow-y-auto">
                <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 uppercase text-xs">
                        <th class="pb-2">Receipt #</th>
                        <th class="pb-2">Date</th>
                        <th class="pb-2">Customer</th>
                        <th class="pb-2">Total</th>
                        <th class="pb-2">Paid</th>
                        <th class="pb-2">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($latestSales)): ?>
                        <tr>
                            <td colspan="6" class="py-6 text-center text-slate-400">No sales recorded yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($latestSales as $sale):
                            $balance = (float)$sale['total_amount'] - (float)$sale['total_paid'];
                        ?>
                            <tr>
                                <td class="py-2 font-medium text-slate-700">#<?= (int)$sale['id'] ?></td>
                                <td class="py-2 text-slate-600"><?= htmlspecialchars($sale['sale_date']) ?></td>
                                <td class="py-2 text-slate-600"><?= htmlspecialchars($sale['customer']) ?></td>
                                <td class="py-2 text-slate-800"><?= number_format((float)$sale['total_amount'], 2) ?> so'm</td>
                                <td class="py-2 text-emerald-600"><?= number_format((float)$sale['total_paid'], 2) ?> so'm</td>
                                <td class="py-2 <?= $balance > 0 ? 'text-rose-600' : 'text-slate-500' ?>"><?= number_format($balance, 2) ?> so'm</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                </table>
            </div>
        </div>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Inventory Snapshot</h3>
        <p class="text-sm text-slate-500 mb-2">Total units in stock: <span class="font-semibold text-slate-700"><?= number_format($totalStockItems, 2) ?></span></p>
        <div class="space-y-2">
            <?php if (empty($lowStock)): ?>
                <p class="text-sm text-slate-500">All products have healthy stock levels.</p>
            <?php else: ?>
                <?php foreach ($lowStock as $item): ?>
                    <div class="border border-amber-200 bg-amber-50 rounded-md px-3 py-2">
                        <p class="text-sm font-medium text-amber-900"><?= htmlspecialchars($item['name']) ?></p>
                        <p class="text-xs text-amber-700">Remaining: <?= number_format((float)$item['stock'], 2) ?> <?= htmlspecialchars($item['unit']) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Cashflow</h3>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between">
                <dt class="text-slate-500">Total purchases</dt>
                <dd class="text-slate-700"><?= number_format((float)$totalPurchases, 2) ?> so'm</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">Payments received</dt>
                <dd class="text-emerald-600 font-medium"><?= number_format((float)$totalPayments, 2) ?> so'm</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">Outstanding balance</dt>
                <dd class="text-rose-600 font-medium"><?= number_format((float)$outstanding, 2) ?> so'm</dd>
            </div>
        </dl>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Guided Workflow</h3>
        <ol class="space-y-3 text-sm text-slate-600">
            <li><span class="font-semibold text-slate-800">1.</span> Create or update products with their default selling prices.</li>
            <li><span class="font-semibold text-slate-800">2.</span> Log purchases whenever you restock from suppliers.</li>
            <li><span class="font-semibold text-slate-800">3.</span> Record sales, capture immediate payments, or leave balances as debt.</li>
            <li><span class="font-semibold text-slate-800">4.</span> Track receipts to add follow-up payments and monitor debtors.</li>
            <li><span class="font-semibold text-slate-800">5.</span> Review reports and profit dashboards to stay profitable.</li>
        </ol>
    </section>
</div>
<?php
render_footer();
?>
