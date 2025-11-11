<?php
require_once __DIR__ . '/inc/layout.php';

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $sku = trim($_POST['sku'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $price = (float)($_POST['default_price'] ?? 0);

    if ($name === '') {
        $errors[] = 'Product name is required.';
    }
    if ($unit === '') {
        $errors[] = 'Unit is required (e.g. bottle, pack).';
    }
    if ($price < 0) {
        $errors[] = 'Default selling price must be zero or higher.';
    }

    if (empty($errors)) {
        if ($action === 'create') {
            execute($pdo, 'INSERT INTO products (sku, name, unit, default_price) VALUES (?, ?, ?, ?)', [$sku ?: null, $name, $unit, $price]);
            $success = 'Product created successfully.';
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            execute($pdo, 'UPDATE products SET sku = ?, name = ?, unit = ?, default_price = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$sku ?: null, $name, $unit, $price, $id]);
            $success = 'Product updated successfully.';
        }
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    execute($pdo, 'DELETE FROM products WHERE id = ?', [$id]);
    header('Location: products.php');
    exit;
}

$editingProduct = null;
if (isset($_GET['edit'])) {
    $editingProduct = fetchOne($pdo, 'SELECT * FROM products WHERE id = ?', [(int)$_GET['edit']]);
}

$products = fetchAll($pdo, 'SELECT * FROM products ORDER BY name ASC');

render_header('Products');
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <section class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-slate-800">Product Catalogue</h3>
            <a href="products.php" class="text-sm text-blue-600">Reset form</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-slate-500">
                        <th class="pb-2">SKU</th>
                        <th class="pb-2">Name</th>
                        <th class="pb-2">Unit</th>
                        <th class="pb-2">Price</th>
                        <th class="pb-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="5" class="py-6 text-center text-slate-400">No products yet. Add your first item using the form.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td class="py-2 text-slate-500"><?= htmlspecialchars($product['sku'] ?? '-') ?></td>
                                <td class="py-2 font-medium text-slate-800"><?= htmlspecialchars($product['name']) ?></td>
                                <td class="py-2 text-slate-600"><?= htmlspecialchars($product['unit']) ?></td>
                                <td class="py-2 text-slate-800">₩<?= number_format((float)$product['default_price'], 2) ?></td>
                                <td class="py-2">
                                    <a href="products.php?edit=<?= (int)$product['id'] ?>" class="text-blue-600 hover:text-blue-800 text-xs mr-3">Edit</a>
                                    <a href="products.php?delete=<?= (int)$product['id'] ?>" class="text-rose-600 hover:text-rose-800 text-xs" onclick="return confirm('Delete this product?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-5">
        <h3 class="text-lg font-semibold text-slate-800 mb-4"><?= $editingProduct ? 'Edit Product' : 'Add Product' ?></h3>
        <?php if (!empty($errors)): ?>
            <div class="mb-4 border border-rose-200 bg-rose-50 text-rose-700 text-sm px-3 py-2 rounded">
                <ul class="list-disc pl-4">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($success): ?>
            <div class="mb-4 border border-emerald-200 bg-emerald-50 text-emerald-700 text-sm px-3 py-2 rounded">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <form method="post" class="space-y-4">
            <input type="hidden" name="action" value="<?= $editingProduct ? 'update' : 'create' ?>">
            <?php if ($editingProduct): ?>
                <input type="hidden" name="id" value="<?= (int)$editingProduct['id'] ?>">
            <?php endif; ?>
            <div>
                <label class="block text-sm font-medium text-slate-700">SKU (optional)</label>
                <input type="text" name="sku" value="<?= htmlspecialchars($editingProduct['sku'] ?? '') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Product name</label>
                <input type="text" name="name" value="<?= htmlspecialchars($editingProduct['name'] ?? '') ?>" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Unit</label>
                <input type="text" name="unit" value="<?= htmlspecialchars($editingProduct['unit'] ?? '') ?>" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400" placeholder="e.g. bottle, can, pack">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Default selling price (₩)</label>
                <input type="number" step="0.01" min="0" name="default_price" value="<?= htmlspecialchars($editingProduct['default_price'] ?? '0') ?>" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-slate-400">
            </div>
            <div class="pt-2">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-slate-900 text-white text-sm font-medium rounded-md hover:bg-slate-800">Save product</button>
            </div>
        </form>
    </section>
</div>
<?php
render_footer();
?>
