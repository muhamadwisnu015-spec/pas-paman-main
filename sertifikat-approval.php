
<?php
require_once 'config/database.php';
require_once 'core/auth.php';

// Proteksi: Hanya Kepala Balai yang boleh masuk
cek_login();
cek_kabalai();

// Perlu upload tanda tangan dulu sebelum bisa menyetujui sertifikat apapun
$stmtTtd = $pdo->prepare("SELECT tanda_tangan FROM users WHERE id = ?");
$stmtTtd->execute([$_SESSION['user_id']]);
$ttdTerpasang = (bool) $stmtTtd->fetchColumn();

$msg = $_GET['msg'] ?? '';

// Daftar yang masih menunggu tanda tangan
$stmtMenunggu = $pdo->query("
    SELECT ht.id, ht.nilai, ht.jawaban_benar, ht.total_pertanyaan, ht.waktu_selesai,
           u.nama as nama_agen, u.agen_id, u.nama_instansi
    FROM hasil_test ht
    JOIN bank_soal bs ON bs.id = ht.bank_soal_id
    JOIN users u ON u.id = ht.user_id
    WHERE bs.jenis = 'post_test' AND ht.nilai >= 70 AND ht.status_sertifikat = 'menunggu_ttd'
    ORDER BY ht.waktu_selesai ASC
");
$daftarMenunggu = $stmtMenunggu->fetchAll();

// Riwayat yang sudah ditandatangani (20 terbaru)
$stmtDisetujui = $pdo->query("
    SELECT ht.id, ht.nilai, ht.signed_at, u.nama as nama_agen, u.agen_id
    FROM hasil_test ht
    JOIN bank_soal bs ON bs.id = ht.bank_soal_id
    JOIN users u ON u.id = ht.user_id
    WHERE bs.jenis = 'post_test' AND ht.nilai >= 70 AND ht.status_sertifikat = 'disetujui'
    ORDER BY ht.signed_at DESC
    LIMIT 20
");
$daftarDisetujui = $stmtDisetujui->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Persetujuan Sertifikat | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }</style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-12 overflow-y-auto">
        <div class="max-w-5xl mx-auto">

            <header class="mb-10">
                <h2 class="text-3xl font-black text-gray-900 tracking-tight">Persetujuan Sertifikat</h2>
                <p class="text-gray-400 font-bold text-sm mt-1 italic">Tanda tangani sertifikat kelulusan Post-Test agen secara elektronik</p>
            </header>

            <?php if (!$ttdTerpasang): ?>
            <div class="bg-red-50 text-red-700 p-5 rounded-2xl mb-8 font-bold text-sm border border-red-100 flex items-center gap-3">
                <i class="fas fa-triangle-exclamation"></i>
                <span>Kamu belum mengunggah gambar tanda tangan elektronik. Silakan unggah dulu di halaman <a href="profil" class="underline">Profil</a> sebelum bisa menyetujui sertifikat.</span>
            </div>
            <?php endif; ?>

            <?php if ($msg === 'signed'): ?>
            <div class="bg-orange-50 text-orange-600 p-4 rounded-2xl mb-8 font-bold text-center border border-orange-100">Sertifikat berhasil ditandatangani!</div>
            <?php elseif ($msg === 'error'): ?>
            <div class="bg-red-50 text-red-600 p-4 rounded-2xl mb-8 font-bold text-center border border-red-100">Gagal menandatangani sertifikat. Coba lagi.</div>
            <?php endif; ?>

            <!-- Menunggu Tanda Tangan -->
            <div class="bg-white rounded-[32px] border border-gray-100 shadow-sm overflow-hidden mb-10">
                <div class="px-8 py-6 border-b border-gray-50 flex items-center justify-between">
                    <h3 class="text-xs font-black uppercase tracking-[0.2em] text-gray-500">Menunggu Tanda Tangan (<?= count($daftarMenunggu) ?>)</h3>
                </div>
                <div class="divide-y divide-gray-50">
                    <?php if (count($daftarMenunggu) > 0): ?>
                        <?php foreach ($daftarMenunggu as $item): ?>
                        <div class="px-8 py-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="font-bold text-gray-900 text-sm"><?= htmlspecialchars($item['nama_agen']) ?></p>
                                <p class="text-[11px] text-gray-400 mt-0.5 font-semibold">
                                    ID Agen: <?= htmlspecialchars($item['agen_id'] ?: '-') ?> &middot;
                                    <?= htmlspecialchars($item['nama_instansi'] ?: '-') ?> &middot;
                                    Selesai <?= date('d M Y, H:i', strtotime($item['waktu_selesai'])) ?>
                                </p>
                            </div>
                            <div class="flex items-center gap-4 shrink-0">
                                <div class="text-right">
                                    <p class="text-lg font-black text-orange-600"><?= number_format($item['nilai'], 0) ?></p>
                                    <p class="text-[9px] text-gray-400 font-bold"><?= $item['jawaban_benar'] ?>/<?= $item['total_pertanyaan'] ?> benar</p>
                                </div>
                                <form action="approve-sertifikat" method="GET" onsubmit="return konfirmasiTtd(event, this)">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <button type="submit" <?= $ttdTerpasang ? '' : 'disabled' ?>
                                        class="inline-flex items-center gap-2 px-5 py-3 bg-red-800 hover:bg-black text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed">
                                        <i class="fas fa-signature"></i> Setujui & TTD
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="px-8 py-16 text-center italic text-gray-400 text-sm">Tidak ada sertifikat yang menunggu tanda tangan.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Riwayat Ditandatangani -->
            <div class="bg-white rounded-[32px] border border-gray-100 shadow-sm overflow-hidden">
                <div class="px-8 py-6 border-b border-gray-50">
                    <h3 class="text-xs font-black uppercase tracking-[0.2em] text-gray-500">Riwayat Ditandatangani (<?= count($daftarDisetujui) ?>)</h3>
                </div>
                <div class="divide-y divide-gray-50">
                    <?php if (count($daftarDisetujui) > 0): ?>
                        <?php foreach ($daftarDisetujui as $item): ?>
                        <div class="px-8 py-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($item['nama_agen']) ?></p>
                                <p class="text-[10px] text-gray-400 mt-0.5">
                                    ID Agen: <?= htmlspecialchars($item['agen_id'] ?: '-') ?>
                                    <?php if ($item['signed_at']): ?>
                                    &middot; Ditandatangani <?= date('d M Y, H:i', strtotime($item['signed_at'])) ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="flex items-center gap-4 shrink-0">
                                <div class="text-right">
                                    <p class="text-sm font-black text-gray-900"><?= number_format($item['nilai'], 0) ?></p>
                                    <p class="text-[9px] text-green-600 font-bold uppercase tracking-widest">Sudah TTD</p>
                                </div>
                                <a href="sertifikat?id=<?= (int)$item['id'] ?>"
                                   class="inline-flex items-center gap-2 px-5 py-3 bg-orange-600 hover:bg-orange-700 text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all active:scale-95">
                                    <i class="fas fa-eye"></i> Lihat Sertifikat
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="px-8 py-16 text-center italic text-gray-400 text-sm">Belum ada sertifikat yang ditandatangani.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

    <script>
        function konfirmasiTtd(event, form) {
            event.preventDefault();
            Swal.fire({
                title: 'Tanda tangani sertifikat ini?',
                text: 'Tanda tangan elektronik kamu akan ditempel pada sertifikat dan agen bisa langsung mengunduhnya.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Setujui & TTD',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#991b1b',
                customClass: { popup: 'rounded-[32px]' }
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
            return false;
        }
    </script>
</body>
</html>
