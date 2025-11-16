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

render_header('Hisobotlar');
?>
<div class="space-y-8">
    <section class="rounded-3xl bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 text-white p-8 shadow-xl">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.4em] text-white/60">Filtr</p>
                <h2 class="text-3xl font-semibold">Davr bo'yicha hisobot</h2>
                <p class="text-sm text-white/70">Sana oralig'ini o'zgartiring va pastda moliyaviy ko'rsatkichlarni ko'ring.</p>
            </div>
            <form method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 bg-white/10 rounded-2xl p-4 text-sm">
                <label class="space-y-1">
                    <span class="text-white/70">Boshlanish</span>
                    <input type="date" name="start" value="<?= htmlspecialchars($start) ?>" class="w-full rounded-xl border border-white/20 bg-white/90 px-3 py-2 text-slate-900 focus:border-brand-400 focus:ring-brand-200">
                </label>
                <label class="space-y-1">
                    <span class="text-white/70">Tugash</span>
                    <input type="date" name="end" value="<?= htmlspecialchars($end) ?>" class="w-full rounded-xl border border-white/20 bg-white/90 px-3 py-2 text-slate-900 focus:border-brand-400 focus:ring-brand-200">
                </label>
                <button type="submit" class="w-full rounded-xl bg-white text-slate-900 font-semibold px-4 py-2 mt-auto">Yangilash</button>
                <a href="reports.php" class="w-full rounded-xl border border-white/40 text-center px-4 py-2 text-white/90 mt-auto">Tozalash</a>
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
        </div>
        <div class="rounded-3xl border border-slate-200 bg-white p-6 space-y-4">
            <div>
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Qarzdorlik</p>
                <p class="text-3xl font-semibold text-rose-600"><?= number_format($outstanding, 2) ?> so'm</p>
                <p class="text-xs text-slate-500">Tushumga nisbatan <?= number_format($debtPercent, 1) ?>%.</p>
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
<?php
render_footer();
?>
