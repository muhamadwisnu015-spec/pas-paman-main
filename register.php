<?php
require_once 'config/database.php';
session_start();

// Redirect jika sudah login
if (isset($_SESSION['user_id'])) {
    $dashboard_per_role = ['admin' => 'admin-dashboard', 'kabalai' => 'kabalai-dashboard'];
    header("Location: " . ($dashboard_per_role[$_SESSION['role']] ?? 'dashboard'));
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama          = htmlspecialchars($_POST['nama']);
    $email         = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $jenis_kelamin = $_POST['jenis_kelamin'];
    $usia          = filter_var($_POST['usia'], FILTER_SANITIZE_NUMBER_INT);
    $nama_instansi = htmlspecialchars($_POST['nama_instansi']);
    $nomor_hp      = htmlspecialchars($_POST['nomor_hp']);
    $password      = $_POST['password'];
    $konfirmasi    = $_POST['konfirmasi'];

    try {
        if ($password !== $konfirmasi) throw new Exception("Konfirmasi password tidak cocok!");
        if (strlen($password) < 6) throw new Exception("Password minimal 6 karakter!");

        $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $checkEmail->execute([$email]);
        if ($checkEmail->fetch()) throw new Exception("Email sudah terdaftar.");

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $sql = "INSERT INTO users (nama, email, password, role, jenis_kelamin, usia, nama_instansi, nomor_hp, foto_profil, status) 
                VALUES (?, ?, ?, 'agen', ?, ?, ?, ?, 'default.png', 'aktif')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nama, $email, $hashed_password, $jenis_kelamin, $usia, $nama_instansi, $nomor_hp]);

        $message = "<div class='bg-orange-100 text-orange-700 p-4 rounded-2xl mb-6 font-bold text-center border border-orange-200 animate-pulse'>Registrasi Berhasil! Silakan Login.</div>";
    } catch (Exception $e) {
        $message = "<div class='bg-red-100 text-red-700 p-4 rounded-2xl mb-6 font-bold text-center border border-red-200'>" . $e->getMessage() . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Kader | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #fff5f2 0%, #fffaf5 100%);
        }
        .logo-mask {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid white;
            box-shadow: 0 10px 25px rgba(153, 27, 27, 0.2);
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4 md:p-12">

    <div class="w-full max-w-[750px] my-10">
        <div class="bg-white rounded-[50px] shadow-2xl shadow-red-900/10 p-8 md:p-14 border border-orange-50 relative overflow-hidden">
            <div class="absolute -bottom-16 -left-16 w-48 h-48 bg-red-50 rounded-full opacity-60"></div>
            
            <div class="text-center mb-12 relative z-10">
                <div class="flex justify-center mb-6">
                    <div class="logo-mask bg-white">
                        <img src="views/gas-paman.png" alt="GAS-PAMAN" class="w-full h-full object-cover">
                    </div>
                </div>
                <h1 class="text-3xl md:text-4xl font-black text-gray-900 tracking-tight leading-none">Gabung Kader <span class="text-red-800 italic">GAS-PAMAN</span></h1>
                <p class="text-gray-400 text-sm mt-3 font-medium italic">"Edukasi Obat dan Makanan Aman di Komunitas"</p>
            </div>

            <?= $message ?>

            <form action="" method="POST" class="space-y-7 relative z-10">
                
                <!-- Nama & Email -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-7">
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Nama Lengkap</label>
                        <div class="relative">
                            <i class="fas fa-user absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                            <input type="text" name="nama" required placeholder="Nama Lengkap Sesuai KTP"
                                   class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 focus:ring-4 focus:ring-orange-600/10 outline-none transition-all font-semibold text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Alamat Email</label>
                        <div class="relative">
                            <i class="fas fa-envelope absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                            <input type="email" name="email" required placeholder="contoh@mail.com"
                                   class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 focus:ring-4 focus:ring-orange-600/10 outline-none transition-all font-semibold text-sm">
                        </div>
                    </div>
                </div>

                <!-- Instansi (full width) -->
                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Instansi / Universitas</label>
                    <div class="relative">
                        <i class="fas fa-building absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                        <input type="text" name="nama_instansi" required placeholder="Asal Instansi Anda"
                               class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 focus:ring-4 focus:ring-orange-600/10 outline-none transition-all font-semibold text-sm">
                    </div>
                </div>

                <!-- WhatsApp, Usia, Gender -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-7">
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">No. WhatsApp</label>
                        <div class="relative">
                            <i class="fab fa-whatsapp absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                            <input type="number" name="nomor_hp" required placeholder="0812..."
                                   class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 focus:ring-4 focus:ring-orange-600/10 outline-none transition-all font-semibold text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Usia</label>
                        <div class="relative">
                            <i class="fas fa-birthday-cake absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                            <input type="number" name="usia" required placeholder="Thn"
                                   class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 focus:ring-4 focus:ring-orange-600/10 outline-none transition-all font-semibold text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Gender</label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="flex items-center justify-center py-4 border rounded-2xl cursor-pointer transition-all bg-gray-50 border-gray-100 text-gray-400 hover:bg-orange-50/50">
                                <input type="radio" name="jenis_kelamin" value="Pria" class="hidden peer" required>
                                <span class="text-[10px] font-black uppercase">Pria</span>
                            </label>
                            <label class="flex items-center justify-center py-4 border rounded-2xl cursor-pointer transition-all bg-gray-50 border-gray-100 text-gray-400 hover:bg-orange-50/50">
                                <input type="radio" name="jenis_kelamin" value="Wanita" class="hidden peer">
                                <span class="text-[10px] font-black uppercase">Wanita</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Password -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-7">
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Password</label>
                        <div class="relative">
                            <i class="fas fa-lock absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                            <input type="password" name="password" required placeholder="Minimal 6 karakter"
                                   class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-red-800 focus:ring-4 focus:ring-red-800/10 outline-none transition-all text-sm font-semibold">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Ulangi Password</label>
                        <div class="relative">
                            <i class="fas fa-shield-alt absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
                            <input type="password" name="konfirmasi" required placeholder="Konfirmasi sandi"
                                   class="w-full pl-12 pr-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-red-800 focus:ring-4 focus:ring-red-800/10 outline-none transition-all text-sm font-semibold">
                        </div>
                    </div>
                </div>

                <button type="submit" 
                        class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-6 rounded-[32px] shadow-xl shadow-orange-900/20 transition-all transform active:scale-[0.97] hover:-translate-y-1 mt-6 uppercase tracking-widest text-xs">
                    Daftar Sebagai Kader
                </button>

                <div class="mt-8 text-center border-t border-gray-50 pt-8">
                    <p class="text-sm font-medium text-gray-500">
                        Sudah punya akun? 
                        <a href="login" class="text-red-800 font-black hover:text-orange-600 ml-1 underline underline-offset-4 transition-colors">Masuk Sekarang</a>
                    </p>
                </div>
            </form>
        </div>

        <div class="text-center mt-12 mb-10">
            <p class="text-gray-400 text-[10px] font-black uppercase tracking-[0.3em]">
                &copy; 2026 BBPOM DI MATARAM
            </p>
        </div>
    </div>

    <script>
        const radios = document.querySelectorAll('input[type="radio"]');
        radios.forEach(radio => {
            radio.addEventListener('change', () => {
                radios.forEach(r => {
                    r.parentElement.classList.remove('bg-orange-50', 'border-orange-500', 'text-orange-700', 'font-bold');
                    r.parentElement.classList.add('bg-gray-50', 'border-gray-100', 'text-gray-400');
                });
                if (radio.checked) {
                    radio.parentElement.classList.add('bg-orange-50', 'border-orange-500', 'text-orange-700', 'font-bold');
                    radio.parentElement.classList.remove('bg-gray-50', 'border-gray-200', 'text-gray-400');
                }
            });
        });
    </script>
</body>
</html>