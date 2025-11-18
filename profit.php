<?php
require_once __DIR__ . '/inc/auth.php';
require_login();
require_once __DIR__ . '/inc/layout.php';

function periodMetrics(PDO $pdo, string $start, string $end): array
{
    $salesData = fetchOne($pdo, 'SELECT IFNULL(SUM(si.total),0) AS revenue,
            IFNULL(SUM(si.quantity),0) AS units,
            COUNT(DISTINCT s.id) AS orders
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        WHERE s.sale_date BETWEEN ? AND ?', [$start, $end]);

    $salesByProduct = fetchAll($pdo, 'SELECT si.product_id, p.name, p.unit, SUM(si.quantity) AS quantity, SUM(si.total) AS revenue
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN products p ON p.id = si.product_id
        WHERE s.sale_date BETWEEN ? AND ?
        GROUP BY si.product_id, p.name, p.unit', [$start, $end]);

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
        return '<span class="text-slate-500 text-xs">O\'zgarish yo\'q</span>';
    }
    if ($previous == 0) {
        return '<span class="text-emerald-600 text-xs">▲ Yangi</span>';
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

$perProductBreakdown = [];
foreach ($current['sales_by_product'] as $row) {
    $cost = $current['product_costs'][$row['product_id']] ?? 0;
    $perProductBreakdown[] = [
        'name' => $row['name'] ?? 'Noma\'lum',
        'unit' => $row['unit'] ?? '',
        'quantity' => (float)$row['quantity'],
        'revenue' => (float)$row['revenue'],
        'cost' => $cost,
        'profit' => (float)$row['revenue'] - $cost,
    ];
}
usort($perProductBreakdown, fn($a, $b) => $b['profit'] <=> $a['profit']);

$potentialStock = fetchAll($pdo, 'SELECT p.id, p.name, p.unit, p.default_price,
    IFNULL((SELECT SUM(quantity) FROM purchases WHERE product_id = p.id),0) +
    IFNULL((SELECT SUM(quantity_change) FROM adjustments WHERE product_id = p.id),0) -
    IFNULL((SELECT SUM(quantity) FROM sale_items WHERE product_id = p.id),0) AS stock
    FROM products p
    ORDER BY p.name');

$potentialProducts = [];
$potentialTotalProfit = 0.0;
foreach ($potentialStock as $row) {
    $stockQty = (float)$row['stock'];
    if ($stockQty <= 0) {
        continue;
    }
    $purchaseTotals = fetchOne($pdo, 'SELECT SUM(quantity * unit_cost) AS cost, SUM(quantity) AS qty FROM purchases WHERE product_id = ?', [$row['id']]);
    $cost = (float)($purchaseTotals['cost'] ?? 0);
    $qty = (float)($purchaseTotals['qty'] ?? 0);
    $avgCost = $qty > 0 ? $cost / $qty : 0;
    $potentialRevenue = $stockQty * (float)$row['default_price'];
    $potentialCost = $stockQty * $avgCost;
    $potentialProfit = $potentialRevenue - $potentialCost;
    $potentialProducts[] = [
        'name' => $row['name'],
        'unit' => $row['unit'],
        'stock' => $stockQty,
        'potential_revenue' => $potentialRevenue,
        'potential_profit' => $potentialProfit,
    ];
    $potentialTotalProfit += $potentialProfit;
}
usort($potentialProducts, fn($a, $b) => $b['potential_profit'] <=> $a['potential_profit']);

render_header('Foyda paneli');
?>
<div class="rounded-3xl border border-slate-200 bg-white p-6 mb-8">
    <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Davr filtri</p>
            <h2 class="text-3xl font-semibold text-slate-900">Sotuvdan foyda va potensialni kuzating</h2>
            <p class="text-sm text-slate-500">Har bir mahsulot bo'yicha foyda va qolgan zaxiradan olinadigan potensial daromadni ko'ring.</p>
        </div>
        <form method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
            <label class="space-y-1">
                <span class="text-slate-500">Rejim</span>
                <select name="mode" class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-brand-400 focus:ring-brand-200">
                    <option value="current" <?= $mode === 'current' ? 'selected' : '' ?>>Joriy oy</option>
                    <option value="previous" <?= $mode === 'previous' ? 'selected' : '' ?>>O'tgan oy</option>
                    <option value="custom" <?= $mode === 'custom' ? 'selected' : '' ?>>Tanlangan davr</option>
                </select>
            </label>
            <label class="space-y-1">
                <span class="text-slate-500">Boshlanish</span>
                <input type="date" name="start" value="<?= htmlspecialchars($start) ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-brand-400 focus:ring-brand-200">
            </label>
            <label class="space-y-1">
                <span class="text-slate-500">Tugash</span>
                <input type="date" name="end" value="<?= htmlspecialchars($end) ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2 focus:border-brand-400 focus:ring-brand-200">
            </label>
            <div class="flex items-end gap-2">
                <button type="submit" class="w-full inline-flex items-center justify-center rounded-xl bg-brand-600 text-white font-semibold px-4 py-2">Yangilash</button>
                <a href="profit.php" class="w-full inline-flex items-center justify-center rounded-xl border border-slate-200 text-slate-600 px-4 py-2">Tozalash</a>
            </div>
        </form>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Foydalilik ko'rsatkichi</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div class="p-4 border border-slate-200 rounded-lg">
                <p class="text-slate-500">Tushum</p>
                <p class="text-2xl font-semibold text-slate-900"><?= number_format($current['revenue'], 2) ?> so'm</p>
                <?= trendBadge($current['revenue'], $comparison['revenue']) ?>
            </div>
            <div class="p-4 border border-slate-200 rounded-lg">
                <p class="text-slate-500">Yalpi foyda</p>
                <p class="text-2xl font-semibold text-emerald-600"><?= number_format($current['gross_profit'], 2) ?> so'm</p>
                <?= trendBadge($current['gross_profit'], $comparison['gross_profit']) ?>
            </div>
            <div class="p-4 border border-slate-200 rounded-lg">
                <p class="text-slate-500">Foyda marjasi</p>
                <p class="text-2xl font-semibold text-slate-900"><?= number_format($current['margin'], 2) ?>%</p>
                <?= trendBadge($current['margin'], $comparison['margin']) ?>
            </div>
            <div class="p-4 border border-slate-200 rounded-lg">
                <p class="text-slate-500">O'rtacha chek summasi</p>
                <p class="text-2xl font-semibold text-slate-900"><?= number_format($current['average_order'], 2) ?> so'm</p>
                <?= trendBadge($current['average_order'], $comparison['average_order']) ?>
            </div>
        </div>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Naqd va Click</h3>
        <ul class="space-y-2 text-sm">
            <li class="flex justify-between"><span class="text-slate-500">Naqd to'lovlar</span><span class="text-emerald-600 font-medium"><?= number_format($current['cash'], 2) ?> so'm</span></li>
            <li class="flex justify-between"><span class="text-slate-500">Click to'lovlar</span><span class="text-emerald-600 font-medium"><?= number_format($current['click'], 2) ?> so'm</span></li>
            <li class="flex justify-between pt-2 border-t border-slate-200"><span class="text-slate-500">Yig'imlar</span><span class="text-slate-700"><?= number_format($current['collections'], 2) ?> so'm</span></li>
            <li class="flex justify-between"><span class="text-slate-500">Yakuniy qarz</span><span class="text-rose-600 font-medium"><?= number_format($current['closing_debt'], 2) ?> so'm</span></li>
        </ul>
    </section>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-3">Qarzdorlik harakati</h3>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Boshlang'ich qarz</dt><dd class="text-slate-700"><?= number_format($current['opening_debt'], 2) ?> so'm</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Yig'imlar</dt><dd class="text-emerald-600"><?= number_format($current['collections'], 2) ?> so'm</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Yangi qarz</dt><dd class="text-rose-600"><?= number_format($current['new_debt'], 2) ?> so'm</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Yakuniy qarz</dt><dd class="text-slate-800 font-semibold"><?= number_format($current['closing_debt'], 2) ?> so'm</dd></div>
        </dl>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-3">Sotilgan birliklar</h3>
        <p class="text-2xl font-semibold text-slate-900"><?= number_format($current['units'], 2) ?></p>
        <p class="text-sm text-slate-500">Ushbu davrda <?= $current['orders'] ?> ta chek bo'yicha.</p>
    </section>
</div>

<div class="bg-white border border-slate-200 rounded-lg p-5 mt-6">
    <h3 class="text-lg font-semibold text-slate-800 mb-3">Kunlik trend</h3>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="pb-2">Sana</th>
                    <th class="pb-2">Tushum</th>
                    <th class="pb-2">Birliklar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($dailyTrend)): ?>
                    <tr><td colspan="3" class="py-6 text-center text-slate-400">Bu davrda savdolar mavjud emas.</td></tr>
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

<section class="bg-white border border-slate-200 rounded-lg p-5 mt-6 space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold text-slate-800">Har biri · mahsulotlar bo'yicha foyda</h3>
            <p class="text-sm text-slate-500">Tanlangan davrda sotilgan barcha mahsulotlar bo'yicha tushum va COGS.</p>
        </div>
        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
            <?= count($perProductBreakdown) ?> ta mahsulot
        </span>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="pb-2">Mahsulot</th>
                    <th class="pb-2">Sotildi</th>
                    <th class="pb-2">Tushum</th>
                    <th class="pb-2">COGS</th>
                    <th class="pb-2">Foyda</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($perProductBreakdown)): ?>
                    <tr><td colspan="5" class="py-6 text-center text-slate-400">Ushbu davrda savdolar mavjud emas.</td></tr>
                <?php else: ?>
                    <?php foreach ($perProductBreakdown as $row): ?>
                        <tr>
                            <td class="py-2 text-slate-700"><?= htmlspecialchars($row['name']) ?></td>
                            <td class="py-2 text-slate-600"><?= number_format($row['quantity'], 2) ?> <?= htmlspecialchars($row['unit']) ?></td>
                            <td class="py-2 text-slate-800"><?= number_format($row['revenue'], 2) ?> so'm</td>
                            <td class="py-2 text-slate-500"><?= number_format($row['cost'], 2) ?> so'm</td>
                            <td class="py-2 font-semibold <?= $row['profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= number_format($row['profit'], 2) ?> so'm</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="bg-white border border-slate-200 rounded-lg p-5 mt-6 space-y-4">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold text-slate-800">Potential · ombordagi foyda</h3>
            <p class="text-sm text-slate-500">Hozirgi qoldiq to'liq sotilsa olinadigan taxminiy foyda.</p>
        </div>
        <div class="text-right">
            <p class="text-xs text-slate-500">Jami potensial</p>
            <p class="text-2xl font-semibold text-brand-600"><?= number_format($potentialTotalProfit, 2) ?> so'm</p>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase text-slate-500">
                    <th class="pb-2">Mahsulot</th>
                    <th class="pb-2">Qoldiq</th>
                    <th class="pb-2">Potensial tushum</th>
                    <th class="pb-2">Potensial foyda</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (empty($potentialProducts)): ?>
                    <tr><td colspan="4" class="py-6 text-center text-slate-400">Omborda foyda keltiruvchi mahsulot topilmadi.</td></tr>
                <?php else: ?>
                    <?php foreach (array_slice($potentialProducts, 0, 12) as $row): ?>
                        <tr>
                            <td class="py-2 text-slate-700"><?= htmlspecialchars($row['name']) ?></td>
                            <td class="py-2 text-slate-600"><?= number_format($row['stock'], 2) ?> <?= htmlspecialchars($row['unit']) ?></td>
                            <td class="py-2 text-slate-800"><?= number_format($row['potential_revenue'], 2) ?> so'm</td>
                            <td class="py-2 font-semibold text-emerald-600"><?= number_format($row['potential_profit'], 2) ?> so'm</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
render_footer();
?>
