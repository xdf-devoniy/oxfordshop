<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

function render_header(string $title): void
{
    require_login();

    $pages = [
        'dashboard.php' => ['label' => "Boshqaruv paneli", 'icon' => 'heroicons-outline:home'],
        'products.php' => ['label' => 'Mahsulotlar', 'icon' => 'heroicons-outline:rectangle-stack'],
        'purchases.php' => ['label' => 'Xaridlar', 'icon' => 'heroicons-outline:arrow-down-on-square'],
        'sales.php' => ['label' => 'Savdolar', 'icon' => 'heroicons-outline:shopping-bag'],
        'receipts.php' => ['label' => 'Cheklar', 'icon' => 'heroicons-outline:document-text'],
        'debtors.php' => ['label' => 'Qarzdorlar', 'icon' => 'heroicons-outline:user-group'],
        'reports.php' => ['label' => 'Hisobotlar', 'icon' => 'heroicons-outline:chart-bar'],
        'profit.php' => ['label' => 'Foyda', 'icon' => 'heroicons-outline:banknotes'],
        'export.php' => ['label' => 'Eksport', 'icon' => 'heroicons-outline:arrow-up-on-square'],
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
                        fontFamily: {
                            display: ['"Inter"', 'ui-sans-serif', 'system-ui'],
                        },
                        colors: {
                            brand: {
                                50: '#eff6ff',
                                100: '#dbeafe',
                                200: '#bfdbfe',
                                300: '#93c5fd',
                                400: '#60a5fa',
                                500: '#3b82f6',
                                600: '#2563eb',
                                700: '#1d4ed8',
                                800: '#1e40af',
                                900: '#1e3a8a',
                            }
                        }
                    }
                }
            };
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <script src="https://code.iconify.design/3/3.1.0/iconify.min.js" defer></script>
        <script src="https://unpkg.com/lucide@0.474.0/dist/umd/lucide.min.js" defer></script>
    </head>
    <body class="min-h-screen font-display bg-slate-50 text-slate-900">
        <div class="min-h-screen flex flex-col lg:flex-row">
            <aside class="w-full lg:w-72 border-b lg:border-b-0 lg:border-r border-slate-200 bg-white/90 backdrop-blur-xl">
                <div class="px-6 py-8 border-b border-slate-200/80">
                    <p class="text-xs uppercase tracking-[0.35em] text-slate-400">Oxford Shop</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-900">Ichimlik & Gazak</h1>
                    <p class="text-sm text-slate-500">Yengil POS tizimi</p>
                </div>
                <nav class="px-4 py-6 space-y-1">
                    <?php foreach ($pages as $href => $meta):
                        $isActive = $activePath === $href;
                    ?>
                        <a href="<?= $href ?>"
                           class="flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-medium transition-all duration-200 <?= $isActive
                               ? 'bg-brand-50 border border-brand-200 text-brand-700 shadow-sm'
                               : 'text-slate-500 border border-transparent hover:border-slate-200 hover:bg-white' ?>">
                            <span class="iconify text-lg <?= $isActive ? 'text-brand-500' : 'text-slate-400' ?>" data-icon="<?= htmlspecialchars($meta['icon']) ?>"></span>
                            <span><?= htmlspecialchars($meta['label']) ?></span>
                            <?php if ($isActive): ?>
                                <span class="ml-auto inline-flex items-center rounded-full bg-brand-100 px-2.5 py-0.5 text-[11px] font-semibold text-brand-700">Faol</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <div class="px-6 py-6 border-t border-slate-200 text-xs text-slate-500">
                    © <?= date('Y') ?> · Ma'lumotlar lokal SQLite faylida.
                </div>
            </aside>
            <div class="flex-1 flex flex-col">
                <header class="bg-white/95 backdrop-blur-lg border-b border-slate-200">
                    <div class="max-w-6xl mx-auto px-4 lg:px-10 py-5 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Do'kon boshqaruvi</p>
                            <h2 class="text-3xl font-semibold text-slate-900 leading-tight flex items-center gap-2">
                                <?= htmlspecialchars($title) ?>
                                <i data-lucide="sparkles" class="w-4 h-4 text-amber-400"></i>
                            </h2>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <p class="text-xs text-slate-400">Foydalanuvchi</p>
                                <p class="text-sm font-semibold text-slate-700"><?= htmlspecialchars($userName) ?></p>
                            </div>
                            <div class="h-11 w-11 rounded-2xl bg-brand-100 text-brand-600 flex items-center justify-center font-semibold">
                                <?= strtoupper(substr($userName, 0, 1)) ?>
                            </div>
                            <a href="logout.php"
                               class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-brand-600 hover:border-brand-200 transition">
                                <span class="iconify" data-icon="heroicons-outline:arrow-right-on-rectangle"></span>
                                Chiqish
                            </a>
                        </div>
                    </div>
                </header>
                <main class="flex-1 w-full max-w-6xl mx-auto px-4 lg:px-10 py-10 space-y-8">
    <?php
}

function render_footer(): void
{
    ?>
                </main>
                <footer class="border-t border-slate-200 bg-white/90 backdrop-blur">
                    <div class="max-w-6xl mx-auto px-4 lg:px-10 py-4 text-xs text-slate-500 flex items-center justify-between">
                        <span>Tailwind CSS · Heroicons · Lucide bilan yaratilgan.</span>
                        <span>«Ichimlik va Gazak» POS</span>
                    </div>
                </footer>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                if (window.lucide) {
                    window.lucide.createIcons();
                }
            });
        </script>
    </body>
    </html>
    <?php
}
