<?php
require_once __DIR__ . '/inc/auth.php';
require_login();
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

$orderSummary = fetchOne($pdo, 'SELECT COUNT(*) AS order_count FROM sales WHERE sale_date BETWEEN ? AND ?', [$start, $end]);
$orderCount = (int)($orderSummary['order_count'] ?? 0);
$averageOrder = $orderCount > 0 ? $revenue / $orderCount : 0;
$unitsPerSale = $orderCount > 0 ? $unitsSold / $orderCount : 0;

$cashTotal = 0.0;
$clickTotal = 0.0;
$otherPaymentsTotal = 0.0;
$otherPayments = [];
foreach ($paymentsByMethod as $row) {
    $method = $row['payment_method'] ?? '';
    $total = (float)($row['total'] ?? 0);
    if ($method === 'cash') {
        $cashTotal = $total;
    } elseif ($method === 'click') {
        $clickTotal = $total;
    } else {
        $label = $method !== '' ? strtoupper($method) : 'Boshqa';
        $otherPayments[] = ['label' => $label, 'total' => $total];
        $otherPaymentsTotal += $total;
    }
}
$totalCollected = $cashTotal + $clickTotal + $otherPaymentsTotal;
$cashPercent = $totalCollected > 0 ? ($cashTotal / $totalCollected) * 100 : 0;
$clickPercent = $totalCollected > 0 ? ($clickTotal / $totalCollected) * 100 : 0;
$otherPercent = max(0, 100 - $cashPercent - $clickPercent);

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
$debtPercent = $revenue > 0 ? ($outstanding / $revenue) * 100 : 0;

