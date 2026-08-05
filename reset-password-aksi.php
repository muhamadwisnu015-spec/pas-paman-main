<?php
// 1. Atur Zona Waktu agar sinkron dengan Database
date_default_timezone_set('Asia/Makassar'); 

require_once 'config/database.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$message = '';
$error_type = ''; 
$success = false;

// 2. Validasi Token dan Cek Kedaluwarsa
if (empty($token)) {
    $error_type = 'missing_token';
} else {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND token_expire > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        $error_type = 'invalid_token';
    }
}

// 3. Proses Perubahan Password jika token valid
if ($error_type === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'];
    $konfirmasi = $_POST['konfirmasi'];

    try {
        if (strlen($password) < 6) {
            throw new Exception("Password minimal harus 6 karakter!");
        }
        if ($password !== $konfirmasi) {
            throw new Exception("Konfirmasi password tidak cocok!");
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $update = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, token_expire = NULL WHERE id = ?");
        $update->execute([$hashed_password, $user['id']]);

        $success = true;
    } catch (Exception $e) {
        $message = "<div class='bg-red-50 text-red-600 p-4 rounded-2xl mb-6 font-bold text-center text-xs border border-red-100'>" . $e->getMessage() . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Ulang Password | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #fff5f2 0%, #fffaf5 100%);
        }
        .logo-mask {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid white;
            box-shadow: 0 10px 20px -5px rgba(153, 27, 27, 0.2);
            background: white;
            margin: 0 auto 20px;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-6">
    <div class="w-full max-w-[450px]">
        <div class="bg-white rounded-[48px] shadow-2xl shadow-red-900/10 p-10 md:p-14 border border-orange-50 relative overflow-hidden">

            <?php if ($error_type !== ''): ?>
                <div class="text-center relative z-10">
                    <div class="bg-red-50 w-20 h-20 rounded-3xl flex items-center justify-center mx-auto mb-6 text-red-600 shadow-lg shadow-red-100 rotate-3">
                        <i class="fas fa-exclamation-triangle text-3xl"></i>
                    </div>
                    <h2 class="text-2xl font-black text-gray-900 mb-2 tracking-tight">Tautan Tidak Sah</h2>
                    <p class="text-gray-500 text-sm mb-10 font-medium leading-relaxed italic">
                        <?php 
                            echo ($error_type === 'missing_token') 
                            ? "Token tidak ditemukan. Pastikan Anda menyalin tautan dari email dengan lengkap." 
                            : "Tautan ini sudah kedaluwarsa (berlaku 1 hour) atau sudah pernah digunakan.";
                        ?>
                    </p>
                    <a href="lupa-password" class="block w-full bg-red-800 text-white font-black py-5 rounded-[24px] shadow-xl hover:bg-black transition-all transform active:scale-95 uppercase text-xs tracking-widest">
                        Minta Tautan Baru
                    </a>
                </div>

            <?php elseif ($success): ?>
                <div class="text-center relative z-10">
                    <div class="bg-orange-100 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6 text-orange-600 shadow-lg shadow-orange-200">
                        <i class="fas fa-check-circle text-4xl"></i>
                    </div>
                    <h2 class="text-2xl font-black text-gray-900 mb-4">Password Diperbarui!</h2>
                    <p class="text-gray-500 text-sm mb-10 font-medium leading-relaxed italic">"Keluarga Sadar Obat dan Makanan Aman". Silakan masuk dengan kata sandi baru Anda.</p>
                    <a href="login" class="block w-full bg-orange-600 text-white font-black py-5 rounded-[24px] shadow-xl shadow-orange-900/20 hover:bg-orange-700 transition-all transform active:scale-95 uppercase text-xs tracking-widest">
                        Masuk Sekarang
                    </a>
                </div>

            <?php else: ?>
                <div class="text-center mb-10 relative z-10">
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight leading-none">Atur Ulang Password</h1>
                    <p class="text-gray-400 text-xs mt-3 font-medium uppercase tracking-widest text-red-800">Kader GAS-PAMAN</p>
                </div>

                <?= $message ?>

                <form action="" method="POST" class="space-y-6 relative z-10">
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-3 ml-1">Password Baru</label>
                        <div class="relative">
                            <i class="fas fa-lock absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                            <input type="password" name="password" required placeholder="Minimal 6 karakter"
                                   class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 focus:ring-4 focus:ring-orange-600/10 outline-none transition-all font-semibold text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-3 ml-1">Konfirmasi Password</label>
                        <div class="relative">
                            <i class="fas fa-shield-alt absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                            <input type="password" name="konfirmasi" required placeholder="Ulangi password baru"
                                   class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 focus:ring-4 focus:ring-orange-600/10 outline-none transition-all font-semibold text-sm">
                        </div>
                    </div>

                    <button type="submit" 
                            class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-5 rounded-[28px] shadow-xl shadow-orange-900/20 transition-all transform active:scale-[0.97] hover:-translate-y-1 mt-4 uppercase text-xs tracking-widest">
                        Perbarui Kata Sandi
                    </button>
                </form>
            <?php endif; ?>

        </div>
        <p class="text-center mt-8 text-gray-400 text-[10px] font-black uppercase tracking-[0.3em]">&copy; 2026 BBPOM DI MATARAM</p>
    </div>
</body>
</html>