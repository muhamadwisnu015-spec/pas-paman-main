<?php
require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/log_laporan.php';
cek_login();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
// kabalai melihat tampilan monitoring seperti admin (view-only, tanpa aksi approve)
$viewAdmin = in_array($role, ['admin', 'kabalai']);
$isAdmin = ($role === 'admin');

$filter_agen = $_GET['agen'] ?? '';
$tgl_mulai   = $_GET['mulai'] ?? '';
$tgl_selesai = $_GET['selesai'] ?? '';
$search      = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$query = "SELECT c.*, u.nama as nama_agen FROM catatan_harian c 
          JOIN users u ON c.user_id = u.id WHERE 1=1";
$params = [];

if ($role === 'agen') {
    $query .= " AND c.user_id = ?";
    $params[] = $user_id;
} elseif ($viewAdmin && $filter_agen) {
    $query .= " AND c.user_id = ?";
    $params[] = $filter_agen;
}

if ($search) {
    $query .= " AND (c.nama_konsumen LIKE ? OR c.alamat LIKE ? OR c.lokasi LIKE ? OR c.pekerjaan LIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s);
}

if ($tgl_mulai && $tgl_selesai) {
    $query .= " AND c.tanggal BETWEEN ? AND ?";
    $params[] = $tgl_mulai;
    $params[] = $tgl_selesai;
}

if (!empty($_GET['hari_ini'])) {
    $query .= " AND DATE(c.tanggal) = CURDATE()";
}

if ($viewAdmin && in_array($statusFilter, ['pending', 'approved', 'revisi'])) {
    $query .= " AND c.status_review = ?";
    $params[] = $statusFilter;
}

$query .= " ORDER BY c.tanggal DESC, c.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$riwayat = $stmt->fetchAll();

$daftar_agen = [];
if ($viewAdmin) {
    $daftar_agen = $pdo->query("SELECT id, nama FROM users WHERE role = 'agen' ORDER BY nama ASC")->fetchAll();

    $baseWhere = "1=1";
    $baseParams = [];
    if ($filter_agen) { $baseWhere .= " AND user_id = ?"; $baseParams[] = $filter_agen; }
    if ($tgl_mulai && $tgl_selesai) { $baseWhere .= " AND tanggal BETWEEN ? AND ?"; $baseParams[] = $tgl_mulai; $baseParams[] = $tgl_selesai; }

    $st = $pdo->prepare("SELECT COUNT(*) FROM catatan_harian WHERE $baseWhere");
    $st->execute($baseParams);
    $statMasuk = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM catatan_harian WHERE $baseWhere AND status_review = 'approved'");
    $st->execute($baseParams);
    $statApproved = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM catatan_harian WHERE $baseWhere AND status_review = 'pending'");
    $st->execute($baseParams);
    $statPending = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM catatan_harian WHERE $baseWhere AND status_review = 'revisi'");
    $st->execute($baseParams);
    $statRevisi = (int)$st->fetchColumn();
}

// ========== RANDOM SAMPLING (tersimpan di database) ==========
// 20% dari total data yang tampil (filter saat ini). Minimal 1 jika ada data.
$samplePercent = 20;
$poolTotal = count($riwayat);
$sampleSize = $poolTotal > 0 ? max(1, (int)round($poolTotal * $samplePercent / 100)) : 0;
$hasilSampling = [];
$periodeSampling = null;
$sampleMsg = '';
$sampleMsgType = 'info';

