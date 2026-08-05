
<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();

if ($_SESSION['role'] !== 'agen') {
    header("Location: admin-dashboard");
    exit;
}

$userId = $_SESSION['user_id'];

// Flash data dari submit
$flashNilai   = $_SESSION['flash_nilai'] ?? null;
$flashBenar   = $_SESSION['flash_benar'] ?? null;
$flashTotal   = $_SESSION['flash_total'] ?? null;
$flashJenis   = $_SESSION['flash_jenis'] ?? null;
$flashType    = $_SESSION['flash_type'] ?? '';
$flashMessage = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_nilai'], $_SESSION['flash_benar'], $_SESSION['flash_total'],
      $_SESSION['flash_jenis'], $_SESSION['flash_type'], $_SESSION['flash_message']);

// Ambil semua hasil test milik agen ini
$stmtHasil = $pdo->prepare("
    SELECT ht.*, bs.judul, bs.jenis
    FROM hasil_test ht
    JOIN bank_soal bs ON bs.id = ht.bank_soal_id
    WHERE ht.user_id = ?
    ORDER BY ht.created_at DESC
");
$stmtHasil->execute([$userId]);
$hasilList = $stmtHasil->fetchAll();

// Pisah per jenis untuk tampilan ringkasan
$hasilPerJenis = ['pre_test' => null, 'post_test' => null];
foreach ($hasilList as $h) {
    if (!$hasilPerJenis[$h['jenis']]) {
        $hasilPerJenis[$h['jenis']] = $h;
    }
}

function nilaiBadge($nilai) {
    if ($nilai >= 80) return ['bg-green-100 text-green-700', 'Sangat Baik'];
    if ($nilai >= 60) return ['bg-orange-100 text-orange-700', 'Cukup'];
    return ['bg-red-100 text-red-700', 'Perlu Belajar'];
}

$hasilPostTest = null;
foreach ($hasilList as $hasil) {
    if ($hasil['jenis'] === 'post_test' && $hasil['nilai'] >= 70) {
        $hasilPostTest = $hasil;
        break;
    }
}
$lulusPostTest = $hasilPostTest !== null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Test | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }</style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
        <div class="max-w-3xl mx-auto">

            <div class="mb-10">
                <h1 class="text-2xl font-black text-gray-900 tracking-tight">Hasil Test Saya</h1>
                <p class="text-sm text-gray-400 font-medium mt-1">Rekap nilai Pre-Test &amp; Post-Test</p>
            </div>

            <!-- Kartu Ringkasan -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-10">
                <?php
                $jenisInfo = [
                    'pre_test'  => ['Pre-Test', 'fa-clipboard-list', 'orange'],
                    'post_test' => ['Post-Test', 'fa-clipboard-check', 'red'],
                ];
                foreach ($jenisInfo as $jenis => [$label, $icon, $color]):
                    $hasil = $hasilPerJenis[$jenis];
                ?>
                <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-2xl bg-<?= $color ?>-100 text-<?= $color ?>-700 flex items-center justify-center">
                            <i class="fas <?= $icon ?> text-sm"></i>
                        </div>
                        <p class="text-xs font-black uppercase tracking-widest text-gray-500"><?= $label ?></p>
                    </div>
                    <?php if ($hasil): ?>
                        <?php [$cls, $ket] = nilaiBadge($hasil['nilai']); ?>
                        <p class="text-4xl font-black text-gray-900"><?= number_format($hasil['nilai'], 0) ?><span class="text-lg text-gray-400">/100</span></p>
                        <p class="text-xs text-gray-500 mt-1"><?= $hasil['jawaban_benar'] ?>/<?= $hasil['total_pertanyaan'] ?> benar</p>
                        <span class="mt-3 inline-block px-3 py-1 rounded-xl text-[9px] font-black uppercase tracking-widest <?= $cls ?>"><?= $ket ?></span>
                        <a href="detail-hasil-test?id=<?= $hasil['id'] ?>" class="mt-3 flex items-center gap-1.5 text-[10px] font-black text-gray-400 hover:text-red-800 uppercase tracking-widest transition-colors">
                            <i class="fas fa-search text-xs"></i> Lihat Pembahasan
                        </a>
                        <?php if ($jenis === 'post_test' && $hasil['nilai'] >= 70): ?>
                            <?php if ($hasil['status_sertifikat'] === 'disetujui'): ?>
                            <a href="sertifikat?id=<?= $hasil['id'] ?>" class="mt-2 flex items-center gap-1.5 text-[10px] font-black text-<?= $color ?>-700 hover:opacity-70 uppercase tracking-widest transition-colors">
                                <i class="fas fa-certificate text-xs"></i> Unduh Sertifikat
                            </a>
                            <?php else: ?>
                            <p class="mt-2 flex items-center gap-1.5 text-[10px] font-black text-gray-400 uppercase tracking-widest">
                                <i class="fas fa-hourglass-half text-xs"></i> Menunggu TTD Kepala Balai
                            </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-2xl font-black text-gray-300">—</p>
                        <p class="text-xs text-gray-400 mt-1">Belum dikerjakan</p>
                        <a href="<?= $jenis === 'pre_test' ? 'pre-test' : 'post-test' ?>"
                           class="mt-3 inline-block px-4 py-2 bg-<?= $color ?>-800 text-white rounded-xl text-[10px] font-black uppercase tracking-widest hover:opacity-80 transition-opacity">
                            Kerjakan Sekarang
                        </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($lulusPostTest): ?>
            <?php $sudahDitandatangani = $hasilPostTest['status_sertifikat'] === 'disetujui'; ?>
            <div class="bg-gradient-to-r from-orange-500 to-red-700 rounded-3xl p-6 md:p-8 mb-10 text-white flex flex-col sm:flex-row items-center justify-between gap-6 shadow-lg">
                <div class="flex items-center gap-4 text-center sm:text-left">
                    <div class="w-14 h-14 bg-white/15 rounded-2xl flex items-center justify-center shrink-0 mx-auto sm:mx-0">
                        <i class="fas fa-award text-2xl"></i>
                    </div>
                    <div>
                        <p class="font-black text-lg">Selamat, Kamu Lulus!</p>
                        <?php if ($sudahDitandatangani): ?>
                        <p class="text-sm text-white/80 font-medium mt-0.5">Nilai Post-Test kamu <?= number_format($hasilPostTest['nilai'], 0) ?>/100, sertifikat kelulusan sudah bisa diunduh.</p>
                        <?php else: ?>
                        <p class="text-sm text-white/80 font-medium mt-0.5">Nilai Post-Test kamu <?= number_format($hasilPostTest['nilai'], 0) ?>/100. Sertifikat sedang menunggu tanda tangan elektronik dari Kepala Balai sebelum bisa diunduh.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($sudahDitandatangani): ?>
                <a href="sertifikat?id=<?= $hasilPostTest['id'] ?>"
                   class="shrink-0 inline-flex items-center gap-2 px-6 py-3 bg-white text-red-800 font-black text-[11px] uppercase tracking-widest rounded-2xl shadow-lg hover:bg-gray-100 transition-all active:scale-95">
                    <i class="fas fa-certificate"></i> Download Sertifikat
                </a>
                <?php else: ?>
                <div class="shrink-0 inline-flex items-center gap-2 px-6 py-3 bg-white/15 text-white font-black text-[11px] uppercase tracking-widest rounded-2xl">
                    <i class="fas fa-hourglass-half"></i> Menunggu TTD
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Riwayat Lengkap -->
            <?php if (!empty($hasilList)): ?>
            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-50">
                    <h3 class="text-xs font-black uppercase tracking-[0.2em] text-gray-400">Riwayat Pengerjaan</h3>
                </div>
                <div class="divide-y divide-gray-50">
                    <?php foreach ($hasilList as $h): ?>
                    <?php [$cls, $ket] = nilaiBadge($h['nilai']); ?>
                    <div class="px-6 py-5 flex items-center justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-gray-900 text-sm truncate"><?= htmlspecialchars($h['judul']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-0.5">
                                <?= $h['jenis'] === 'pre_test' ? 'Pre-Test' : 'Post-Test' ?> &middot;
                                <?= date('d M Y, H:i', strtotime($h['waktu_selesai'])) ?>
                            </p>
                        </div>
                        <div class="flex items-center gap-4 shrink-0">
                            <div class="text-right">
                                <p class="text-xl font-black text-gray-900"><?= number_format($h['nilai'], 0) ?></p>
                                <span class="text-[9px] font-black px-2 py-0.5 rounded-lg <?= $cls ?>"><?= $ket ?></span>
                            </div>
                            <a href="detail-hasil-test?id=<?= $h['id'] ?>"
                               class="w-9 h-9 rounded-xl bg-red-50 hover:bg-red-100 text-red-700 flex items-center justify-center transition-all shrink-0" title="Lihat Detail & Pembahasan">
                                <i class="fas fa-search text-sm"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-3xl border border-dashed border-gray-200 p-12 text-center text-gray-400">
                <i class="fas fa-clipboard text-4xl mb-4 opacity-30"></i>
                <p class="text-sm italic">Kamu belum mengerjakan test apapun.</p>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <script>
    <?php if ($flashType === 'success' && $flashNilai !== null): ?>
    Swal.fire({
        icon: 'success',
        title: 'Test Selesai!',
        html: `<div class="text-center">
            <p class="text-4xl font-bold text-gray-900 my-2"><?= number_format($flashNilai, 0) ?><span class="text-gray-400">/100</span></p>
            <p class="text-sm text-gray-500"><?= $flashBenar ?> dari <?= $flashTotal ?> jawaban benar</p>
        </div>`,
        confirmButtonColor: '#991b1b',
        confirmButtonText: 'Lihat Hasil',
        customClass: { popup: 'rounded-[32px]' }
    });
    <?php elseif ($flashType && $flashMessage): ?>
    Swal.fire({
        icon: '<?= $flashType ?>',
        title: '<?= $flashType === 'info' ? 'Info' : ($flashType === 'error' ? 'Gagal' : 'Berhasil') ?>',
        text: '<?= addslashes($flashMessage) ?>',
        confirmButtonColor: '#991b1b',
        customClass: { popup: 'rounded-[32px]' }
    });
    <?php endif; ?>
    </script>
</body>
</html>