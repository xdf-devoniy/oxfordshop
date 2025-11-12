<?php
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

$shouldOpenSaleModal = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($products);

render_header('Savdolar');
?>
<style>
    .product-button {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.85rem;
        text-align: left;
        background-color: #ffffff;
        transition: all 0.15s ease-in-out;
    }
    .product-button:not([data-disabled="1"]):hover {
        border-color: #0f172a;
        box-shadow: 0 10px 25px -15px rgba(15, 23, 42, 0.4);
    }
    .product-button[data-disabled="1"] {
        opacity: 0.45;
        cursor: not-allowed;
    }
    .product-button [data-selected-pill] {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        border-radius: 9999px;
        background-color: #dcfce7;
        color: #166534;
        font-size: 0.75rem;
        padding: 0.1rem 0.5rem;
    }
    .payment-card {
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 0.85rem;
        display: flex;
        gap: 0.65rem;
        align-items: flex-start;
        cursor: pointer;
        transition: all 0.15s ease-in-out;
    }
    .payment-card input {
        display: none;
    }
    .payment-card.active {
        border-color: #0f172a;
        background-color: #0f172a;
        color: #f8fafc;
    }
    .payment-card:not(.active):hover {
        border-color: #cbd5f5;
        box-shadow: 0 10px 25px -15px rgba(15, 23, 42, 0.4);
    }
