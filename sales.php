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

$showWorkspace = isset($_GET['new']) || $_SERVER['REQUEST_METHOD'] === 'POST';

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

if ($showWorkspace) {
    ?>
    <!DOCTYPE html>
    <html lang="uz">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Yangi savdo · Ichimlik va Gazak POS</title>
        <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        fontFamily: {
                            display: ['"Inter"', 'ui-sans-serif', 'system-ui'],
                        },
                        colors: {
                            brand: {
                                50: '#eff6ff',
                                100: '#dbeafe',
                                200: '#bfdbfe',
                                300: '#93c5fd',
                                400: '#60a5fa',
                                500: '#3b82f6',
                                600: '#2563eb',
                                700: '#1d4ed8',
                                800: '#1e40af',
                                900: '#1e3a8a',
                            }
                        }
                    }
                }
            };
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    </head>
    <body class="min-h-screen bg-gradient-to-b from-white via-slate-50 to-slate-100 font-display text-slate-900">
        <div class="min-h-screen flex flex-col">
            <main class="flex-1">
                <div class="mx-auto w-full max-w-7xl px-4 lg:px-8 py-6 space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <a href="sales.php" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                            &larr; Savdolar ro'yxati
                        </a>
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-[0.3em] text-slate-400">To'liq ekran rejimi</p>
                            <h1 class="text-2xl font-semibold">Yangi savdo oynasi</h1>
                            <p class="text-sm text-slate-500">Mahsulotlar, savat va to'lovlar bitta sahifada.</p>
                        </div>
                    </div>

                    <?php if (!empty($errors)): ?>
                        <div class="rounded-3xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-700">
                            <ul class="list-disc pl-4 space-y-1">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($products)): ?>
                        <div class="rounded-3xl border border-dashed border-slate-300 bg-white/70 text-center py-12 text-sm text-slate-500">
                            Avval mahsulot qo'shing. <a href="products.php" class="text-brand-600 font-semibold">Mahsulotlar</a> sahifasiga o'ting.
                        </div>
                    <?php else: ?>
                        <form method="post" class="space-y-6" id="sale-form">
                            <input type="hidden" name="cart_payload" id="cart-payload" value='<?= htmlspecialchars($cartJson, ENT_QUOTES, 'UTF-8') ?>'>
                            <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr),minmax(360px,1fr)]">
                                <section class="space-y-6">
                                    <div class="rounded-3xl border border-slate-200 bg-white/80 p-5">
                                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                            <div>
                                                <h2 class="text-lg font-semibold">Mahsulot katalogi</h2>
                                                <p class="text-sm text-slate-500">Kartadagi tugmalar orqali miqdorni boshqaring.</p>
                                            </div>
                                            <div class="relative w-full lg:w-72">
                                                <input type="search" id="product-search" placeholder="Mahsulotni qidiring..." class="w-full rounded-2xl border border-slate-200 bg-white/90 pl-12 pr-4 py-2.5 text-sm text-slate-700 focus:border-brand-500 focus:ring-2 focus:ring-brand-200">
                                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">
                                                    <?= svg_icon('magnifying-glass', 'w-4 h-4') ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="mt-4 max-h-[65vh] overflow-y-auto pr-1">
                                            <div id="product-grid" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                                                <?php foreach ($products as $product): ?>
                                                    <?php
                                                        $price = (float)$product['default_price'];
                                                        $stock = (float)($stockLevels[$product['id']] ?? 0);
                                                        $disabled = $stock <= 0 || $price <= 0;
                                                        $searchName = function_exists('mb_strtolower')
                                                            ? mb_strtolower($product['name'])
                                                            : strtolower($product['name']);
                                                    ?>
                                                    <div class="group flex h-full flex-col justify-between rounded-2xl border border-slate-200 bg-white p-4 transition hover:-translate-y-0.5 hover:border-brand-300 <?= $disabled ? 'opacity-40 pointer-events-none' : '' ?>"
                                                         data-product-card
                                                         data-product-id="<?= (int)$product['id'] ?>"
                                                         data-product-name="<?= htmlspecialchars($searchName) ?>"
                                                         data-product-stock="<?= htmlspecialchars(number_format($stock, 2, '.', '')) ?>">
                                                        <div class="space-y-3">
                                                            <div class="flex items-start justify-between gap-3">
                                                                <div class="space-y-1">
                                                                    <p class="font-semibold leading-snug break-words" title="<?= htmlspecialchars($product['name']) ?>"><?= htmlspecialchars($product['name']) ?></p>
                                                                    <p class="text-xs text-slate-400"><?= htmlspecialchars($product['unit']) ?></p>
                                                                </div>
                                                                <div class="text-right text-sm font-semibold text-brand-600 whitespace-nowrap">
                                                                    <?= number_format($price, 0, '.', ' ') ?> so'm
                                                                </div>
                                                            </div>
                                                            <div class="flex items-center justify-between text-xs text-slate-500">
                                                                <span class="inline-flex items-center rounded-full bg-slate-50 px-3 py-1 font-medium">Ombor: <?= number_format($stock, 2) ?></span>
                                                                <span class="font-mono">ID: #<?= (int)$product['id'] ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-3">
                                                            <div class="flex items-center gap-2">
                                                                <button type="button" class="h-8 w-8 rounded-full border border-slate-200 text-slate-600 hover:bg-slate-100" data-action="minus">−</button>
                                                                <span class="w-10 text-center font-semibold" data-product-qty>0</span>
                                                                <button type="button" class="h-8 w-8 rounded-full border border-slate-200 text-slate-600 hover:bg-slate-100" data-action="plus">+</button>
                                                            </div>
                                                            <span class="text-xs text-slate-400">Chekga qo'shish</span>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <p id="product-empty" class="hidden py-6 text-center text-sm text-slate-400">Natija topilmadi.</p>
                                        </div>
                                    </div>
                                </section>
                                <aside class="space-y-6 lg:sticky lg:top-8 self-start">
                                    <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <h3 class="text-lg font-semibold">Tanlangan mahsulotlar</h3>
                                                <p class="text-sm text-slate-500">Chekdagi barcha pozitsiyalar.</p>
                                            </div>
                                            <span class="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700">Live</span>
                                        </div>
                                        <div id="cart-empty" class="mt-4 rounded-2xl border border-dashed border-slate-200 bg-slate-50/70 px-4 py-6 text-center text-sm text-slate-500">
                                            Savatga hech narsa qo'shilmadi.
                                        </div>
                                        <div id="cart-items" class="mt-4 space-y-3"></div>
                                        <div class="mt-6 rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-500">
                                            <div class="flex items-center justify-between font-semibold text-slate-900">
                                                <span>Jami:</span>
                                                <span id="cart-total">0</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="rounded-3xl border border-slate-200 bg-white p-5 space-y-4">
                                        <div>
                                            <h3 class="text-lg font-semibold">Savdo tafsilotlari</h3>
                                            <p class="text-sm text-slate-500">Sanani va qaydlarni kiriting.</p>
                                        </div>
                                        <label class="space-y-1 text-sm font-medium text-slate-700">
                                            Savdo sanasi
                                            <input type="date" name="sale_date" value="<?= htmlspecialchars($saleDate) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 focus:border-brand-500 focus:ring-brand-200">
                                        </label>
                                        <label class="space-y-1 text-sm font-medium text-slate-700">
                                            Izoh
                                            <textarea name="notes" rows="2" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 focus:border-brand-500 focus:ring-brand-200" placeholder="Istalgan qo'shimcha ma'lumot"><?= htmlspecialchars($notes) ?></textarea>
                                        </label>
                                    </div>
                                    <div class="rounded-3xl border border-slate-200 bg-white p-5 space-y-4">
                                        <div>
                                            <h3 class="text-lg font-semibold">To'lov holati</h3>
                                            <p class="text-sm text-slate-500">To'lov turini tanlang.</p>
                                        </div>
                                        <div class="grid gap-3" id="payment-options">
                                            <label class="payment-card <?= $currentMode === 'cash' ? 'is-active' : '' ?>">
                                                <input type="radio" name="payment_mode" value="cash" <?= $currentMode === 'cash' ? 'checked' : '' ?>>
                                                <div>
                                                    <p class="font-semibold">Naqd to'landi</p>
                                                    <p class="text-xs text-slate-500">To'liq summa kassaga tushadi.</p>
                                                </div>
                                            </label>
                                            <label class="payment-card <?= $currentMode === 'click' ? 'is-active' : '' ?>">
                                                <input type="radio" name="payment_mode" value="click" <?= $currentMode === 'click' ? 'checked' : '' ?>>
                                                <div>
                                                    <p class="font-semibold">Click orqali</p>
                                                    <p class="text-xs text-slate-500">To'lov kartadan qabul qilinadi.</p>
                                                </div>
                                            </label>
                                            <label class="payment-card <?= $currentMode === 'debt' ? 'is-active' : '' ?>">
                                                <input type="radio" name="payment_mode" value="debt" <?= $currentMode === 'debt' ? 'checked' : '' ?>>
                                                <div>
                                                    <p class="font-semibold">Qarzga berildi</p>
                                                    <p class="text-xs text-slate-500">Mijoz keyinroq to'laydi.</p>
                                                </div>
                                            </label>
                                        </div>
                                        <div id="debt-fields" class="space-y-3 <?= $currentMode === 'debt' ? '' : 'hidden' ?>">
                                            <label class="space-y-1 text-sm font-medium text-slate-700">
                                                Mavjud mijoz
                                                <select name="customer_id" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 focus:border-brand-500 focus:ring-brand-200">
                                                    <option value="">Tanlanmagan</option>
                                                    <?php foreach ($customers as $customer): ?>
                                                        <option value="<?= (int)$customer['id'] ?>" <?= $selectedCustomerId === (int)$customer['id'] ? 'selected' : '' ?>><?= htmlspecialchars($customer['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label class="space-y-1 text-sm font-medium text-slate-700">
                                                Yangi mijoz nomi
                                                <input type="text" name="new_customer" value="<?= htmlspecialchars($newCustomerName) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 focus:border-brand-500 focus:ring-brand-200" placeholder="Masalan, Azizbek">
                                            </label>
                                        </div>
                                        <button type="submit" class="w-full rounded-2xl bg-slate-900 px-4 py-3 text-white text-sm font-semibold hover:-translate-y-0.5 transition">
                                            Savdoni saqlash
                                        </button>
                                    </div>
                                </aside>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </main>
        </div>

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
                transition: all 0.25s ease;
                box-shadow: inset 0 0 0 0 rgba(59, 130, 246, 0.15);
            }
            .payment-card input {
                display: none;
            }
            .payment-card.is-active {
                border-color: rgb(59 130 246);
                background-color: rgb(239 246 255);
                box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.35);
            }
        </style>

        <script>
            const productData = <?= $catalogJson ?>;
            const productCards = new Map();
            const productCatalog = new Map();
            const cart = new Map();
            const formatter = new Intl.NumberFormat('uz-UZ');
            const cartContainer = document.getElementById('cart-items');
            const cartTotalEl = document.getElementById('cart-total');
            const payloadInput = document.getElementById('cart-payload');
            const emptyCartNotice = document.getElementById('cart-empty');
            const productGrid = document.getElementById('product-grid');
            const productSearch = document.getElementById('product-search');
            const initialCart = <?= $cartJson ?>;

            if (Array.isArray(productData)) {
                productData.forEach(product => {
                    productCatalog.set(product.id, product);
                });
            }

            if (productGrid) {
                productGrid.querySelectorAll('[data-product-card]').forEach(card => {
                    const productId = Number(card.dataset.productId);
                    if (!productId) {
                        return;
                    }
                    productCards.set(productId, {
                        card,
                        qty: card.querySelector('[data-product-qty]'),
                        plus: card.querySelector('[data-action="plus"]'),
                        minus: card.querySelector('[data-action="minus"]'),
                        stock: Number(card.dataset.productStock ?? 0),
                    });

                    card.addEventListener('click', (event) => {
                        const button = event.target.closest('button');
                        if (!button) {
                            return;
                        }
                        event.preventDefault();
                        const action = button.dataset.action;
                        if (action === 'plus') {
                            const current = cart.get(productId)?.quantity ?? 0;
                            setQuantity(productId, current + 1);
                        } else if (action === 'minus') {
                            const current = cart.get(productId)?.quantity ?? 0;
                            setQuantity(productId, current - 1);
                        }
                    });
                });
            }

            function setQuantity(productId, quantity) {
                if (!productCatalog.has(productId)) {
                    return;
                }
                const product = productCatalog.get(productId);
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
                cartTotalEl.textContent = formatter.format(total) + ' so\'m';
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
                    paymentOptions.querySelectorAll('label').forEach(label => {
                        label.classList.remove('is-active');
                    });
                    selected.classList.add('is-active');
                    if (selected.querySelector('input')?.value === 'debt') {
                        debtFields.classList.remove('hidden');
                    } else {
                        debtFields.classList.add('hidden');
                    }
                });
            }

            if (productSearch && productGrid) {
                productSearch.addEventListener('input', () => {
                    const query = productSearch.value.trim().toLowerCase();
                    let visibleCount = 0;
                    productGrid.querySelectorAll('[data-product-card]').forEach(card => {
                        const name = card.dataset.productName ?? '';
                        const match = !query || name.includes(query);
                        card.classList.toggle('hidden', !match);
                        if (match) {
                            visibleCount += 1;
                        }
                    });
                    const empty = document.getElementById('product-empty');
                    if (empty) {
                        empty.classList.toggle('hidden', visibleCount > 0);
                    }
                });
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

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
        transition: all 0.25s ease;
        box-shadow: inset 0 0 0 0 rgba(59, 130, 246, 0.15);
    }
    .payment-card input {
        display: none;
    }
    .payment-card.is-active {
        border-color: rgb(59 130 246);
        background-color: rgb(239 246 255);
        box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.35);
    }