$stockOnHand = fetchAll($pdo, 'SELECT p.id, p.name, p.unit, p.default_price,
    IFNULL((SELECT SUM(quantity) FROM purchases WHERE product_id = p.id),0) +
    IFNULL((SELECT SUM(quantity_change) FROM adjustments WHERE product_id = p.id),0) -
    IFNULL((SELECT SUM(quantity) FROM sale_items WHERE product_id = p.id),0) AS stock
    FROM products p
    ORDER BY p.name');

$inventoryValue = 0.0;
foreach ($stockOnHand as &$stock) {
    $value = (float)$stock['stock'] * (float)$stock['default_price'];
    $stock['inventory_value'] = $value;
    $inventoryValue += $value;
}
unset($stock);

$dailyRevenue = fetchAll($pdo, 'SELECT s.sale_date AS day, SUM(si.total) AS revenue, SUM(si.quantity) AS units
    FROM sales s
    JOIN sale_items si ON si.sale_id = s.id
    WHERE s.sale_date BETWEEN ? AND ?
    GROUP BY s.sale_date
    ORDER BY s.sale_date', [$start, $end]);

$peakDay = null;
foreach ($dailyRevenue as $row) {
    if ($peakDay === null || $row['revenue'] > $peakDay['revenue']) {
        $peakDay = $row;
    }
}

$largestSale = fetchOne($pdo, 'SELECT s.id, s.total_amount, s.sale_date FROM sales s WHERE s.sale_date BETWEEN ? AND ? ORDER BY s.total_amount DESC LIMIT 1', [$start, $end]);
$collectionGap = max(0, $revenue - $totalCollected);
$recentTrend = array_slice($dailyRevenue, -7);

render_header('Hisobotlar');
?>
<div class="space-y-8">
    <section class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Davr tanlash</p>
                <h2 class="text-3xl font-semibold text-slate-900">Moliyaviy ko'rsatkichlar paneli</h2>
                <p class="text-sm text-slate-500">Sana oralig'ini moslashtiring va savdo, foyda hamda qarzdorlikni bir ko'rinishda kuzating.</p>
            </div>
            <form method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 bg-slate-50 rounded-2xl p-4 text-sm border border-slate-100">
                <label class="space-y-1">
                    <span class="text-slate-500">Boshlanish</span>
                    <input type="date" name="start" value="<?= htmlspecialchars($start) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:border-brand-400 focus:ring-brand-200">
                </label>
                <label class="space-y-1">
                    <span class="text-slate-500">Tugash</span>
                    <input type="date" name="end" value="<?= htmlspecialchars($end) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-900 focus:border-brand-400 focus:ring-brand-200">
                </label>
                <button type="submit" class="w-full rounded-xl bg-brand-600 text-white font-semibold px-4 py-2 mt-auto">Yangilash</button>
                <a href="reports.php" class="w-full rounded-xl border border-slate-200 text-center px-4 py-2 text-slate-600 mt-auto">Tozalash</a>
            </form>
        </div>
    </section>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <div class="xl:col-span-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Tushum</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= number_format($revenue, 2) ?> so'm</p>
                <p class="text-xs text-slate-500 mt-1">Sotuvlardan tushgan summa.</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Yalpi foyda</p>
                <p class="mt-2 text-3xl font-semibold text-emerald-600"><?= number_format($grossProfit, 2) ?> so'm</p>
                <p class="text-xs text-slate-500 mt-1">COGSdan keyingi foyda.</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Foyda marjasi</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= number_format($margin, 1) ?>%</p>
                <p class="text-xs text-slate-500 mt-1">Yalpi foydaning ulushi.</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Savdolar</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= number_format($orderCount) ?></p>
                <p class="text-xs text-slate-500 mt-1">Jami rasmiylashtirilgan cheklar.</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">O'rtacha chek</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= number_format($averageOrder, 2) ?> so'm</p>
                <p class="text-xs text-slate-500 mt-1">Bir savdodan tushgan o'rtacha summa.</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Xaridlar</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= number_format($purchasesTotal, 2) ?> so'm</p>
                <p class="text-xs text-slate-500 mt-1">Omborga kiritilgan tovarlar.</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Birlik / savdo</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= number_format($unitsPerSale, 2) ?></p>
                <p class="text-xs text-slate-500 mt-1">Har bir chekda sotilgan o'rtacha birlik soni.</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Eng katta chek</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= $largestSale ? number_format($largestSale['total_amount'], 2) . " so'm" : "Ma'lumot yo'q" ?></p>
                <p class="text-xs text-slate-500 mt-1">
                    <?php if ($largestSale): ?>
                        #<?= (int)$largestSale['id'] ?> · <?= htmlspecialchars($largestSale['sale_date']) ?>
                    <?php else: ?>
                        Ushbu davrda chek yo'q.
                    <?php endif; ?>
                </p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Eng faol kun</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900"><?= $peakDay ? number_format($peakDay['revenue'], 2) . " so'm" : "Ma'lumot yo'q" ?></p>
                <p class="text-xs text-slate-500 mt-1"><?= $peakDay ? htmlspecialchars($peakDay['day']) : 'Kunlik ma\'lumot topilmadi.' ?></p>
            </article>
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-6 space-y-4">
            <div>
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Qarzdorlik</p>
                <p class="text-3xl font-semibold text-rose-600"><?= number_format($outstanding, 2) ?> so'm</p>
                <p class="text-xs text-slate-500">Tushumga nisbatan <?= number_format($debtPercent, 1) ?>%.</p>
                <p class="text-xs text-amber-600 mt-1">Inkassa farqi: <?= number_format($collectionGap, 2) ?> so'm.</p>
            </div>
            <div class="border-t border-slate-100 pt-4">
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Ombor qiymati</p>
                <p class="text-3xl font-semibold text-slate-900"><?= number_format($inventoryValue, 2) ?> so'm</p>
                <p class="text-xs text-slate-500">Joriy qoldiqning puldagi bahosi.</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <section class="rounded-3xl border border-slate-200 bg-white p-6 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">To'lovlar taqsimoti</h3>
                    <p class="text-sm text-slate-500">Naqd va raqamli tushum ulushi.</p>
                </div>
                <span class="text-xs text-slate-400">Jami <?= number_format($totalCollected, 2) ?> so'm</span>
            </div>
            <div class="space-y-4 text-sm">
                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">Naqd</span>
                        <span class="font-semibold text-slate-900"><?= number_format($cashTotal, 2) ?> so'm</span>
                    </div>
                    <div class="mt-1 h-2 rounded-full bg-slate-100">
                        <div class="h-2 rounded-full bg-emerald-400" style="width: <?= min(100, $cashPercent) ?>%"></div>
                    </div>
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">Click</span>
                        <span class="font-semibold text-slate-900"><?= number_format($clickTotal, 2) ?> so'm</span>
                    </div>
                    <div class="mt-1 h-2 rounded-full bg-slate-100">
                        <div class="h-2 rounded-full bg-sky-400" style="width: <?= min(100, $clickPercent) ?>%"></div>
                    </div>
                </div>
                <?php if (!empty($otherPayments)): ?>
                    <?php foreach ($otherPayments as $entry): ?>
                        <div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500"><?= htmlspecialchars($entry['label']) ?></span>
                                <span class="font-semibold text-slate-900"><?= number_format($entry['total'], 2) ?> so'm</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        <section class="rounded-3xl border border-slate-200 bg-white p-6 space-y-4">
            <h3 class="text-lg font-semibold text-slate-900">Moliyaviy qisqa xulosa</h3>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div class="rounded-2xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Sotilgan birliklar</dt>
                    <dd class="text-2xl font-semibold text-slate-900 mt-2"><?= number_format($unitsSold, 2) ?></dd>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Naqd + Click</dt>
                    <dd class="text-2xl font-semibold text-slate-900 mt-2"><?= number_format($cashTotal + $clickTotal, 2) ?> so'm</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Qarzdor mijozlar</dt>
                    <dd class="text-2xl font-semibold text-rose-600 mt-2"><?= number_format($outstanding, 2) ?> so'm</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 p-4">
                    <dt class="text-slate-500">Inventar qiymati</dt>
                    <dd class="text-2xl font-semibold text-slate-900 mt-2"><?= number_format($inventoryValue, 2) ?> so'm</dd>
                </div>
            </dl>
        </section>
    </div>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-lg font-semibold text-slate-900">Kunlik tushum trendi</h3>
                <p class="text-sm text-slate-500">So'nggi 7 kunlik natijalar (<?= htmlspecialchars($start) ?> — <?= htmlspecialchars($end) ?>).</p>
            </div>
            <?php if ($peakDay): ?>
                <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-4 py-1 text-xs font-semibold text-emerald-600">
                    Eng yuqori kun: <?= htmlspecialchars($peakDay['day']) ?>
                </span>
            <?php endif; ?>
        </div>
        <?php if (empty($recentTrend)): ?>
            <p class="text-sm text-slate-400">Ushbu davr uchun kunlik savdolar topilmadi.</p>
        <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($recentTrend as $row): ?>
                    <?php
                        $percentage = ($peakDay && $peakDay['revenue'] > 0)
                            ? min(100, ($row['revenue'] / $peakDay['revenue']) * 100)
                            : 0;
                    ?>
                    <li>
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-medium text-slate-700"><?= htmlspecialchars($row['day']) ?></span>
                            <span class="text-slate-500"><?= number_format($row['revenue'], 2) ?> so'm</span>
                        </div>
                        <div class="mt-2 h-2 rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-2 rounded-full bg-brand-400" style="width: <?= $percentage ?>%"></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6">
        <h3 class="text-lg font-semibold text-slate-900">Eng yaxshi mahsulotlar</h3>
        <p class="text-sm text-slate-500 mb-4">Tushum bo'yicha tartiblangan.</p>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-slate-400">
                    <tr>
                        <th class="py-2 text-left">Mahsulot</th>
                        <th class="py-2 text-left">Miqdor</th>
                        <th class="py-2 text-left">Tushum</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($salesByProduct)): ?>
                        <tr>
                            <td colspan="3" class="py-6 text-center text-slate-400">Ushbu davrda savdo topilmadi.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($salesByProduct as $row): ?>
                            <tr>
                                <td class="py-3 font-medium text-slate-900"><?= htmlspecialchars($row['name']) ?> (<?= htmlspecialchars($row['unit']) ?>)</td>
                                <td class="py-3 text-slate-500"><?= number_format((float)$row['quantity'], 2) ?></td>
                                <td class="py-3 text-slate-900"><?= number_format((float)$row['revenue'], 2) ?> so'm</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6">
        <h3 class="text-lg font-semibold text-slate-900">Ombordagi qoldiq</h3>
        <p class="text-sm text-slate-500 mb-4">Mahsulotlar qiymati bilan.</p>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-xs uppercase text-slate-400">
                    <tr>
                        <th class="py-2 text-left">Mahsulot</th>
                        <th class="py-2 text-left">Qoldiq</th>
                        <th class="py-2 text-left">Qiymat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($stockOnHand)): ?>
                        <tr>
                            <td colspan="3" class="py-6 text-center text-slate-400">Ombor ma'lumotlari mavjud emas.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stockOnHand as $stock): ?>
                            <tr>
                                <td class="py-3 font-medium text-slate-900"><?= htmlspecialchars($stock['name']) ?></td>
                                <td class="py-3 text-slate-500"><?= number_format((float)$stock['stock'], 2) ?> <?= htmlspecialchars($stock['unit']) ?></td>
                                <td class="py-3 text-slate-900"><?= number_format((float)$stock['inventory_value'], 2) ?> so'm</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php
render_footer();
?>
