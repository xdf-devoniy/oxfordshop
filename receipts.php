<?php
require_once __DIR__ . '/inc/auth.php';
require_login();
require_once __DIR__ . '/inc/layout.php';

$errors = [];
$success = null;
$action = $_POST['action'] ?? '';
$openPriceModal = false;
$priceModalPrefill = [];

$customers = fetchAll($pdo, 'SELECT id, name FROM customers ORDER BY name');

if (isset($_GET['paid'])) {
    $success = "To'lov muvaffaqiyatli qo'shildi.";
}
if (isset($_GET['updated'])) {
    $success = "Chek ma'lumotlari yangilandi.";
}
if (isset($_GET['price_updated'])) {
    $success = "Mahsulot narxlari yangilandi.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add_payment') {
        $saleId = (int)($_POST['sale_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $method = $_POST['method'] ?? 'cash';
        $date = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $note = trim($_POST['notes'] ?? '');

        if ($amount <= 0) {
            $errors[] = "To'lov summasi musbat bo'lishi kerak.";
        }
        if ($date === '') {
            $errors[] = 'To\'lov sanasi talab qilinadi.';
        } else {
            try {
                new DateTime($date);
            } catch (Exception $e) {
                $errors[] = 'To\'lov sanasi noto\'g\'ri.';
            }
        }

        $saleExists = $saleId > 0 ? fetchOne($pdo, 'SELECT id FROM sales WHERE id = ?', [$saleId]) : null;
        if (!$saleExists) {
            $errors[] = 'Chek topilmadi.';
        }

        if (empty($errors)) {
            $summary = salePaymentSummary($pdo, $saleId);
            if ($summary['balance'] <= 0.0001) {
                $errors[] = 'Bu chek allaqachon to\'liq yopilgan.';
            } elseif ($amount > $summary['balance'] + 0.0001) {
                $errors[] = 'To\'lov summasi qolgan qarzdan oshib ketdi.';
            }
        }

        if (empty($errors)) {
            execute(
                $pdo,
                'INSERT INTO payments (sale_id, payment_method, amount, payment_date, notes) VALUES (?, ?, ?, ?, ?)',
                [$saleId, $method, $amount, $date, $note !== '' ? $note : null]
            );
            header('Location: receipts.php?sale_id=' . $saleId . '&paid=1');
            exit;
        }
    } elseif ($action === 'update_sale') {
        $saleId = (int)($_POST['sale_id'] ?? 0);
        $sale = $saleId > 0 ? fetchOne($pdo, 'SELECT * FROM sales WHERE id = ?', [$saleId]) : null;
        if (!$sale) {
            $errors[] = 'Chek topilmadi.';
        } else {
            $saleDate = trim($_POST['sale_date'] ?? $sale['sale_date']);
            $notes = trim($_POST['notes'] ?? '');
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $newCustomerName = trim($_POST['new_customer'] ?? '');

            if ($saleDate === '') {
                $errors[] = 'Savdo sanasi talab qilinadi.';
            } else {
                try {
                    new DateTime($saleDate);
                } catch (Exception $e) {
                    $errors[] = 'Savdo sanasi noto\'g\'ri.';
                }
            }

            if ($newCustomerName !== '') {
                execute($pdo, 'INSERT INTO customers (name) VALUES (?)', [$newCustomerName]);
                $customerId = (int)$pdo->lastInsertId();
            }

            $customerId = $customerId > 0 ? $customerId : null;

            if ($customerId !== null) {
                $customerExists = fetchOne($pdo, 'SELECT id FROM customers WHERE id = ?', [$customerId]);
                if (!$customerExists) {
                    $errors[] = 'Tanlangan mijoz topilmadi.';
                }
            }

            if (empty($errors)) {
                $changes = [];

                if ($sale['sale_date'] !== $saleDate) {
                    $changes[] = ['field' => 'Savdo sanasi', 'old' => $sale['sale_date'], 'new' => $saleDate];
                }

                $oldNotes = trim((string)($sale['notes'] ?? ''));
                if ($oldNotes !== $notes) {
                    $changes[] = ['field' => 'Izoh', 'old' => $oldNotes, 'new' => $notes];
                }

                $oldCustomerId = $sale['customer_id'] ? (int)$sale['customer_id'] : null;
                if ($oldCustomerId !== $customerId) {
                    $oldName = $oldCustomerId
                        ? (fetchOne($pdo, 'SELECT name FROM customers WHERE id = ?', [$oldCustomerId])['name'] ?? 'Ma\'lum emas')
                        : 'Tasodifiy mijoz';
                    $newName = $customerId
                        ? (fetchOne($pdo, 'SELECT name FROM customers WHERE id = ?', [$customerId])['name'] ?? 'Ma\'lum emas')
                        : 'Tasodifiy mijoz';
                    $changes[] = ['field' => 'Mijoz', 'old' => $oldName, 'new' => $newName];
                }

                if (empty($changes)) {
                    $success = "O'zgarishlar aniqlanmadi.";
                } else {
                    try {
                        $pdo->beginTransaction();
                        execute(
                            $pdo,
                            'UPDATE sales SET customer_id = ?, sale_date = ?, notes = ? WHERE id = ?',
                            [$customerId, $saleDate, $notes !== '' ? $notes : null, $saleId]
                        );
                        $auditStmt = $pdo->prepare('INSERT INTO receipt_audits (sale_id, field, old_value, new_value) VALUES (?, ?, ?, ?)');
                        foreach ($changes as $change) {
                            $auditStmt->execute([$saleId, $change['field'], $change['old'], $change['new']]);
                        }
                        $pdo->commit();
                        header('Location: receipts.php?sale_id=' . $saleId . '&updated=1');
                        exit;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $errors[] = "Chekni yangilashda xatolik yuz berdi.";
                    }
                }
            }
        }
    } elseif ($action === 'update_prices') {
        $saleId = (int)($_POST['sale_id'] ?? 0);
        $sale = $saleId > 0 ? fetchOne($pdo, 'SELECT * FROM sales WHERE id = ?', [$saleId]) : null;
        $payloadRaw = $_POST['items_payload'] ?? '[]';
        $priceModalPrefill = json_decode($payloadRaw, true);
        if (!is_array($priceModalPrefill)) {
            $errors[] = "Yangi narxlar noto'g'ri formatda.";
            $priceModalPrefill = [];
        }

        if (!$sale) {
            $errors[] = 'Chek topilmadi.';
        }

        $existingItems = [];
        if ($sale) {
            $existingItems = fetchAll(
                $pdo,
                'SELECT si.id, si.product_id, si.quantity, si.unit_price, si.total, p.name, p.unit
                 FROM sale_items si
                 JOIN products p ON p.id = si.product_id
                 WHERE si.sale_id = ?',
                [$saleId]
            );
            if (empty($existingItems)) {
                $errors[] = 'Chek uchun mahsulotlar topilmadi.';
            }
        }

        $changes = [];
        if (empty($errors)) {
            $itemsById = [];
            foreach ($existingItems as $itemRow) {
                $itemsById[(int)$itemRow['id']] = $itemRow;
            }

            foreach ($priceModalPrefill as &$entry) {
                $itemId = (int)($entry['id'] ?? 0);
                $newPrice = isset($entry['unit_price']) ? (float)$entry['unit_price'] : 0.0;

                if ($itemId <= 0 || !isset($itemsById[$itemId])) {
                    $errors[] = "Yangi narxlar noto'g'ri tanlandi.";
                    continue;
                }

                if ($newPrice <= 0) {
                    $errors[] = sprintf("%s uchun narx musbat bo'lishi kerak.", $itemsById[$itemId]['name']);
                    continue;
                }

                $entry['unit_price'] = $newPrice;
                $entry['quantity'] = (float)$itemsById[$itemId]['quantity'];
                $entry['name'] = $itemsById[$itemId]['name'];
                $entry['unit'] = $itemsById[$itemId]['unit'];
                $entry['original_price'] = (float)$itemsById[$itemId]['unit_price'];

                if (abs((float)$itemsById[$itemId]['unit_price'] - $newPrice) > 0.0001) {
                    $changes[] = [
                        'item_id' => $itemId,
                        'old_price' => (float)$itemsById[$itemId]['unit_price'],
                        'new_price' => $newPrice,
                        'quantity' => (float)$itemsById[$itemId]['quantity'],
                        'name' => $itemsById[$itemId]['name'],
                    ];
                }
            }
            unset($entry);
        }

        if (empty($errors)) {
            if (empty($changes)) {
                $success = "Narxlar o'zgartirilmagan.";
            } else {
                try {
                    $pdo->beginTransaction();
                    $updateStmt = $pdo->prepare('UPDATE sale_items SET unit_price = ?, total = ? WHERE id = ?');
                    $auditStmt = $pdo->prepare('INSERT INTO receipt_audits (sale_id, field, old_value, new_value) VALUES (?, ?, ?, ?)');
                    $newTotal = 0.0;
                    $changesById = [];
                    foreach ($changes as $change) {
                        $changesById[$change['item_id']] = $change;
                    }

                    foreach ($existingItems as $itemRow) {
                        $itemId = (int)$itemRow['id'];
                        if (isset($changesById[$itemId])) {
                            $change = $changesById[$itemId];
                            $lineTotal = $change['quantity'] * $change['new_price'];
                            $updateStmt->execute([$change['new_price'], $lineTotal, $itemId]);
                            $auditStmt->execute([
                                $saleId,
                                'Mahsulot narxi - ' . $change['name'],
                                number_format($change['old_price'], 2) . " so'm",
                                number_format($change['new_price'], 2) . " so'm",
                            ]);
                            $newTotal += $lineTotal;
                        } else {
                            $newTotal += (float)$itemRow['total'];
                        }
                    }

                    execute($pdo, 'UPDATE sales SET total_amount = ? WHERE id = ?', [$newTotal, $saleId]);
                    $pdo->commit();

                    header('Location: receipts.php?sale_id=' . $saleId . '&price_updated=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = "Narxlarni yangilashda xatolik yuz berdi.";
                }
            }
        }

        if (!empty($errors)) {
            $openPriceModal = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($errors) || $action === 'update_prices')) {
    $saleId = (int)($_POST['sale_id'] ?? 0);
} else {
    $saleId = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : null;
}

$sale = null;
$items = [];
$payments = [];
$history = [];
$priceModalItems = [];

if ($saleId) {
    $sale = fetchOne($pdo, 'SELECT s.*, IFNULL(c.name, "Tasodifiy mijoz") AS customer_name
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE s.id = ?', [$saleId]);
    if ($sale) {
        $items = fetchAll($pdo, 'SELECT si.*, p.name, p.unit FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = ?', [$saleId]);
        $payments = fetchAll($pdo, 'SELECT * FROM payments WHERE sale_id = ? ORDER BY payment_date ASC', [$saleId]);
        $history = fetchAll($pdo, 'SELECT field, old_value, new_value, changed_at FROM receipt_audits WHERE sale_id = ? ORDER BY changed_at DESC', [$saleId]);

        if (!empty($items)) {
            $prefillById = [];
            foreach ($priceModalPrefill as $prefill) {
                if (isset($prefill['id'])) {
                    $prefillById[(int)$prefill['id']] = $prefill;
                }
            }

            foreach ($items as $item) {
                $itemId = (int)$item['id'];
                $currentPrice = isset($prefillById[$itemId])
                    ? (float)$prefillById[$itemId]['unit_price']
                    : (float)$item['unit_price'];
                $quantity = (float)$item['quantity'];
                $priceModalItems[] = [
                    'id' => $itemId,
                    'name' => $item['name'],
                    'unit' => $item['unit'],
                    'quantity' => $quantity,
                    'original_price' => (float)$item['unit_price'],
                    'unit_price' => $currentPrice,
                    'total' => $quantity * $currentPrice,
                ];
            }
        }
    } else {
        $saleId = null;
    }
}

$priceModalJson = json_encode($priceModalItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($priceModalJson === false) {
    $priceModalJson = '[]';
}

render_header('Cheklar');
?>
<div class="space-y-5">
    <?php if (!empty($errors)): ?>
        <div class="border border-rose-200 bg-rose-50 text-rose-700 text-sm px-3 py-2 rounded">
            <ul class="list-disc pl-4 space-y-1">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php elseif ($success): ?>
        <div class="border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm px-3 py-2 rounded">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
            <h3 class="text-lg font-semibold text-slate-800 mb-4">Savdo cheklaringiz</h3>
            <div class="overflow-x-auto">
                <div class="max-h-[32rem] overflow-y-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr class="text-left">
                                <th class="px-3 py-2">Chek №</th>
                                <th class="px-3 py-2">Sana</th>
                                <th class="px-3 py-2">Mijoz</th>
                                <th class="px-3 py-2">Jami</th>
                                <th class="px-3 py-2">To'langan</th>
                                <th class="px-3 py-2">Qoldiq</th>
                                <th class="px-3 py-2">Holat</th>
                                <th class="px-3 py-2">Ko'rish</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php
                            $receipts = fetchAll($pdo, 'SELECT s.id, s.sale_date, s.total_amount, IFNULL(c.name, "Tasodifiy mijoz") AS customer_name,
                                IFNULL(paid.total_paid,0) AS total_paid
                                FROM sales s
                                LEFT JOIN customers c ON c.id = s.customer_id
                                LEFT JOIN (
                                    SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
                                ) paid ON paid.sale_id = s.id
                                ORDER BY s.sale_date DESC, s.id DESC');
                            if (empty($receipts)): ?>
                                <tr>
                                    <td colspan="8" class="px-3 py-6 text-center text-slate-400">Hali cheklar mavjud emas.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($receipts as $receipt):
                                    $balance = (float)$receipt['total_amount'] - (float)$receipt['total_paid'];
                                    $status = 'To\'langan';
                                    if ($balance > 0 && $receipt['total_paid'] > 0) {
                                        $status = 'Qisman';
                                    } elseif ($balance > 0) {
                                        $status = 'Qarz';
                                    }
                                ?>
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-slate-800">#<?= (int)$receipt['id'] ?></td>
                                        <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($receipt['sale_date']) ?></td>
                                        <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($receipt['customer_name']) ?></td>
                                        <td class="px-3 py-2 text-slate-700"><?= number_format((float)$receipt['total_amount'], 2) ?> so'm</td>
                                        <td class="px-3 py-2 text-emerald-600"><?= number_format((float)$receipt['total_paid'], 2) ?> so'm</td>
                                        <td class="px-3 py-2 <?= $balance > 0 ? 'text-rose-600' : 'text-slate-500' ?>"><?= number_format($balance, 2) ?> so'm</td>
                                        <td class="px-3 py-2">
                                            <span class="inline-flex px-2 py-1 rounded-full text-xs font-medium <?= $status === "To'langan" ? 'bg-emerald-100 text-emerald-700' : ($status === 'Qisman' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700') ?>"><?= $status ?></span>
                                        </td>
                                        <td class="px-3 py-2">
                                            <a href="receipts.php?sale_id=<?= (int)$receipt['id'] ?>" class="text-blue-600 text-xs font-medium hover:underline">Ko'rish</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <section class="bg-white border border-slate-200 rounded-lg p-5">
            <h3 class="text-lg font-semibold text-slate-800 mb-4">Chek tafsilotlari</h3>
            <?php if ($saleId && $sale): ?>
                <?php $summary = salePaymentSummary($pdo, $saleId); ?>
                <div class="space-y-3 text-sm">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="text-slate-500">Chek №<?= (int)$sale['id'] ?></p>
                            <p class="font-medium text-slate-800">Mijoz: <?= htmlspecialchars($sale['customer_name']) ?></p>
                            <p class="text-slate-500">Sana: <?= htmlspecialchars($sale['sale_date']) ?></p>
                        </div>
                        <button type="button"
                                class="text-xs text-blue-600 font-medium hover:underline js-edit-sale"
                                data-id="<?= (int)$sale['id'] ?>"
                                data-date="<?= htmlspecialchars($sale['sale_date']) ?>"
                                data-notes="<?= htmlspecialchars($sale['notes'] ?? '') ?>"
                                data-customer="<?= (int)($sale['customer_id'] ?? 0) ?>">
                            Tahrirlash
                        </button>
                    </div>
                    <div>
                        <div class="flex items-center justify-between">
                            <h4 class="font-semibold text-slate-700">Mahsulotlar</h4>
                            <?php if (!empty($items)): ?>
                                <button type="button"
                                        class="text-xs text-blue-600 font-medium hover:underline js-edit-prices"
                                        data-id="<?= (int)$sale['id'] ?>"
                                        data-items='<?= htmlspecialchars($priceModalJson, ENT_QUOTES, 'UTF-8') ?>'
                                        data-open="<?= $openPriceModal ? '1' : '0' ?>">
                                    Narxlarni tahrirlash
                                </button>
                            <?php endif; ?>
                        </div>
                        <ul class="mt-2 space-y-1">
                            <?php foreach ($items as $item): ?>
                                <li class="flex justify-between">
                                    <span><?= htmlspecialchars($item['name']) ?> · <?= number_format((float)$item['quantity'], 2) ?> <?= htmlspecialchars($item['unit']) ?> × <?= number_format((float)$item['unit_price'], 2) ?> so'm</span>
                                    <span class="font-medium"><?= number_format((float)$item['total'], 2) ?> so'm</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="mt-2 text-sm font-semibold text-slate-800">Jami: <?= number_format((float)$sale['total_amount'], 2) ?> so'm</p>
                    </div>
                    <div>
                        <h4 class="font-semibold text-slate-700">To'lovlar</h4>
                        <?php if (empty($payments)): ?>
                            <p class="text-slate-500">Hali to'lovlar kiritilmagan.</p>
                        <?php else: ?>
                            <ul class="mt-2 space-y-1">
                                <?php foreach ($payments as $payment): ?>
                                    <li class="flex justify-between">
                                        <span><?= strtoupper($payment['payment_method']) ?> · <?= htmlspecialchars($payment['payment_date']) ?></span>
                                        <span class="font-medium text-emerald-600"><?= number_format((float)$payment['amount'], 2) ?> so'm</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <p class="mt-2 text-sm text-slate-600">To'langan: <?= number_format($summary['total_paid'], 2) ?> so'm</p>
                        <p class="text-sm <?= $summary['balance'] > 0 ? 'text-rose-600' : 'text-slate-500' ?>">Qoldiq: <?= number_format($summary['balance'], 2) ?> so'm</p>
                    </div>
                    <?php if (!empty($history)): ?>
                        <div>
                            <h4 class="font-semibold text-slate-700">O'zgarishlar tarixi</h4>
                            <ul class="mt-2 space-y-1 max-h-32 overflow-y-auto pr-1">
                                <?php foreach ($history as $event): ?>
                                    <li>
                                        <p class="text-xs text-slate-500"><?= htmlspecialchars($event['changed_at']) ?></p>
                                        <p class="text-sm text-slate-700"><span class="font-medium"><?= htmlspecialchars($event['field']) ?>:</span> <?= htmlspecialchars($event['old_value'] ?? '—') ?> → <?= htmlspecialchars($event['new_value'] ?? '—') ?></p>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                <hr class="my-4">
                <h4 class="font-semibold text-slate-700 mb-2">Qo'shimcha to'lov qo'shish</h4>
                <?php if ($summary['balance'] <= 0.0001): ?>
                    <p class="text-sm text-slate-500">Bu chek to'liq yopilgan, qo'shimcha to'lov talab qilinmaydi.</p>
                <?php else: ?>
                    <form method="post" class="space-y-3">
                        <input type="hidden" name="action" value="add_payment">
                        <input type="hidden" name="sale_id" value="<?= (int)$sale['id'] ?>">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Summa (so'm)</label>
                            <input type="number" step="0.01" min="0" max="<?= htmlspecialchars(number_format($summary['balance'], 2, '.', '')) ?>" name="amount" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                            <p class="text-xs text-slate-500 mt-1">Qolgan qarz: <?= number_format($summary['balance'], 2) ?> so'm</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-slate-700">Usul</label>
                                <select name="method" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                                    <option value="cash" <?= (($_POST['method'] ?? '') === 'cash') ? 'selected' : '' ?>>Naqd</option>
                                    <option value="click" <?= (($_POST['method'] ?? '') === 'click') ? 'selected' : '' ?>>Click</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700">To'lov sanasi</label>
                                <input type="date" name="payment_date" value="<?= htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Izoh (ixtiyoriy)</label>
                            <textarea name="notes" rows="2" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <button type="submit" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800">To'lovni qo'shish</button>
                        </div>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-sm text-slate-500">Ro'yxatdan biror chekni tanlab, tafsilotlarini ko'ring va to'lov qo'shing.</p>
            <?php endif; ?>
        </section>
    </div>
</div>

<div id="price-edit-modal" class="hidden fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 px-4" data-open-initial="<?= $openPriceModal ? '1' : '0' ?>">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
            <div>
                <h3 class="text-lg font-semibold text-slate-800">Mahsulot narxlarini tahrirlash</h3>
                <p class="text-sm text-slate-500">Har bir mahsulot uchun yangi narxni kiriting. O'zgarishlar tarixda saqlanadi.</p>
            </div>
            <button type="button" class="text-slate-500 hover:text-slate-700" data-close-price>&#10005;</button>
        </div>
        <form method="post" class="px-5 py-4 space-y-4" id="price-edit-form">
            <input type="hidden" name="action" value="update_prices">
            <input type="hidden" name="sale_id" id="price-edit-sale-id" value="<?= $saleId ? (int)$saleId : '' ?>">
            <input type="hidden" name="items_payload" id="price-edit-payload" value="">
            <div class="border border-slate-200 rounded-lg">
                <div class="max-h-72 overflow-y-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr class="text-left">
                                <th class="px-3 py-2">Mahsulot</th>
                                <th class="px-3 py-2 text-right">Miqdor</th>
                                <th class="px-3 py-2 text-right">Joriy narx</th>
                                <th class="px-3 py-2 text-right">Yangi narx</th>
                                <th class="px-3 py-2 text-right">Jami</th>
                            </tr>
                        </thead>
                        <tbody id="price-edit-body" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>
            <p class="text-xs text-slate-500">Narxlarni o'zgartirish savdo summasini avtomatik yangilaydi.</p>
            <div class="flex items-center justify-between pt-2 border-t border-slate-200">
                <button type="button" class="text-sm text-slate-500 hover:text-slate-700" data-close-price>Bekor qilish</button>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800">Saqlash</button>
            </div>
        </form>
    </div>
</div>

<div id="sale-edit-modal" class="hidden fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 px-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
            <div>
                <h3 class="text-lg font-semibold text-slate-800">Chek ma'lumotlarini tahrirlash</h3>
                <p class="text-sm text-slate-500">Sanani, mijozni yoki izohni yangilang.</p>
            </div>
            <button type="button" class="text-slate-500 hover:text-slate-700" data-close-edit>&#10005;</button>
        </div>
        <form method="post" class="px-5 py-4 space-y-4" id="sale-edit-form">
            <input type="hidden" name="action" value="update_sale">
            <input type="hidden" name="sale_id" id="edit-sale-id" value="">
            <div>
                <label class="block text-sm font-medium text-slate-700">Savdo sanasi</label>
                <input type="date" name="sale_date" id="edit-sale-date" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Mijoz</label>
                <select name="customer_id" id="edit-sale-customer" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                    <option value="0">Tasodifiy mijoz</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int)$customer['id'] ?>"><?= htmlspecialchars($customer['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-slate-500 mt-1">Yangi mijozni qo'shish uchun pastdagi maydondan foydalaning.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Yangi mijoz ismi</label>
                <input type="text" name="new_customer" id="edit-sale-new-customer" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Masalan: Nodirbek">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Izoh</label>
                <textarea name="notes" id="edit-sale-notes" rows="3" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Chekga aloqador eslatmalar."></textarea>
            </div>
            <div class="flex items-center justify-between pt-2 border-t border-slate-200">
                <button type="button" class="text-sm text-slate-500 hover:text-slate-700" data-close-edit>Bekor qilish</button>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800">Saqlash</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('sale-edit-modal');
        const closeButtons = document.querySelectorAll('[data-close-edit]');
        const form = document.getElementById('sale-edit-form');
        const saleIdInput = document.getElementById('edit-sale-id');
        const saleDateInput = document.getElementById('edit-sale-date');
        const customerSelect = document.getElementById('edit-sale-customer');
        const newCustomerInput = document.getElementById('edit-sale-new-customer');
        const notesInput = document.getElementById('edit-sale-notes');

        const openModal = () => modal.classList.remove('hidden');
        const closeModal = () => modal.classList.add('hidden');

        document.querySelectorAll('.js-edit-sale').forEach(button => {
            button.addEventListener('click', () => {
                saleIdInput.value = button.dataset.id || '';
                saleDateInput.value = button.dataset.date || '';
                notesInput.value = button.dataset.notes || '';
                customerSelect.value = button.dataset.customer || '0';
                newCustomerInput.value = '';
                openModal();
            });
        });

        closeButtons.forEach(btn => btn.addEventListener('click', closeModal));

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        const priceModal = document.getElementById('price-edit-modal');
        const priceCloseButtons = document.querySelectorAll('[data-close-price]');
        const priceBody = document.getElementById('price-edit-body');
        const priceForm = document.getElementById('price-edit-form');
        const pricePayload = document.getElementById('price-edit-payload');
        const priceSaleIdInput = document.getElementById('price-edit-sale-id');
        const currencyFormatter = new Intl.NumberFormat('uz-UZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const quantityFormatter = new Intl.NumberFormat('uz-UZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        let priceItems = [];

        const renderPriceRows = () => {
            if (!priceBody) {
                return;
            }
            priceBody.innerHTML = '';
            if (!Array.isArray(priceItems) || priceItems.length === 0) {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="5" class="px-3 py-4 text-center text-slate-500">Mahsulotlar topilmadi.</td>';
                priceBody.appendChild(row);
                return;
            }

            priceItems.forEach((item, index) => {
                const safePrice = Number.isFinite(item.unit_price) ? item.unit_price : 0;
                const lineTotal = item.quantity * safePrice;
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td class="px-3 py-2">
                        <div class="font-medium text-slate-800">${item.name}</div>
                        <div class="text-xs text-slate-500">${item.unit}</div>
                    </td>
                    <td class="px-3 py-2 text-right text-slate-600">${quantityFormatter.format(item.quantity)} ${item.unit}</td>
                    <td class="px-3 py-2 text-right text-slate-500">${currencyFormatter.format(item.original_price)} so'm</td>
                    <td class="px-3 py-2 text-right">
                        <input type="number" step="0.01" min="0.01" class="w-28 border border-slate-300 rounded-md px-2 py-1 text-sm focus:outline-none focus:ring focus:ring-slate-400" data-index="${index}" value="${safePrice.toFixed(2)}">
                    </td>
                    <td class="px-3 py-2 text-right font-semibold text-slate-800" data-total="${index}">${currencyFormatter.format(lineTotal)} so'm</td>
                `;
                priceBody.appendChild(row);
            });
        };

        const openPriceModal = (saleId, items) => {
            if (!priceModal) {
                return;
            }
            priceItems = Array.isArray(items)
                ? items.map((item) => ({
                    id: Number(item.id) || 0,
                    name: item.name || '',
                    unit: item.unit || '',
                    quantity: Number(item.quantity) || 0,
                    original_price: Number(item.original_price) || 0,
                    unit_price: Number(item.unit_price) > 0 ? Number(item.unit_price) : (Number(item.original_price) || 0),
                }))
                : [];
            priceSaleIdInput.value = saleId || '';
            renderPriceRows();
            priceModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        };

        const closePriceModal = () => {
            if (!priceModal) {
                return;
            }
            priceModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        };

        document.querySelectorAll('.js-edit-prices').forEach(button => {
            button.addEventListener('click', () => {
                const saleId = button.dataset.id || '';
                let items = [];
                try {
                    const parsed = JSON.parse(button.dataset.items || '[]');
                    if (Array.isArray(parsed)) {
                        items = parsed;
                    }
                } catch (error) {
                    items = [];
                }
                openPriceModal(saleId, items);
            });
        });

        priceCloseButtons.forEach(btn => btn.addEventListener('click', closePriceModal));
        priceModal?.addEventListener('click', (event) => {
            if (event.target === priceModal) {
                closePriceModal();
            }
        });

        priceBody?.addEventListener('input', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }
            const index = Number(target.dataset.index);
            if (!Number.isFinite(index) || !priceItems[index]) {
                return;
            }
            let value = parseFloat(target.value);
            if (!Number.isFinite(value) || value <= 0) {
                value = 0;
            }
            priceItems[index].unit_price = value;
            const totalCell = priceBody.querySelector(`[data-total="${index}"]`);
            if (totalCell) {
                const lineTotal = priceItems[index].quantity * priceItems[index].unit_price;
                totalCell.textContent = `${currencyFormatter.format(lineTotal)} so'm`;
            }
        });

        priceForm?.addEventListener('submit', () => {
            if (!pricePayload) {
                return;
            }
            const payload = priceItems.map(item => ({
                id: item.id,
                unit_price: item.unit_price,
                name: item.name,
                unit: item.unit,
            }));
            pricePayload.value = JSON.stringify(payload);
        });

        const autoOpenButton = document.querySelector('.js-edit-prices[data-open="1"]');
        if (autoOpenButton) {
            autoOpenButton.dataset.open = '0';
            let items = [];
            try {
                const parsed = JSON.parse(autoOpenButton.dataset.items || '[]');
                if (Array.isArray(parsed)) {
                    items = parsed;
                }
            } catch (error) {
                items = [];
            }
            openPriceModal(autoOpenButton.dataset.id || '', items);
        }
    });
</script>
<?php
render_footer();
?>
