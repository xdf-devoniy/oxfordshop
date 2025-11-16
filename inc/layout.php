<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

function render_header(string $title): void
{
    require_login();

    $pages = [
        'Boshqaruv paneli' => 'dashboard.php',
        'Mahsulotlar' => 'products.php',
        'Xaridlar' => 'purchases.php',
        'Savdolar' => 'sales.php',
        'Cheklar' => 'receipts.php',
        'Qarzdorlar' => 'debtors.php',
        'Hisobotlar' => 'reports.php',
        'Foyda' => 'profit.php',
        'Eksport' => 'export.php',
    ];
    $activePath = basename($_SERVER['PHP_SELF']);
    $userName = $_SESSION['user_login'] ?? 'admin';
    ?>
    <!DOCTYPE html>
    <html lang="uz">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?> · Ichimlik va Gazak POS</title>
        <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            brand: {
                                50: '#f0fdfa',
                                100: '#ccfbf1',
                                200: '#99f6e4',
                                300: '#5eead4',
                                400: '#2dd4bf',
                                500: '#14b8a6',
                                600: '#0d9488',
                                700: '#0f766e',
                                800: '#115e59',
                                900: '#134e4a'
                            }
                        },
                        fontFamily: {
                            display: ['"Plus Jakarta Sans"', 'Inter', 'ui-sans-serif', 'system-ui']
                        }
                    }
                }
            };
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    </head>
    <body class="min-h-screen font-display bg-slate-950/95 text-slate-100">
        <div class="flex min-h-screen">
            <aside class="hidden md:flex w-64 flex-col bg-slate-950 border-r border-white/5">
                <div class="px-6 py-8 border-b border-white/5">
                    <p class="text-xs uppercase tracking-[0.4em] text-slate-500">Oxford Shop</p>
                    <h1 class="mt-2 text-2xl font-semibold text-white">Ichimlik & Gazak</h1>
                    <p class="text-sm text-slate-500">Offlayn POS tizimi</p>
                </div>
                <nav class="flex-1 px-4 py-6 space-y-1">
                    <?php foreach ($pages as $label => $href):
                        $isActive = $activePath === $href;
                    ?>
                        <a href="<?= $href ?>"
                           class="flex items-center justify-between rounded-xl px-4 py-2 text-sm font-medium transition <?= $isActive
                               ? 'bg-white/10 text-white shadow-inner'
                               : 'text-slate-400 hover:bg-white/5 hover:text-white' ?>">
                            <span><?= htmlspecialchars($label) ?></span>
                            <?php if ($isActive): ?>
                                <span class="h-2 w-2 rounded-full bg-brand-400"></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="px-6 py-4 border-t border-white/5 text-xs text-slate-500">
                    © <?= date('Y') ?> Ichimlik va Gazak POS
                </div>
            </aside>
            <div class="flex-1 flex flex-col bg-slate-950/40">
                <header class="bg-white/80 backdrop-blur border-b border-white/60">
                    <div class="max-w-6xl mx-auto px-4 lg:px-8 py-4 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Do'kon boshqaruvi</p>
                            <h2 class="text-2xl font-semibold text-slate-900"><?= htmlspecialchars($title) ?></h2>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <p class="text-xs text-slate-400">Foydalanuvchi</p>
                                <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($userName) ?></p>
                            </div>
                            <a href="logout.php"
                               class="inline-flex items-center rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:border-brand-500 hover:text-brand-600">
                                Chiqish
                            </a>
                        </div>
                    </div>
                </header>
                <main class="flex-1 w-full max-w-6xl mx-auto px-4 lg:px-8 py-8">
    <?php
}

function render_footer(): void
{
    ?>
                </main>
                <footer class="border-t border-white/60 bg-white/80 backdrop-blur">
                    <div class="max-w-6xl mx-auto px-4 lg:px-8 py-4 text-xs text-slate-500 flex items-center justify-between">
                        <span>Ma'lumotlar SQLite faylida saqlanadi.</span>
                        <span>Tailwind CSS bilan yaratilgan.</span>
                    </div>
                </footer>
            </div>
        </div>
    </body>
    </html>
    <?php
}
