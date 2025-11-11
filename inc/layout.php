<?php
require_once __DIR__ . '/db.php';

function render_header(string $title): void
{
    $pages = [
        'Dashboard' => 'dashboard.php',
        'Products' => 'products.php',
        'Purchases' => 'purchases.php',
        'Sales' => 'sales.php',
        'Receipts' => 'receipts.php',
        'Debtors' => 'debtors.php',
        'Reports' => 'reports.php',
        'Profit' => 'profit.php',
        'Export' => 'export.php',
    ];
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> · Beverage & Snack POS</title>
        <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            brand: '#0f172a'
                        }
                    }
                }
            };
        </script>
        <style>
            body { background-color: #f8fafc; }
            .nav-active { background-color: #0f172a; color: #f8fafc; }
        </style>
    </head>
    <body class="min-h-screen flex">
        <aside class="w-64 bg-slate-900 text-white min-h-screen hidden md:block">
            <div class="p-6 border-b border-slate-700">
                <h1 class="text-xl font-semibold">Beverage & Snack POS</h1>
                <p class="text-sm text-slate-400">Single-User Dashboard</p>
            </div>
            <nav class="p-4 space-y-1">
                <?php foreach ($pages as $label => $href): $active = basename($_SERVER['PHP_SELF']) === $href; ?>
                    <a href="<?= $href ?>"
                       class="block px-3 py-2 rounded-md text-sm font-medium <?= $active ? 'nav-active' : 'hover:bg-slate-800' ?>">
                        <?= htmlspecialchars($label) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <div class="flex-1 flex flex-col">
            <header class="bg-white shadow-sm border-b border-slate-200">
                <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-semibold text-slate-800"><?= htmlspecialchars($title) ?></h2>
                        <p class="text-sm text-slate-500">Manage your beverage & snack business with confidence.</p>
                    </div>
                    <a href="dashboard.php" class="text-sm text-blue-600 hover:text-blue-800">Back to dashboard</a>
                </div>
            </header>
            <main class="flex-1 max-w-6xl mx-auto w-full px-4 py-6">
    <?php
}

function render_footer(): void
{
    ?>
            </main>
            <footer class="bg-white border-t border-slate-200">
                <div class="max-w-6xl mx-auto px-4 py-4 text-sm text-slate-500">
                    &copy; <?= date('Y') ?> Beverage & Snack POS · Built with SQLite & PHP
                </div>
            </footer>
        </div>
    </body>
    </html>
    <?php
}
