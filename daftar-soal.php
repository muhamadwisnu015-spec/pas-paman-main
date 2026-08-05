<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();
cek_staff_atau_admin();

$message = $_SESSION['flash_message'] ?? '';
$swal_type = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

$daftarSoal = $pdo->query("
    SELECT bs.*, u.nama as pembuat,
        (SELECT COUNT(*) FROM pertanyaan WHERE bank_soal_id = bs.id) as total_soal
    FROM bank_soal bs
    JOIN users u ON bs.created_by = u.id
    ORDER BY bs.tanggal DESC, bs.jenis ASC, bs.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Soal | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }</style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
        <div class="max-w-5xl mx-auto">

            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-10">
                <div>
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight">Kelola Bank Soal</h1>
                    <p class="text-sm text-gray-400 font-medium mt-1">Pre-Test &amp; Post-Test untuk Agen</p>
                </div>
                <a href="tambah-soal" class="inline-flex items-center gap-2 bg-red-800 hover:bg-black text-white text-xs font-black uppercase tracking-widest px-6 py-4 rounded-2xl transition-all shadow-lg">
                    <i class="fas fa-plus"></i> Buat Soal Baru
                </a>
            </div>

            <?php
            // Kelompokkan per tanggal
            $grouped = [];
            foreach ($daftarSoal as $s) {
                $tgl = $s['tanggal'] ?? 'Tanpa Tanggal';
                $grouped[$tgl][] = $s;
            }

            if (empty($grouped)):
            ?>
            <div class="bg-white rounded-3xl border border-dashed border-gray-200 p-10 text-center text-gray-400 italic text-sm">
                Belum ada paket soal. <a href="tambah-soal" class="text-red-800 font-bold not-italic">Buat sekarang</a>.
            </div>
            <?php else: ?>
            <?php foreach ($grouped as $tanggal => $list): ?>
            <div class="mb-10">
                <h2 class="text-xs font-black uppercase tracking-[0.25em] text-gray-400 mb-4 flex items-center gap-3">
                    <span class="w-5 h-1 bg-red-800 rounded-full"></span>
                    <?php
                    if ($tanggal === 'Tanpa Tanggal') {
                        echo 'Tanpa Tanggal';
                    } else {
                        $tglObj = DateTime::createFromFormat('Y-m-d', $tanggal);
                        echo $tglObj ? $tglObj->format('d F Y') : $tanggal;
                        if ($tanggal === date('Y-m-d')) echo ' <span class="ml-2 px-2 py-0.5 bg-green-100 text-green-700 text-[9px] font-black uppercase tracking-widest rounded-lg normal-case">Hari Ini</span>';
                    }
                    ?>
                </h2>
                <div class="space-y-3">
                    <?php foreach ($list as $soal): ?>
                    <?php $isPreTest = ($soal['jenis'] === 'pre_test'); ?>
                    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="flex items-start gap-4 flex-1">
                            <div class="w-10 h-10 rounded-2xl flex items-center justify-center shrink-0 <?= $isPreTest ? 'bg-orange-100 text-orange-600' : 'bg-red-100 text-red-700' ?>">
                                <i class="fas <?= $isPreTest ? 'fa-clipboard-list' : 'fa-clipboard-check' ?> text-sm"></i>
                            </div>
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="px-2 py-0.5 <?= $isPreTest ? 'bg-orange-100 text-orange-600' : 'bg-red-100 text-red-700' ?> text-[9px] font-black uppercase tracking-widest rounded-lg">
                                        <?= $isPreTest ? 'Pre-Test' : 'Post-Test' ?>
                                    </span>
                                    <h3 class="font-black text-gray-900"><?= htmlspecialchars($soal['judul']) ?></h3>
                                    <?php if ($soal['status'] === 'aktif'): ?>
                                    <span class="px-2 py-0.5 bg-green-100 text-green-700 text-[9px] font-black uppercase tracking-widest rounded-lg">Aktif</span>
                                    <?php else: ?>
                                    <span class="px-2 py-0.5 bg-gray-100 text-gray-400 text-[9px] font-black uppercase tracking-widest rounded-lg">Nonaktif</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-gray-500 mt-1"><?= $soal['total_soal'] ?> soal &middot; Dibuat oleh <?= htmlspecialchars($soal['pembuat']) ?></p>
                                <?php if ($soal['deskripsi']): ?>
                                <p class="text-xs text-gray-400 mt-1 italic"><?= htmlspecialchars($soal['deskripsi']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <?php if ($soal['status'] === 'nonaktif'): ?>
                            <a href="aktifkan-soal?id=<?= $soal['id'] ?>"
                               onclick="return confirm('Aktifkan paket soal ini?')"
                               class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-[10px] font-black uppercase tracking-wider rounded-xl transition-all">
                                <i class="fas fa-toggle-on mr-1"></i> Aktifkan
                            </a>
                            <?php else: ?>
                            <a href="aktifkan-soal?id=<?= $soal['id'] ?>&nonaktif=1"
                               onclick="return confirm('Nonaktifkan paket soal ini?')"
                               class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-600 text-[10px] font-black uppercase tracking-wider rounded-xl transition-all">
                                <i class="fas fa-toggle-off mr-1"></i> Nonaktifkan
                            </a>
                            <?php endif; ?>
                            <a href="edit-soal?id=<?= $soal['id'] ?>" class="w-9 h-9 rounded-xl bg-orange-50 hover:bg-orange-100 text-orange-600 flex items-center justify-center transition-all">
                                <i class="fas fa-edit text-sm"></i>
                            </a>
                            <a href="hapus-soal?id=<?= $soal['id'] ?>"
                               onclick="return confirm('Hapus paket soal ini beserta semua soalnya?')"
                               class="w-9 h-9 rounded-xl bg-red-50 hover:bg-red-100 text-red-600 flex items-center justify-center transition-all">
                                <i class="fas fa-trash text-sm"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </main>

    <script>
    <?php if ($swal_type): ?>
    Swal.fire({
        icon: '<?= $swal_type ?>',
        title: '<?= $swal_type === 'success' ? 'Berhasil' : 'Gagal' ?>',
        text: '<?= addslashes($message) ?>',
        confirmButtonColor: '#991b1b',
        customClass: { popup: 'rounded-[32px]' }
    });
    <?php endif; ?>
    </script>
</body>
</html>
