<?php
require_once __DIR__ . '/inc/auth.php';

if (is_authenticated()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$username = trim($_POST['username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if ($username === 'admin' && $password === 'adminoxford') {
        login_user('admin');
        header('Location: dashboard.php');
        exit;
    }
    $error = "Login yoki parol noto'g'ri.";
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kirish · Ichimlik va Gazak POS</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#ecfeff',
                            100: '#cffafe',
                            200: '#a5f3fc',
                            300: '#67e8f9',
                            400: '#22d3ee',
                            500: '#06b6d4',
                            600: '#0891b2',
                            700: '#0e7490',
                            800: '#155e75',
                            900: '#164e63'
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-brand-50 flex items-center justify-center px-4 py-10">
    <div class="max-w-md w-full bg-white shadow-xl rounded-3xl p-8 space-y-6 border border-slate-100">
        <div class="text-center space-y-2">
            <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Ichimlik & Gazak POS</p>
            <h1 class="text-3xl font-semibold text-slate-900">Tizimga kirish</h1>
            <p class="text-sm text-slate-500">Login: <strong>admin</strong> · Parol: <strong>adminoxford</strong></p>
        </div>
        <?php if ($error): ?>
            <div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 px-4 py-3 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <form method="post" class="space-y-5">
            <div>
                <label class="text-sm font-medium text-slate-600">Login</label>
                <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" required
                       class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-slate-800 focus:border-brand-500 focus:ring-brand-500/40">
            </div>
            <div>
                <label class="text-sm font-medium text-slate-600">Parol</label>
                <input type="password" name="password" required
                       class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-slate-800 focus:border-brand-500 focus:ring-brand-500/40">
            </div>
            <button type="submit"
                    class="w-full inline-flex items-center justify-center rounded-xl bg-brand-600 text-white font-semibold py-3 shadow-lg shadow-brand-500/30 hover:shadow-brand-500/50 transition">
                Kirish
            </button>
        </form>
        <p class="text-center text-xs text-slate-400">Ma'lumotlar faqat lokal qurilmangizda saqlanadi.</p>
    </div>
</body>
</html>