if ($isAdmin) {
    sampling_ensure_tables($pdo);

    // Reset periode aktif
    if (isset($_GET['reset_sample']) && $_GET['reset_sample'] === '1') {
        sampling_reset($pdo);
        header('Location: riwayat?' . http_build_query(array_filter([
            'agen' => $filter_agen,
            'mulai' => $tgl_mulai,
            'selesai' => $tgl_selesai,
            'search' => $search,
            'status' => $statusFilter,
            'msg_sample' => 'reset',
        ])));
        exit;
    }

    // Acak & simpan ke DB (hanya jika belum ada periode aktif)
    if (isset($_GET['sample']) && $_GET['sample'] === '1') {
        $aktifCek = sampling_get_aktif($pdo);
        if ($aktifCek) {
            header('Location: riwayat?' . http_build_query(array_filter([
                'agen' => $filter_agen,
                'mulai' => $tgl_mulai,
                'selesai' => $tgl_selesai,
                'search' => $search,
                'status' => $statusFilter,
                'msg_sample' => 'masih_aktif',
            ])));
            exit;
        }

        if (count($riwayat) > 0) {
            $pool = $riwayat;
            $n = count($pool);
            // Hitung ulang 20% dari total pool saat ini
            $sampleSize = max(1, (int)round($n * $samplePercent / 100));
            for ($i = $n - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
            }
            $terpilih = array_slice($pool, 0, min($sampleSize, $n));
            $ids = array_map(static function ($r) { return (int)$r['id']; }, $terpilih);

            $filterInfo = json_encode([
                'agen' => $filter_agen,
                'mulai' => $tgl_mulai,
                'selesai' => $tgl_selesai,
                'search' => $search,
                'status' => $statusFilter,
                'pool' => $n,
                'percent' => $samplePercent,
                'sample_size' => count($ids),
            ], JSON_UNESCAPED_UNICODE);

            $hasil = sampling_simpan($pdo, (int)$user_id, $ids, $filterInfo);
            header('Location: riwayat?' . http_build_query(array_filter([
                'agen' => $filter_agen,
                'mulai' => $tgl_mulai,
                'selesai' => $tgl_selesai,
                'search' => $search,
                'status' => $statusFilter,
                'msg_sample' => $hasil['ok'] ? 'ok' : 'gagal',
            ])));
            exit;
        }

        header('Location: riwayat?' . http_build_query(array_filter([
            'agen' => $filter_agen,
            'mulai' => $tgl_mulai,
            'selesai' => $tgl_selesai,
            'search' => $search,
            'status' => $statusFilter,
            'msg_sample' => 'kosong',
        ])));
        exit;
    }

    // Muat hasil sampling aktif dari DB (tetap ada meski laptop dimatikan)
    $periodeSampling = sampling_get_aktif($pdo);
    $hasilSampling = sampling_get_hasil_aktif($pdo);

    $msgSample = $_GET['msg_sample'] ?? '';
    if ($msgSample === 'ok') {
        $sampleMsg = 'Sampling berhasil disimpan ke database.';
        $sampleMsgType = 'ok';
    } elseif ($msgSample === 'reset') {
        $sampleMsg = 'Periode sampling di-reset. Silakan acak ulang untuk periode berikutnya.';
        $sampleMsgType = 'ok';
    } elseif ($msgSample === 'masih_aktif') {
        $sampleMsg = 'Masih ada hasil sampling aktif. Klik Reset Periode dulu sebelum mengacak lagi.';
        $sampleMsgType = 'warn';
    } elseif ($msgSample === 'kosong') {
        $sampleMsg = 'Tidak ada data konsumen pada filter saat ini untuk diundi.';
        $sampleMsgType = 'warn';
    } elseif ($msgSample === 'gagal') {
        $sampleMsg = 'Gagal menyimpan sampling. Coba lagi atau cek koneksi database.';
        $sampleMsgType = 'warn';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Laporan | BBPOM Diary</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }</style>
</head>
<body class="bg-gray-50 flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-12 overflow-y-auto">
        <header class="mb-8">
            <h2 class="text-3xl font-black text-gray-900 tracking-tight">
                <?= ($viewAdmin) ? 'Monitoring Laporan' : 'Riwayat Catatan' ?>
            </h2>
            <p class="text-sm text-gray-500 mt-1 font-medium italic">
                Total ditemukan: <span class="font-black text-orange-600"><?= count($riwayat) ?></span> data
            </p>
            <?php if ($role === 'agen'): ?>
            <p class="text-xs text-gray-400 mt-2 max-w-2xl leading-relaxed">
                Setiap baris = <b>satu data konsumen/masyarakat</b> yang Anda edukasi.
                Kolom usia, jenis kelamin, dan pekerjaan mengacu pada <b>individu yang dicatat</b> di laporan (bukan rata-rata komunitas).
                Klik <b>Detail</b> untuk melihat data lengkap & mengisi nilai pre/post test.
            </p>
            <?php endif; ?>
        </header>

        <?php if ($viewAdmin): ?>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
            <a href="?<?= http_build_query(array_filter(['mulai'=>$tgl_mulai,'selesai'=>$tgl_selesai,'agen'=>$filter_agen,'search'=>$search])) ?>"
               class="bg-white p-7 rounded-[28px] border border-gray-100 shadow-sm relative overflow-hidden hover:shadow-md transition-all">
                <div class="absolute top-0 left-0 w-2 h-full bg-red-800"></div>
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.15em]">Laporan Masuk</p>
                <h3 class="text-4xl font-black text-gray-900 mt-3"><?= $statMasuk ?></h3>
            </a>
            <a href="?<?= http_build_query(array_filter(['status'=>'approved','mulai'=>$tgl_mulai,'selesai'=>$tgl_selesai,'agen'=>$filter_agen,'search'=>$search])) ?>"
               class="bg-white p-7 rounded-[28px] border border-gray-100 shadow-sm relative overflow-hidden hover:shadow-md transition-all">
                <div class="absolute top-0 left-0 w-2 h-full bg-green-600"></div>
                <p class="text-[10px] font-black text-green-600 uppercase tracking-[0.15em]">Sudah Review</p>
                <h3 class="text-4xl font-black text-gray-900 mt-3"><?= $statApproved ?></h3>
                <?php if ($statRevisi): ?><p class="text-[10px] text-gray-400 mt-1">+ <?= $statRevisi ?> revisi</p><?php endif; ?>
            </a>
            <a href="?<?= http_build_query(array_filter(['status'=>'pending','mulai'=>$tgl_mulai,'selesai'=>$tgl_selesai,'agen'=>$filter_agen,'search'=>$search])) ?>"
               class="bg-white p-7 rounded-[28px] border border-gray-100 shadow-sm relative overflow-hidden hover:shadow-md transition-all">
                <div class="absolute top-0 left-0 w-2 h-full bg-orange-600"></div>
                <p class="text-[10px] font-black text-orange-600 uppercase tracking-[0.15em]">Menunggu Review</p>
                <h3 class="text-4xl font-black text-gray-900 mt-3"><?= $statPending ?></h3>
            </a>
        </div>
        <?php endif; ?>

        <form method="GET" class="bg-white rounded-[28px] border border-gray-100 shadow-sm p-6 mb-8 flex flex-wrap gap-4 items-end">
            <?php if ($viewAdmin): ?>
            <div>
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Agen</label>
                <select name="agen" class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none">
                    <option value="">Semua Agen</option>
                    <?php foreach ($daftar_agen as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $filter_agen == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Status</label>
                <select name="status" class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none">
                    <option value="">Semua Status</option>
                    <option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option>
                    <option value="approved" <?= $statusFilter==='approved'?'selected':'' ?>>Approved</option>
                    <option value="revisi" <?= $statusFilter==='revisi'?'selected':'' ?>>Revisi</option>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Dari</label>
                <input type="date" name="mulai" value="<?= htmlspecialchars($tgl_mulai) ?>" class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none">
            </div>
            <div>
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Sampai</label>
                <input type="date" name="selesai" value="<?= htmlspecialchars($tgl_selesai) ?>" class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none">
            </div>
            <div class="flex-1 min-w-[180px]">
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Cari</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nama / alamat / pekerjaan..."
                       class="w-full px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none">
            </div>
            <button type="submit" class="px-6 py-3 bg-red-800 hover:bg-black text-white text-[10px] font-black uppercase tracking-widest rounded-xl">Filter</button>
            <a href="riwayat" class="px-6 py-3 bg-gray-100 text-gray-600 text-[10px] font-black uppercase tracking-widest rounded-xl">Reset</a>
        </form>

        <div class="hidden md:block bg-white rounded-[32px] shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-gray-50/50">
                        <tr>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Tanggal</th>
                            <?php if ($viewAdmin): ?>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Agen</th>
                            <?php endif; ?>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Nama Konsumen / Masyarakat</th>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">WA</th>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">JK</th>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">Usia</th>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Pekerjaan</th>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Lokasi</th>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">Pre</th>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">Post</th>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Status</th>
                            <th class="px-5 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php foreach ($riwayat as $row):
                            $badge = match($row['status_review']) {
                                'approved' => 'bg-green-100 text-green-700',
                                'revisi'   => 'bg-red-100 text-red-700',
                                default    => 'bg-orange-100 text-orange-700',
                            };
                        ?>
                        <tr class="hover:bg-orange-50/30 transition-all">
                            <td class="px-5 py-5 text-xs font-semibold text-gray-600 whitespace-nowrap"><?= date('d M Y', strtotime($row['tanggal'])) ?></td>
                            <?php if ($viewAdmin): ?>
                            <td class="px-5 py-5 text-xs font-bold text-orange-700"><?= htmlspecialchars($row['nama_agen']) ?></td>
                            <?php endif; ?>
                            <td class="px-5 py-5 font-bold text-gray-800 text-sm"><?= htmlspecialchars($row['nama_konsumen']) ?></td>
                            <td class="px-5 py-5 text-center">
                                <?php
                                $waUrl = wa_chat_url($row['no_hp'] ?? '', 'Halo ' . ($row['nama_konsumen'] ?? '') . ', saya dari BBPOM Mataram terkait edukasi GAS-PAMAN.');
                                if ($waUrl): ?>
                                <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener"
                                   class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-green-500 hover:bg-green-600 text-white shadow-md shadow-green-200 transition-all"
                                   title="Chat WhatsApp: <?= htmlspecialchars($row['no_hp'] ?? '') ?>">
                                    <i class="fab fa-whatsapp text-lg"></i>
                                </a>
                                <?php else: ?>
                                <span class="text-gray-300 text-xs" title="No. WA belum diisi">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-5 text-xs text-gray-600"><?= htmlspecialchars($row['jenis_kelamin'] ?? '-') ?></td>
                            <td class="px-5 py-5 text-center text-sm font-bold"><?= $row['usia'] ?? '-' ?></td>
                            <td class="px-5 py-5 text-xs text-gray-600"><?= htmlspecialchars($row['pekerjaan'] ?? '-') ?></td>
                            <td class="px-5 py-5 text-xs text-gray-500 italic max-w-[140px]"><?= htmlspecialchars($row['lokasi'] ?: ($row['alamat'] ?? '-')) ?></td>
                            <td class="px-5 py-5 text-center font-black text-orange-600"><?= $row['nilai_pre_test'] !== null ? number_format($row['nilai_pre_test'],0) : '—' ?></td>
                            <td class="px-5 py-5 text-center font-black text-red-700"><?= $row['nilai_post_test'] !== null ? number_format($row['nilai_post_test'],0) : '—' ?></td>
                            <td class="px-5 py-5"><span class="px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-widest <?= $badge ?>"><?= $row['status_review'] ?></span></td>
                            <td class="px-5 py-5 text-center whitespace-nowrap">
                                <a href="detail-catatan?id=<?= $row['id'] ?>" class="text-red-800 font-black text-[10px] uppercase tracking-widest hover:text-orange-600"><?= $role === 'agen' ? 'Detail' : 'Detail' ?></a>
                                <?php if ($role === 'agen' && in_array($row['status_review'], ['pending', 'revisi'], true)): ?>
                                <a href="edit-catatan?id=<?= $row['id'] ?>" class="ml-2 text-orange-600 font-black text-[10px] uppercase tracking-widest hover:text-orange-700">Edit</a>
                                <?php endif; ?>
                                <?php if ($isAdmin && $row['status_review'] === 'pending'): ?>
                                <a href="approve-catatan?id=<?= $row['id'] ?>" class="ml-2 text-green-600 font-black text-[10px] uppercase tracking-widest" onclick="return confirm('Approve laporan ini?')">Approve</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($riwayat)): ?>
                        <tr><td colspan="12" class="px-8 py-16 text-center italic text-gray-400">Tidak ada laporan ditemukan.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:hidden">
            <?php foreach ($riwayat as $row):
                $badge = match($row['status_review']) {
                    'approved' => 'bg-green-100 text-green-700',
                    'revisi'   => 'bg-red-100 text-red-700',
                    default    => 'bg-orange-100 text-orange-700',
                };
            ?>
            <div class="bg-white p-6 rounded-[32px] border border-gray-100 shadow-sm space-y-3 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1.5 h-full bg-red-800"></div>
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-[9px] font-black text-gray-400 uppercase tracking-widest mb-1"><?= date('d F Y', strtotime($row['tanggal'])) ?></p>
                        <h4 class="font-bold text-gray-900 leading-tight"><?= htmlspecialchars($row['nama_konsumen']) ?></h4>
                        <?php if ($viewAdmin): ?>
                        <p class="text-[9px] font-bold text-orange-600 mt-1 uppercase italic">Agen: <?= htmlspecialchars($row['nama_agen']) ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="px-3 py-1 rounded-lg text-[8px] font-black uppercase tracking-widest <?= $badge ?>"><?= $row['status_review'] ?></span>
                </div>
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div><span class="text-gray-400 font-bold uppercase text-[9px]">JK / Usia</span><p class="font-semibold"><?= htmlspecialchars($row['jenis_kelamin'] ?? '-') ?> / <?= $row['usia'] ?? '-' ?></p></div>
                    <div><span class="text-gray-400 font-bold uppercase text-[9px]">Pekerjaan</span><p class="font-semibold"><?= htmlspecialchars($row['pekerjaan'] ?? '-') ?></p></div>
                    <div><span class="text-gray-400 font-bold uppercase text-[9px]">Pre / Post</span><p class="font-black text-orange-600"><?= $row['nilai_pre_test']!==null?number_format($row['nilai_pre_test'],0):'—' ?> / <span class="text-red-700"><?= $row['nilai_post_test']!==null?number_format($row['nilai_post_test'],0):'—' ?></span></p></div>
                    <div><span class="text-gray-400 font-bold uppercase text-[9px]">Lokasi</span><p class="font-semibold italic"><?= htmlspecialchars($row['lokasi'] ?: '-') ?></p></div>
                </div>
                <div class="flex gap-2">
                    <a href="detail-catatan?id=<?= $row['id'] ?>" class="flex-1 text-center py-3 bg-red-800 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest">Detail</a>
                    <?php
                    $waUrlM = wa_chat_url($row['no_hp'] ?? '');
                    if ($waUrlM): ?>
                    <a href="<?= htmlspecialchars($waUrlM) ?>" target="_blank" rel="noopener"
                       class="w-12 flex items-center justify-center bg-green-500 text-white rounded-2xl">
                        <i class="fab fa-whatsapp text-lg"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($role === 'agen' && in_array($row['status_review'], ['pending', 'revisi'], true)): ?>
                    <a href="edit-catatan?id=<?= $row['id'] ?>" class="px-4 flex items-center justify-center bg-orange-100 text-orange-700 rounded-2xl text-[10px] font-black uppercase">Edit</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($isAdmin): ?>
        <!-- Random Sampling adil (tersimpan di database) -->
        <div class="mt-10 bg-white rounded-[32px] border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-8 py-6 border-b border-gray-50 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <div>
                    <h3 class="text-xs font-black uppercase tracking-[0.2em] text-gray-700">Random Sampling Konsumen</h3>
                    <p class="text-[11px] text-gray-400 font-semibold mt-1">
                        Undian adil (Fisher–Yates) — <b><?= $samplePercent ?>%</b> dari total data
                        (<?= $poolTotal ?> data → ±<?= $sampleSize ?> orang), peluang setara.
                        Hasil <b>disimpan di database</b> sampai di-reset.
                    </p>
                    <?php if ($periodeSampling): ?>
                    <p class="text-[10px] text-green-600 font-bold mt-2">
                        <i class="fas fa-database mr-1"></i>
                        Periode aktif #<?= (int)$periodeSampling['id'] ?>
                        · dibuat <?= date('d M Y, H:i', strtotime($periodeSampling['created_at'])) ?>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="flex flex-wrap gap-3">
                    <?php if (!$periodeSampling): ?>
                    <a href="?<?= http_build_query(array_filter([
                        'agen' => $filter_agen,
                        'mulai' => $tgl_mulai,
                        'selesai' => $tgl_selesai,
                        'search' => $search,
                        'status' => $statusFilter,
                        'sample' => '1',
                    ])) ?>"
                       class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-orange-500 to-red-700 hover:from-orange-600 hover:to-red-800 text-white text-[10px] font-black uppercase tracking-widest rounded-2xl shadow-lg transition-all active:scale-95">
                        <i class="fas fa-dice"></i> Acak <?= $samplePercent ?>% (<?= $sampleSize ?> orang)
                    </a>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-2 px-6 py-3 bg-gray-100 text-gray-400 text-[10px] font-black uppercase tracking-widest rounded-2xl cursor-not-allowed" title="Reset dulu sebelum acak ulang">
                        <i class="fas fa-dice"></i> Acak (kunci)
                    </span>
                    <a href="?<?= http_build_query(array_filter([
                        'agen' => $filter_agen,
                        'mulai' => $tgl_mulai,
                        'selesai' => $tgl_selesai,
                        'search' => $search,
                        'status' => $statusFilter,
                        'reset_sample' => '1',
                    ])) ?>"
                       onclick="return confirm('Reset periode sampling ini? Hasil aktif akan ditutup. Setelah itu bisa mengacak 20% data untuk periode baru.');"
                       class="inline-flex items-center gap-2 px-6 py-3 bg-gray-800 hover:bg-black text-white text-[10px] font-black uppercase tracking-widest rounded-2xl shadow-lg transition-all active:scale-95">
                        <i class="fas fa-rotate-left"></i> Reset Periode
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($sampleMsg): ?>
            <div class="mx-6 mt-6 px-5 py-3 rounded-2xl text-xs font-bold <?= $sampleMsgType === 'ok' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-orange-50 text-orange-700 border border-orange-100' ?>">
                <?= htmlspecialchars($sampleMsg) ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($hasilSampling)): ?>
            <div class="p-6 md:p-8">
                <p class="text-[10px] font-black text-green-600 uppercase tracking-widest mb-4">
                    <i class="fas fa-check-circle mr-1"></i> Terpilih <?= count($hasilSampling) ?> konsumen (tersimpan)
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($hasilSampling as $i => $s):
                        $waS = wa_chat_url($s['no_hp'] ?? '');
                        $nomor = (int)($s['urutan'] ?? ($i + 1));
                        $cid = (int)($s['catatan_id'] ?? $s['id']);
                    ?>
                    <div class="rounded-2xl border border-orange-100 bg-orange-50/40 p-5 flex items-start gap-4">
                        <span class="w-8 h-8 rounded-xl bg-red-800 text-white text-xs font-black flex items-center justify-center shrink-0"><?= $nomor ?></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-gray-900 text-sm truncate"><?= htmlspecialchars($s['nama_konsumen']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-0.5">
                                <?= htmlspecialchars($s['nama_agen'] ?? '-') ?> · <?= htmlspecialchars($s['no_hp'] ?: 'WA belum diisi') ?>
                            </p>
                            <div class="flex gap-2 mt-3">
                                <a href="detail-catatan?id=<?= $cid ?>" class="text-[9px] font-black uppercase tracking-widest text-red-800">Detail</a>
                                <?php if ($waS): ?>
                                <a href="<?= htmlspecialchars($waS) ?>" target="_blank" rel="noopener" class="text-[9px] font-black uppercase tracking-widest text-green-600">
                                    <i class="fab fa-whatsapp"></i> WA
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="px-8 py-10 text-center text-gray-400 text-sm">
                Belum ada sampling aktif. Atur filter tabel di atas (opsional), lalu klik
                <b>Acak <?= $sampleSize ?> Orang</b>. Hasil akan tersimpan sampai kamu tekan <b>Reset Periode</b>.
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>