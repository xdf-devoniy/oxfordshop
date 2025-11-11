<?php
require_once __DIR__ . '/inc/layout.php';

$errors = [];
$success = null;

if (isset($_GET['paid'])) {
    $success = 'Payment recorded successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sale_id'])) {
    $saleId = (int)$_POST['sale_id'];
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'cash';
    $date = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $note = trim($_POST['notes'] ?? '');

    if ($amount <= 0) {
        $errors[] = 'Payment amount must be greater than zero.';
    }
    if ($date === '') {
        $errors[] = 'Payment date is required.';
    } else {
        try {
            new DateTime($date);
        } catch (Exception $e) {
            $errors[] = 'Payment date is invalid.';
        }
    }

    $saleExists = fetchOne($pdo, 'SELECT id FROM sales WHERE id = ?', [$saleId]);
    if (!$saleExists) {
        $errors[] = 'Receipt not found.';
    }

    if (empty($errors)) {
        $summary = salePaymentSummary($pdo, $saleId);
        if ($summary['balance'] <= 0.0001) {
            $errors[] = 'This receipt is already fully settled.';
        } elseif ($amount > $summary['balance'] + 0.0001) {
            $errors[] = 'Payment exceeds the remaining balance of ₩' . number_format($summary['balance'], 2) . '.';
        }
    }

    if (empty($errors)) {
        execute($pdo, 'INSERT INTO payments (sale_id, payment_method, amount, payment_date, notes) VALUES (?, ?, ?, ?, ?)', [$saleId, $method, $amount, $date, $note ?: null]);
        header('Location: receipts.php?sale_id=' . $saleId . '&paid=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $saleId = (int)$_POST['sale_id'];
} else {
    $saleId = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : null;
}

if ($saleId) {
    $sale = fetchOne($pdo, 'SELECT s.*, IFNULL(c.name, "Walk-in") AS customer_name
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE s.id = ?', [$saleId]);
    if (!$sale) {
        $saleId = null;
    }
}

render_header('Receipts');
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Sale Receipts</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="pb-2">Receipt #</th>
                        <th class="pb-2">Date</th>
                        <th class="pb-2">Customer</th>
                        <th class="pb-2">Total</th>
                        <th class="pb-2">Paid</th>
                        <th class="pb-2">Balance</th>
                        <th class="pb-2">Status</th>
                        <th class="pb-2">View</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php
                    $receipts = fetchAll($pdo, 'SELECT s.id, s.sale_date, s.total_amount, IFNULL(c.name, "Walk-in") AS customer_name,
                        IFNULL(paid.total_paid,0) AS total_paid
                        FROM sales s
                        LEFT JOIN customers c ON c.id = s.customer_id
                        LEFT JOIN (
                            SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
                        ) paid ON paid.sale_id = s.id
                        ORDER BY s.sale_date DESC, s.id DESC');
                    if (empty($receipts)): ?>
                        <tr>
                            <td colspan="8" class="py-6 text-center text-slate-400">No receipts yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($receipts as $receipt):
                            $balance = (float)$receipt['total_amount'] - (float)$receipt['total_paid'];
                            $status = 'Paid';
                            if ($balance > 0 && $receipt['total_paid'] > 0) {
                                $status = 'Partial';
                            } elseif ($balance > 0) {
                                $status = 'Debt';
                            }
                        ?>
                            <tr>
                                <td class="py-2 font-medium text-slate-800">#<?= (int)$receipt['id'] ?></td>
                                <td class="py-2 text-slate-600"><?= htmlspecialchars($receipt['sale_date']) ?></td>
                                <td class="py-2 text-slate-600"><?= htmlspecialchars($receipt['customer_name']) ?></td>
                                <td class="py-2 text-slate-700">₩<?= number_format((float)$receipt['total_amount'], 2) ?></td>
                                <td class="py-2 text-emerald-600">₩<?= number_format((float)$receipt['total_paid'], 2) ?></td>
                                <td class="py-2 <?= $balance > 0 ? 'text-rose-600' : 'text-slate-500' ?>">₩<?= number_format($balance, 2) ?></td>
                                <td class="py-2">
                                    <span class="inline-flex px-2 py-1 rounded-full text-xs font-medium <?= $status === 'Paid' ? 'bg-emerald-100 text-emerald-700' : ($status === 'Partial' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700') ?>"><?= $status ?></span>
                                </td>
                                <td class="py-2">
                                    <a href="receipts.php?sale_id=<?= (int)$receipt['id'] ?>" class="text-blue-600 text-xs">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4">Receipt Details</h3>
        <?php if ($saleId && isset($sale)): ?>
            <?php
            $items = fetchAll($pdo, 'SELECT si.*, p.name, p.unit FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = ?', [$saleId]);
            $payments = fetchAll($pdo, 'SELECT * FROM payments WHERE sale_id = ? ORDER BY payment_date ASC', [$saleId]);
            $summary = salePaymentSummary($pdo, $saleId);
            $totalPaid = $summary['total_paid'];
            $balance = $summary['balance'];
            ?>
            <div class="space-y-3 text-sm">
                <div>
                    <p class="text-slate-500">Receipt #<?= (int)$sale['id'] ?></p>
                    <p class="font-medium text-slate-800">Customer: <?= htmlspecialchars($sale['customer_name']) ?></p>
                    <p class="text-slate-500">Date: <?= htmlspecialchars($sale['sale_date']) ?></p>
                </div>
                <div>
                    <h4 class="font-semibold text-slate-700">Items</h4>
                    <ul class="mt-2 space-y-1">
                        <?php foreach ($items as $item): ?>
                            <li class="flex justify-between">
                                <span><?= htmlspecialchars($item['name']) ?> · <?= number_format((float)$item['quantity'], 2) ?> <?= htmlspecialchars($item['unit']) ?> × ₩<?= number_format((float)$item['unit_price'], 2) ?></span>
                                <span class="font-medium">₩<?= number_format((float)$item['total'], 2) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mt-2 text-sm font-semibold text-slate-800">Total: ₩<?= number_format((float)$sale['total_amount'], 2) ?></p>
                </div>
                <div>
                    <h4 class="font-semibold text-slate-700">Payments</h4>
                    <?php if (empty($payments)): ?>
                        <p class="text-slate-500">No payments yet.</p>
                    <?php else: ?>
                        <ul class="mt-2 space-y-1">
                            <?php foreach ($payments as $payment): ?>
                                <li class="flex justify-between">
                                    <span><?= strtoupper($payment['payment_method']) ?> · <?= htmlspecialchars($payment['payment_date']) ?></span>
                                    <span class="font-medium text-emerald-600">₩<?= number_format((float)$payment['amount'], 2) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="mt-2 text-sm text-slate-600">Paid: ₩<?= number_format($totalPaid, 2) ?></p>
                    <p class="text-sm <?= $balance > 0 ? 'text-rose-600' : 'text-slate-500' ?>">Balance: ₩<?= number_format($balance, 2) ?></p>
                </div>
            </div>
            <hr class="my-4">
            <h4 class="font-semibold text-slate-700 mb-2">Add payment</h4>
            <?php if ($balance <= 0.0001): ?>
                <p class="text-sm text-slate-500">This receipt is fully paid. No further payments are required.</p>
            <?php else: ?>
                <?php if (!empty($errors)): ?>
                    <div class="mb-3 border border-rose-200 bg-rose-50 text-rose-700 text-sm px-3 py-2 rounded">
                        <ul class="list-disc pl-4">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php elseif ($success): ?>
                    <div class="mb-3 border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm px-3 py-2 rounded">
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>
                <form method="post" class="space-y-3">
                    <input type="hidden" name="sale_id" value="<?= (int)$sale['id'] ?>">
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Amount (₩)</label>
                        <input type="number" step="0.01" min="0" max="<?= htmlspecialchars(number_format($balance, 2, '.', '')) ?>" name="amount" value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" required>
                        <p class="text-xs text-slate-500 mt-1">Remaining balance: ₩<?= number_format($balance, 2) ?></p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Method</label>
                            <select name="method" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                                <option value="cash" <?= (($_POST['method'] ?? '') === 'cash') ? 'selected' : '' ?>>Cash</option>
                                <option value="click" <?= (($_POST['method'] ?? '') === 'click') ? 'selected' : '' ?>>Click</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Payment date</label>
                            <input type="date" name="payment_date" value="<?= htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')) ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700">Notes (optional)</label>
                        <textarea name="notes" rows="2" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800">Add payment</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-sm text-slate-500">Select a receipt from the list to view details and add payments.</p>
        <?php endif; ?>
    </section>
</div>
<?php
render_footer();
?>
