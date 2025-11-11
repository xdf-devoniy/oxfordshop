<?php
$dbDir = __DIR__ . '/../data';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}

$dbPath = $dbDir . '/pos.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

function runMigrations(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT,
        name TEXT NOT NULL,
        unit TEXT NOT NULL,
        default_price REAL NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        phone TEXT,
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS purchases (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        quantity REAL NOT NULL,
        unit_cost REAL NOT NULL,
        purchase_date TEXT NOT NULL,
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER,
        sale_date TEXT NOT NULL,
        total_amount REAL NOT NULL,
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS sale_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity REAL NOT NULL,
        unit_price REAL NOT NULL,
        total REAL NOT NULL,
        FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        payment_method TEXT NOT NULL,
        amount REAL NOT NULL,
        payment_date TEXT NOT NULL,
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(sale_id) REFERENCES sales(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS adjustments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        quantity_change REAL NOT NULL,
        reason TEXT,
        adjustment_date TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_purchases_product ON purchases(product_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sale_items_product ON sale_items(product_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_payments_sale ON payments(sale_id)');
}

function fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    return $result === false ? null : $result;
}

function execute(PDO $pdo, string $sql, array $params = []): bool
{
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

function productStockSnapshot(PDO $pdo): array
{
    $rows = fetchAll($pdo, 'SELECT p.id,
        IFNULL((SELECT SUM(quantity) FROM purchases WHERE product_id = p.id),0) +
        IFNULL((SELECT SUM(quantity_change) FROM adjustments WHERE product_id = p.id),0) -
        IFNULL((SELECT SUM(quantity) FROM sale_items WHERE product_id = p.id),0) AS stock
        FROM products p');

    $stock = [];
    foreach ($rows as $row) {
        $stock[(int)$row['id']] = (float)$row['stock'];
    }

    return $stock;
}

function salePaymentSummary(PDO $pdo, int $saleId): array
{
    $row = fetchOne($pdo, 'SELECT s.total_amount,
            IFNULL(paid.total_paid, 0) AS total_paid,
            (s.total_amount - IFNULL(paid.total_paid, 0)) AS balance
        FROM sales s
        LEFT JOIN (
            SELECT sale_id, SUM(amount) AS total_paid FROM payments GROUP BY sale_id
        ) paid ON paid.sale_id = s.id
        WHERE s.id = ?', [$saleId]);

    if (!$row) {
        return ['total_amount' => 0.0, 'total_paid' => 0.0, 'balance' => 0.0];
    }

    return [
        'total_amount' => (float)$row['total_amount'],
        'total_paid' => (float)$row['total_paid'],
        'balance' => (float)$row['balance'],
    ];
}

runMigrations($pdo);
