<?php
require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/log_laporan.php';
cek_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Admin & Kabalai: semua laporan. Agen: hanya miliknya.
if (in_array($role, ['admin', 'kabalai'], true)) {
    $stmt = $pdo->prepare("SELECT ch.*, u.nama as nama_agen FROM catatan_harian ch JOIN users u ON ch.user_id = u.id WHERE ch.id = ?");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("SELECT *, NULL as nama_agen FROM catatan_harian WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user_id]);
}

$detail = $stmt->fetch();

// Redirect jika data tidak ditemukan
if (!$detail) {
    header("Location: riwayat");
    exit;
}

$stmtFile = $pdo->prepare("SELECT * FROM catatan_files WHERE catatan_id = ?");
$stmtFile->execute([$id]);
$files = $stmtFile->fetchAll();

$lampiranHasil = $detail['lampiran_hasil_test'] ?? null;
$msgNilai = '';
$msgRevisi = '';

// Pastikan kolom catatan_revisi ada
try {
    $cols = $pdo->query("SHOW COLUMNS FROM catatan_harian")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('catatan_revisi', $cols, true)) {
        $pdo->exec("ALTER TABLE catatan_harian ADD COLUMN catatan_revisi TEXT NULL");
    }
} catch (Throwable $e) { /* ignore */ }

// Admin minta revisi / balasan ke agen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['minta_revisi']) && $role === 'admin') {
    $pesan = trim($_POST['pesan_revisi'] ?? '');
    try {
        if ($pesan === '' || mb_strlen($pesan) < 5) {
            throw new Exception('Isi pesan revisi minimal 5 karakter agar agen paham apa yang harus diperbaiki.');
        }
        if (!in_array($detail['status_review'], ['pending', 'revisi'], true)) {
            throw new Exception('Laporan yang sudah disetujui tidak bisa diminta revisi.');
        }
        $upd = $pdo->prepare("UPDATE catatan_harian SET status_review = 'revisi', catatan_revisi = ? WHERE id = ?");
        $upd->execute([$pesan, $id]);
        if (function_exists('log_laporan')) {
            log_laporan($pdo, (int)$user_id, 'revisi', $id, 'Minta revisi: ' . mb_substr($pesan, 0, 200));
        }
        // refresh
        $stmt->execute([$id]);
        $detail = $stmt->fetch();
        $msgRevisi = 'ok';
    } catch (Exception $e) {
        $msgRevisi = $e->getMessage();
    }
}

