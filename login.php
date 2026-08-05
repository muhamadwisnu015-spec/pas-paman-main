<?php
require_once 'config/database.php';
session_start();

// Jika sudah login DAN cuma buka halaman ini biasa (GET), lempar ke dashboard sesuai role.
// Khusus request POST (submit form login) tetap diproses, supaya user bisa ganti akun/role
// dari tab yang sama tanpa nge-block validasi login barunya.
if (isset($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $dashboard_per_role = ['admin' => 'admin-dashboard', 'kabalai' => 'kabalai-dashboard'];
    header("Location: " . ($dashboard_per_role[$_SESSION['role']] ?? 'dashboard'));
    exit;
}

const CAPTCHA_MAX_ATTEMPT = 3;
const CAPTCHA_LOCK_SECONDS = 180; // 3 menit

$swal_type = '';
$swal_msg = '';
$redirect_url = '';
$selected_role = $_POST['login_role'] ?? 'agen'; // default tab: Agen Lapangan

// Cek status lock captcha (disimpan per session/browser)
$lockUntil = $_SESSION['captcha_lock_until'] ?? 0;
$isLocked = $lockUntil > time();
$sisaDetik = $isLocked ? ($lockUntil - time()) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($isLocked) {
        // Masih dalam masa lock, tolak duluan tanpa proses apapun
        $menit = floor($sisaDetik / 60);
        $detik = $sisaDetik % 60;
        $swal_type = 'error';
        $swal_msg = "Terlalu banyak percobaan captcha salah. Coba lagi dalam " . sprintf('%d menit %d detik', $menit, $detik) . ".";
    } else {
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $password = $_POST['password']; 
        $user_captcha = $_POST['captcha'] ?? '';

        if (!empty($email) && !empty($password) && !empty($user_captcha)) {

            if ($user_captcha != ($_SESSION['captcha_code'] ?? null)) {
                // Captcha salah -> tambah counter kegagalan
                $_SESSION['captcha_fail_count'] = ($_SESSION['captcha_fail_count'] ?? 0) + 1;

                if ($_SESSION['captcha_fail_count'] >= CAPTCHA_MAX_ATTEMPT) {
                    $_SESSION['captcha_lock_until'] = time() + CAPTCHA_LOCK_SECONDS;
                    $_SESSION['captcha_fail_count'] = 0;
                    $isLocked = true;
                    $sisaDetik = CAPTCHA_LOCK_SECONDS;
                    $swal_type = 'error';
                    $swal_msg = "Anda salah memasukkan captcha 3 kali. Silakan coba lagi dalam 3 menit.";
                } else {
                    $sisa = CAPTCHA_MAX_ATTEMPT - $_SESSION['captcha_fail_count'];
                    $swal_type = 'error';
                    $swal_msg = "Kode Verifikasi (Captcha) salah! Sisa percobaan: $sisa kali.";
                }
            } else {
                // Captcha benar -> reset counter
                $_SESSION['captcha_fail_count'] = 0;
                unset($_SESSION['captcha_code']);

                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                $roleCocok = $user && (
                    ($selected_role === 'admin' && in_array($user['role'], ['admin', 'kabalai'])) ||
                    ($selected_role === 'agen'  && in_array($user['role'], ['agen', 'staff']))
                );

                if ($user && password_verify($password, $user['password']) && $roleCocok) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['nama']    = $user['nama'];
                    $_SESSION['role']    = $user['role'];

                    $dashboard_per_role = ['admin' => 'admin-dashboard', 'kabalai' => 'kabalai-dashboard'];

                    $swal_type = 'success';
                    $swal_msg = "Selamat Datang, " . $user['nama'] . "! Login sebagai " . ucfirst($user['role']);
                    $redirect_url = $dashboard_per_role[$user['role']] ?? 'dashboard';
                } elseif ($user && password_verify($password, $user['password']) && !$roleCocok) {
                    $swal_type = 'error';
                    $swal_msg = "Akun ini terdaftar sebagai " . ucfirst($user['role']) . ", bukan pada kategori login yang Anda pilih.";
                } else {
                    $swal_type = 'error';
                    $swal_msg = "Email atau password salah!";
                }
            }
        } else {
            $swal_type = 'warning';
            $swal_msg = "Silakan isi semua kolom termasuk kode verifikasi.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: linear-gradient(135deg, #fff5f2 0%, #fffaf5 100%);
        }
        /* Animasi Mengambang untuk Logo */
        @keyframes float {
            0% { transform: translateY(0px) rotate(-3deg); }
            50% { transform: translateY(-15px) rotate(0deg); }
            100% { transform: translateY(0px) rotate(-3deg); }
        }
        .animate-float {
            animation: float 5s ease-in-out infinite;
        }
        .logo-container {
            position: relative;
            width: 320px;
            height: 320px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        /* Lingkaran cahaya di belakang logo */
        .logo-glow {
            position: absolute;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(251,146,60,0.3) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
        }
        .swal2-popup { border-radius: 40px !important; padding: 2rem !important; }

        /* Toggle Role Login */
        .role-tab { cursor: pointer; transition: all .2s ease; }
        .role-tab input { display: none; }
        .role-tab .tab-inner {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 14px 10px; border-radius: 18px; font-weight: 800; font-size: 12.5px;
            color: #9ca3af; background: #f9fafb; border: 1.5px solid #f3f4f6;
        }
        .role-tab input:checked + .tab-inner {
            background: #991b1b; color: #fff; border-color: #991b1b;
            box-shadow: 0 8px 20px -6px rgba(153,27,27,0.4);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

    <div class="flex w-full max-w-[1000px] bg-white rounded-[50px] shadow-[0_20px_60px_-15px_rgba(153,27,27,0.2)] overflow-hidden min-h-[680px] border border-orange-50">
        
        <div class="hidden lg:flex w-1/2 bg-red-800 relative p-12 flex-col justify-between text-white overflow-hidden">
            <div class="absolute -top-10 -left-10 w-64 h-64 bg-orange-500 rounded-full opacity-10"></div>
            
            <div class="relative z-10">
                <div class="bg-white/10 backdrop-blur-md px-4 py-2 rounded-2xl inline-flex items-center space-x-3 border border-white/20 mb-6">
                    <img src="views/bpom.webp" alt="BBPOM" class="h-6 w-auto brightness-0 invert">
                    <span class="text-[10px] font-black uppercase tracking-widest">BBPOM di Mataram</span>
                </div>
                <h2 class="text-4xl font-extrabold leading-tight">Selamat Datang di <br><span class="text-orange-400 font-black italic">GAS-PAMAN</span></h2>
                <div class="h-1.5 w-16 bg-orange-400 mt-4 rounded-full"></div>
            </div>

            <div class="relative z-10 flex flex-col items-center">
                <div class="logo-container animate-float">
                    <div class="logo-glow"></div>
                    <img src="views/logo-gas-paman.png" alt="Logo GAS-PAMAN" 
                         class="relative z-20 w-72 h-72 object-contain drop-shadow-[0_20px_40px_rgba(0,0,0,0.3)]">
                </div>
                <div class="text-center mt-4">
                    <p class="text-lg font-bold text-orange-400 mb-1 leading-none uppercase tracking-tighter">Keluarga Sadar</p>
                    <p class="text-xs font-medium text-orange-100/70 italic">Obat dan Makanan Aman di Komunitas</p>
                </div>
            </div>

            <div class="relative z-10 text-[9px] font-black uppercase tracking-[0.4em] text-white/40">
                Integrasi Inovasi &copy; 2026
            </div>
        </div>

        <div class="w-full lg:w-1/2 p-10 md:p-14 flex flex-col justify-center bg-white">
            
            <div class="lg:hidden text-center mb-10">
                <div class="relative inline-block animate-float">
                    <div class="absolute inset-0 bg-orange-100 rounded-full blur-2xl opacity-50"></div>
                    <img src="views/gas-paman-round.png" alt="Logo" class="relative z-10 h-36 mx-auto">
                </div>
                <h1 class="text-2xl font-black text-red-800 mt-4">Buku Harian Agen GAS-PAMAN</h1>
            </div>

            <div class="mb-8">
                <h3 class="text-3xl font-black text-gray-900 tracking-tight">Masuk Akun</h3>
                <p class="text-gray-400 text-sm mt-1 font-medium">Lanjutkan aktivitas pelaporan agen Anda.</p>
            </div>

            <!-- Toggle Role Login -->
            <div class="grid grid-cols-2 gap-3 mb-7">
                <label class="role-tab">
                    <input type="radio" name="login_role" value="agen" form="loginForm" <?= $selected_role === 'agen' ? 'checked' : '' ?>>
                    <div class="tab-inner"><i class="fas fa-user-shield"></i> Agen Lapangan</div>
                </label>
                <label class="role-tab">
                    <input type="radio" name="login_role" value="admin" form="loginForm" <?= $selected_role === 'admin' ? 'checked' : '' ?>>
                    <div class="tab-inner"><i class="fas fa-user-tie"></i> Admin / Staff</div>
                </label>
            </div>

            <?php if ($isLocked): ?>
            <div id="lockBanner" class="mb-6 px-5 py-4 rounded-2xl bg-red-50 border border-red-100 flex items-center gap-3">
                <i class="fas fa-lock text-red-700"></i>
                <p class="text-xs font-bold text-red-700">Form terkunci sementara. Coba lagi dalam <span id="countdownTimer"><?= gmdate('i:s', $sisaDetik) ?></span></p>
            </div>
            <?php endif; ?>

            <form id="loginForm" action="" method="POST" class="space-y-6">
                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Alamat Email</label>
                    <div class="relative">
                        <i class="fas fa-envelope absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                        <input type="email" name="email" required placeholder="nama@bbpom.go.id" <?= $isLocked ? 'disabled' : '' ?>
                               class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-red-800 focus:ring-4 focus:ring-red-800/10 outline-none transition-all font-semibold text-sm disabled:opacity-50">
                    </div>
                </div>

                <div>
                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Kata Sandi</label>
                    <div class="relative">
                        <i class="fas fa-lock absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                        <input type="password" name="password" required placeholder="••••••••" <?= $isLocked ? 'disabled' : '' ?>
                               class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-red-800 focus:ring-4 focus:ring-red-800/10 outline-none transition-all font-semibold text-sm disabled:opacity-50">
                    </div>
                    <p class="text-sm text-gray-500 font-medium italic">
                    <a href="lupa-password" class="text-orange-600 font-black hover:text-orange-700 ml-1">Lupa password?</a>
                </p>
                </div>

                <div>
                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Verifikasi Keamanan</label>
                    <div class="flex items-center space-x-3">
                        <div class="rounded-2xl overflow-hidden border border-orange-100 shadow-sm flex-shrink-0 bg-gray-50">
                            <img src="captcha.php?t=<?= time() ?>" id="captchaImg" alt="Captcha" class="h-[54px] w-[110px] object-cover">
                        </div>
                        <input type="number" name="captcha" required placeholder="Kode" <?= $isLocked ? 'disabled' : '' ?>
                               class="flex-1 px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 focus:ring-4 focus:ring-orange-600/10 outline-none transition-all font-semibold text-sm text-center tracking-[0.2em] disabled:opacity-50">
                        <button type="button" onclick="refreshCaptcha()" <?= $isLocked ? 'disabled' : '' ?> class="p-4 text-gray-400 hover:text-orange-600 transition-colors disabled:opacity-30">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" id="submitBtn" <?= $isLocked ? 'disabled' : '' ?>
                        class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-5 rounded-[24px] shadow-xl shadow-orange-600/20 transition-all transform active:scale-[0.97] hover:-translate-y-1 mt-4 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:translate-y-0">
                    <?= $isLocked ? 'Form Terkunci' : 'Masuk ke Sistem' ?>
                </button>
            </form>

            <div class="mt-10 pt-8 border-t border-gray-50 text-center">
                <p class="text-sm text-gray-500 font-medium italic">
                    Belum terdaftar? <a href="register" class="text-orange-600 font-black hover:text-orange-700 ml-1 underline underline-offset-4">Daftar Disini</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        function refreshCaptcha() {
            document.getElementById('captchaImg').src = 'captcha.php?t=' + new Date().getTime();
        }

        <?php if ($isLocked): ?>
        // Countdown timer buat lock captcha
        let sisaDetik = <?= $sisaDetik ?>;
        const timerEl = document.getElementById('countdownTimer');
        const interval = setInterval(() => {
            sisaDetik--;
            if (sisaDetik <= 0) {
                clearInterval(interval);
                window.location.reload();
                return;
            }
            const m = Math.floor(sisaDetik / 60).toString().padStart(2, '0');
            const s = (sisaDetik % 60).toString().padStart(2, '0');
            if (timerEl) timerEl.textContent = m + ':' + s;
        }, 1000);
        <?php endif; ?>

        <?php if ($swal_type): ?>
            Swal.fire({
                icon: '<?= $swal_type ?>',
                title: '<?= ($swal_type === 'success') ? 'Berhasil!' : 'Oops...' ?>',
                text: '<?= addslashes($swal_msg) ?>',
                showConfirmButton: <?= ($swal_type === 'success') ? 'false' : 'true' ?>,
                timer: <?= ($swal_type === 'success') ? '2000' : 'null' ?>,
                confirmButtonColor: '#ea580c'
            }).then(() => {
                <?php if ($redirect_url): ?>
                    window.location.href = '<?= $redirect_url ?>';
                <?php elseif (!$isLocked): ?>
                    refreshCaptcha();
                <?php endif; ?>
            });
        <?php endif; ?>
    </script>
</body>
</html>