</style>
<div class="space-y-8">
    <?php if ($success): ?>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 flex items-center justify-between gap-4">
            <span><?= htmlspecialchars($success) ?></span>
            <?php if (!empty($lastSale)): ?>
                <a href="receipts.php?sale_id=<?= (int)$lastSale['id'] ?>" class="text-xs font-semibold text-emerald-800 underline">Chekni ko'rish</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$showWorkspace): ?>
        <section class="rounded-3xl border border-slate-200 bg-gradient-to-br from-white via-sky-50 to-emerald-50 p-8 shadow-sm">
            <div class="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                <div class="space-y-4 max-w-2xl">
                    <div class="inline-flex items-center gap-2 rounded-full bg-white/70 px-3 py-1 text-xs font-semibold text-slate-500 shadow-sm">
                        <?= svg_icon('circle-dashed', 'w-3.5 h-3.5 text-emerald-500') ?>
                        Real vaqt rejimidagi savdolar
                    </div>
                    <h2 class="text-3xl font-semibold leading-tight text-slate-900">Katalog, chek va to'lovlar alohida sahifada</h2>
                    <p class="text-sm text-slate-600">“Yangi savdo” tugmasini bosganingizda to'liq ekranli ishchi maydon ochiladi va barcha elementlar bir vaqtning o'zida ko'rinadi.</p>
                    <div class="flex flex-wrap gap-3">
                        <?php if (empty($products)): ?>
                            <a href="products.php" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 text-white px-5 py-3 text-sm font-semibold shadow hover:-translate-y-0.5 transition">
                                <?= svg_icon('plus-circle', 'w-4 h-4') ?>
                                Mahsulot qo'shish
                            </a>
                        <?php else: ?>
                            <a href="sales.php?new=1" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 text-white px-6 py-3 text-sm font-semibold shadow-lg shadow-slate-900/20 hover:-translate-y-0.5 transition">
                                <?= svg_icon('play', 'w-4 h-4') ?>
                                Yangi savdoni ochish
                            </a>
                        <?php endif; ?>
                        <a href="receipts.php" class="inline-flex items-center gap-2 rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:border-brand-300 transition">
                            <?= svg_icon('queue-list', 'w-4 h-4') ?>
                            Cheklar tarixini ko'rish
                        </a>
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 w-full lg:w-[22rem]">
                    <article class="rounded-2xl bg-white border border-white/60 shadow-inner px-4 py-5">
                        <p class="text-xs text-slate-400">Bugungi tushum</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-900"><?= number_format($todayRevenue, 0, '.', ' ') ?> so'm</p>
                        <p class="text-xs text-emerald-600 inline-flex items-center gap-1 mt-2"><?= svg_icon('activity', 'w-3.5 h-3.5') ?>Live kuzatuv</p>
                    </article>
                    <article class="rounded-2xl bg-white border border-white/60 shadow-inner px-4 py-5">
                        <p class="text-xs text-slate-400">Bugungi savdolar</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-900"><?= number_format($todaySalesCount) ?></p>
                        <p class="text-xs text-slate-500 mt-2">Cheklar soni</p>
                    </article>
                    <article class="rounded-2xl bg-white border border-white/60 shadow-inner px-4 py-5 sm:col-span-2">
                        <p class="text-xs text-slate-400">Qarzdorlik</p>
                        <div class="flex items-center justify-between mt-2">
                            <p class="text-2xl font-semibold text-amber-600"><?= number_format($openDebtTotal, 0, '.', ' ') ?> so'm</p>
                            <div class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">Nazorat ostida</div>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <section class="rounded-3xl border border-slate-200 bg-white p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Oy favorit mahsulot</p>
                        <h3 class="text-xl font-semibold text-slate-900"><?= $topProductName ? htmlspecialchars($topProductName) : "Ma'lumot yo'q" ?></h3>
                    </div>
                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                        <?= svg_icon('star', 'w-3.5 h-3.5') ?>
                        Bestseller
                    </span>
                </div>
                <p class="text-3xl font-semibold text-emerald-600"><?= number_format($topProductRevenue, 0, '.', ' ') ?> so'm</p>
                <p class="text-sm text-slate-500">Oxirgi 30 kun ichida eng ko'p tushum keltirgan mahsulot.</p>
            </section>
            <section class="rounded-3xl border border-slate-200 bg-white p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Eng kam qoldiq</p>
                        <h3 class="text-xl font-semibold text-rose-600"><?= $lowestStockProduct ? htmlspecialchars($lowestStockProduct['name']) : "Ma'lumot yo'q" ?></h3>
                    </div>
                    <?= svg_icon('alert-triangle', 'w-5 h-5 text-rose-500') ?>
                </div>
                <p class="text-3xl font-semibold text-slate-900"><?= $lowestStockProduct ? number_format($lowestStockProduct['stock'], 2) . ' ' . htmlspecialchars($lowestStockProduct['unit']) : '' ?></p>
                <p class="text-sm text-slate-500">Tugab qolmasligi uchun tezda qayta xarid qiling.</p>
            </section>
            <section class="rounded-3xl border border-slate-200 bg-white p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Savdo oynasi</p>
                        <h3 class="text-xl font-semibold text-slate-900">Interaktiv panel</h3>
                    </div>
                    <?= svg_icon('mouse-pointer-click', 'w-5 h-5 text-brand-500') ?>
                </div>
                <p class="text-sm text-slate-500">Panel endi alohida sahifada ochiladi, shuning uchun kartalar va tanlangan mahsulotlar doim ko'rinadi.</p>
                <a href="sales.php?new=1" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-brand-200 bg-brand-50 px-5 py-2.5 text-sm font-semibold text-brand-700 hover:-translate-y-0.5 transition">
                    <?= svg_icon('sparkles', 'w-4 h-4') ?>
                    Yangi savdoni boshlash
                </a>
            </section>
        </div>
    <?php endif; ?>
</div>


<?php
render_footer();
?>
