<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();

$userId = $_SESSION['user_id'];

// Ambil data user
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$userData = $stmtUser->fetch();

$sudah_lengkap = profil_lengkap($userData);

// Statistik
$countTotal = $pdo->query("SELECT COUNT(*) FROM catatan_harian WHERE user_id = $userId")->fetchColumn() ?: 0;
$countToday = $pdo->query("SELECT COUNT(*) FROM catatan_harian WHERE user_id = $userId AND DATE(tanggal) = CURDATE()")->fetchColumn() ?: 0;

// Status test
$stmtPreTest = $pdo->prepare("SELECT ht.nilai FROM hasil_test ht JOIN bank_soal bs ON bs.id = ht.bank_soal_id WHERE ht.user_id = ? AND bs.jenis = 'pre_test' ORDER BY ht.created_at DESC LIMIT 1");
$stmtPreTest->execute([$userId]);
$hasilPreTest = $stmtPreTest->fetchColumn();

$stmtPostTest = $pdo->prepare("SELECT ht.nilai FROM hasil_test ht JOIN bank_soal bs ON bs.id = ht.bank_soal_id WHERE ht.user_id = ? AND bs.jenis = 'post_test' ORDER BY ht.created_at DESC LIMIT 1");
$stmtPostTest->execute([$userId]);
$hasilPostTest = $stmtPostTest->fetchColumn();