// Agen (pemilik) boleh update nilai pre/post masyarakat di halaman detail
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_nilai_masyarakat']) && $role === 'agen' && (int)$detail['user_id'] === (int)$user_id) {
    $nPre  = filter_var($_POST['nilai_pre_test'] ?? null, FILTER_VALIDATE_FLOAT);
    $nPost = filter_var($_POST['nilai_post_test'] ?? null, FILTER_VALIDATE_FLOAT);
    try {
        if ($nPre === false || $nPost === false || $nPre < 0 || $nPre > 100 || $nPost < 0 || $nPost > 100) {
            throw new Exception('Nilai pre-test dan post-test harus angka 0–100.');
        }
        $upd = $pdo->prepare("UPDATE catatan_harian SET nilai_pre_test = ?, nilai_post_test = ? WHERE id = ? AND user_id = ?");
        $upd->execute([$nPre, $nPost, $id, $user_id]);
        $msgNilai = 'ok';
        // refresh
        $stmt->execute(in_array($role, ['admin', 'kabalai'], true) ? [$id] : [$id, $user_id]);
        $detail = $stmt->fetch();
    } catch (Exception $e) {
        $msgNilai = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Laporan | BBPOM Diary</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .swal2-popup { border-radius: 40px !important; }
    </style>
</head>
<body class="bg-gray-50 flex flex-col md:flex-row min-h-screen">
    
    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-12 overflow-y-auto">
        <div class="max-w-4xl mx-auto">
            
            <div class="flex flex-col md:flex-row md:items-center justify-between mb-6 gap-4">
                <a href="riwayat" class="inline-flex items-center text-red-800 font-black text-xs uppercase tracking-widest hover:bg-orange-50 px-5 py-3 rounded-2xl transition-all w-fit border border-transparent hover:border-orange-100">
                    <i class="fas fa-arrow-left mr-2"></i> Kembali
                </a>
                
                <?php 
                $status = $detail['status_review'];
                $badge = $status === 'approved' ? 'bg-orange-100 text-orange-700 border-orange-200' : ($status === 'pending' ? 'bg-yellow-50 text-yellow-600 border-yellow-100' : 'bg-red-50 text-red-700 border-red-100');
                $labelStatus = $status === 'approved' ? 'Disetujui' : ($status === 'revisi' ? 'Perlu Revisi' : 'Menunggu Review');
                ?>
                <div class="flex items-center gap-3 flex-wrap">
                    <?php if ($role === 'agen' && in_array($status, ['pending', 'revisi'], true)): ?>
                    <a href="edit-catatan?id=<?= (int)$detail['id'] ?>"
                       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white text-[10px] font-black uppercase tracking-widest shadow-md transition-all">
                        <i class="fas fa-pen"></i> <?= $status === 'revisi' ? 'Perbaiki Laporan' : 'Edit Laporan' ?>
                    </a>
                    <?php endif; ?>
                    <?php
                    $waDetail = wa_chat_url($detail['no_hp'] ?? '');
                    if ($waDetail && in_array($role, ['admin', 'kabalai'], true)):
                    ?>
                    <a href="<?= htmlspecialchars($waDetail) ?>" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-green-500 hover:bg-green-600 text-white text-[10px] font-black uppercase tracking-widest shadow-md transition-all">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </a>
                    <?php endif; ?>
                    <div class="flex items-center space-x-3 <?= $badge ?> px-6 py-2.5 rounded-2xl border shadow-sm">
                        <span class="w-2 h-2 rounded-full bg-current animate-pulse"></span>
                        <span class="text-[10px] font-black uppercase tracking-[0.2em]">Status: <?= $labelStatus ?></span>
                    </div>
                </div>
            </div>

            
            
            <?php if ($msgRevisi === 'ok'): ?>
            <div class="mb-6 px-5 py-4 rounded-2xl bg-green-50 border border-green-100 text-green-700 text-sm font-bold">
                <i class="fas fa-check-circle mr-1"></i> Pesan revisi terkirim ke agen. Status laporan: Perlu Revisi.
            </div>
            <?php elseif ($msgRevisi): ?>
            <div class="mb-6 px-5 py-4 rounded-2xl bg-red-50 border border-red-100 text-red-700 text-sm font-bold">
                <?= htmlspecialchars($msgRevisi) ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($detail['catatan_revisi'])): ?>
            <div class="mb-6 rounded-[28px] border border-amber-200 bg-amber-50 p-6 md:p-8">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
                        <i class="fas fa-reply text-lg"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-black uppercase tracking-[0.2em] text-amber-700 mb-1">
                            <?= $status === 'revisi' ? 'Balasan Admin — Perlu Diperbaiki' : 'Catatan Revisi dari Admin' ?>
                        </p>
                        <p class="text-sm font-semibold text-gray-800 leading-relaxed whitespace-pre-line"><?= htmlspecialchars($detail['catatan_revisi']) ?></p>
                        <?php if ($role === 'agen' && $status === 'revisi'): ?>
                        <a href="edit-catatan?id=<?= (int)$detail['id'] ?>"
                           class="inline-flex items-center gap-2 mt-4 px-5 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-[10px] font-black uppercase tracking-widest transition-all">
                            <i class="fas fa-pen"></i> Perbaiki & Kirim Ulang
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($detail['latitude']) && !empty($detail['longitude'])): ?>
            <div class="mb-6 bg-green-50 border border-green-100 rounded-2xl px-5 py-4 text-sm">
                <p class="text-[10px] font-black text-green-700 uppercase tracking-widest mb-1">Koordinat GPS</p>
                <p class="font-mono text-gray-700"><?= htmlspecialchars($detail['latitude']) ?>, <?= htmlspecialchars($detail['longitude']) ?></p>
                <a class="text-[11px] font-bold text-green-700 underline" target="_blank" rel="noopener"
                   href="https://www.google.com/maps?q=<?= urlencode($detail['latitude'] . ',' . $detail['longitude']) ?>">Buka di Google Maps</a>
            </div>
            <?php else: ?>
            <div class="mb-6 bg-gray-50 border border-gray-100 rounded-2xl px-5 py-4 text-xs text-gray-400 font-semibold">
                Koordinat GPS belum tersedia pada laporan ini.
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-[48px] shadow-2xl shadow-red-900/5 border border-gray-100 overflow-hidden">
                <div class="p-8 md:p-14">
                    
                    <?php if (in_array($role, ['admin', 'kabalai'], true)): ?>
                    <div class="mb-8 bg-red-50/50 border border-red-100 p-5 rounded-3xl flex items-center justify-between shadow-inner">
                        <div class="flex items-center space-x-4">
                            <div class="w-10 h-10 bg-red-800 rounded-xl flex items-center justify-center text-white">
                                <i class="fas fa-user-tag text-sm"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Laporan Masuk Dari</p>
                                <p class="text-sm font-black text-red-800"><?= htmlspecialchars($detail['nama_agen']) ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="flex items-center space-x-5 mb-12">
                        <div class="w-16 h-16 bg-orange-600 rounded-[24px] flex items-center justify-center text-white shadow-xl shadow-orange-200 transform -rotate-3">
                            <i class="fas fa-file-invoice text-2xl"></i>
                        </div>
                        <div>
                            <h2 class="text-3xl font-black text-gray-900 leading-tight tracking-tight">Detail Laporan</h2>
                            <p class="text-[10px] text-gray-400 font-black uppercase tracking-[0.3em] mt-1">ID: #<?= $detail['id'] ?></p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-y-10 gap-x-12 mb-14">
                        <div class="space-y-1">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Tanggal Kegiatan</p>
                            <p class="text-lg font-bold text-gray-800"><?= date('d F Y', strtotime($detail['tanggal'])) ?></p>
                        </div>
                        <div class="space-y-1">
                            <p class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em]">Nama Konsumen / Masyarakat</p>
                            <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($detail['nama_konsumen']) ?></p>
                        </div>
                        <div class="space-y-1 border-l-4 border-gray-50 pl-4">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Usia <span class="normal-case tracking-normal text-gray-300 font-semibold">(data individu)</span></p>
                            <p class="text-lg font-bold text-gray-800"><?= $detail['usia'] ? (int)$detail['usia'] . ' Tahun' : '-' ?></p>
                        </div>
                        <div class="space-y-1 border-l-4 border-gray-50 pl-4">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Jenis Kelamin</p>
                            <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($detail['jenis_kelamin'] ?: '-') ?></p>
                        </div>
                        <div class="space-y-1 border-l-4 border-gray-50 pl-4">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Pekerjaan</p>
                            <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($detail['pekerjaan'] ?: '-') ?></p>
                        </div>
                        <div class="space-y-1 border-l-4 border-gray-50 pl-4">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Nilai Pre-Test</p>
                            <p class="text-lg font-bold text-gray-800"><?= $detail['nilai_pre_test'] !== null ? rtrim(rtrim(number_format((float)$detail['nilai_pre_test'], 2, '.', ''), '0'), '.') : '-' ?></p>
                        </div>
                        <div class="space-y-1 border-l-4 border-gray-50 pl-4">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Nilai Post-Test</p>
                            <p class="text-lg font-bold text-gray-800"><?= $detail['nilai_post_test'] !== null ? rtrim(rtrim(number_format((float)$detail['nilai_post_test'], 2, '.', ''), '0'), '.') : '-' ?></p>
                        </div>

                        <?php if ($role === 'agen'): ?>
                        <div class="md:col-span-2">
                            <?php if ($msgNilai === 'ok'): ?>
                            <div class="mb-4 bg-orange-50 text-orange-700 border border-orange-100 rounded-2xl px-5 py-3 text-sm font-bold">Nilai pre/post test masyarakat berhasil disimpan.</div>
                            <?php elseif ($msgNilai): ?>
                            <div class="mb-4 bg-red-50 text-red-700 border border-red-100 rounded-2xl px-5 py-3 text-sm font-bold"><?= htmlspecialchars($msgNilai) ?></div>
                            <?php endif; ?>
                            <form method="POST" class="bg-orange-50/40 border border-orange-100 rounded-[28px] p-6 md:p-8">
                                <input type="hidden" name="update_nilai_masyarakat" value="1">
                                <p class="text-[10px] font-black text-orange-700 uppercase tracking-widest mb-4">Input / Update Nilai Pre &amp; Post Test Masyarakat</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                                    <div>
                                        <label class="block text-[10px] font-black text-gray-400 uppercase mb-2">Nilai Pre-Test (0–100)</label>
                                        <input type="number" name="nilai_pre_test" min="0" max="100" step="0.01" required
                                               value="<?= $detail['nilai_pre_test'] !== null ? htmlspecialchars((string)$detail['nilai_pre_test']) : '' ?>"
                                               class="w-full px-5 py-3 rounded-2xl bg-white border border-orange-100 font-bold outline-none focus:border-orange-500">
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-black text-gray-400 uppercase mb-2">Nilai Post-Test (0–100)</label>
                                        <input type="number" name="nilai_post_test" min="0" max="100" step="0.01" required
                                               value="<?= $detail['nilai_post_test'] !== null ? htmlspecialchars((string)$detail['nilai_post_test']) : '' ?>"
                                               class="w-full px-5 py-3 rounded-2xl bg-white border border-orange-100 font-bold outline-none focus:border-orange-500">
                                    </div>
                                </div>
                                <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 bg-red-800 hover:bg-black text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">
                                    <i class="fas fa-save"></i> Simpan Nilai
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>

                        <div class="md:col-span-2 space-y-2">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Lokasi / Tempat Sosialisasi</p>
                            <div class="flex items-center space-x-2 text-red-800">
                                <i class="fas fa-map-marker-alt text-sm"></i>
                                <p class="text-lg font-bold"><?= htmlspecialchars($detail['lokasi'] ?: 'Lokasi tidak spesifik') ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="mb-14">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-4 ml-1">Informasi yang Disampaikan</p>
                        <div class="bg-orange-50/30 p-8 md:p-10 rounded-[40px] text-gray-700 leading-relaxed text-base border border-orange-100/50 shadow-inner">
                            <?= nl2br(htmlspecialchars($detail['informasi'])) ?>
                        </div>
                    </div>

                    <?php if ($lampiranHasil): ?>
                    <div class="mb-14">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-6 ml-1">Lampiran Lembar Hasil Test</p>
                        <?php $isPdf = strtolower(pathinfo($lampiranHasil, PATHINFO_EXTENSION)) === 'pdf'; ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <a href="uploads/<?= rawurlencode($lampiranHasil) ?>" target="_blank" class="flex items-center gap-4 p-5 rounded-3xl border border-orange-100 bg-orange-50/40 hover:bg-orange-50 transition-colors">
                                <span class="w-11 h-11 rounded-2xl flex items-center justify-center <?= $isPdf ? 'bg-red-100 text-red-700' : 'bg-orange-100 text-orange-700' ?>">
                                    <i class="fas <?= $isPdf ? 'fa-file-pdf' : 'fa-image' ?>"></i>
                                </span>
                                <span>
                                    <span class="block text-[10px] font-black uppercase tracking-widest text-gray-400">Pre-Test &amp; Post-Test</span>
                                    <span class="block text-sm font-black text-gray-800 mt-1">Buka Lampiran <i class="fas fa-external-link-alt text-xs ml-1"></i></span>
                                </span>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div>
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-6 ml-1">Lampiran Bukti Kegiatan</p>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-6">
                            <?php foreach ($files as $f): ?>
                                <div class="group relative aspect-square rounded-[32px] overflow-hidden border-4 border-white shadow-lg hover:shadow-orange-200 transition-all duration-300">
                                    <img src="uploads/<?= $f['file_path'] ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700">
                                    <div class="absolute inset-0 bg-red-900/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center backdrop-blur-[2px]">
                                        <a href="uploads/<?= $f['file_path'] ?>" target="_blank" class="text-white bg-white/20 p-4 rounded-2xl backdrop-blur-xl border border-white/30 hover:bg-orange-600 transition-colors">
                                            <i class="fas fa-expand-alt"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($role === 'admin' && in_array($status, ['pending', 'revisi'], true)): ?>
                <!-- Form balasan / minta revisi -->
                <div class="border-t border-gray-100 p-6 md:p-8 bg-white">
                    <form method="POST" class="rounded-[28px] border border-amber-100 bg-amber-50/50 p-6 space-y-4">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center"><i class="fas fa-reply"></i></span>
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-widest text-amber-700">Balasan ke Agen</p>
                                <p class="text-[11px] text-gray-500 font-semibold">Jika data salah / kurang, tulis pesan revisi. Agen akan memperbaiki lalu kirim ulang.</p>
                            </div>
                        </div>
                        <textarea name="pesan_revisi" rows="3" required minlength="5"
                                  placeholder="Contoh: Mohon perbaiki alamat desa dan unggah ulang foto bukti yang lebih jelas."
                                  class="w-full px-5 py-4 rounded-2xl bg-white border border-amber-100 focus:border-amber-500 outline-none font-semibold text-sm text-gray-800"><?= htmlspecialchars($detail['catatan_revisi'] ?? '') ?></textarea>
                        <button type="submit" name="minta_revisi" value="1"
                                class="inline-flex items-center gap-2 px-6 py-3.5 rounded-2xl bg-amber-600 hover:bg-amber-700 text-white text-[10px] font-black uppercase tracking-widest shadow-md transition-all">
                            <i class="fas fa-paper-plane"></i> Kirim Minta Revisi
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="bg-gray-50/50 p-8 md:p-10 border-t border-gray-100 flex flex-wrap gap-4 justify-center md:justify-end">
                    <?php if ($role === 'admin'): ?>
                        <button onclick="konfirmasiHapus(<?= $detail['id'] ?>)" class="bg-white text-red-700 border border-red-100 px-8 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-red-700 hover:text-white transition-all shadow-sm">
                            <i class="fas fa-trash-alt mr-2"></i> Hapus Laporan
                        </button>

                        <?php if (in_array($status, ['pending', 'revisi'], true)): ?>
                        <a href="approve-catatan?id=<?= $detail['id'] ?>" onclick="return confirm('Setujui laporan ini? Pastikan data sudah benar.')" class="bg-orange-600 text-white px-10 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-orange-700 transition-all shadow-xl shadow-orange-200">
                            <i class="fas fa-check-circle mr-2"></i> <?= $status === 'revisi' ? 'Setujui Setelah Revisi' : 'Verifikasi Laporan' ?>
                        </a>
                        <?php endif; ?>

                    <?php elseif ($role === 'agen' && in_array($status, ['pending', 'revisi'], true)): ?>
                        <a href="edit-catatan?id=<?= $detail['id'] ?>" class="bg-orange-600 text-white px-10 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-orange-700 transition-all shadow-xl shadow-orange-200">
                            <i class="fas fa-edit mr-2 text-xs"></i> <?= $status === 'revisi' ? 'Perbaiki & Kirim Ulang' : 'Edit Kembali' ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    function konfirmasiHapus(id) {
        Swal.fire({
            title: 'Hapus Laporan?',
            text: "Data ini akan dihapus permanen dari sistem BBPOM!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#991b1b', // Red-800
            cancelButtonColor: '#cbd5e1',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal',
            customClass: {
                popup: 'rounded-[40px]',
                confirmButton: 'rounded-2xl px-8 py-3 font-bold',
                cancelButton: 'rounded-2xl px-8 py-3 font-bold'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'hapus-catatan?id=' + id;
            }
        })
    }
    </script>
</body>
</html>