</style>
<div class="space-y-6">
    <?php if ($success): ?>
        <div class="border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm px-3 py-2 rounded flex items-center justify-between">
            <span><?= htmlspecialchars($success) ?></span>
            <?php if (!empty($lastSale)): ?>
                <a href="receipts.php?sale_id=<?= (int)$lastSale['id'] ?>" class="text-xs underline">Chekni ko'rish</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-slate-200 rounded-lg p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-800">Savdo yarating</h2>
            <p class="text-sm text-slate-500">Mahsulotlarni tanlab, to'lov usulini belgilang va savdoni saqlang.</p>
        </div>
        <div class="flex items-center gap-3">
            <?php if (empty($products)): ?>
                <a href="products.php" class="inline-flex items-center justify-center px-4 py-2 text-sm font-semibold rounded-md border border-slate-300 text-slate-600 hover:bg-slate-50">Avval mahsulot qo'shing</a>
            <?php else: ?>
                <button type="button" id="open-sale-modal" class="inline-flex items-center justify-center bg-slate-900 text-white px-4 py-2 rounded-md text-sm font-semibold hover:bg-slate-800">Yangi savdo</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="sale-modal" class="fixed inset-0 z-40 <?= $shouldOpenSaleModal ? '' : 'hidden' ?> flex items-center justify-center bg-slate-900/50 px-4" data-open-initial="<?= $shouldOpenSaleModal ? '1' : '0' ?>">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-6xl max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <div>
                <h3 class="text-lg font-semibold text-slate-800">Yangi savdo</h3>
                <p class="text-sm text-slate-500">Mahsulotlarni tanlang va pastda savdo ma'lumotlarini to'ldiring.</p>
            </div>
            <button type="button" class="text-slate-500 hover:text-slate-700" id="close-sale-modal">&#10005;</button>
        </div>
        <div class="px-6 py-5 overflow-y-auto">
            <?php if (!empty($errors)): ?>
                <div class="border border-rose-200 bg-rose-50 text-rose-700 text-sm px-3 py-2 rounded mb-5">
                    <ul class="list-disc pl-4 space-y-1">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($products)): ?>
                <div class="bg-white border border-dashed border-slate-300 rounded-lg p-6 text-sm text-slate-600 text-center">
                    Avval mahsulot qo'shing. <a class="text-blue-600" href="products.php">Mahsulotlar</a> bo'limiga o'ting.
                </div>
            <?php else: ?>
                <form method="post" class="space-y-6" id="sale-form">
                    <input type="hidden" name="cart_payload" id="cart-payload" value='<?= htmlspecialchars($cartJson, ENT_QUOTES, 'UTF-8') ?>'>
                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                        <section class="xl:col-span-2 space-y-6">
                            <div class="border border-slate-200 rounded-lg p-5">
                                <div class="flex items-start justify-between mb-4">
                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-800">Mahsulot katalogi</h3>
                                        <p class="text-sm text-slate-500">Pastdagi tugmalardan foydalanib savdo chekingizni to'ldiring.</p>
                                    </div>
                                    <div class="text-right text-xs text-slate-400">
                                        Ombordagi qoldiq asosida mahsulotlar cheklanadi.
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" id="product-grid">
                                    <?php foreach ($products as $product): ?>
                                        <?php
                                            $price = (float)$product['default_price'];
                                            $stock = (float)($stockLevels[$product['id']] ?? 0);
                                            $disabled = $stock <= 0 || $price <= 0;
                                        ?>
                                        <button type="button"
                                                id="product-card-<?= (int)$product['id'] ?>"
                                                class="product-button"
                                                data-id="<?= (int)$product['id'] ?>"
                                                data-stock="<?= htmlspecialchars(number_format($stock, 2, '.', '')) ?>"
                                                data-price="<?= htmlspecialchars(number_format($price, 2, '.', '')) ?>"
                                                data-disabled="<?= $disabled ? '1' : '0' ?>"
                                                <?= $disabled ? 'disabled' : '' ?>>
                                            <div class="font-semibold text-slate-800 truncate" title="<?= htmlspecialchars($product['name']) ?>">
                                                <?= htmlspecialchars($product['name']) ?>
                                            </div>
                                            <div class="text-sm text-slate-500 flex items-center justify-between">
                                                <span><?= number_format($price, 0, '.', ' ') ?> so'm</span>
                                                <span><?= htmlspecialchars($product['unit']) ?></span>
                                            </div>
                                            <div class="text-xs <?= $stock > 0 ? 'text-emerald-600' : 'text-rose-600' ?>">
                                                <?= $stock > 0 ? 'Omborda: ' . number_format($stock, 2) : 'Omborda mavjud emas' ?>
                                            </div>
                                            <span class="hidden" data-selected-pill>
                                                Tanlangan: <span data-selected-count>0</span>
                                            </span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="border border-slate-200 rounded-lg p-5">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <h3 class="text-base font-semibold text-slate-800">Savdo cheki</h3>
                                        <p class="text-sm text-slate-500">Mahsulot sonini + va − tugmalari orqali boshqaring.</p>
                                    </div>
                                    <div class="text-right text-sm text-slate-500">
                                        <p>Umumiy summa</p>
                                        <p class="text-lg font-semibold text-slate-800"><span id="cart-total">0</span> so'm</p>
                                    </div>
                                </div>
                                <div class="border border-slate-200 rounded-lg">
                                    <div class="max-h-72 overflow-y-auto">
                                        <table class="min-w-full text-sm">
                                            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                                                <tr class="text-left">
                                                    <th class="px-3 py-2">Mahsulot</th>
                                                    <th class="px-3 py-2 text-center">Soni</th>
                                                    <th class="px-3 py-2 text-right">Narx</th>
                                                    <th class="px-3 py-2 text-right">Jami</th>
                                                    <th class="px-3 py-2 text-center">O'chirish</th>
                                                </tr>
                                            </thead>
                                            <tbody id="cart-items" class="divide-y divide-slate-100"></tbody>
                                        </table>
                                        <div id="empty-cart" class="px-4 py-6 text-center text-sm text-slate-500">
                                            Mahsulot tanlang va savdo cheki shu yerda ko'rinadi.
                                        </div>
                                    </div>
                                </div>
                                <p class="text-xs text-slate-500 mt-3">Narxlar avtomatik ravishda mahsulot kartasidagi standart narxdan olinadi.</p>
                            </div>
                        </section>
                        <section class="space-y-6">
                            <div class="border border-slate-200 rounded-lg p-5 space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700">Savdo sanasi</label>
                                    <input type="date" name="sale_date" value="<?= htmlspecialchars($saleDate) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700">Izoh</label>
                                    <textarea name="notes" rows="3" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Masalan: tushlik vaqti savdosi."><?= htmlspecialchars($notes) ?></textarea>
                                </div>
                            </div>
                            <div class="border border-slate-200 rounded-lg p-5">
                                <h3 class="text-base font-semibold text-slate-800 mb-3">To'lov va mijoz</h3>
                                <p class="text-sm text-slate-500 mb-4">Qaysi usulda to'lov qabul qilinganini belgilang. Qarz savdosi uchun mijozni kiriting.</p>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4" id="payment-options">
                                    <label class="payment-card <?= $currentMode === 'cash' ? 'active' : '' ?>">
                                        <input type="radio" name="payment_mode" value="cash" <?= $currentMode === 'cash' ? 'checked' : '' ?>>
                                        <span>
                                            <span class="block font-semibold">Naqd</span>
                                            <span class="block text-xs">To'liq naqd to'lov</span>
                                        </span>
                                    </label>
                                    <label class="payment-card <?= $currentMode === 'click' ? 'active' : '' ?>">
                                        <input type="radio" name="payment_mode" value="click" <?= $currentMode === 'click' ? 'checked' : '' ?>>
                                        <span>
                                            <span class="block font-semibold">Click</span>
                                            <span class="block text-xs">To'liq raqamli to'lov</span>
                                        </span>
                                    </label>
                                    <label class="payment-card <?= $currentMode === 'debt' ? 'active' : '' ?>">
                                        <input type="radio" name="payment_mode" value="debt" <?= $currentMode === 'debt' ? 'checked' : '' ?>>
                                        <span>
                                            <span class="block font-semibold">Qarz</span>
                                            <span class="block text-xs">To'lov keyin olinadi</span>
                                        </span>
                                    </label>
                                </div>
                                <div id="debt-fields" class="<?= $currentMode === 'debt' ? '' : 'hidden' ?> space-y-3">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700">Mavjud mijoz</label>
                                        <select name="customer_id" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                                            <option value="0">Tanlanmagan</option>
                                            <?php foreach ($customers as $customer): ?>
                                                <option value="<?= (int)$customer['id'] ?>" <?= $selectedCustomerId === (int)$customer['id'] ? 'selected' : '' ?>><?= htmlspecialchars($customer['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700">Yangi mijoz ismi</label>
                                        <input type="text" name="new_customer" value="<?= htmlspecialchars($newCustomerName) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Masalan, Azizbek">
                                        <p class="text-xs text-slate-500 mt-1">Agar mijoz ro'yxatda bo'lmasa, shu yerga kiriting.</p>
                                    </div>
                                </div>
                                <div class="pt-4 mt-4 border-t border-slate-200 flex items-center justify-between">
                                    <div class="text-sm text-slate-500">Barcha summalar o'zbek so'mida hisoblanadi.</div>
                                    <button type="submit" class="inline-flex items-center justify-center bg-slate-900 text-white px-4 py-2 rounded-md text-sm font-semibold hover:bg-slate-800">Savdoni saqlash</button>
                                </div>
                            </div>
                        </section>
                    </div>
                </form>
                <div id="product-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
                    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
                        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-800" id="product-modal-name">Mahsulot</h3>
                                <p class="text-sm text-slate-500" id="product-modal-stock"></p>
                            </div>
                            <button type="button" class="text-slate-500 hover:text-slate-700" data-close-product-modal>&#10005;</button>
                        </div>
                        <div class="px-5 py-4 space-y-4">
                            <div>
                                <p class="text-sm text-slate-500">Narx</p>
                                <p class="text-lg font-semibold text-slate-800" id="product-modal-price">0 so'm</p>
                            </div>
                            <div>
                                <p class="text-sm text-slate-500 mb-2">Miqdor</p>
                                <div class="flex items-center justify-center gap-4">
                                    <button type="button" id="product-modal-minus" class="h-10 w-10 rounded-full border border-slate-300 flex items-center justify-center text-lg text-slate-600 hover:bg-slate-100">−</button>
                                    <span class="text-2xl font-semibold text-slate-800" id="product-modal-qty">1</span>
                                    <button type="button" id="product-modal-plus" class="h-10 w-10 rounded-full border border-slate-300 flex items-center justify-center text-lg text-slate-600 hover:bg-slate-100">+</button>
                                </div>
                            </div>
                            <div class="flex items-center justify-between pt-2 border-t border-slate-200">
                                <button type="button" class="text-sm text-slate-500 hover:text-slate-700" data-close-product-modal>Bekor qilish</button>
                                <button type="button" id="product-modal-confirm" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800">Savdoga qo'shish</button>
                            </div>
                        </div>
                    </div>
                </div>
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
    document.addEventListener('DOMContentLoaded', function () {
        const productCatalog = new Map((<?= $catalogJson ?>).map(item => [Number(item.id), item]));
        const initialCart = (<?= $cartJson ?>);

        const cart = new Map();
        const cartTableBody = document.getElementById('cart-items');
        const cartTotalEl = document.getElementById('cart-total');
        const emptyCartNotice = document.getElementById('empty-cart');
        const payloadInput = document.getElementById('cart-payload');
        const productButtons = new Map();
        const formatter = new Intl.NumberFormat('uz-UZ', { minimumFractionDigits: 0, maximumFractionDigits: 0 });

        document.querySelectorAll('.product-button').forEach(button => {
            const id = Number(button.dataset.id);
            productButtons.set(id, button);
            button.addEventListener('click', () => {
                if (button.dataset.disabled === '1') {
                    return;
                }
                openProductModal(id);
            });
        });

        const productModal = document.getElementById('product-modal');
        const modalName = document.getElementById('product-modal-name');
        const modalPrice = document.getElementById('product-modal-price');
        const modalStock = document.getElementById('product-modal-stock');
        const modalQuantityValue = document.getElementById('product-modal-qty');
        const modalMinus = document.getElementById('product-modal-minus');
        const modalPlus = document.getElementById('product-modal-plus');
        const modalConfirm = document.getElementById('product-modal-confirm');
        const modalCloseButtons = document.querySelectorAll('[data-close-product-modal]');

        let activeProductId = null;
        let modalQuantity = 1;

        const closeProductModal = () => {
            productModal?.classList.add('hidden');
            activeProductId = null;
        };

        modalCloseButtons.forEach(button => {
            button.addEventListener('click', () => closeProductModal());
        });

        productModal?.addEventListener('click', (event) => {
            if (event.target === productModal) {
                closeProductModal();
            }
        });

        function openProductModal(productId) {
            if (!productCatalog.has(productId)) {
                return;
            }
            activeProductId = productId;
            const product = productCatalog.get(productId);
            modalName.textContent = product.name;
            modalPrice.textContent = formatter.format(product.price) + ' so\'m';
            modalStock.textContent = product.stock > 0
                ? `Omborda: ${formatter.format(product.stock)} ${product.unit}`
                : 'Omborda mavjud emas';
            const existing = cart.get(productId)?.quantity ?? 0;
            modalQuantity = existing > 0 ? existing : 1;
            updateModalQuantity(product);
            productModal.classList.remove('hidden');
        }

        function updateModalQuantity(product) {
            modalQuantity = Math.max(1, Math.round(modalQuantity * 100) / 100);
            if (product.stock > 0 && modalQuantity > product.stock) {
                modalQuantity = product.stock;
            }
            modalQuantityValue.textContent = modalQuantity;
            modalMinus.disabled = modalQuantity <= 1;
            if (product.stock > 0) {
                modalPlus.disabled = modalQuantity >= product.stock;
            } else {
                modalPlus.disabled = false;
            }
        }

        modalMinus?.addEventListener('click', () => {
            if (activeProductId === null) {
                return;
            }
            const product = productCatalog.get(activeProductId);
            modalQuantity = Math.max(1, modalQuantity - 1);
            updateModalQuantity(product);
        });

        modalPlus?.addEventListener('click', () => {
            if (activeProductId === null) {
                return;
            }
            const product = productCatalog.get(activeProductId);
            modalQuantity += 1;
            updateModalQuantity(product);
        });

        modalConfirm?.addEventListener('click', () => {
            if (activeProductId === null) {
                return;
            }
            setQuantity(activeProductId, modalQuantity);
            closeProductModal();
            const badge = productButtons.get(activeProductId)?.querySelector('[data-selected-pill]');
            if (badge) {
                badge.classList.remove('hidden');
            }
        });

        function setQuantity(productId, quantity) {
            if (!productCatalog.has(productId)) {
                return;
            }
            const product = productCatalog.get(productId);
            if (quantity <= 0) {
                cart.delete(productId);
            } else if (product.stock > 0 && quantity > product.stock + 0.0001) {
                alert('Omborda yetarli mahsulot yo\'q.');
                return;
            } else {
                cart.set(productId, { product_id: productId, quantity: quantity });
            }
            renderCart();
        }

        function removeItem(productId) {
            cart.delete(productId);
            renderCart();
        }

        function renderCart() {
            cartTableBody.innerHTML = '';
            let total = 0;
            cart.forEach((item, productId) => {
                if (!productCatalog.has(productId)) {
                    return;
                }
                const product = productCatalog.get(productId);
                const lineTotal = item.quantity * product.price;
                total += lineTotal;

                const row = document.createElement('tr');
                row.innerHTML = `
                    <td class="px-3 py-2 text-slate-700">
                        <div class="font-medium">${product.name}</div>
                        <div class="text-xs text-slate-500">${formatter.format(product.price)} so'm · ${product.unit}</div>
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex items-center justify-center gap-2">
                            <button type="button" class="h-7 w-7 rounded-full border border-slate-300 flex items-center justify-center text-slate-600 hover:bg-slate-100" data-action="decrease" data-id="${productId}">−</button>
                            <span class="min-w-[2.5rem] text-center font-semibold">${item.quantity}</span>
                            <button type="button" class="h-7 w-7 rounded-full border border-slate-300 flex items-center justify-center text-slate-600 hover:bg-slate-100" data-action="increase" data-id="${productId}">+</button>
                        </div>
                    </td>
                    <td class="px-3 py-2 text-right text-slate-600">${formatter.format(product.price)} so'm</td>
                    <td class="px-3 py-2 text-right font-semibold text-slate-800">${formatter.format(lineTotal)} so'm</td>
                    <td class="px-3 py-2 text-center">
                        <button type="button" class="text-rose-600 text-sm" data-action="remove" data-id="${productId}">O'chirish</button>
                    </td>
                `;
                cartTableBody.appendChild(row);
            });

            cartTotalEl.textContent = formatter.format(total);
            emptyCartNotice.classList.toggle('hidden', cart.size > 0);
            payloadInput.value = JSON.stringify(Array.from(cart.values()));

            productButtons.forEach((button, productId) => {
                const badge = button.querySelector('[data-selected-pill]');
                if (!badge) {
                    return;
                }
                const countEl = badge.querySelector('[data-selected-count]');
                const quantity = cart.get(productId)?.quantity ?? 0;
                if (quantity > 0) {
                    badge.classList.remove('hidden');
                    countEl.textContent = quantity;
                } else {
                    badge.classList.add('hidden');
                }
            });
        }

        cartTableBody.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (!target) {
                return;
            }
            const productId = Number(target.dataset.id);
            const action = target.dataset.action;
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
                removeItem(productId);
            }
        });

        if (Array.isArray(initialCart)) {
            initialCart.forEach(entry => {
                const productId = Number(entry.product_id ?? 0);
                const quantity = Number(entry.quantity ?? 0);
                if (productId && quantity > 0) {
                    setQuantity(productId, quantity);
                }
            });
        }

        renderCart();

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
                paymentOptions.querySelectorAll('.payment-card').forEach(card => card.classList.remove('active'));
                selected.classList.add('active');
                const mode = selected.querySelector('input').value;
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
