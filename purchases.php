<?php
require_once __DIR__ . '/inc/layout.php';

$errors = [];
$success = null;

$products = fetchAll($pdo, 'SELECT id, name, unit FROM products ORDER BY name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 0);
        $unitCost = (float)($_POST['unit_cost'] ?? 0);
        $date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');

        if ($productId <= 0) {
            $errors[] = 'Mahsulotni tanlang.';
        }
        if ($quantity <= 0) {
            $errors[] = "Miqdor 0 dan katta bo'lishi kerak.";
        }
        if ($unitCost < 0) {
            $errors[] = 'Tannarx manfiy bo\'lishi mumkin emas.';
        }
        if ($date === '') {
            $errors[] = 'Xarid sanasi majburiy.';
        }

        if (empty($errors)) {
            execute(
                $pdo,
                'INSERT INTO purchases (product_id, quantity, unit_cost, purchase_date, notes) VALUES (?, ?, ?, ?, ?)',
                [$productId, $quantity, $unitCost, $date, $notes !== '' ? $notes : null]
            );
            $success = 'Yangi xarid muvaffaqiyatli qo\'shildi.';
        }
    } elseif ($action === 'update') {
        $purchaseId = (int)($_POST['purchase_id'] ?? 0);
        $existing = $purchaseId > 0
            ? fetchOne($pdo, 'SELECT * FROM purchases WHERE id = ?', [$purchaseId])
            : null;

        if (!$existing) {
            $errors[] = 'Xarid topilmadi.';
        } else {
            $productId = (int)($_POST['product_id'] ?? 0);
            $quantity = (float)($_POST['quantity'] ?? 0);
            $unitCost = (float)($_POST['unit_cost'] ?? 0);
            $date = trim($_POST['purchase_date'] ?? $existing['purchase_date']);
            $notes = trim($_POST['notes'] ?? '');

            if ($productId <= 0) {
                $errors[] = 'Mahsulotni tanlang.';
            }
            if ($quantity <= 0) {
                $errors[] = "Miqdor 0 dan katta bo'lishi kerak.";
            }
            if ($unitCost < 0) {
                $errors[] = 'Tannarx manfiy bo\'lishi mumkin emas.';
            }
            if ($date === '') {
                $errors[] = 'Xarid sanasi majburiy.';
            }

            if (empty($errors)) {
                execute(
                    $pdo,
                    'UPDATE purchases SET product_id = ?, quantity = ?, unit_cost = ?, purchase_date = ?, notes = ? WHERE id = ?',
                    [$productId, $quantity, $unitCost, $date, $notes !== '' ? $notes : null, $purchaseId]
                );
                $success = 'Xarid yozuvi yangilandi.';
            }
        }
    } elseif ($action === 'delete') {
        $purchaseId = (int)($_POST['purchase_id'] ?? 0);
        $existing = $purchaseId > 0
            ? fetchOne($pdo, 'SELECT id FROM purchases WHERE id = ?', [$purchaseId])
            : null;

        if (!$existing) {
            $errors[] = 'Xarid topilmadi.';
        } else {
            execute($pdo, 'DELETE FROM purchases WHERE id = ?', [$purchaseId]);
            $success = 'Xarid yozuvi o\'chirildi.';
        }
    }
}

