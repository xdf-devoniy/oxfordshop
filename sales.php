<?php
require_once __DIR__ . '/inc/auth.php';
require_login();
require_once __DIR__ . '/inc/layout.php';

$errors = [];
$success = null;
$lastSale = null;

$products = fetchAll($pdo, 'SELECT * FROM products ORDER BY name');
$customers = fetchAll($pdo, 'SELECT * FROM customers ORDER BY name');
$stockLevels = productStockSnapshot($pdo);

$productLookup = [];
foreach ($products as $product) {
    $productLookup[(int)$product['id']] = $product;
}

$currentMode = $_POST['payment_mode'] ?? 'cash';
$saleDate = $_POST['sale_date'] ?? date('Y-m-d');
$notes = trim($_POST['notes'] ?? '');
$selectedCustomerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
$newCustomerName = trim($_POST['new_customer'] ?? '');

$cartPrefill = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saleDate = trim($_POST['sale_date'] ?? date('Y-m-d'));
    $notes = trim($_POST['notes'] ?? '');
    $currentMode = $_POST['payment_mode'] ?? 'cash';
    $selectedCustomerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $newCustomerName = trim($_POST['new_customer'] ?? '');

    if ($saleDate === '') {
        $errors[] = "Savdo sanasini tanlang.";
    } else {
        try {
            new DateTime($saleDate);
        } catch (Exception $e) {
            $errors[] = "Savdo sanasi noto'g'ri.";
        }
    }

    $rawCart = $_POST['cart_payload'] ?? '[]';
    $cartData = json_decode($rawCart, true);
    if (!is_array($cartData)) {
        $cartData = [];
    }

    if (empty($cartData)) {
        $errors[] = "Savdoga kamida bitta mahsulot qo'shing.";
    }

    $preparedItems = [];
    $totalAmount = 0.0;
    $requestedByProduct = [];

    foreach ($cartData as $entry) {
        $productId = (int)($entry['product_id'] ?? 0);
        $quantity = (float)($entry['quantity'] ?? 0);

        if ($productId <= 0 || !isset($productLookup[$productId])) {
            $errors[] = "Tanlangan mahsulot topilmadi.";
            continue;
        }

        if ($quantity <= 0) {
            $errors[] = sprintf("%s uchun miqdor 0 dan katta bo'lishi kerak.", $productLookup[$productId]['name']);
            continue;
        }

        $requestedByProduct[$productId] = ($requestedByProduct[$productId] ?? 0) + $quantity;
        $available = (float)($stockLevels[$productId] ?? 0);
        if ($requestedByProduct[$productId] > $available + 0.0001) {
            $errors[] = sprintf(
                "%s uchun ombordagi qoldiq yetarli emas. So'ralgan: %s, mavjud: %s",
                $productLookup[$productId]['name'],
                number_format($requestedByProduct[$productId], 2),
                number_format($available, 2)
            );
            continue;
        }

        $unitPrice = (float)$productLookup[$productId]['default_price'];
        if ($unitPrice <= 0) {
            $errors[] = sprintf("%s uchun narx 0 dan katta bo'lishi kerak.", $productLookup[$productId]['name']);
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

        $cartPrefill[] = [
            'product_id' => $productId,
            'quantity' => $quantity,
        ];
    }

    if ($totalAmount <= 0) {
        $errors[] = "Savdo summasi nol bo'lishi mumkin emas.";
    }

    if (!in_array($currentMode, ['cash', 'click', 'debt'], true)) {
        $errors[] = "To'lov usulini tanlang.";
    }

    $customerId = $selectedCustomerId ?: null;
    $pendingCustomerName = $newCustomerName !== '' ? $newCustomerName : null;

    if ($currentMode === 'debt' && $customerId === null && $pendingCustomerName === null) {
        $errors[] = "Qarz uchun mijozni tanlang yoki yangi mijoz kiriting.";
    }

    $paymentAmount = 0.0;
    $paymentMethod = null;
    $paymentDate = $saleDate;

    if ($currentMode === 'cash') {
        $paymentAmount = $totalAmount;
        $paymentMethod = 'cash';
    } elseif ($currentMode === 'click') {
        $paymentAmount = $totalAmount;
        $paymentMethod = 'click';
    } else {
        $paymentAmount = 0;
        $paymentMethod = null;
        $paymentDate = null;
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($pendingCustomerName !== null) {
                execute($pdo, 'INSERT INTO customers (name) VALUES (?)', [$pendingCustomerName]);
                $customerId = (int)$pdo->lastInsertId();
            }

            execute(
                $pdo,
                'INSERT INTO sales (customer_id, sale_date, total_amount, notes) VALUES (?, ?, ?, ?)',
                [$customerId, $saleDate, $totalAmount, $notes !== '' ? $notes : null]
            );
            $saleId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');
            foreach ($preparedItems as $line) {
                $stmt->execute([$saleId, $line['product_id'], $line['quantity'], $line['unit_price'], $line['total']]);
            }

            if ($paymentAmount > 0 && $paymentMethod !== null) {
                execute(
                    $pdo,
                    'INSERT INTO payments (sale_id, payment_method, amount, payment_date) VALUES (?, ?, ?, ?)',
                    [$saleId, $paymentMethod, $paymentAmount, $paymentDate]
                );
            }

            $pdo->commit();

            header('Location: sales.php?recorded=' . $saleId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "Savdoni saqlab bo'lmadi. Iltimos, qayta urinib ko'ring.";
        }
    }
}

