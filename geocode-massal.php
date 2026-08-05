<?php
require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/geocoding.php';

cek_login();
if ($_SESSION['role'] !== 'admin') {
    header("Location: dashboard");
    exit;
}

set_time_limit(0);
$hasilProses = null;
$BATAS_PER_KLIK = 15; // dibatasi biar gak timeout & sopan ke rate limit Nominatim (1 req/detik)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mulai_geocode'])) {
    $diproses = 0; $berhasil = 0; $gagal = 0;

    // 1. Agen yang alamatnya ada tapi belum punya koordinat
    $agenBelum = $pdo->query("
        SELECT id, alamat FROM users
        WHERE role = 'agen' AND alamat IS NOT NULL AND alamat != '' AND latitude IS NULL
        LIMIT $BATAS_PER_KLIK
    ")->fetchAll();

    foreach ($agenBelum as $a) {
        $geo = geocode_alamat($a['alamat']);
        $diproses++;
        if ($geo) {
            $pdo->prepare("UPDATE users SET latitude = ?, longitude = ? WHERE id = ?")
                ->execute([$geo['lat'], $geo['lng'], $a['id']]);
            $berhasil++;
        } else {
            $gagal++;
        }
        sleep(1); // wajib jeda 1 detik antar request ke Nominatim
    }

    // 2. Sisa kuota dipakai buat catatan masyarakat yang belum punya koordinat
    $sisaKuota = $BATAS_PER_KLIK - $diproses;
    if ($sisaKuota > 0) {
        $catatanBelum = $pdo->query("
            SELECT id, COALESCE(NULLIF(alamat,''), lokasi) as alamat_pakai
            FROM catatan_harian
            WHERE status_review = 'approved' AND latitude IS NULL
              AND (COALESCE(NULLIF(alamat,''), lokasi) IS NOT NULL AND COALESCE(NULLIF(alamat,''), lokasi) != '')
            LIMIT $sisaKuota
        ")->fetchAll();

        foreach ($catatanBelum as $c) {
            $geo = geocode_alamat($c['alamat_pakai']);
            $diproses++;
            if ($geo) {
                $pdo->prepare("UPDATE catatan_harian SET latitude = ?, longitude = ? WHERE id = ?")
                    ->execute([$geo['lat'], $geo['lng'], $c['id']]);
                $berhasil++;
            } else {
                $gagal++;
            }
            sleep(1);
        }
    }

    $hasilProses = ['diproses' => $diproses, 'berhasil' => $berhasil, 'gagal' => $gagal];
}

$sisaAgenCount = (int)$pdo->query("
    SELECT COUNT(*) FROM users
    WHERE role = 'agen' AND alamat IS NOT NULL AND alamat != '' AND latitude IS NULL
")->fetchColumn();

$sisaCatatanCount = (int)$pdo->query("
    SELECT COUNT(*) FROM catatan_harian
    WHERE status_review = 'approved' AND latitude IS NULL
      AND (COALESCE(NULLIF(alamat,''), lokasi) IS NOT NULL AND COALESCE(NULLIF(alamat,''), lokasi) != '')
")->fetchColumn();

$sisaTotal = $sisaAgenCount + $sisaCatatanCount;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geocode Data Lama | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style> body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; } </style>
</head>
<body class="bg-gray-50 flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-12 overflow-y-auto">
        <header class="mb-10">
            <a href="admin-dashboard" class="text-[10px] font-black text-gray-400 uppercase tracking-widest hover:text-orange-600"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Dashboard</a>
            <h2 class="text-3xl font-black text-gray-900 tracking-tight mt-3">Geocode Data Lama</h2>
            <p class="text-gray-400 font-bold text-sm mt-1 italic">Cari koordinat otomatis untuk agen & laporan yang belum punya titik di peta</p>
        </header>

        <?php if ($hasilProses): ?>
        <div class="bg-white rounded-[32px] border border-gray-100 shadow-sm p-8 mb-8">
            <h4 class="font-black text-gray-800 uppercase text-xs tracking-[0.2em] mb-4">Hasil Proses Barusan</h4>
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-gray-50 rounded-2xl p-5 text-center">
                    <p class="text-2xl font-black text-gray-900"><?= $hasilProses['diproses'] ?></p>
                    <p class="text-[9px] font-black text-gray-400 uppercase mt-1">Diproses</p>
                </div>
                <div class="bg-orange-50 rounded-2xl p-5 text-center">
                    <p class="text-2xl font-black text-orange-600"><?= $hasilProses['berhasil'] ?></p>
                    <p class="text-[9px] font-black text-orange-600 uppercase mt-1">Berhasil</p>
                </div>
                <div class="bg-red-50 rounded-2xl p-5 text-center">
                    <p class="text-2xl font-black text-red-700"><?= $hasilProses['gagal'] ?></p>
                    <p class="text-[9px] font-black text-red-700 uppercase mt-1">Gagal / Tidak Dikenali</p>
                </div>
            </div>
            <?php if ($hasilProses['gagal'] > 0): ?>
            <p class="text-xs text-gray-400 font-semibold mt-4 italic">Yang gagal biasanya karena teks alamatnya terlalu umum/gak lengkap. Titiknya bisa digeser manual langsung di peta dashboard admin.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-[32px] border border-gray-100 shadow-sm p-8 md:p-10">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-12 h-12 bg-red-800 rounded-2xl flex items-center justify-center text-white shadow-lg">
                    <i class="fas fa-map-marker-alt text-xl"></i>
                </div>
                <div>
                    <h3 class="font-black text-xl text-gray-900">Sisa Data Belum Ada Koordinat</h3>
                    <p class="text-xs text-gray-400 font-semibold">Total: <span class="text-red-800 font-black"><?= $sisaTotal ?></span> data (<?= $sisaAgenCount ?> agen, <?= $sisaCatatanCount ?> laporan masyarakat)</p>
                </div>
            </div>

            <?php if ($sisaTotal > 0): ?>
            <form method="POST" onsubmit="document.getElementById('btnProses').disabled = true; document.getElementById('btnProses').innerText = 'Sedang memproses, jangan tutup halaman...';">
                <button type="submit" id="btnProses" name="mulai_geocode" class="w-full bg-red-800 hover:bg-black text-white font-black py-5 rounded-[22px] shadow-xl uppercase text-[11px] tracking-widest transition-all">
                    Proses <?= min($BATAS_PER_KLIK, $sisaTotal) ?> Data Berikutnya
                </button>
                <p class="text-[11px] text-gray-400 font-semibold mt-4 text-center italic">
                    Diproses <?= $BATAS_PER_KLIK ?> data per klik (butuh sekitar <?= $BATAS_PER_KLIK ?> detik) supaya gak kena limit dari layanan pencari koordinat gratisnya.
                    Kalau datanya banyak, klik tombol ini berkali-kali sampai sisanya 0.
                </p>
            </form>
            <?php else: ?>
            <div class="text-center py-8">
                <i class="fas fa-check-circle text-5xl text-orange-500 mb-4"></i>
                <p class="font-bold text-gray-700">Semua data udah punya koordinat!</p>
                <p class="text-xs text-gray-400 mt-1">Cek peta di <a href="admin-dashboard" class="text-orange-600 font-black underline">dashboard admin</a> buat lihat hasilnya.</p>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