$purchases = fetchAll($pdo, 'SELECT pu.*, pr.name AS product_name, pr.unit
    FROM purchases pu
    JOIN products pr ON pr.id = pu.product_id
    ORDER BY purchase_date DESC, pu.id DESC');

render_header('Xaridlar');
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

    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold text-slate-800">Xaridlar tarixi</h3>
            <p class="text-sm text-slate-500">Har bir xarid bo\'yicha ma\'lumotlarni kuzatib boring.</p>
        </div>
        <button type="button" id="open-create" class="inline-flex items-center bg-slate-900 text-white text-sm font-medium px-4 py-2 rounded-md hover:bg-slate-800">
            Yangi xarid
        </button>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg">
        <div class="overflow-x-auto">
            <div class="max-h-[28rem] overflow-y-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                        <tr class="text-left">
                            <th class="px-3 py-2">Sana</th>
                            <th class="px-3 py-2">Mahsulot</th>
                            <th class="px-3 py-2">Miqdor</th>
                            <th class="px-3 py-2">Tannarx</th>
                            <th class="px-3 py-2">Jami</th>
                            <th class="px-3 py-2 text-center">Amallar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($purchases)): ?>
                            <tr>
                                <td colspan="6" class="px-3 py-6 text-center text-slate-400">Hali xaridlar kiritilmagan.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($purchases as $purchase): ?>
                                <tr>
                                    <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($purchase['purchase_date']) ?></td>
                                    <td class="px-3 py-2 font-medium text-slate-800">
                                        <?= htmlspecialchars($purchase['product_name']) ?>
                                        <?php if (!empty($purchase['notes'])): ?>
                                            <div class="text-xs text-slate-400">Izoh: <?= htmlspecialchars($purchase['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-2 text-slate-600"><?= number_format((float)$purchase['quantity'], 2) ?> <?= htmlspecialchars($purchase['unit']) ?></td>
                                    <td class="px-3 py-2 text-slate-600"><?= number_format((float)$purchase['unit_cost'], 2) ?> so'm</td>
                                    <td class="px-3 py-2 text-slate-800 font-semibold"><?= number_format((float)$purchase['quantity'] * (float)$purchase['unit_cost'], 2) ?> so'm</td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center justify-center gap-2">
                                            <button type="button"
                                                    class="text-blue-600 text-xs font-medium hover:underline js-edit"
                                                    data-id="<?= (int)$purchase['id'] ?>"
                                                    data-product="<?= (int)$purchase['product_id'] ?>"
                                                    data-quantity="<?= htmlspecialchars(number_format((float)$purchase['quantity'], 2, '.', '')) ?>"
                                                    data-unit_cost="<?= htmlspecialchars(number_format((float)$purchase['unit_cost'], 2, '.', '')) ?>"
                                                    data-date="<?= htmlspecialchars($purchase['purchase_date']) ?>"
                                                    data-notes="<?= htmlspecialchars($purchase['notes'] ?? '') ?>">
                                                Tahrirlash
                                            </button>
                                            <button type="button"
                                                    class="text-rose-600 text-xs font-medium hover:underline js-delete"
                                                    data-id="<?= (int)$purchase['id'] ?>"
                                                    data-name="<?= htmlspecialchars($purchase['product_name']) ?>">
                                                O'chirish
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="purchase-modal" class="hidden fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 px-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
            <h3 id="purchase-modal-title" class="text-lg font-semibold text-slate-800">Yangi xarid</h3>
            <button type="button" class="text-slate-500 hover:text-slate-700" data-close>
                &#10005;
            </button>
        </div>
        <form method="post" class="px-5 py-4 space-y-4" id="purchase-form">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="purchase_id" id="purchase-id" value="">
            <div>
                <label class="block text-sm font-medium text-slate-700">Mahsulot</label>
                <select name="product_id" id="purchase-product" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                    <option value="">Mahsulotni tanlang</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int)$product['id'] ?>">
                            <?= htmlspecialchars($product['name']) ?> (<?= htmlspecialchars($product['unit']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Miqdor</label>
                    <input type="number" step="0.01" min="0" name="quantity" id="purchase-quantity" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Tannarx (so'm)</label>
                    <input type="number" step="0.01" min="0" name="unit_cost" id="purchase-unit-cost" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Xarid sanasi</label>
                <input type="date" name="purchase_date" id="purchase-date" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Izoh (ixtiyoriy)</label>
                <textarea name="notes" id="purchase-notes" rows="3" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="Masalan: yetkazib beruvchi, hisob-faktura."></textarea>
            </div>
            <div class="flex items-center justify-between pt-2 border-t border-slate-200">
                <button type="button" class="text-sm text-slate-500 hover:text-slate-700" data-close>Bekor qilish</button>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800">Saqlash</button>
            </div>
        </form>
    </div>
</div>

<div id="delete-modal" class="hidden fixed inset-0 z-40 flex items-center justify-center bg-slate-900/50 px-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm">
        <div class="px-5 py-4 border-b border-slate-200">
            <h3 class="text-lg font-semibold text-slate-800">Xaridni o'chirish</h3>
            <p class="mt-1 text-sm text-slate-500">Tanlangan xarid butunlay o'chiriladi. Davom etasizmi?</p>
            <p class="mt-2 text-sm font-medium text-slate-700" id="delete-target"></p>
        </div>
        <form method="post" class="px-5 py-4 space-y-3">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="purchase_id" id="delete-id" value="">
            <div class="flex items-center justify-between pt-2 border-t border-slate-200">
                <button type="button" class="text-sm text-slate-500 hover:text-slate-700" data-close>Bekor qilish</button>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-rose-600 text-white text-sm font-medium rounded-md hover:bg-rose-700">O'chirish</button>
            </div>
        </form>
    </div>
</div>

<?php if (!empty($products)): ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('purchase-modal');
        const deleteModal = document.getElementById('delete-modal');
        const form = document.getElementById('purchase-form');
        const title = document.getElementById('purchase-modal-title');
        const purchaseIdInput = document.getElementById('purchase-id');
        const productSelect = document.getElementById('purchase-product');
        const quantityInput = document.getElementById('purchase-quantity');
        const costInput = document.getElementById('purchase-unit-cost');
        const dateInput = document.getElementById('purchase-date');
        const notesInput = document.getElementById('purchase-notes');
        const deleteIdInput = document.getElementById('delete-id');
        const deleteTarget = document.getElementById('delete-target');

        const openModal = () => modal.classList.remove('hidden');
        const closeModal = () => modal.classList.add('hidden');
        const openDelete = () => deleteModal.classList.remove('hidden');
        const closeDelete = () => deleteModal.classList.add('hidden');

        document.querySelectorAll('#purchase-modal [data-close]').forEach(btn => btn.addEventListener('click', closeModal));
        document.querySelectorAll('#delete-modal [data-close]').forEach(btn => btn.addEventListener('click', closeDelete));

        document.getElementById('open-create')?.addEventListener('click', () => {
            form.reset();
            form.querySelector('[name="action"]').value = 'create';
            purchaseIdInput.value = '';
            title.textContent = 'Yangi xarid';
            const today = new Date().toISOString().split('T')[0];
            dateInput.value = today;
            openModal();
        });

        document.querySelectorAll('.js-edit').forEach(button => {
            button.addEventListener('click', () => {
                form.querySelector('[name="action"]').value = 'update';
                purchaseIdInput.value = button.dataset.id || '';
                productSelect.value = button.dataset.product || '';
                quantityInput.value = button.dataset.quantity || '';
                costInput.value = button.dataset.unit_cost || '';
                dateInput.value = button.dataset.date || '';
                notesInput.value = button.dataset.notes || '';
                title.textContent = 'Xaridni tahrirlash';
                openModal();
            });
        });

        document.querySelectorAll('.js-delete').forEach(button => {
            button.addEventListener('click', () => {
                deleteIdInput.value = button.dataset.id || '';
                deleteTarget.textContent = button.dataset.name ? `Mahsulot: ${button.dataset.name}` : '';
                openDelete();
            });
        });

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        deleteModal.addEventListener('click', (event) => {
            if (event.target === deleteModal) {
                closeDelete();
            }
        });
    });
</script>
<?php endif; ?>
<?php
render_footer();
?>
