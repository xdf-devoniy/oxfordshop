<?php
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
    streamCsv(['ID', 'Date', 'Product', 'Quantity', 'Unit Cost', 'Total'], array_map(fn($row) => [
        $row['id'], $row['purchase_date'], $row['name'], $row['quantity'], $row['unit_cost'], $row['total']
    ], $rows), 'purchases.csv');
}

if ($type === 'sales') {
    $rows = fetchAll($pdo, 'SELECT s.id, s.sale_date, IFNULL(c.name, "Walk-in") AS customer, s.total_amount
        FROM sales s
        LEFT JOIN customers c ON c.id = s.customer_id
        ORDER BY s.sale_date DESC');
    streamCsv(['Receipt', 'Date', 'Customer', 'Total Amount'], array_map(fn($row) => [
        $row['id'], $row['sale_date'], $row['customer'], $row['total_amount']
    ], $rows), 'sales.csv');
}

if ($type === 'payments') {
    $rows = fetchAll($pdo, 'SELECT p.id, p.sale_id, p.payment_method, p.amount, p.payment_date
        FROM payments p
        ORDER BY p.payment_date DESC');
    streamCsv(['Payment ID', 'Sale ID', 'Method', 'Amount', 'Date'], array_map(fn($row) => [
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
    streamCsv(['Product', 'Stock', 'Unit', 'Default Price'], array_map(fn($row) => [
        $row['name'], $row['stock'], $row['unit'], $row['default_price']
    ], $rows), 'stock.csv');
}

if ($type === 'debtors') {
    $rows = fetchAll($pdo, 'SELECT IFNULL(c.name, "Walk-in") AS customer,
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
    streamCsv(['Customer', 'Date', 'Total', 'Paid', 'Balance'], array_map(fn($row) => [
        $row['customer'], $row['sale_date'], $row['total_amount'], $row['paid'], $row['balance']
    ], $rows), 'debtors.csv');
}

render_header('Exports');
?>
<div class="bg-white border border-slate-200 rounded-lg p-6">
    <h3 class="text-lg font-semibold text-slate-800 mb-4">Download CSV Backups</h3>
    <p class="text-sm text-slate-500 mb-6">Export raw data for external analysis or archiving. Files are generated instantly from the SQLite database.</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a href="export.php?type=purchases" class="px-4 py-3 border border-slate-300 rounded-lg flex items-center justify-between text-sm hover:border-slate-400">
            <span>Purchases</span>
            <span class="text-blue-600">Download</span>
        </a>
        <a href="export.php?type=sales" class="px-4 py-3 border border-slate-300 rounded-lg flex items-center justify-between text-sm hover:border-slate-400">
            <span>Sales</span>
            <span class="text-blue-600">Download</span>
        </a>
        <a href="export.php?type=payments" class="px-4 py-3 border border-slate-300 rounded-lg flex items-center justify-between text-sm hover:border-slate-400">
            <span>Payments</span>
            <span class="text-blue-600">Download</span>
        </a>
        <a href="export.php?type=stock" class="px-4 py-3 border border-slate-300 rounded-lg flex items-center justify-between text-sm hover:border-slate-400">
            <span>Stock on hand</span>
            <span class="text-blue-600">Download</span>
        </a>
        <a href="export.php?type=debtors" class="px-4 py-3 border border-slate-300 rounded-lg flex items-center justify-between text-sm hover:border-slate-400">
            <span>Debtors</span>
            <span class="text-blue-600">Download</span>
        </a>
    </div>
</div>
<?php
render_footer();
?>
