<?php
require_once 'config/database.php';
require_once 'core/auth.php';

cek_login();
if ($_SESSION['role'] !== 'admin') {
    header('Location: dashboard');
    exit;
}

// Helper log (aman jika file belum ter-deploy)
$logHelperOk = false;
if (is_file(__DIR__ . '/core/log_laporan.php')) {
    require_once __DIR__ . '/core/log_laporan.php';
    $logHelperOk = function_exists('log_laporan_ensure_table');
}

$dbError = null;
$logs = [];
$daftar_agen = [];

try {
    if ($logHelperOk) {
        log_laporan_ensure_table($pdo);
    } else {
        // Fallback: coba buat tabel langsung
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS log_laporan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                catatan_id INT NULL,
                user_id INT NOT NULL,
                aksi ENUM('buat','edit','approve','revisi','hapus') NOT NULL,
                keterangan TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_log_user (user_id),
                INDEX idx_log_catatan (catatan_id),
                INDEX idx_log_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    $filter_agen = $_GET['agen'] ?? '';
    $aksiFilter  = $_GET['aksi'] ?? '';
    $tgl_mulai   = $_GET['mulai'] ?? '';
    $tgl_selesai = $_GET['selesai'] ?? '';

    $query = "
        SELECT l.*, u.nama as nama_aktor, u.role as role_aktor,
               c.nama_konsumen, agen.nama as nama_agen
        FROM log_laporan l
        JOIN users u ON u.id = l.user_id
        LEFT JOIN catatan_harian c ON c.id = l.catatan_id
        LEFT JOIN users agen ON agen.id = c.user_id
        WHERE 1=1
    ";
    $params = [];

    if ($filter_agen !== '') {
        $query .= " AND (c.user_id = ? OR (l.user_id = ? AND l.aksi IN ('buat','edit')))";
        $params[] = $filter_agen;
        $params[] = $filter_agen;
    }
    if (in_array($aksiFilter, ['buat', 'edit', 'approve', 'revisi', 'hapus'], true)) {
        $query .= " AND l.aksi = ?";
        $params[] = $aksiFilter;
    }
    if ($tgl_mulai && $tgl_selesai) {
        $query .= " AND DATE(l.created_at) BETWEEN ? AND ?";
        $params[] = $tgl_mulai;
        $params[] = $tgl_selesai;
    }

    $query .= " ORDER BY l.created_at DESC LIMIT 300";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    $daftar_agen = $pdo->query("SELECT id, nama FROM users WHERE role = 'agen' ORDER BY nama ASC")->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    $filter_agen = $_GET['agen'] ?? '';
    $aksiFilter  = $_GET['aksi'] ?? '';
    $tgl_mulai   = $_GET['mulai'] ?? '';
    $tgl_selesai = $_GET['selesai'] ?? '';
}

$labelAksi = [
    'buat'    => ['Buat Laporan', 'bg-orange-100 text-orange-700'],
    'edit'    => ['Edit Laporan', 'bg-blue-100 text-blue-700'],
    'approve' => ['Approve', 'bg-green-100 text-green-700'],
    'revisi'  => ['Revisi', 'bg-red-100 text-red-700'],
    'hapus'   => ['Hapus', 'bg-gray-100 text-gray-600'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Laporan Agen | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }</style>
</head>
<body class="bg-gray-50 flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-12 overflow-y-auto">
        <header class="mb-8">
            <h2 class="text-3xl font-black text-gray-900 tracking-tight">Log Laporan Agen</h2>
            <p class="text-sm text-gray-500 mt-1 font-medium italic">
                Jejak aktivitas: kapan agen membuat / mengedit laporan, dan kapan admin menyetujui.
            </p>
        </header>

        <?php if ($dbError): ?>
        <div class="bg-red-50 border border-red-100 text-red-700 p-6 rounded-2xl mb-8 text-sm font-semibold leading-relaxed">
            <p class="font-black uppercase text-xs tracking-widest mb-2">Gagal memuat log</p>
            <p class="mb-3"><?= htmlspecialchars($dbError) ?></p>
            <p class="text-xs text-red-500">
                Pastikan file <code class="bg-red-100 px-1 rounded">core/log_laporan.php</code> sudah di-upload,
                lalu jalankan SQL migrasi di database:
            </p>
            <pre class="mt-3 bg-white border border-red-100 rounded-xl p-4 text-[11px] overflow-x-auto text-gray-700">CREATE TABLE IF NOT EXISTS log_laporan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  catatan_id INT NULL,
  user_id INT NOT NULL,
  aksi ENUM('buat','edit','approve','revisi','hapus') NOT NULL,
  keterangan TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);</pre>
        </div>
        <?php endif; ?>

        <form method="GET" class="bg-white rounded-[28px] border border-gray-100 shadow-sm p-6 mb-8 flex flex-wrap gap-4 items-end">
            <div>
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Agen</label>
                <select name="agen" class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none">
                    <option value="">Semua Agen</option>
                    <?php foreach ($daftar_agen as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= ($filter_agen ?? '') == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Jenis Aksi</label>
                <select name="aksi" class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none">
                    <option value="">Semua Aksi</option>
                    <?php foreach ($labelAksi as $k => $v): ?>
                    <option value="<?= $k ?>" <?= ($aksiFilter ?? '') === $k ? 'selected' : '' ?>><?= $v[0] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Dari</label>
                <input type="date" name="mulai" value="<?= htmlspecialchars($tgl_mulai ?? '') ?>" class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none">
            </div>
            <div>
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1">Sampai</label>
                <input type="date" name="selesai" value="<?= htmlspecialchars($tgl_selesai ?? '') ?>" class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none">
            </div>
            <button type="submit" class="px-6 py-3 bg-red-800 hover:bg-black text-white text-[10px] font-black uppercase tracking-widest rounded-xl">Filter</button>
            <a href="log-laporan" class="px-6 py-3 bg-gray-100 text-gray-600 text-[10px] font-black uppercase tracking-widest rounded-xl">Reset</a>
        </form>

        <div class="bg-white rounded-[32px] shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-gray-50/50">
                        <tr>
                            <th class="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Waktu</th>
                            <th class="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Aksi</th>
                            <th class="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Pelaku</th>
                            <th class="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Konsumen</th>
                            <th class="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Agen Laporan</th>
                            <th class="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest">Keterangan</th>
                            <th class="px-6 py-4 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php foreach ($logs as $log):
                            $lbl = $labelAksi[$log['aksi']] ?? [$log['aksi'], 'bg-gray-100 text-gray-600'];
                        ?>
                        <tr class="hover:bg-orange-50/30 transition-all">
                            <td class="px-6 py-5 text-xs font-semibold text-gray-600 whitespace-nowrap">
                                <?= date('d M Y, H:i', strtotime($log['created_at'])) ?>
                            </td>
                            <td class="px-6 py-5">
                                <span class="px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-widest <?= $lbl[1] ?>"><?= $lbl[0] ?></span>
                            </td>
                            <td class="px-6 py-5 text-sm font-bold text-gray-800">
                                <?= htmlspecialchars($log['nama_aktor']) ?>
                                <span class="block text-[10px] text-gray-400 font-semibold uppercase"><?= htmlspecialchars($log['role_aktor']) ?></span>
                            </td>
                            <td class="px-6 py-5 text-sm text-gray-700"><?= htmlspecialchars($log['nama_konsumen'] ?? '—') ?></td>
                            <td class="px-6 py-5 text-xs font-bold text-orange-700"><?= htmlspecialchars($log['nama_agen'] ?? '—') ?></td>
                            <td class="px-6 py-5 text-xs text-gray-500 max-w-[220px]"><?= htmlspecialchars($log['keterangan'] ?? '—') ?></td>
                            <td class="px-6 py-5 text-center">
                                <?php if (!empty($log['catatan_id'])): ?>
                                <a href="detail-catatan?id=<?= (int)$log['catatan_id'] ?>" class="text-red-800 font-black text-[10px] uppercase tracking-widest hover:text-orange-600">Lihat</a>
                                <?php else: ?>
                                <span class="text-gray-300">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs) && !$dbError): ?>
                        <tr>
                            <td colspan="7" class="px-8 py-16 text-center italic text-gray-400">
                                Belum ada log aktivitas. Log terisi otomatis saat agen membuat/mengedit laporan atau admin menyetujui.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>
