<?php
require_once __DIR__ . '/inc/layout.php';

function periodMetrics(PDO $pdo, string $start, string $end): array
{
    $salesData = fetchOne($pdo, 'SELECT IFNULL(SUM(si.total),0) AS revenue,
            IFNULL(SUM(si.quantity),0) AS units,
            COUNT(DISTINCT s.id) AS orders
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE s.sale_date BETWEEN ? AND ?', [$start, $end]);

    $salesByProduct = fetchAll($pdo, 'SELECT si.product_id, SUM(si.quantity) AS quantity, SUM(si.total) AS revenue
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE s.sale_date BETWEEN ? AND ?
        GROUP BY si.product_id', [$start, $end]);

    $cogs = 0;
    $productCosts = [];
    foreach ($salesByProduct as $row) {
        $purchaseTotals = fetchOne($pdo, 'SELECT SUM(quantity * unit_cost) AS cost, SUM(quantity) AS qty
            FROM purchases
            WHERE product_id = ? AND purchase_date <= ?', [$row['product_id'], $end]);
        $cost = (float)($purchaseTotals['cost'] ?? 0);
        $qty = (float)($purchaseTotals['qty'] ?? 0);
        $avgCost = $qty > 0 ? $cost / $qty : 0;
        $lineCost = $avgCost * (float)$row['quantity'];
        $cogs += $lineCost;
        $productCosts[$row['product_id']] = $lineCost;
    }

    $payments = fetchAll($pdo, 'SELECT payment_method, SUM(amount) AS total
        FROM payments
        WHERE payment_date BETWEEN ? AND ?
        GROUP BY payment_method', [$start, $end]);
    $paymentsByMethod = ['cash' => 0, 'click' => 0];
    foreach ($payments as $payment) {
        $method = strtolower($payment['payment_method']);
        $paymentsByMethod[$method] = ($paymentsByMethod[$method] ?? 0) + (float)$payment['total'];
    }

    $grossProfit = ((float)$salesData['revenue']) - $cogs;
    $margin = ($salesData['revenue'] ?? 0) > 0 ? ($grossProfit / (float)$salesData['revenue']) * 100 : 0;
    $averageOrder = ($salesData['orders'] ?? 0) > 0 ? ((float)$salesData['revenue']) / (float)$salesData['orders'] : 0;

    $closingDebt = fetchOne($pdo, 'SELECT IFNULL(SUM(s.total_amount - IFNULL(pay.total_paid,0)),0) AS balance
        FROM sales s
        LEFT JOIN (
            SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
        ) pay ON pay.sale_id = s.id
        WHERE s.sale_date <= ?', [$end]);
    $closingDebt = (float)($closingDebt['balance'] ?? 0);

    $openingDebt = fetchOne($pdo, 'SELECT IFNULL(SUM(s.total_amount - IFNULL(pay.total_paid,0)),0) AS balance
        FROM sales s
        LEFT JOIN (
            SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
        ) pay ON pay.sale_id = s.id
        WHERE s.sale_date < ?', [$start]);
    $openingDebt = (float)($openingDebt['balance'] ?? 0);

    $collections = fetchOne($pdo, 'SELECT IFNULL(SUM(amount),0) AS total FROM payments WHERE payment_date BETWEEN ? AND ?', [$start, $end]);
    $collections = (float)($collections['total'] ?? 0);

    $newDebt = max(0, $closingDebt - $openingDebt + $collections);

    return [
        'start' => $start,
        'end' => $end,
        'revenue' => (float)($salesData['revenue'] ?? 0),
        'units' => (float)($salesData['units'] ?? 0),
        'orders' => (int)($salesData['orders'] ?? 0),
        'cogs' => $cogs,
        'gross_profit' => $grossProfit,
        'margin' => $margin,
        'cash' => $paymentsByMethod['cash'] ?? 0,
        'click' => $paymentsByMethod['click'] ?? 0,
        'collections' => $collections,
        'average_order' => $averageOrder,
        'opening_debt' => $openingDebt,
        'closing_debt' => $closingDebt,
        'new_debt' => $newDebt,
        'product_costs' => $productCosts,
        'sales_by_product' => $salesByProduct,
    ];
}

$mode = $_GET['mode'] ?? 'current';
$customStart = $_GET['start'] ?? date('Y-m-01');
$customEnd = $_GET['end'] ?? date('Y-m-d');

if ($mode === 'previous') {
    $start = (new DateTime('first day of previous month'))->format('Y-m-01');
    $end = (new DateTime('last day of previous month'))->format('Y-m-t');
    $compareStart = (new DateTime('first day of -2 month'))->format('Y-m-01');
    $compareEnd = (new DateTime('last day of -2 month'))->format('Y-m-t');
} elseif ($mode === 'custom') {
    try {
        $startDate = new DateTime($customStart);
        $endDate = new DateTime($customEnd);
    } catch (Exception $e) {
        $startDate = new DateTime(date('Y-m-01'));
        $endDate = new DateTime(date('Y-m-d'));
    }
    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
    $start = $startDate->format('Y-m-d');
    $end = $endDate->format('Y-m-d');
    $compareStart = (new DateTime($start))->modify('-1 month')->format('Y-m-01');
    $compareEnd = (new DateTime($start))->modify('-1 month')->format('Y-m-t');
} else {
    $start = date('Y-m-01');
    $end = date('Y-m-d');
    $compareStart = (new DateTime('first day of previous month'))->format('Y-m-01');
    $compareEnd = (new DateTime('last day of previous month'))->format('Y-m-t');
}

$current = periodMetrics($pdo, $start, $end);
$comparison = periodMetrics($pdo, $compareStart, $compareEnd);

function trendBadge(float $current, float $previous): string
{
    if ($previous == 0 && $current == 0) {
        return '<span class="text-slate-500 text-xs">No change</span>';
    }
    if ($previous == 0) {
        return '<span class="text-emerald-600 text-xs">▲ New</span>';
    }
    $diff = (($current - $previous) / $previous) * 100;
    if ($diff > 0) {
        return '<span class="text-emerald-600 text-xs">▲ ' . number_format($diff, 1) . '%</span>';
    }
    if ($diff < 0) {
        return '<span class="text-rose-600 text-xs">▼ ' . number_format(abs($diff), 1) . '%</span>';
    }
    return '<span class="text-slate-500 text-xs">0%</span>';
}

$dailyTrend = fetchAll($pdo, 'SELECT s.sale_date AS day, SUM(si.total) AS revenue, SUM(si.quantity) AS units
    FROM sales s
    JOIN sale_items si ON si.sale_id = s.id
    WHERE s.sale_date BETWEEN ? AND ?
    GROUP BY s.sale_date
    ORDER BY s.sale_date', [$start, $end]);

$topProducts = [];
foreach ($current['sales_by_product'] as $row) {
    $profit = (float)$row['revenue'] - ($current['product_costs'][$row['product_id']] ?? 0);
    $product = fetchOne($pdo, 'SELECT name FROM products WHERE id = ?', [$row['product_id']]);
    $topProducts[] = [
        'name' => $product['name'] ?? 'Unknown',
        'revenue' => (float)$row['revenue'],
        'profit' => $profit,
        'quantity' => (float)$row['quantity'],
    ];
}

usort($topProducts, fn($a, $b) => $b['profit'] <=> $a['profit']);
$topProducts = array_slice($topProducts, 0, 10);

render_header('Profit Dashboard');
?>
<div class="bg-white border border-slate-200 rounded-lg p-6 mb-6">
    <form method="get" class="flex flex-wrap gap-3 text-sm items-end">
        <div>
            <label class="block text-slate-600">Mode</label>
            <select name="mode" class="mt-1 border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring focus:ring-slate-400">
                <option value="current" <?= $mode === 'current' ? 'selected' : '' ?>>This month</option>
                <option value="previous" <?= $mode === 'previous' ? 'selected' : '' ?>>Previous month</option>
                <option value="custom" <?= $mode === 'custom' ? 'selected' : '' ?>>Custom range</option>
            </select>
        </div>
        <div>
            <label class="block text-slate-600">Start</label>
            <input type="date" name="start" value="<?= htmlspecialchars($start) ?>" class="mt-1 border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring focus:ring-slate-400">
        </div>
        <div>
            <label class="block text-slate-600">End</label>
            <input type="date" name="end" value="<?= htmlspecialchars($end) ?>" class="mt-1 border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring focus:ring-slate-400">
        </div>
        <div>
            <button type="submit" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white rounded-md">Update</button>
        </div>
    </form>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Profitability Overview</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div class="p-4 border border-slate-200 rounded-lg">
                <p class="text-slate-500">Revenue</p>
                <p class="text-2xl font-semibold text-slate-900"><?= number_format($current['revenue'], 2) ?> so'm</p>
                <?= trendBadge($current['revenue'], $comparison['revenue']) ?>
            </div>
            <div class="p-4 border border-slate-200 rounded-lg">
                <p class="text-slate-500">Gross Profit</p>
                <p class="text-2xl font-semibold text-emerald-600"><?= number_format($current['gross_profit'], 2) ?> so'm</p>
                <?= trendBadge($current['gross_profit'], $comparison['gross_profit']) ?>
            </div>
            <div class="p-4 border border-slate-200 rounded-lg">
                <p class="text-slate-500">Profit Margin</p>
                <p class="text-2xl font-semibold text-slate-900"><?= number_format($current['margin'], 2) ?>%</p>
                <?= trendBadge($current['margin'], $comparison['margin']) ?>
            </div>
            <div class="p-4 border border-slate-200 rounded-lg">
                <p class="text-slate-500">Average Order Value</p>
                <p class="text-2xl font-semibold text-slate-900"><?= number_format($current['average_order'], 2) ?> so'm</p>
                <?= trendBadge($current['average_order'], $comparison['average_order']) ?>
            </div>
        </div>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Cash vs Click</h3>
        <ul class="space-y-2 text-sm">
            <li class="flex justify-between"><span class="text-slate-500">Cash collected</span><span class="text-emerald-600 font-medium"><?= number_format($current['cash'], 2) ?> so'm</span></li>
            <li class="flex justify-between"><span class="text-slate-500">Click collected</span><span class="text-emerald-600 font-medium"><?= number_format($current['click'], 2) ?> so'm</span></li>
            <li class="flex justify-between pt-2 border-t border-slate-200"><span class="text-slate-500">Collections</span><span class="text-slate-700"><?= number_format($current['collections'], 2) ?> so'm</span></li>
            <li class="flex justify-between"><span class="text-slate-500">Closing debt</span><span class="text-rose-600 font-medium"><?= number_format($current['closing_debt'], 2) ?> so'm</span></li>
        </ul>
    </section>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-3">Debtor Movement</h3>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Opening debt</dt><dd class="text-slate-700"><?= number_format($current['opening_debt'], 2) ?> so'm</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Collections</dt><dd class="text-emerald-600"><?= number_format($current['collections'], 2) ?> so'm</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">New debt</dt><dd class="text-rose-600"><?= number_format($current['new_debt'], 2) ?> so'm</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Closing debt</dt><dd class="text-slate-800 font-semibold"><?= number_format($current['closing_debt'], 2) ?> so'm</dd></div>
        </dl>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-3">Units Sold</h3>
        <p class="text-2xl font-semibold text-slate-900"><?= number_format($current['units'], 2) ?></p>
        <p class="text-sm text-slate-500">Across <?= $current['orders'] ?> orders in this period.</p>
    </section>
</div>

<div class="bg-white border border-slate-200 rounded-lg p-5 mt-6">
    <h3 class="text-lg font-semibold text-slate-800 mb-3">Daily Trend</h3>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="pb-2">Date</th>
                    <th class="pb-2">Revenue</th>
                    <th class="pb-2">Units</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($dailyTrend)): ?>
                    <tr><td colspan="3" class="py-6 text-center text-slate-400">No sales in this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($dailyTrend as $day): ?>
                        <tr>
                            <td class="py-2 text-slate-600"><?= htmlspecialchars($day['day']) ?></td>
                            <td class="py-2 text-slate-800"><?= number_format((float)$day['revenue'], 2) ?> so'm</td>
                            <td class="py-2 text-slate-600"><?= number_format((float)$day['units'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white border border-slate-200 rounded-lg p-5 mt-6">
    <h3 class="text-lg font-semibold text-slate-800 mb-3">Top Products by Profit</h3>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="pb-2">Product</th>
                    <th class="pb-2">Quantity</th>
                    <th class="pb-2">Revenue</th>
                    <th class="pb-2">Profit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($topProducts)): ?>
                    <tr><td colspan="4" class="py-6 text-center text-slate-400">No profitable products to display.</td></tr>
                <?php else: ?>
                    <?php foreach ($topProducts as $product): ?>
                        <tr>
                            <td class="py-2 text-slate-700"><?= htmlspecialchars($product['name']) ?></td>
                            <td class="py-2 text-slate-600"><?= number_format($product['quantity'], 2) ?></td>
                            <td class="py-2 text-slate-800"><?= number_format($product['revenue'], 2) ?> so'm</td>
                            <td class="py-2 text-emerald-600"><?= number_format($product['profit'], 2) ?> so'm</td>
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