if (empty($cartPrefill) && !empty($_POST['cart_payload'])) {
    $decoded = json_decode($_POST['cart_payload'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $entry) {
            $productId = (int)($entry['product_id'] ?? 0);
            $quantity = (float)($entry['quantity'] ?? 0);
            if ($productId > 0 && $quantity > 0) {
                $cartPrefill[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ];
            }
        }
    }
}

$recordedSaleId = isset($_GET['recorded']) ? (int)$_GET['recorded'] : null;
if ($recordedSaleId) {
    $lastSale = fetchOne(
        $pdo,
        'SELECT s.id, s.sale_date, s.total_amount, IFNULL(c.name, "Doimiy mijoz emas") AS customer_name
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE s.id = ?',
        [$recordedSaleId]
    );
    if ($lastSale) {
        $success = sprintf(
            "Savdo №%d muvaffaqiyatli saqlandi. Umumiy summa: %s so'm.",
            $lastSale['id'],
            number_format((float)$lastSale['total_amount'], 0, '.', ' ')
        );
    }
}

$productClientData = array_map(function (array $product) use ($stockLevels) {
    return [
        'id' => (int)$product['id'],
        'name' => $product['name'],
        'price' => (float)$product['default_price'],
        'unit' => $product['unit'],
        'stock' => (float)($stockLevels[$product['id']] ?? 0),
    ];
}, $products);

