<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);


require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/ntb_helper.php';

cek_login();
if ($_SESSION['role'] !== 'admin') {
    header("Location: dashboard");
    exit;
}

// Filter periode (sama gaya form di Manajemen Agen)
$tgl_mulai   = $_GET['mulai'] ?? '';
$tgl_selesai = $_GET['selesai'] ?? '';
$filterPeriode = ($tgl_mulai && $tgl_selesai);

// ========== STATISTIK UTAMA ==========
$totalAgen = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'agen' AND status = 'aktif'")->fetchColumn();

if ($filterPeriode) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM catatan_harian WHERE tanggal BETWEEN ? AND ?");
    $st->execute([$tgl_mulai, $tgl_selesai]);
    $totalLaporan = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COALESCE(SUM(jumlah_peserta),0) FROM catatan_harian WHERE tanggal BETWEEN ? AND ?");
    $st->execute([$tgl_mulai, $tgl_selesai]);
    $totalMasyarakat = (int)($st->fetchColumn() ?: 0);
    if ($totalMasyarakat === 0) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM catatan_harian WHERE tanggal BETWEEN ? AND ?");
        $st->execute([$tgl_mulai, $tgl_selesai]);
        $totalMasyarakat = (int)$st->fetchColumn();
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM catatan_harian WHERE status_review = 'pending' AND tanggal BETWEEN ? AND ?");
    $st->execute([$tgl_mulai, $tgl_selesai]);
    $pendingLaporan = (int)$st->fetchColumn();
} else {
    $totalLaporan = (int)$pdo->query("SELECT COUNT(*) FROM catatan_harian")->fetchColumn();
    $totalMasyarakat = (int)($pdo->query("SELECT COALESCE(SUM(jumlah_peserta),0) FROM catatan_harian WHERE status_review = 'approved'")->fetchColumn() ?: 0);
    if ($totalMasyarakat === 0) {
        $totalMasyarakat = (int)$pdo->query("SELECT COUNT(*) FROM catatan_harian WHERE status_review = 'approved'")->fetchColumn();
    }
    $pendingLaporan = (int)$pdo->query("SELECT COUNT(*) FROM catatan_harian WHERE status_review = 'pending'")->fetchColumn();
}

