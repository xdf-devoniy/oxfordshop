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

render_header('Boshqaruv paneli');
?>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <div class="bg-white border border-slate-200 rounded-lg p-5">
        <p class="text-sm text-slate-500">Mahsulotlar soni</p>
        <p class="text-3xl font-semibold text-slate-900"><?= number_format((float)$totalProducts) ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-lg p-5">
        <p class="text-sm text-slate-500">Mijozlar soni</p>
        <p class="text-3xl font-semibold text-slate-900"><?= number_format((float)$totalCustomers) ?></p>
    </div>
    <div class="bg-white border border-slate-200 rounded-lg p-5">
        <p class="text-sm text-slate-500">Umumiy tushum</p>
        <p class="text-3xl font-semibold text-emerald-600"><?= number_format((float)$totalRevenue, 2) ?> so'm</p>
    </div>
    <div class="bg-white border border-slate-200 rounded-lg p-5">
        <p class="text-sm text-slate-500">Qarzdorlik</p>
        <p class="text-3xl font-semibold text-rose-600"><?= number_format((float)$outstanding, 2) ?> so'm</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">So'nggi savdolar</h3>
        <div class="overflow-x-auto">
            <div class="max-h-[22rem] overflow-y-auto">
                <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-500 uppercase text-xs">
                        <th class="pb-2">Chek №</th>
                        <th class="pb-2">Sana</th>
                        <th class="pb-2">Mijoz</th>
                        <th class="pb-2">Jami</th>
                        <th class="pb-2">To'langan</th>
                        <th class="pb-2">Qoldiq</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($latestSales)): ?>
                        <tr>
                            <td colspan="6" class="py-6 text-center text-slate-400">Hali savdolar kiritilmagan.</td>
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
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Ombor holati</h3>
        <p class="text-sm text-slate-500 mb-2">Ombordagi jami birliklar: <span class="font-semibold text-slate-700"><?= number_format($totalStockItems, 2) ?></span></p>
        <div class="space-y-2">
            <?php if (empty($lowStock)): ?>
                <p class="text-sm text-slate-500">Barcha mahsulotlarda zaxira yetarli.</p>
            <?php else: ?>
                <?php foreach ($lowStock as $item): ?>
                    <div class="border border-amber-200 bg-amber-50 rounded-md px-3 py-2">
                        <p class="text-sm font-medium text-amber-900"><?= htmlspecialchars($item['name']) ?></p>
                        <p class="text-xs text-amber-700">Qoldiq: <?= number_format((float)$item['stock'], 2) ?> <?= htmlspecialchars($item['unit']) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Pul oqimi</h3>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between">
                <dt class="text-slate-500">Jami xaridlar</dt>
                <dd class="text-slate-700"><?= number_format((float)$totalPurchases, 2) ?> so'm</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">Qabul qilingan to'lovlar</dt>
                <dd class="text-emerald-600 font-medium"><?= number_format((float)$totalPayments, 2) ?> so'm</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">Qarzdorlik qoldig'i</dt>
                <dd class="text-rose-600 font-medium"><?= number_format((float)$outstanding, 2) ?> so'm</dd>
            </div>
        </dl>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Ish jarayoni bo'yicha yo'riqnoma</h3>
        <ol class="space-y-3 text-sm text-slate-600">
            <li><span class="font-semibold text-slate-800">1.</span> Mahsulotlarni narxlari bilan kiriting yoki yangilang.</li>
            <li><span class="font-semibold text-slate-800">2.</span> Yetkazib beruvchilardan olgan har bir partiyani xarid sifatida kiriting.</li>
            <li><span class="font-semibold text-slate-800">3.</span> Savdolarni yozib boring, to'lovni darhol qabul qiling yoki qarzga qoldiring.</li>
            <li><span class="font-semibold text-slate-800">4.</span> Cheklar bo'limida qo'shimcha to'lovlarni kiriting va qarzdorlarni kuzating.</li>
            <li><span class="font-semibold text-slate-800">5.</span> Hisobot va foyda paneli orqali natijalarni tahlil qiling.</li>
        </ol>
    </section>
</div>
<?php
render_footer();
?>