$catalogJson = json_encode($productClientData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($catalogJson === false) {
    $catalogJson = '[]';
}

$cartJson = json_encode($cartPrefill, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($cartJson === false) {
    $cartJson = '[]';
}

$today = date('Y-m-d');
$todaySummary = fetchOne(
    $pdo,
    'SELECT COUNT(*) AS sale_count, IFNULL(SUM(total_amount), 0) AS total_amount FROM sales WHERE sale_date = ?',
    [$today]
);
$todayRevenue = (float)($todaySummary['total_amount'] ?? 0);
$todaySalesCount = (int)($todaySummary['sale_count'] ?? 0);

$openDebtRow = fetchOne(
    $pdo,
    'SELECT IFNULL(SUM(s.total_amount - IFNULL(pay.total_paid, 0)), 0) AS balance
     FROM sales s
     LEFT JOIN (
        SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
     ) pay ON pay.sale_id = s.id'
);
$openDebtTotal = (float)($openDebtRow['balance'] ?? 0);

$monthStart = date('Y-m-01');
$monthTopProduct = fetchOne(
    $pdo,
    'SELECT p.name, SUM(si.total) AS revenue
     FROM sale_items si
     JOIN sales s ON s.id = si.sale_id
     JOIN products p ON p.id = si.product_id
     WHERE s.sale_date BETWEEN ? AND ?
     GROUP BY si.product_id, p.name
     ORDER BY revenue DESC
     LIMIT 1',
    [$monthStart, $today]
);
$topProductName = $monthTopProduct['name'] ?? null;
$topProductRevenue = (float)($monthTopProduct['revenue'] ?? 0);

$lowestStockProduct = null;
foreach ($products as $product) {
    $stockQty = (float)($stockLevels[$product['id']] ?? 0);
    if ($lowestStockProduct === null || $stockQty < $lowestStockProduct['stock']) {
        $lowestStockProduct = [
            'name' => $product['name'],
            'stock' => $stockQty,
            'unit' => $product['unit'],
        ];
    }
}

$shouldOpenSaleModal = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($products);

render_header('Savdolar');
?>
<style>
    .payment-card {
        border: 1px solid rgb(226 232 240);
        border-radius: 1rem;
        padding: 1rem;
        display: flex;
        gap: 0.75rem;
        align-items: flex-start;
        cursor: pointer;
        background-color: white;
        transition: all 0.2s ease;
    }
    .payment-card input {
        display: none;
    }
    .payment-card.is-active {
        border-color: rgb(20 184 166);
        background-color: rgb(236 253 245);
        box-shadow: 0 20px 45px -25px rgba(15, 118, 110, 0.45);
    }
</style>
<div class="space-y-6">
    <?php if ($success): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 flex items-center justify-between gap-4">
            <span><?= htmlspecialchars($success) ?></span>
            <?php if (!empty($lastSale)): ?>
                <a href="receipts.php?sale_id=<?= (int)$lastSale['id'] ?>" class="text-xs font-semibold text-emerald-800 underline">Chekni ko'rish</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <section class="lg:col-span-2 rounded-3xl bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 text-white p-8 relative overflow-hidden">
            <div class="absolute inset-y-0 right-0 w-2/3 bg-[radial-gradient(circle_at_top,_rgba(45,212,191,0.35),_transparent_65%)] opacity-60 pointer-events-none"></div>
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="space-y-4 max-w-xl">
                    <p class="text-xs uppercase tracking-[0.4em] text-white/60">Savdo rejimi</p>
                    <h2 class="text-3xl font-semibold leading-tight">Mahsulotlarni tez tanlang va savdoni yakunlang</h2>
                    <p class="text-sm text-white/70">Modal oynada katalog, savdo cheki va mijoz ma'lumotlari bir joyda jamlangan.</p>
                    <div class="flex flex-wrap gap-3">
                        <?php if (empty($products)): ?>
                            <a href="products.php" class="inline-flex items-center rounded-full bg-white/10 px-5 py-2 text-sm font-semibold text-white hover:bg-white/20">Avval mahsulot qo'shing</a>
                        <?php else: ?>
                            <button type="button" id="open-sale-modal" class="inline-flex items-center rounded-full bg-white text-slate-900 px-6 py-3 text-sm font-semibold shadow-lg shadow-slate-900/40 hover:-translate-y-0.5 transition">Yangi savdo</button>
                        <?php endif; ?>
                        <a href="receipts.php" class="inline-flex items-center rounded-full border border-white/40 px-5 py-2 text-sm font-semibold text-white/80 hover:text-white">Cheklar tarixi</a>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 w-full lg:w-72">
                    <div class="rounded-2xl bg-white/10 p-4">
                        <p class="text-xs text-white/60">Bugungi tushum</p>
                        <p class="mt-2 text-2xl font-semibold"><?= number_format($todayRevenue, 0, '.', ' ') ?> so'm</p>
                    </div>
                    <div class="rounded-2xl bg-white/10 p-4">
                        <p class="text-xs text-white/60">Bugun savdolar</p>
                        <p class="mt-2 text-2xl font-semibold"><?= number_format($todaySalesCount) ?></p>
                    </div>
                    <div class="col-span-2 rounded-2xl bg-white/10 p-4">
                        <p class="text-xs text-white/60">Qarzdorlik</p>
                        <p class="mt-2 text-2xl font-semibold text-amber-200"><?= number_format($openDebtTotal, 0, '.', ' ') ?> so'm</p>
                    </div>
                </div>
            </div>
        </section>
        <section class="rounded-3xl border border-white/10 bg-white/90 backdrop-blur p-6 space-y-4 text-slate-900">
            <div>
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Analitika</p>
                <h3 class="text-xl font-semibold">Tezkor ko'rsatkichlar</h3>
            </div>
            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">Oy favorit mahsulot</span>
                    <span class="font-semibold text-slate-900"><?= $topProductName ? htmlspecialchars($topProductName) : "Ma'lumot yo'q" ?></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">Oy bo'yicha tushum</span>
                    <span class="font-semibold text-emerald-600"><?= number_format($topProductRevenue, 0, '.', ' ') ?> so'm</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">Eng kam qoldiq</span>
                    <span class="font-semibold text-rose-600"><?= $lowestStockProduct ? htmlspecialchars($lowestStockProduct['name']) . ' · ' . number_format($lowestStockProduct['stock'], 2) . ' ' . htmlspecialchars($lowestStockProduct['unit']) : "Ma'lumot yo'q" ?></span>
                </div>
            </div>
        </section>
    </div>
</div>

<div id="sale-modal" class="fixed inset-0 z-40 <?= $shouldOpenSaleModal ? '' : 'hidden' ?> bg-slate-950/70 backdrop-blur-sm px-4 py-8 flex items-start justify-center" data-open-initial="<?= $shouldOpenSaleModal ? '1' : '0' ?>">
    <div class="relative w-full max-w-6xl bg-white rounded-3xl shadow-2xl flex flex-col max-h-[90vh]">
        <div class="flex items-center justify-between border-b border-slate-200 px-8 py-5">
            <div>
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Yangi savdo</p>
                <h3 class="text-2xl font-semibold text-slate-900">Modal savdo oynasi</h3>
            </div>
            <button type="button" class="h-10 w-10 rounded-full border border-slate-200 text-slate-500 hover:text-slate-800 flex items-center justify-center" id="close-sale-modal">&times;</button>
        </div>
        <div class="flex-1 overflow-y-auto px-8 py-6">
            <?php if (!empty($errors)): ?>
                <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-700 px-5 py-4 mb-5 text-sm">
                    <ul class="list-disc space-y-1 pl-4">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($products)): ?>
                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 text-center py-10 text-sm text-slate-500">
                    Avval mahsulot qo'shing. <a href="products.php" class="text-brand-600 font-semibold">Mahsulotlar</a> sahifasiga o'ting.
                </div>
            <?php else: ?>
                <form method="post" class="space-y-6" id="sale-form">
                    <input type="hidden" name="cart_payload" id="cart-payload" value='<?= htmlspecialchars($cartJson, ENT_QUOTES, 'UTF-8') ?>'>
                    <div class="flex flex-col xl:flex-row gap-6">
                        <section class="xl:w-2/3 space-y-6">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-5">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-900">Mahsulot katalogi</h3>
                                        <p class="text-sm text-slate-500">Har bir karta + va − tugmalari bilan boshqariladi.</p>
                                    </div>
                                    <div class="relative w-full lg:w-64">
                                        <input type="search" id="product-search" placeholder="Mahsulotni qidiring..." class="w-full rounded-2xl border border-slate-200 bg-white/80 pl-11 pr-4 py-2.5 text-sm text-slate-700 focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm">🔍</span>
                                    </div>
                                </div>
                                <div class="mt-4 max-h-[360px] overflow-y-auto pr-1">
                                    <div id="product-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                        <?php foreach ($products as $product): ?>
                                            <?php
                                                $price = (float)$product['default_price'];
                                                $stock = (float)($stockLevels[$product['id']] ?? 0);
                                                $disabled = $stock <= 0 || $price <= 0;
                                                $searchName = function_exists('mb_strtolower')
                                                    ? mb_strtolower($product['name'])
                                                    : strtolower($product['name']);
                                            ?>
                                            <div class="group rounded-2xl border border-slate-200 bg-white p-4 transition hover:-translate-y-0.5 hover:border-brand-300 <?= $disabled ? 'opacity-40 pointer-events-none' : '' ?>"
                                                 data-product-card
                                                 data-product-id="<?= (int)$product['id'] ?>"
                                                 data-product-name="<?= htmlspecialchars($searchName) ?>"
                                                 data-product-stock="<?= htmlspecialchars(number_format($stock, 2, '.', '')) ?>">
                                                <div class="flex items-start justify-between gap-2">
                                                    <div>
                                                        <p class="font-semibold text-slate-900 truncate" title="<?= htmlspecialchars($product['name']) ?>"><?= htmlspecialchars($product['name']) ?></p>
                                                        <p class="text-xs text-slate-400 mt-1"><?= htmlspecialchars($product['unit']) ?></p>
                                                    </div>
                                                    <div class="text-right text-sm font-semibold text-brand-600"><?= number_format($price, 0, '.', ' ') ?> so'm</div>
                                                </div>
                                                <div class="mt-4 flex items-center justify-between gap-3">
                                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">Ombor: <?= number_format($stock, 2) ?></span>
                                                    <div class="flex items-center gap-2">
                                                        <button type="button" class="h-8 w-8 rounded-full border border-slate-200 text-slate-600 hover:bg-slate-100" data-action="minus">−</button>
                                                        <span class="w-8 text-center font-semibold text-slate-900" data-product-qty>0</span>
                                                        <button type="button" class="h-8 w-8 rounded-full border border-slate-200 text-slate-600 hover:bg-slate-100" data-action="plus">+</button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <p id="product-empty" class="hidden py-6 text-center text-sm text-slate-400">Natija topilmadi.</p>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                                <div class="flex flex-wrap items-center justify-between gap-4">
                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-900">Tanlangan mahsulotlar</h3>
                                        <p class="text-sm text-slate-500">Chek avtomatik tarzda yangilanadi.</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs text-slate-400">Umumiy summa</p>
                                        <p class="text-2xl font-semibold text-slate-900"><span id="cart-total">0</span> so'm</p>
                                    </div>
                                </div>
                                <div class="mt-4 space-y-3" id="cart-items"></div>
                                <div id="empty-cart" class="rounded-2xl border border-dashed border-slate-200 text-center py-8 text-sm text-slate-400">Mahsulot tanlang va shu yerda paydo bo'ladi.</div>
                            </div>
                        </section>
                        <section class="xl:w-1/3 space-y-6">
                            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Savdo sanasi</label>
                                    <input type="date" name="sale_date" value="<?= htmlspecialchars($saleDate) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2.5 text-sm text-slate-700 focus:border-brand-500 focus:ring-brand-200" required>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Izoh</label>
                                    <textarea name="notes" rows="4" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2.5 text-sm text-slate-700 focus:border-brand-500 focus:ring-brand-200" placeholder="Masalan: tushlik savdosi."><?= htmlspecialchars($notes) ?></textarea>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900">To'lov va mijoz</h3>
                                    <p class="text-sm text-slate-500">Qaysi usulda to'lov olindi va agar kerak bo'lsa qarzdor mijozni belgilang.</p>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="payment-options">
                                    <label class="payment-card <?= $currentMode === 'cash' ? 'is-active' : '' ?>">
                                        <input type="radio" name="payment_mode" value="cash" <?= $currentMode === 'cash' ? 'checked' : '' ?>>
                                        <div>
                                            <p class="font-semibold text-slate-900">Naqd</p>
                                            <p class="text-xs text-slate-500">To'liq naqd to'lov</p>
                                        </div>
                                    </label>
                                    <label class="payment-card <?= $currentMode === 'click' ? 'is-active' : '' ?>">
                                        <input type="radio" name="payment_mode" value="click" <?= $currentMode === 'click' ? 'checked' : '' ?>>
                                        <div>
                                            <p class="font-semibold text-slate-900">Click</p>
                                            <p class="text-xs text-slate-500">Raqamli to'lov</p>
                                        </div>
                                    </label>
                                    <label class="payment-card <?= $currentMode === 'debt' ? 'is-active' : '' ?>">
                                        <input type="radio" name="payment_mode" value="debt" <?= $currentMode === 'debt' ? 'checked' : '' ?>>
                                        <div>
                                            <p class="font-semibold text-slate-900">Qarz</p>
                                            <p class="text-xs text-slate-500">To'lov keyin olinadi</p>
                                        </div>
                                    </label>
                                </div>
                                <div id="debt-fields" class="<?= $currentMode === 'debt' ? '' : 'hidden' ?> space-y-3">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Mavjud mijoz</label>
                                        <select name="customer_id" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2.5 text-sm text-slate-700 focus:border-brand-500 focus:ring-brand-200">
                                            <option value="0">Tanlanmagan</option>
                                            <?php foreach ($customers as $customer): ?>
                                                <option value="<?= (int)$customer['id'] ?>" <?= $selectedCustomerId === (int)$customer['id'] ? 'selected' : '' ?>><?= htmlspecialchars($customer['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Yangi mijoz ismi</label>
                                        <input type="text" name="new_customer" value="<?= htmlspecialchars($newCustomerName) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2.5 text-sm text-slate-700 focus:border-brand-500 focus:ring-brand-200" placeholder="Masalan, Azizbek">
                                        <p class="text-xs text-slate-400 mt-1">Agar mijoz ro'yxatda bo'lmasa, shu yerga kiriting.</p>
                                    </div>
                                </div>
                                <div class="pt-4 mt-2 border-t border-slate-100 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="text-xs text-slate-400">Barcha summalar o'zbek so'mida.</p>
                                    <button type="submit" class="inline-flex items-center justify-center rounded-full bg-gradient-to-r from-brand-500 to-emerald-400 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-brand-500/30 hover:shadow-brand-500/50">Savdoni saqlash</button>
                                </div>
                            </div>
                        </section>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const saleModal = document.getElementById('sale-modal');
        const openButton = document.getElementById('open-sale-modal');
        const closeButton = document.getElementById('close-sale-modal');

        const toggleBodyScroll = (isOpen) => {
            document.body.classList.toggle('overflow-hidden', isOpen);
        };

        const openSaleModal = () => {
            if (!saleModal) {
                return;
            }
            saleModal.classList.remove('hidden');
            toggleBodyScroll(true);
        };

        const closeSaleModal = () => {
            if (!saleModal) {
                return;
            }
            saleModal.classList.add('hidden');
            toggleBodyScroll(false);
        };

        openButton?.addEventListener('click', openSaleModal);
        closeButton?.addEventListener('click', closeSaleModal);
        saleModal?.addEventListener('click', (event) => {
            if (event.target === saleModal) {
                closeSaleModal();
            }
        });

        if (saleModal?.dataset.openInitial === '1') {
            openSaleModal();
        }
    });
</script>
<?php if (!empty($products)): ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const productCatalog = new Map((<?= $catalogJson ?>).map(item => [Number(item.id), item]));
        const initialCart = (<?= $cartJson ?>);
        const cart = new Map();
        const cartContainer = document.getElementById('cart-items');
        const cartTotalEl = document.getElementById('cart-total');
        const emptyCartNotice = document.getElementById('empty-cart');
        const payloadInput = document.getElementById('cart-payload');
        const productCards = new Map();
        const searchInput = document.getElementById('product-search');
        const productEmptyState = document.getElementById('product-empty');
        const formatter = new Intl.NumberFormat('uz-UZ', { minimumFractionDigits: 0, maximumFractionDigits: 0 });

        document.querySelectorAll('[data-product-card]').forEach(card => {
            const productId = Number(card.dataset.productId);
            if (!productId) {
                return;
            }
            const meta = {
                card,
                minus: card.querySelector('[data-action="minus"]'),
                plus: card.querySelector('[data-action="plus"]'),
                qty: card.querySelector('[data-product-qty]'),
                stock: Number(card.dataset.productStock ?? '0'),
                disabled: card.classList.contains('pointer-events-none')
            };
            productCards.set(productId, meta);

            meta.plus?.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (meta.disabled) {
                    return;
                }
                const current = cart.get(productId)?.quantity ?? 0;
                setQuantity(productId, current + 1);
            });

            meta.minus?.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (meta.disabled) {
                    return;
                }
                const current = cart.get(productId)?.quantity ?? 0;
                setQuantity(productId, current - 1);
            });

            card.addEventListener('click', (event) => {
                if (meta.disabled || event.target.closest('[data-action]')) {
                    return;
                }
                const current = cart.get(productId)?.quantity ?? 0;
                setQuantity(productId, current + 1);
            });
        });

        const filterProducts = () => {
            const query = (searchInput?.value ?? '').toLowerCase().trim();
            let visibleCount = 0;
            productCards.forEach((meta) => {
                const haystack = meta.card.dataset.productName ?? '';
                const isVisible = haystack.includes(query);
                meta.card.classList.toggle('hidden', !isVisible);
                if (isVisible) {
                    visibleCount += 1;
                }
            });
            if (productEmptyState) {
                productEmptyState.classList.toggle('hidden', visibleCount > 0);
            }
        };
        searchInput?.addEventListener('input', filterProducts);
        filterProducts();

        function setQuantity(productId, quantity) {
            if (!productCatalog.has(productId)) {
                return;
            }
            const product = productCatalog.get(productId);
            const meta = productCards.get(productId);
            if (meta?.disabled) {
                return;
            }
            if (quantity <= 0) {
                cart.delete(productId);
            } else {
                let allowed = quantity;
                if (product.stock > 0 && allowed > product.stock) {
                    allowed = product.stock;
                }
                cart.set(productId, { product_id: productId, quantity: Math.round(allowed * 100) / 100 });
            }
            renderCart();
        }

        function renderCart() {
            if (!cartContainer) {
                return;
            }
            cartContainer.innerHTML = '';
            let total = 0;
            cart.forEach((item, productId) => {
                const product = productCatalog.get(productId);
                if (!product) {
                    return;
                }
                const lineTotal = item.quantity * product.price;
                total += lineTotal;
                const row = document.createElement('div');
                row.className = 'rounded-2xl border border-slate-200 p-4 bg-slate-50/70';
                row.innerHTML = `
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-semibold text-slate-900">${product.name}</p>
                            <p class="text-xs text-slate-500">${formatter.format(product.price)} so'm · ${product.unit}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-slate-400">Jami</p>
                            <p class="text-lg font-semibold text-slate-900">${formatter.format(lineTotal)} so'm</p>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <span class="inline-flex items-center rounded-full bg-white px-3 py-1 text-xs text-slate-500">Ombor: ${formatter.format(product.stock)}</span>
                        <div class="flex items-center gap-2">
                            <button type="button" class="h-8 w-8 rounded-full border border-slate-300 text-slate-600 hover:bg-white" data-cart-action="decrease" data-id="${productId}">−</button>
                            <span class="w-10 text-center font-semibold text-slate-900">${item.quantity}</span>
                            <button type="button" class="h-8 w-8 rounded-full border border-slate-300 text-slate-600 hover:bg-white" data-cart-action="increase" data-id="${productId}">+</button>
                        </div>
                        <button type="button" class="text-xs font-semibold text-rose-600" data-cart-action="remove" data-id="${productId}">O'chirish</button>
                    </div>
                `;
                cartContainer.appendChild(row);
            });
            cartTotalEl.textContent = formatter.format(total);
            emptyCartNotice?.classList.toggle('hidden', cart.size > 0);
            payloadInput.value = JSON.stringify(Array.from(cart.values()));
            productCards.forEach((meta, productId) => syncProductCard(productId));
        }

        function syncProductCard(productId) {
            const meta = productCards.get(productId);
            if (!meta) {
                return;
            }
            const quantity = cart.get(productId)?.quantity ?? 0;
            if (meta.qty) {
                meta.qty.textContent = quantity;
            }
            meta.card.classList.toggle('ring-2', quantity > 0);
            meta.card.classList.toggle('ring-brand-300', quantity > 0);
            meta.card.classList.toggle('bg-brand-50/40', quantity > 0);
            if (meta.minus) {
                meta.minus.disabled = quantity <= 0;
            }
            if (meta.plus) {
                meta.plus.disabled = meta.stock > 0 && quantity >= meta.stock;
            }
        }

        cartContainer?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-cart-action]');
            if (!button) {
                return;
            }
            const productId = Number(button.dataset.id);
            const action = button.dataset.cartAction;
            if (!productId) {
                return;
            }
            if (action === 'increase') {
                const current = cart.get(productId)?.quantity ?? 0;
                setQuantity(productId, current + 1);
            } else if (action === 'decrease') {
                const current = cart.get(productId)?.quantity ?? 0;
                setQuantity(productId, current - 1);
            } else if (action === 'remove') {
                cart.delete(productId);
                renderCart();
            }
        });

        if (Array.isArray(initialCart)) {
            initialCart.forEach(entry => {
                const productId = Number(entry.product_id ?? 0);
                const quantity = Number(entry.quantity ?? 0);
                if (productId && quantity > 0) {
                    cart.set(productId, { product_id: productId, quantity });
                }
            });
            renderCart();
        } else {
            renderCart();
        }

        const saleForm = document.getElementById('sale-form');
        saleForm?.addEventListener('submit', () => {
            payloadInput.value = JSON.stringify(Array.from(cart.values()));
        });

        const paymentOptions = document.getElementById('payment-options');
        const debtFields = document.getElementById('debt-fields');
        if (paymentOptions && debtFields) {
            paymentOptions.addEventListener('change', (event) => {
                const selected = event.target.closest('label');
                if (!selected) {
                    return;
                }
                paymentOptions.querySelectorAll('.payment-card').forEach(card => card.classList.remove('is-active'));
                selected.classList.add('is-active');
                const mode = selected.querySelector('input')?.value;
                if (mode === 'debt') {
                    debtFields.classList.remove('hidden');
                } else {
                    debtFields.classList.add('hidden');
                }
            });
        }
    });
</script>
<?php endif; ?>

<?php
render_footer();
?>
