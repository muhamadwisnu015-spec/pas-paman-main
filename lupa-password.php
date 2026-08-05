<?php
require_once 'config/database.php';

// Load PHPMailer secara manual
require 'phpmailer/src/Exception.php';
require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$swal_type = '';
$swal_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    
    $stmt = $pdo->prepare("SELECT id, nama FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $expire = date("Y-m-d H:i:s", strtotime('+1 hour'));

        $update = $pdo->prepare("UPDATE users SET reset_token = ?, token_expire = ? WHERE id = ?");
        $update->execute([$token, $expire, $user['id']]);

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'mail.xlabscloud.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'bensalem@xlabscloud.com'; 
            $mail->Password   = 'YOUR_SMTP_PASSWORD';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
            $mail->Port       = 587;
            $mail->Timeout    = 30; 

            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            $mail->setFrom('bensalem@xlabscloud.com', 'GAS-PAMAN BBPOM');
            $mail->addAddress($email);

            $url = "http://bayus-macbook-air.local/reset-password-aksi?token=$token";
            $mail->isHTML(true);
            $mail->Subject = 'Instruksi Reset Password GAS-PAMAN';
            $mail->Body    = "
                <div style='font-family:sans-serif; padding:30px; border:1px solid #fff5f2; border-radius:20px; max-width:500px; background-color:#fff;'>
                    <div style='text-align:center; margin-bottom:20px;'>
                        <h2 style='color:#991b1b; margin:0;'>Atur Ulang Password</h2>
                    </div>
                    <p>Halo <b>{$user['nama']}</b>,</p>
                    <p>Kami menerima permintaan untuk mengatur ulang kata sandi akun GAS-PAMAN Anda. Silakan klik tombol di bawah ini:</p>
                    <div style='text-align:center; margin:30px 0;'>
                        <a href='$url' style='background-color:#ea580c; color:#ffffff; padding:15px 30px; text-decoration:none; border-radius:12px; font-weight:bold; display:inline-block;'>Reset Password Saya</a>
                    </div>
                    <p style='color:#666; font-size:12px;'>Link ini hanya berlaku selama 1 jam. Jika Anda tidak merasa melakukan permintaan ini, silakan abaikan email ini.</p>
                    <hr style='border:none; border-top:1px solid #eee; margin:20px 0;'>
                    <p style='text-align:center; color:#991b1b; font-weight:bold; font-size:10px; letter-spacing:2px;'>BBPOM DI MATARAM</p>
                </div>";

            $mail->send();
            $swal_type = 'success';
            $swal_msg  = 'Instruksi telah dikirim! Silakan periksa email Anda.';
        } catch (Exception $e) {
            $swal_type = 'error';
            $swal_msg  = "Koneksi SMTP Gagal. (Error: {$mail->ErrorInfo})";
        }
    } else {
        $swal_type = 'error';
        $swal_msg  = 'Alamat email tersebut tidak terdaftar.';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: linear-gradient(135deg, #fff5f2 0%, #fffaf5 100%);
        }
        .swal2-popup { border-radius: 40px !important; }
        
        /* Animasi mengambang untuk logo agar tidak kaku */
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(2deg); }
            100% { transform: translateY(0px) rotate(0deg); }
        }
        .animate-float { animation: float 4s ease-in-out infinite; }

        .logo-mask {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            border: 6px solid white;
            box-shadow: 0 15px 35px rgba(153, 27, 27, 0.15);
            background: white;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-6">

    <div class="w-full max-w-[480px]">
        <div class="bg-white rounded-[60px] shadow-[0_20px_70px_-15px_rgba(153,27,27,0.12)] p-10 md:p-16 border border-orange-50/50 text-center relative overflow-hidden">
            
            <div class="absolute -top-12 -right-12 w-40 h-40 bg-orange-50 rounded-full opacity-40"></div>
            <div class="absolute -bottom-12 -left-12 w-32 h-32 bg-red-50 rounded-full opacity-30"></div>
            
            <div class="flex justify-center relative z-10">
                <div class="logo-mask animate-float">
                    <img src="views/gas-paman.png" alt="GAS-PAMAN" class="w-full h-full object-cover">
                </div>
            </div>
            
            <div class="relative z-10">
                <h2 class="text-3xl font-black text-gray-900 mb-3 tracking-tight">Lupa Password?</h2>
                <p class="text-gray-400 text-sm mb-10 font-medium italic">
                    "Keluarga Sadar Obat dan Makanan Aman"
                </p>
            </div>

            <form action="" method="POST" class="space-y-7 text-left relative z-10">
                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-3 ml-1">Email Terdaftar</label>
                    <div class="relative">
                        <i class="fas fa-envelope absolute left-6 top-1/2 -translate-y-1/2 text-gray-300"></i>
                        <input type="email" name="email" required placeholder="email@kader-gas-paman.id" 
                               class="w-full pl-14 pr-6 py-5 rounded-[24px] bg-gray-50 border border-gray-100 focus:border-orange-600 focus:ring-4 focus:ring-orange-600/10 outline-none transition-all font-semibold text-sm">
                    </div>
                </div>

                <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-5 rounded-[24px] shadow-xl shadow-orange-900/20 transition-all transform active:scale-[0.97] hover:-translate-y-1 uppercase text-xs tracking-widest">
                    Kirim Link Pemulihan
                </button>
                
                <div class="text-center mt-10 border-t border-gray-50 pt-8">
                    <a href="login" class="text-xs font-black text-red-800 hover:text-orange-600 transition-colors uppercase tracking-[0.2em] flex items-center justify-center gap-2">
                        <i class="fas fa-arrow-left"></i> Kembali ke Login
                    </a>
                </div>
            </form>
        </div>
        
        <p class="text-center mt-10 text-gray-400 text-[10px] font-black uppercase tracking-[0.4em] opacity-60">
            &copy; 2026 BBPOM DI MATARAM
        </p>
    </div>

    <script>
        <?php if ($swal_type): ?>
            Swal.fire({
                icon: '<?= $swal_type ?>',
                title: '<?= ($swal_type === 'success') ? 'Berhasil!' : 'Konfirmasi' ?>',
                text: '<?= $swal_msg ?>',
                confirmButtonColor: '#ea580c',
                customClass: {
                    popup: 'rounded-[40px]'
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>