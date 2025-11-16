<?php
require_once __DIR__ . '/inc/auth.php';
require_login();
require_once __DIR__ . '/inc/layout.php';

$type = $_GET['type'] ?? null;

function streamCsv(array $headers, array $rows, string $filename): void
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

if ($type === 'purchases') {
    $rows = fetchAll($pdo, 'SELECT pu.id, pu.purchase_date, pr.name, pu.quantity, pu.unit_cost, (pu.quantity * pu.unit_cost) AS total
        FROM purchases pu
        JOIN products pr ON pr.id = pu.product_id
        ORDER BY pu.purchase_date DESC');
    streamCsv(['ID', 'Sana', 'Mahsulot', 'Miqdor', 'Tannarx', 'Jami'], array_map(fn($row) => [
        $row['id'], $row['purchase_date'], $row['name'], $row['quantity'], $row['unit_cost'], $row['total']
    ], $rows), 'purchases.csv');
}

if ($type === 'sales') {
    $rows = fetchAll($pdo, 'SELECT s.id, s.sale_date, IFNULL(c.name, "Tasodifiy mijoz") AS customer, s.total_amount
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        ORDER BY s.sale_date DESC');
    streamCsv(['Chek', 'Sana', 'Mijoz', 'Umumiy summa'], array_map(fn($row) => [
        $row['id'], $row['sale_date'], $row['customer'], $row['total_amount']
    ], $rows), 'sales.csv');
}

if ($type === 'payments') {
    $rows = fetchAll($pdo, 'SELECT p.id, p.sale_id, p.payment_method, p.amount, p.payment_date
        FROM payments p
        ORDER BY p.payment_date DESC');
    streamCsv(['To\'lov ID', 'Savdo ID', 'Usul', 'Summa', 'Sana'], array_map(fn($row) => [
        $row['id'], $row['sale_id'], strtoupper($row['payment_method']), $row['amount'], $row['payment_date']
    ], $rows), 'payments.csv');
}

if ($type === 'stock') {
    $rows = fetchAll($pdo, 'SELECT p.name,
        IFNULL((SELECT SUM(quantity) FROM purchases WHERE product_id = p.id),0) +
        IFNULL((SELECT SUM(quantity_change) FROM adjustments WHERE product_id = p.id),0) -
        IFNULL((SELECT SUM(quantity) FROM sale_items WHERE product_id = p.id),0) AS stock,
        p.unit, p.default_price
        FROM products p
        ORDER BY p.name');
    streamCsv(['Mahsulot', 'Qoldiq', 'O\'lchov', 'Standart narx'], array_map(fn($row) => [
        $row['name'], $row['stock'], $row['unit'], $row['default_price']
    ], $rows), 'stock.csv');
}

if ($type === 'debtors') {
    $rows = fetchAll($pdo, 'SELECT IFNULL(c.name, "Tasodifiy mijoz") AS customer,
        s.sale_date,
        s.total_amount,
        IFNULL(pay.total_paid,0) AS paid,
        (s.total_amount - IFNULL(pay.total_paid,0)) AS balance
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        LEFT JOIN (
            SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
        ) pay ON pay.sale_id = s.id
        WHERE s.total_amount > IFNULL(pay.total_paid,0)
        ORDER BY s.sale_date DESC');
    streamCsv(['Mijoz', 'Sana', 'Jami', 'To\'langan', 'Qoldiq'], array_map(fn($row) => [
        $row['customer'], $row['sale_date'], $row['total_amount'], $row['paid'], $row['balance']
    ], $rows), 'debtors.csv');
}

render_header('Eksport');
?>
<div class="bg-white border border-slate-200 rounded-lg p-6">
    <h3 class="text-lg font-semibold text-slate-800 mb-4">CSV ko'chirmalarni yuklab oling</h3>
    <p class="text-sm text-slate-500 mb-6">Ma'lumotlarni tashqi tahlil yoki zahira nusxa uchun eksport qiling. Fayllar darhol SQLite bazasidan shakllantiriladi.</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a href="export.php?type=purchases" class="px-4 py-3 border border-slate-300 rounded-lg flex items-center justify-between text-sm hover:border-slate-400">
            <span>Xaridlar</span>
            <span class="text-blue-600">Yuklab olish</span>
        </a>
        <a href="export.php?type=sales" class="px-4 py-3 border border-slate-300 rounded-lg flex items-center justify-between text-sm hover:border-slate-400">
            <span>Savdolar</span>
            <span class="text-blue-600">Yuklab olish</span>
        </a>
        <a href="export.php?type=payments" class="px-4 py-3 border border-slate-300 rounded-lg flex items-center justify-between text-sm hover:border-slate-400">
            <span>To'lovlar</span>
            <span class="text-blue-600">Yuklab olish</span>
        </a>
        <a href="export.php?type=stock" class="px-4 py-3 border border-slate-300 rounded-lg flex items-center justify-between text-sm hover:border-slate-400">
            <span>Ombordagi qoldiq</span>
            <span class="text-blue-600">Yuklab olish</span>
        </a>
        <a href="export.php?type=debtors" class="px-4 py-3 border border-slate-300 rounded-lg flex items-center justify-between text-sm hover:border-slate-400">
            <span>Qarzdorlar</span>
            <span class="text-blue-600">Yuklab olish</span>
        </a>
    </div>
</div>
<?php
render_footer();
?>
