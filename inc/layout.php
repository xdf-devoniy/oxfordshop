<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

function svg_icon(string $name, string $class = 'w-5 h-5'): string
{
    static $icons = null;
    if ($icons === null) {
        $icons = [
            'home' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 11.25 12 2.25l9.75 9" /><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5v9.75A1.5 1.5 0 006 21.75h4.5v-6h3v6H18a1.5 1.5 0 001.5-1.5V10.5" />',
            'rectangle-stack' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4.5 7.5h15a1.5 1.5 0 011.5 1.5v8.25a1.5 1.5 0 01-1.5 1.5h-15A1.5 1.5 0 013 17.25V9a1.5 1.5 0 011.5-1.5z" /><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 5.25h9" />',
            'arrow-down-on-square' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6 3.75h12A2.25 2.25 0 0120.25 6v12A2.25 2.25 0 0118 20.25H6A2.25 2.25 0 013.75 18V6A2.25 2.25 0 016 3.75z" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5v7.5m0 0 3-3m-3 3-3-3" />',
            'shopping-bag' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5h10.5a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5H6.75a1.5 1.5 0 01-1.5-1.5v-9a1.5 1.5 0 011.5-1.5z" /><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 7.5V6a3.75 3.75 0 117.5 0v1.5" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h.01M15 12h.01" />',
            'document-text' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5h6l3.75 3.75V19.5A1.5 1.5 0 0116.5 21h-8.25A1.5 1.5 0 016.75 19.5V6A1.5 1.5 0 018.25 4.5z" /><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 4.5v3.75h3.75" /><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6M9 15h6" />',
            'user-group' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 13.5a3.75 3.75 0 10-3.75-3.75 3.75 3.75 0 003.75 3.75z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a3 3 0 100-6 3 3 0 000 6z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 20.25a6 6 0 0112 0" /><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 20.25a4.5 4.5 0 018 0" />',
            'chart-bar' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5h15" /><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 16.5v-6a1.5 1.5 0 011.5-1.5H12a1.5 1.5 0 011.5 1.5v6" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 16.5v-9a1.5 1.5 0 011.5-1.5H18a1.5 1.5 0 011.5 1.5v9" /><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 16.5v-3a1.5 1.5 0 011.5-1.5H6a1.5 1.5 0 011.5 1.5v3" />',
            'banknotes' => '<rect x="3.75" y="6.75" width="16.5" height="10.5" rx="2" ry="2" /><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 9.75v0a2.25 2.25 0 01-2.25-2.25" /><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 18.75v0a2.25 2.25 0 002.25-2.25" /><circle cx="12" cy="12" r="2.25" />',
            'arrow-up-on-square' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6 3.75h12A2.25 2.25 0 0120.25 6v12A2.25 2.25 0 0118 20.25H6A2.25 2.25 0 013.75 18V6A2.25 2.25 0 016 3.75z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75 12 7.5l2.25 2.25M12 7.5v9" />',
            'arrow-right-on-rectangle' => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.25 6.75h3A2.25 2.25 0 0119.5 9v6a2.25 2.25 0 01-2.25 2.25h-3" /><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 8.25 6 12l3.75 3.75" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 12h9" />',
            'sparkles' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5l1 3.5 3.5 1-3.5 1-1 3.5-1-3.5-3.5-1 3.5-1 1-3.5z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 14.25l.5 1.5 1.5.5-1.5.5-.5 1.5-.5-1.5-1.5-.5 1.5-.5.5-1.5z" /><path stroke-linecap="round" stroke-linejoin="round" d="M17.5 13l.5 1.5 1.5.5-1.5.5-.5 1.5-.5-1.5-1.5-.5 1.5-.5.5-1.5z" />',
            'plus-circle' => '<circle cx="12" cy="12" r="8.25" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 8.25v7.5M8.25 12h7.5" />',
            'play' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 8.25 16.5 12 9 15.75z" />',
            'queue-list' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 7.5h11.25" /><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 12h11.25" /><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 16.5h11.25" /><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 7.5h.008v.008H4.5zM4.5 12h.008v.008H4.5zM4.5 16.5h.008v.008H4.5z" />',
            'magnifying-glass' => '<circle cx="10.5" cy="10.5" r="5.25" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l4.5 4.5" />',
            'circle-dashed' => '<circle cx="12" cy="12" r="7.5" stroke-dasharray="3 3" />',
            'activity' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.5l4.5-3 3 4.5 3-6 3 4.5 4.5-2.25" />',
            'star' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5l2.164 4.386 4.836.704-3.5 3.417.826 4.843L12 19.125l-4.326 2.725.826-4.843-3.5-3.417 4.836-.704z" />',
            'alert-triangle' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 18.75h16.5L12 3.75z" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 9.75v3.75" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5h.01" />',
            'mouse-pointer-click' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3.75l4.5 13.5 1.875-5.625 5.625-1.875-13.5-4.5z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6.75l1.5-1.5" /><path stroke-linecap="round" stroke-linejoin="round" d="M18 9l1.5-1.5" />',
            'x' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M6 18L18 6" />',
        ];
    }

    if (!isset($icons[$name])) {
        return '';
    }

    $classAttr = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');

    return '<svg class="' . $classAttr . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
        . $icons[$name]
        . '</svg>';
}

function render_header(string $title): void
{
    require_login();

    $pages = [
        'dashboard.php' => ['label' => "Boshqaruv paneli", 'icon' => 'home'],
        'products.php' => ['label' => 'Mahsulotlar', 'icon' => 'rectangle-stack'],
        'purchases.php' => ['label' => 'Xaridlar', 'icon' => 'arrow-down-on-square'],
        'sales.php' => ['label' => 'Savdolar', 'icon' => 'shopping-bag'],
        'receipts.php' => ['label' => 'Cheklar', 'icon' => 'document-text'],
        'debtors.php' => ['label' => 'Qarzdorlar', 'icon' => 'user-group'],
        'reports.php' => ['label' => 'Hisobotlar', 'icon' => 'chart-bar'],
        'profit.php' => ['label' => 'Foyda', 'icon' => 'banknotes'],
        'export.php' => ['label' => 'Eksport', 'icon' => 'arrow-up-on-square'],
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
                            <span class="text-lg <?= $isActive ? 'text-brand-500' : 'text-slate-400' ?>">
                                <?= svg_icon($meta['icon'], 'w-5 h-5') ?>
                            </span>
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
                                <?= svg_icon('sparkles', 'w-4 h-4 text-amber-400') ?>
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
                                <?= svg_icon('arrow-right-on-rectangle', 'w-4 h-4') ?>
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
    </body>
    </html>
    <?php
}