$adaPreTest  = $pdo->query("SELECT COUNT(*) FROM bank_soal WHERE jenis = 'pre_test' AND status = 'aktif'")->fetchColumn() > 0;
$adaPostTest = $pdo->query("SELECT COUNT(*) FROM bank_soal WHERE jenis = 'post_test' AND status = 'aktif'")->fetchColumn() > 0;

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType    = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        
        /* BANNER: WARNA ORANGE KEMERAHAN (SUNSET ORANGE) */
        .norm-banner {
            width: 100%;
            min-height: 180px;
            /* Gradasi Orange ke Merah Maroon */
            background: linear-gradient(135deg, #f97316 0%, #991b1b 100%); 
            border-radius: 24px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            padding: 30px;
            box-shadow: 0 15px 35px -10px rgba(153, 27, 27, 0.3);
        }

        /* Wrapper foto/ID card di banner */
        .norm-id-box {
            flex-shrink: 0;
            z-index: 10;
        }

        /* Placeholder saat profil belum lengkap */
        .norm-id-box-empty {
            width: 100px;
            height: 130px;
            border-radius: 12px;
            border: 2px dashed rgba(255,255,255,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.6);
        }

        /* Wedge dengan Glassmorphism Soft */
        .norm-wedge {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 250px;
            background: rgba(0, 0, 0, 0.1); 
            backdrop-filter: blur(10px);
            clip-path: polygon(20% 0%, 100% 0%, 100% 100%, 0% 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-end;
            padding-right: 30px;
            z-index: 5;
            border-left: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* MEDIA QUERY UNTUK VERSI MOBILE */
        @media (max-width: 640px) {
            .norm-banner { flex-direction: column; text-align: center; height: auto; padding: 40px 20px 20px 20px; }
            .norm-id-box { margin-bottom: 20px; }
            .norm-id-box-empty { width: 110px; height: 140px; }
            .norm-text { margin-left: 0 !important; margin-bottom: 30px; }
            .norm-wedge { position: relative; width: 100%; height: 70px; clip-path: none; padding-right: 0; align-items: center; backdrop-filter: none; background: rgba(0,0,0,0.2); margin-top: 10px; border-radius: 15px; }
        }
    </style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 lg:p-10 overflow-y-auto">
        <div class="max-w-5xl mx-auto">
            
            <div class="norm-banner mb-10">
                <div class="norm-id-box">
                    <?php if ($sudah_lengkap): ?>
                        <?php
                            $card_data = $userData;
                            $card_size = 'xs';
                            $card_download = false;
                            $card_uid = 'banner';
                            include 'views/includes/id-card.php';
                        ?>
                    <?php else: ?>
                        <a href="profil" class="norm-id-box-empty shadow-xl" title="Lengkapi profil untuk membuat ID Card">
                            <i class="fas fa-id-card text-xl"></i>
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="norm-text ml-8 text-white z-10">
                    <p class="text-[9px] font-black uppercase tracking-[0.2em] text-orange-200 mb-1">Agen BBPOM Mataram</p>
                    <h2 class="text-3xl font-black tracking-tight leading-tight uppercase"><?= htmlspecialchars($userData['nama']) ?></h2>
                    <p class="text-[11px] font-bold text-gray-100 italic mt-1 leading-tight">"Keluarga Sadar Obat dan Makanan Aman"</p>
                </div>

                <div class="norm-wedge">
                    <div class="bg-white p-1 rounded-full mb-2 shadow-lg">
                        <img src="views/bpom.webp" class="w-8 h-8 object-contain">
                    </div>
                    <p class="text-[8px] font-black text-white/40 uppercase tracking-widest leading-none mb-1">ID AGEN</p>
                    <p class="text-xl font-black text-white tracking-widest leading-none"><?= htmlspecialchars($userData['agen_id'] ?: 'AG-001') ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
                <div class="md:col-span-8">
                    <h3 class="text-lg font-black text-gray-900 mb-6 flex items-center gap-2">
                        <span class="w-6 h-1 bg-red-800 rounded-full"></span>
                        Ringkasan Performa
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 md:gap-6">
                        <a href="riwayat" class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm flex items-center gap-5 group hover:border-orange-500 transition-all">
                            <div class="w-12 h-12 bg-orange-50 rounded-xl flex items-center justify-center text-orange-600 shrink-0">
                                <i class="fas fa-file-alt text-lg"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">Total Laporan</p>
                                <p class="text-3xl font-black text-gray-900 mt-1"><?= $countTotal ?></p>
                                <p class="text-[10px] text-orange-600 font-bold mt-1">Lihat detail <i class="fas fa-arrow-right text-[9px]"></i></p>
                            </div>
                        </a>

                        <a href="riwayat?hari_ini=1" class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm flex items-center gap-5 group hover:border-red-800 transition-all">
                            <div class="w-12 h-12 bg-red-50 rounded-xl flex items-center justify-center text-red-800 shrink-0">
                                <i class="fas fa-bolt text-lg"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">Aksi Hari Ini</p>
                                <p class="text-3xl font-black text-gray-900 mt-1"><?= $countToday ?></p>
                                <p class="text-[10px] text-red-700 font-bold mt-1">Lihat detail <i class="fas fa-arrow-right text-[9px]"></i></p>
                            </div>
                        </a>

                    </div>

                    <?php if ($adaPreTest || $adaPostTest): ?>
                    <h3 class="text-lg font-black text-gray-900 mt-8 mb-4 flex items-center gap-2">
                        <span class="w-6 h-1 bg-orange-500 rounded-full"></span>
                        Status Test
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <?php if ($adaPreTest): ?>
                        <div class="bg-white p-5 rounded-2xl border <?= $hasilPreTest !== false ? 'border-green-200' : 'border-orange-200' ?> shadow-sm">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-9 h-9 rounded-xl bg-orange-100 text-orange-600 flex items-center justify-center">
                                    <i class="fas fa-clipboard-list text-sm"></i>
                                </div>
                                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500">Pre-Test</p>
                            </div>
                            <?php if ($hasilPreTest !== false): ?>
                                <p class="text-2xl font-black text-gray-900"><?= number_format($hasilPreTest, 0) ?><span class="text-sm text-gray-400">/100</span></p>
                                <p class="text-[10px] text-green-600 font-bold mt-1"><i class="fas fa-check-circle mr-1"></i>Sudah dikerjakan</p>
                                <a href="hasil-test" class="inline-block mt-2 text-[10px] font-black text-orange-600 uppercase tracking-widest">Lihat hasil →</a>
                            <?php else: ?>
                                <p class="text-sm font-bold text-gray-400 mb-2">Belum dikerjakan</p>
                                <a href="pre-test" class="inline-block px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">Kerjakan</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($adaPostTest): ?>
                        <div class="bg-white p-5 rounded-2xl border <?= $hasilPostTest !== false ? 'border-green-200' : 'border-red-200' ?> shadow-sm">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-9 h-9 rounded-xl bg-red-100 text-red-700 flex items-center justify-center">
                                    <i class="fas fa-clipboard-check text-sm"></i>
                                </div>
                                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500">Post-Test</p>
                            </div>
                            <?php if ($hasilPostTest !== false): ?>
                                <p class="text-2xl font-black text-gray-900"><?= number_format($hasilPostTest, 0) ?><span class="text-sm text-gray-400">/100</span></p>
                                <p class="text-[10px] text-green-600 font-bold mt-1"><i class="fas fa-check-circle mr-1"></i>Sudah dikerjakan</p>
                                <a href="hasil-test" class="inline-block mt-2 text-[10px] font-black text-red-700 uppercase tracking-widest">Lihat hasil →</a>
                            <?php else: ?>
                                <p class="text-sm font-bold text-gray-400 mb-2">Belum dikerjakan</p>
                                <a href="post-test" class="inline-block px-4 py-2 bg-red-800 hover:bg-red-900 text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">Kerjakan</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="md:col-span-4">
                    <a href="tambah-catatan" class="bg-red-800 hover:bg-black text-white p-6 md:p-8 rounded-3xl flex flex-col items-center justify-center text-center shadow-lg transition-all active:scale-95 group min-h-[140px]">
                        <div class="w-12 h-12 bg-white/10 rounded-full flex items-center justify-center mb-3">
                            <i class="fas fa-plus text-white text-xl"></i>
                        </div>
                        <h4 class="text-white font-black text-sm uppercase tracking-widest">Tambah Laporan</h4>
                    </a>
                </div>
            </div>

            <footer class="mt-12 py-6 border-t border-gray-100 flex flex-col sm:flex-row justify-between items-center gap-2 opacity-30">
                <p class="text-[9px] font-black uppercase tracking-[0.4em] text-gray-400">&copy; 2026 BBPOM MATARAM</p>
            </footer>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if ($flashMessage): ?>
    <script>
    Swal.fire({
        icon: '<?= $flashType ?: 'info' ?>',
        title: '<?= $flashType === 'error' ? 'Oops!' : 'Info' ?>',
        text: '<?= addslashes($flashMessage) ?>',
        confirmButtonColor: '#991b1b',
        customClass: { popup: 'rounded-[32px]' }
    });
    </script>
    <?php endif; ?>
</body>
</html>