// Periode Agen: hitung yang lagi berjalan magangnya hari ini
$agenBerjalan = (int)$pdo->query("
    SELECT COUNT(*) FROM users
    WHERE role = 'agen'
      AND magang_mulai IS NOT NULL AND magang_mulai <= CURRENT_DATE()
      AND (magang_selesai IS NULL OR magang_selesai >= CURRENT_DATE())
")->fetchColumn();
$agenBelumAturPeriode = (int)$pdo->query("
    SELECT COUNT(*) FROM users WHERE role = 'agen' AND magang_mulai IS NULL AND magang_selesai IS NULL
")->fetchColumn();

// Sertifikat
$sertMenunggu = (int)$pdo->query("
    SELECT COUNT(*) FROM hasil_test ht
    JOIN bank_soal bs ON bs.id = ht.bank_soal_id
    WHERE bs.jenis = 'post_test' AND ht.nilai >= 70 AND ht.status_sertifikat = 'menunggu_ttd'
")->fetchColumn();
$sertDisetujui = (int)$pdo->query("
    SELECT COUNT(*) FROM hasil_test ht
    JOIN bank_soal bs ON bs.id = ht.bank_soal_id
    WHERE bs.jenis = 'post_test' AND ht.nilai >= 70 AND ht.status_sertifikat = 'disetujui'
")->fetchColumn();

// Rata-rata Pre/Post Agen
$rataPreAgen  = $pdo->query("SELECT AVG(ht.nilai) FROM hasil_test ht JOIN bank_soal bs ON bs.id=ht.bank_soal_id WHERE bs.jenis='pre_test'")->fetchColumn();
$rataPostAgen = $pdo->query("SELECT AVG(ht.nilai) FROM hasil_test ht JOIN bank_soal bs ON bs.id=ht.bank_soal_id WHERE bs.jenis='post_test'")->fetchColumn();

// Rata-rata Pre/Post Masyarakat (dari catatan_harian)
if ($filterPeriode) {
    $st = $pdo->prepare("SELECT AVG(nilai_pre_test) FROM catatan_harian WHERE nilai_pre_test IS NOT NULL AND tanggal BETWEEN ? AND ?");
    $st->execute([$tgl_mulai, $tgl_selesai]);
    $rataPreMasy = $st->fetchColumn();
    $st = $pdo->prepare("SELECT AVG(nilai_post_test) FROM catatan_harian WHERE nilai_post_test IS NOT NULL AND tanggal BETWEEN ? AND ?");
    $st->execute([$tgl_mulai, $tgl_selesai]);
    $rataPostMasy = $st->fetchColumn();
} else {
    $rataPreMasy  = $pdo->query("SELECT AVG(nilai_pre_test) FROM catatan_harian WHERE nilai_pre_test IS NOT NULL")->fetchColumn();
    $rataPostMasy = $pdo->query("SELECT AVG(nilai_post_test) FROM catatan_harian WHERE nilai_post_test IS NOT NULL")->fetchColumn();
}

// ========== PETA SEBARAN ==========
$agenRows = [];
try {
    $colsU = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('latitude', $colsU, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN latitude DECIMAL(10,7) NULL");
        $pdo->exec("ALTER TABLE users ADD COLUMN longitude DECIMAL(10,7) NULL");
    }
} catch (Throwable $e) {}

// Pastikan kolom wilayah terstruktur ada
try {
    $colsCh = $pdo->query("SHOW COLUMNS FROM catatan_harian")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('kab_kota', $colsCh, true)) {
        $pdo->exec("ALTER TABLE catatan_harian ADD COLUMN kab_kota VARCHAR(100) NULL");
    }
    if (!in_array('kecamatan', $colsCh, true)) {
        $pdo->exec("ALTER TABLE catatan_harian ADD COLUMN kecamatan VARCHAR(100) NULL");
    }
    if (!in_array('desa', $colsCh, true)) {
        $pdo->exec("ALTER TABLE catatan_harian ADD COLUMN desa VARCHAR(100) NULL");
    }
} catch (Throwable $e) {}

// Sebaran agen: HANYA dari alamat + koordinat profil agen (bukan dari laporan)
$agenRows = $pdo->query("SELECT id, alamat, nama, latitude, longitude FROM users WHERE role = 'agen' AND status = 'aktif'")->fetchAll();
$mapAgen = ntb_aggregate_gps($agenRows, 'alamat', 'latitude', 'longitude', null, 'nama');

if ($filterPeriode) {
    $st = $pdo->prepare("
        SELECT COALESCE(NULLIF(alamat,''), lokasi) as alamat,
               GREATEST(COALESCE(jumlah_peserta,1),1) as bobot,
               nama_konsumen, latitude, longitude, kab_kota, kecamatan, desa
        FROM catatan_harian
        WHERE tanggal BETWEEN ? AND ?
    ");
    $st->execute([$tgl_mulai, $tgl_selesai]);
    $masyRows = $st->fetchAll();
} else {
    $masyRows = $pdo->query("
        SELECT COALESCE(NULLIF(alamat,''), lokasi) as alamat,
               GREATEST(COALESCE(jumlah_peserta,1),1) as bobot,
               nama_konsumen, latitude, longitude, kab_kota, kecamatan, desa
        FROM catatan_harian
    ")->fetchAll();
}
$mapMasy = ntb_aggregate_gps($masyRows, 'alamat', 'latitude', 'longitude', 'bobot', 'nama_konsumen');

// Rekap per kabupaten/kota (untuk grafik batang)
$rekapAgenKabupaten = ntb_aggregate_kabupaten($agenRows, 'alamat');
$rekapMasyKabupaten = ntb_aggregate_kabupaten($masyRows, 'alamat', 'bobot');

// Data chart (exclude Lainnya)
$chartLabels = [];
$chartAgenData = [];
$chartMasyData = [];
foreach ($rekapAgenKabupaten as $kab => $n) {
    if ($kab === 'Lainnya / Tidak Diketahui') continue;
    $chartLabels[] = $kab;
    $chartAgenData[] = (int)$n;
    $chartMasyData[] = (int)($rekapMasyKabupaten[$kab] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .logo-admin-branding {
            width: 80px; height: 80px; border-radius: 50%; overflow: hidden;
            border: 3px solid white; box-shadow: 0 10px 20px -5px rgba(153, 27, 27, 0.2);
            background: white; flex-shrink: 0;
        }
        .logo-admin-branding img { width: 100%; height: 100%; object-fit: cover; }
        .stat-card { transition: all .2s; cursor: pointer; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 12px 30px -10px rgba(153,27,27,.2); }
        .map-box { height: 340px; border-radius: 24px; z-index: 1; }
        .chart-box { position: relative; height: 320px; }
    </style>
</head>
<body class="bg-gray-50 flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-12 overflow-y-auto">
        <header class="mb-8 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
            <div class="flex items-center gap-5">
                <div class="logo-admin-branding">
                    <img src="views/gas-paman-round.png" alt="GAS-PAMAN">
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="px-2 py-0.5 bg-red-800 text-white text-[8px] font-black uppercase tracking-widest rounded-md">Panel Kendali</span>
                        <span class="px-2 py-0.5 bg-orange-100 text-orange-700 text-[8px] font-black uppercase tracking-widest rounded-md">GAS-PAMAN</span>
                    </div>
                    <h2 class="text-3xl font-black text-gray-900 tracking-tight">Monitoring Admin</h2>
                    <p class="text-gray-500 text-sm font-medium italic">"Keluarga Sadar Obat dan Makanan Aman"</p>
                </div>
            </div>
            <div class="bg-white px-5 py-3 rounded-2xl shadow-sm border border-gray-100 flex items-center space-x-3">
                <div class="w-10 h-10 bg-red-700 rounded-xl flex items-center justify-center text-white shadow-lg shadow-red-100">
                    <i class="fas fa-user-shield text-sm"></i>
                </div>
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase leading-none mb-1">Status Login</p>
                    <p class="text-sm font-bold text-red-700 uppercase leading-none">ADMIN BBPOM</p>
                </div>
            </div>
        </header>

        <!-- Filter Periode (sama gaya Manajemen Agen) -->
        <form method="GET" class="bg-white rounded-[28px] border border-gray-100 shadow-sm p-6 mb-8 flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1 ml-1">Dari Tanggal</label>
                <input type="date" name="mulai" value="<?= htmlspecialchars($tgl_mulai) ?>"
                       class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold focus:ring-2 focus:ring-orange-600 outline-none">
            </div>
            <div>
                <label class="block text-[9px] font-black text-gray-400 uppercase mb-1 ml-1">Sampai Tanggal</label>
                <input type="date" name="selesai" value="<?= htmlspecialchars($tgl_selesai) ?>"
                       class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold focus:ring-2 focus:ring-orange-600 outline-none">
            </div>
            <button type="submit" class="px-6 py-3 bg-red-800 hover:bg-black text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">
                <i class="fas fa-filter mr-1"></i> Terapkan Periode
            </button>
            <?php if ($filterPeriode): ?>
            <a href="admin-dashboard" class="px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-600 text-[10px] font-black uppercase tracking-widest rounded-xl">Reset</a>
            <p class="text-xs text-orange-600 font-bold ml-2 self-center">
                Periode: <?= date('d M Y', strtotime($tgl_mulai)) ?> – <?= date('d M Y', strtotime($tgl_selesai)) ?>
            </p>
            <?php endif; ?>
        </form>

        <!-- Kartu Statistik (klik = detail) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <a href="daftar-agen" class="stat-card bg-white p-8 rounded-[32px] border border-gray-100 shadow-sm relative overflow-hidden group block">
                <div class="absolute top-0 left-0 w-2 h-full bg-red-800"></div>
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.15em]">Total Agen Aktif</p>
                <h3 class="text-4xl font-black text-gray-900 mt-4"><?= $totalAgen ?></h3>
                <p class="text-[10px] text-orange-600 font-bold mt-2"><i class="fas fa-arrow-right mr-1"></i>Lihat daftar agen</p>
                <i class="fas fa-users absolute -right-4 -bottom-4 text-7xl text-gray-50 opacity-10 group-hover:opacity-20 transition-opacity"></i>
            </a>

            <a href="riwayat?status=approved" class="stat-card bg-white p-8 rounded-[32px] border border-gray-100 shadow-sm relative overflow-hidden group block">
                <div class="absolute top-0 left-0 w-2 h-full bg-orange-500"></div>
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.15em]">Masyarakat Teredukasi</p>
                <h3 class="text-4xl font-black text-gray-900 mt-4"><?= number_format($totalMasyarakat) ?></h3>
                <p class="text-[10px] text-orange-600 font-bold mt-2"><i class="fas fa-arrow-right mr-1"></i>Lihat detail laporan</p>
                <i class="fas fa-graduation-cap absolute -right-4 -bottom-4 text-7xl text-gray-50 opacity-10 group-hover:opacity-20 transition-opacity"></i>
            </a>

            <a href="riwayat" class="stat-card bg-white p-8 rounded-[32px] border border-gray-100 shadow-sm relative overflow-hidden group block">
                <div class="absolute top-0 left-0 w-2 h-full bg-red-500"></div>
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.15em]">Laporan Masuk</p>
                <h3 class="text-4xl font-black text-gray-900 mt-4"><?= $totalLaporan ?></h3>
                <p class="text-[10px] text-orange-600 font-bold mt-2"><i class="fas fa-arrow-right mr-1"></i>Semua laporan</p>
                <i class="fas fa-file-alt absolute -right-4 -bottom-4 text-7xl text-gray-50 opacity-10 group-hover:opacity-20 transition-opacity"></i>
            </a>

            <a href="riwayat?status=pending" class="stat-card bg-white p-8 rounded-[32px] border border-gray-100 shadow-sm relative overflow-hidden group block">
                <div class="absolute top-0 left-0 w-2 h-full bg-orange-600"></div>
                <p class="text-[10px] font-black text-orange-600 uppercase tracking-[0.15em]">Menunggu Review</p>
                <h3 class="text-4xl font-black text-gray-900 mt-4"><?= $pendingLaporan ?></h3>
                <p class="text-[10px] text-orange-600 font-bold mt-2"><i class="fas fa-arrow-right mr-1"></i>Review sekarang</p>
                <i class="fas fa-clock absolute -right-4 -bottom-4 text-7xl text-gray-50 opacity-10 group-hover:opacity-20 transition-opacity"></i>
            </a>
        </div>

        <!-- Rata-rata Test + Sertifikat -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
            <div class="bg-white p-7 rounded-[32px] border border-gray-100 shadow-sm">
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.15em] mb-4">Rata-rata Nilai Agen</p>
                <div class="flex items-end gap-8">
                    <div>
                        <p class="text-[9px] font-bold text-orange-600 uppercase">Pre-Test</p>
                        <p class="text-3xl font-black text-gray-900"><?= $rataPreAgen !== null ? number_format($rataPreAgen, 1) : '—' ?></p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold text-red-700 uppercase">Post-Test</p>
                        <p class="text-3xl font-black text-gray-900"><?= $rataPostAgen !== null ? number_format($rataPostAgen, 1) : '—' ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white p-7 rounded-[32px] border border-gray-100 shadow-sm">
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.15em] mb-4">Rata-rata Nilai Masyarakat</p>
                <div class="flex items-end gap-8">
                    <div>
                        <p class="text-[9px] font-bold text-orange-600 uppercase">Pre-Test</p>
                        <p class="text-3xl font-black text-gray-900"><?= $rataPreMasy !== null ? number_format($rataPreMasy, 1) : '—' ?></p>
                    </div>
                    <div>
                        <p class="text-[9px] font-bold text-red-700 uppercase">Post-Test</p>
                        <p class="text-3xl font-black text-gray-900"><?= $rataPostMasy !== null ? number_format($rataPostMasy, 1) : '—' ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white p-7 rounded-[32px] border border-gray-100 shadow-sm">
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.15em] mb-4">Sertifikat Post-Test</p>
                <div class="flex items-end gap-8">
                    <a href="hasil-test-admin?jenis=post_test" class="hover:opacity-80">
                        <p class="text-[9px] font-bold text-green-600 uppercase">Sudah TTD</p>
                        <p class="text-3xl font-black text-gray-900"><?= $sertDisetujui ?></p>
                    </a>
                    <a href="hasil-test-admin?jenis=post_test" class="hover:opacity-80">
                        <p class="text-[9px] font-bold text-orange-600 uppercase">Menunggu TTD</p>
                        <p class="text-3xl font-black text-gray-900"><?= $sertMenunggu ?></p>
                    </a>
                </div>
            </div>
        </div>

        <!-- Peta NTB -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
            <div class="bg-white p-6 md:p-8 rounded-[32px] border border-gray-100 shadow-sm">
                <h4 class="font-black text-gray-800 uppercase text-xs tracking-[0.2em] mb-4">
                    <i class="fas fa-map-marker-alt text-red-700 mr-2"></i>Sebaran Agen (NTB)
                </h4>
                <div id="mapAgen" class="map-box border border-gray-100"></div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php foreach ($mapAgen['counts'] as $wil => $n): if ($n <= 0) continue; ?>
                    <span class="px-3 py-1 bg-red-50 text-red-800 text-[10px] font-bold rounded-full"><?= htmlspecialchars($wil) ?>: <?= $n ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="mt-5 max-h-48 overflow-y-auto space-y-2 pr-1">
                    <?php foreach (array_slice($mapAgen['locations'] ?? [], 0, 30) as $loc): ?>
                    <div class="text-[10px] leading-relaxed border border-gray-50 rounded-xl px-3 py-2 bg-gray-50/60">
                        <span class="font-black text-gray-800"><?= htmlspecialchars($loc['label'] ?: 'Agen') ?></span>
                        <span class="text-gray-400"> · </span>
                        <?php if ($loc['kabupaten']): ?><span class="text-red-800 font-bold"><?= htmlspecialchars($loc['kabupaten']) ?></span><?php endif; ?>
                        <?php if ($loc['kecamatan']): ?> · Kec. <?= htmlspecialchars($loc['kecamatan']) ?><?php endif; ?>
                        <?php if ($loc['desa']): ?> · Desa/Kel. <?= htmlspecialchars($loc['desa']) ?><?php endif; ?>
                        <?php if ($loc['alamat']): ?><div class="text-gray-500 italic mt-0.5"><?= htmlspecialchars($loc['alamat']) ?></div><?php endif; ?>
                        <?php if ($loc['lat'] !== null): ?><div class="text-gray-400 mt-0.5">Koordinat: <?= number_format($loc['lat'], 6) ?>, <?= number_format($loc['lng'], 6) ?><?= !empty($loc['gps']) ? ' · <span class="text-green-600 font-bold">GPS</span>' : ' · perkiraan' ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="bg-white p-6 md:p-8 rounded-[32px] border border-gray-100 shadow-sm">
                <h4 class="font-black text-gray-800 uppercase text-xs tracking-[0.2em] mb-4">
                    <i class="fas fa-map-marker-alt text-orange-600 mr-2"></i>Sebaran Masyarakat Teredukasi
                </h4>
                <div id="mapMasy" class="map-box border border-gray-100"></div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php foreach ($mapMasy['counts'] as $wil => $n): if ($n <= 0) continue; ?>
                    <span class="px-3 py-1 bg-orange-50 text-orange-700 text-[10px] font-bold rounded-full"><?= htmlspecialchars($wil) ?>: <?= $n ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="mt-5 max-h-48 overflow-y-auto space-y-2 pr-1">
                    <?php foreach (array_slice($mapMasy['locations'] ?? [], 0, 30) as $loc): ?>
                    <div class="text-[10px] leading-relaxed border border-gray-50 rounded-xl px-3 py-2 bg-gray-50/60">
                        <span class="font-black text-gray-800"><?= htmlspecialchars($loc['label'] ?: 'Konsumen') ?></span>
                        <span class="text-gray-400"> · </span>
                        <?php if ($loc['kabupaten']): ?><span class="text-orange-700 font-bold"><?= htmlspecialchars($loc['kabupaten']) ?></span><?php endif; ?>
                        <?php if ($loc['kecamatan']): ?> · Kec. <?= htmlspecialchars($loc['kecamatan']) ?><?php endif; ?>
                        <?php if ($loc['desa']): ?> · Desa/Kel. <?= htmlspecialchars($loc['desa']) ?><?php endif; ?>
                        <?php if ($loc['alamat']): ?><div class="text-gray-500 italic mt-0.5"><?= htmlspecialchars($loc['alamat']) ?></div><?php endif; ?>
                        <?php if ($loc['lat'] !== null): ?><div class="text-gray-400 mt-0.5">Koordinat: <?= number_format($loc['lat'], 6) ?>, <?= number_format($loc['lng'], 6) ?><?= !empty($loc['gps']) ? ' · <span class="text-green-600 font-bold">GPS</span>' : ' · perkiraan' ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Grafik Sebaran per Kabupaten/Kota (pisah agen & masyarakat) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-10">
            <div class="bg-white rounded-[32px] border border-gray-100 shadow-sm p-6 md:p-8">
                <h4 class="font-black text-gray-800 uppercase text-xs tracking-[0.2em] mb-6">
                    <i class="fas fa-chart-bar text-red-700 mr-2"></i>Grafik Sebaran Agen per Kabupaten/Kota
                </h4>
                <div class="chart-box">
                    <canvas id="chartAgen"></canvas>
                </div>
            </div>
            <div class="bg-white rounded-[32px] border border-gray-100 shadow-sm p-6 md:p-8">
                <h4 class="font-black text-gray-800 uppercase text-xs tracking-[0.2em] mb-6">
                    <i class="fas fa-chart-bar text-orange-600 mr-2"></i>Grafik Sebaran Masyarakat per Kabupaten/Kota
                </h4>
                <div class="chart-box">
                    <canvas id="chartMasy"></canvas>
                </div>
            </div>
        </div>
    </main>

    <script>
    function initMap(id, markers, color) {
        const map = L.map(id, { maxZoom: 14 }).setView([-8.5833, 116.1167], 9);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);
        const layerGroup = L.featureGroup().addTo(map);
        const list = markers || [];
        list.forEach(m => {
            const lat = Number(m.lat);
            const lng = Number(m.lng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
            const r = Math.max(7, Math.min(18, 6 + (Number(m.total) || 1) * 1.2));
            const circle = L.circleMarker([lat, lng], {
                radius: r, color: color, fillColor: color, fillOpacity: 0.7, weight: 2
            }).addTo(layerGroup);
            let extra = '';
            if (m.detail) extra += '<br><span style="font-size:11px">' + m.detail + '</span>';
            extra += '<br><span style="font-size:10px;color:#6b7280">Koordinat: ' + lat.toFixed(6) + ', ' + lng.toFixed(6) + (m.gps ? ' · GPS' : ' · dari alamat') + '</span>';
            circle.bindPopup('<b>' + (m.nama || 'Lokasi') + '</b><br>Total: ' + (m.total || 1) + extra);
        });
        try {
            if (layerGroup.getLayers().length) {
                map.fitBounds(layerGroup.getBounds().pad(0.25), { maxZoom: 12 });
            }
        } catch (e) {}
        setTimeout(() => map.invalidateSize(), 250);
        return map;
    }
    const markersAgen = <?= json_encode($mapAgen['markers']) ?>;
    const markersMasy = <?= json_encode($mapMasy['markers']) ?>;
    initMap('mapAgen', markersAgen, '#991b1b');
    initMap('mapMasy', markersMasy, '#ea580c');

    const chartLabels = <?= json_encode($chartLabels) ?>;
    const chartAgenData = <?= json_encode($chartAgenData) ?>;
    const chartMasyData = <?= json_encode($chartMasyData) ?>;

    function makeBarChart(canvasId, labels, data, color) {
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Jumlah',
                    data: data,
                    backgroundColor: color,
                    borderRadius: 8,
                    maxBarThickness: 36
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        titleFont: { weight: 'bold' },
                        padding: 12
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            font: { size: 10, weight: '600' },
                            maxRotation: 45,
                            minRotation: 30
                        },
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 11 } },
                        grid: { color: '#f3f4f6' }
                    }
                }
            }
        });
    }
    makeBarChart('chartAgen', chartLabels, chartAgenData, 'rgba(153, 27, 27, 0.85)');
    makeBarChart('chartMasy', chartLabels, chartMasyData, 'rgba(234, 88, 12, 0.85)');
    </script>
</body>
</html>