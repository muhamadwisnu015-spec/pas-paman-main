<?php
require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/ntb_helper.php';

cek_login();
cek_admin_atau_kabalai();

$tgl_mulai   = $_GET['mulai'] ?? date('Y-m-d', strtotime('-6 months'));
$tgl_selesai = $_GET['selesai'] ?? date('Y-m-d');
$filterPeriode = ($tgl_mulai && $tgl_selesai);

// ========== AGEN ==========
$totalAgen = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='agen'")->fetchColumn();
$totalAgenAktif = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='agen' AND status='aktif'")->fetchColumn();

$jkAgen = $pdo->query("SELECT COALESCE(jenis_kelamin,'Tidak Diketahui') as label, COUNT(*) as total FROM users WHERE role='agen' GROUP BY label")->fetchAll();
$usiaAgen = $pdo->query("
    SELECT CASE
        WHEN usia IS NULL THEN 'Tidak diisi'
        WHEN usia < 20 THEN '< 20'
        WHEN usia BETWEEN 20 AND 29 THEN '20-29'
        WHEN usia BETWEEN 30 AND 39 THEN '30-39'
        WHEN usia BETWEEN 40 AND 49 THEN '40-49'
        ELSE '50+'
    END as label, COUNT(*) as total
    FROM users WHERE role='agen' GROUP BY label ORDER BY label
")->fetchAll();
$kerjaAgen = $pdo->query("SELECT COALESCE(NULLIF(pekerjaan,''),'Tidak diisi') as label, COUNT(*) as total FROM users WHERE role='agen' GROUP BY label ORDER BY total DESC LIMIT 10")->fetchAll();
$kampusAgen = $pdo->query("
    SELECT kampus, jurusan, COUNT(*) as total
    FROM users WHERE role='agen' AND kampus IS NOT NULL AND kampus != ''
    GROUP BY kampus, jurusan ORDER BY total DESC
")->fetchAll();

// Sebaran agen: sama sumbernya dengan dashboard — alamat + koordinat profil agen
try {
    $colsU = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('latitude', $colsU, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN latitude DECIMAL(10,7) NULL");
        $pdo->exec("ALTER TABLE users ADD COLUMN longitude DECIMAL(10,7) NULL");
    }
} catch (Throwable $e) {}
$agenRows = $pdo->query("SELECT id, alamat, nama, latitude, longitude FROM users WHERE role='agen' AND status='aktif'")->fetchAll();
$mapAgen = ntb_aggregate_gps($agenRows, 'alamat', 'latitude', 'longitude', null, 'nama');

// Pre/Post agen per bulan
$stmtNilai = $pdo->prepare("
    SELECT DATE_FORMAT(ht.waktu_selesai, '%Y-%m') as bulan, bs.jenis, AVG(ht.nilai) as rata
    FROM hasil_test ht JOIN bank_soal bs ON bs.id = ht.bank_soal_id
    WHERE DATE(ht.waktu_selesai) BETWEEN ? AND ?
    GROUP BY bulan, bs.jenis ORDER BY bulan
");
$stmtNilai->execute([$tgl_mulai, $tgl_selesai]);
$nilaiRaw = $stmtNilai->fetchAll();
$mapPre = []; $mapPost = [];
foreach ($nilaiRaw as $row) {
    if ($row['jenis'] === 'pre_test') $mapPre[$row['bulan']] = round((float)$row['rata'],1);
    if ($row['jenis'] === 'post_test') $mapPost[$row['bulan']] = round((float)$row['rata'],1);
}
$labelBulan = []; $dataPre = []; $dataPost = [];
$start = new DateTime($tgl_mulai); $end = new DateTime($tgl_selesai);
$start->modify('first day of this month');
while ($start <= $end) {
    $key = $start->format('Y-m');
    $labelBulan[] = $start->format('M Y');
    $dataPre[] = $mapPre[$key] ?? null;
    $dataPost[] = $mapPost[$key] ?? null;
    $start->modify('+1 month');
}

// Tabel agen download
$tabelAgen = $pdo->query("
    SELECT u.*,
        (SELECT ht.nilai FROM hasil_test ht JOIN bank_soal bs ON bs.id=ht.bank_soal_id WHERE ht.user_id=u.id AND bs.jenis='pre_test' ORDER BY ht.waktu_selesai DESC LIMIT 1) as nilai_pre,
        (SELECT ht.nilai FROM hasil_test ht JOIN bank_soal bs ON bs.id=ht.bank_soal_id WHERE ht.user_id=u.id AND bs.jenis='post_test' ORDER BY ht.waktu_selesai DESC LIMIT 1) as nilai_post
    FROM users u WHERE u.role='agen' ORDER BY u.nama
")->fetchAll();

// ========== MASYARAKAT ==========
$stmtMasy = $pdo->prepare("SELECT * FROM catatan_harian WHERE tanggal BETWEEN ? AND ?");
$stmtMasy->execute([$tgl_mulai, $tgl_selesai]);
$masyAll = $stmtMasy->fetchAll();

$totalMasy = 0;
foreach ($masyAll as $m) $totalMasy += max((int)($m['jumlah_peserta'] ?? 1), 1);
if ($totalMasy === 0) $totalMasy = count($masyAll);

$jkMasy = []; $usiaMasy = []; $kerjaMasy = [];
foreach ($masyAll as $m) {
    $jk = $m['jenis_kelamin'] ?: 'Tidak diisi';
    $jkMasy[$jk] = ($jkMasy[$jk] ?? 0) + 1;
    $u = $m['usia'];
    if ($u === null) $ul = 'Tidak diisi';
    elseif ($u < 20) $ul = '< 20';
    elseif ($u <= 29) $ul = '20-29';
    elseif ($u <= 39) $ul = '30-39';
    elseif ($u <= 49) $ul = '40-49';
    else $ul = '50+';
    $usiaMasy[$ul] = ($usiaMasy[$ul] ?? 0) + 1;
    $pk = $m['pekerjaan'] ?: 'Tidak diisi';
    $kerjaMasy[$pk] = ($kerjaMasy[$pk] ?? 0) + 1;
}
arsort($kerjaMasy);
$kerjaMasy = array_slice($kerjaMasy, 0, 10, true);

// Sebaran masyarakat: sama sumbernya dengan dashboard — alamat + koordinat di catatan/laporan
$masyRowsMap = [];
foreach ($masyAll as $m) {
    $masyRowsMap[] = [
        'alamat' => $m['alamat'] ?: ($m['lokasi'] ?? ''),
        'bobot' => max((int)($m['jumlah_peserta'] ?? 1), 1),
        'nama_konsumen' => $m['nama_konsumen'] ?? '',
        'latitude' => $m['latitude'] ?? null,
        'longitude' => $m['longitude'] ?? null,
        'kab_kota' => $m['kab_kota'] ?? null,
        'kecamatan' => $m['kecamatan'] ?? null,
        'desa' => $m['desa'] ?? null,
    ];
}
$mapMasy = ntb_aggregate_gps($masyRowsMap, 'alamat', 'latitude', 'longitude', 'bobot', 'nama_konsumen');

$rataPreM = $pdo->prepare("SELECT AVG(nilai_pre_test) FROM catatan_harian WHERE nilai_pre_test IS NOT NULL AND tanggal BETWEEN ? AND ?");
$rataPreM->execute([$tgl_mulai, $tgl_selesai]);
$rataPreMasy = $rataPreM->fetchColumn();
$rataPostM = $pdo->prepare("SELECT AVG(nilai_post_test) FROM catatan_harian WHERE nilai_post_test IS NOT NULL AND tanggal BETWEEN ? AND ?");
$rataPostM->execute([$tgl_mulai, $tgl_selesai]);
$rataPostMasy = $rataPostM->fetchColumn();

// Tren laporan
$stmtTren = $pdo->prepare("
    SELECT DATE_FORMAT(tanggal,'%Y-%m') as bulan, COUNT(*) as total
    FROM catatan_harian WHERE tanggal BETWEEN ? AND ?
    GROUP BY bulan ORDER BY bulan
");
$stmtTren->execute([$tgl_mulai, $tgl_selesai]);
$mapTren = [];
foreach ($stmtTren->fetchAll() as $r) $mapTren[$r['bulan']] = (int)$r['total'];
$dataTren = [];
$start2 = new DateTime($tgl_mulai); $start2->modify('first day of this month');
$end2 = new DateTime($tgl_selesai);
$labelTren = [];
while ($start2 <= $end2) {
    $key = $start2->format('Y-m');
    $labelTren[] = $start2->format('M Y');
    $dataTren[] = $mapTren[$key] ?? 0;
    $start2->modify('+1 month');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analitik & Laporan | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .chart-wrap { position: relative; height: 260px; }
        .map-box { height: 300px; border-radius: 20px; z-index: 1; }
    </style>
</head>
<body class="bg-gray-50 flex flex-col md:flex-row min-h-screen">
    <?php include 'views/includes/sidebar.php'; ?>
    <main class="flex-1 p-6 md:p-12 overflow-y-auto">
        <header class="mb-8 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
            <div>
                <h2 class="text-3xl font-black text-gray-900 tracking-tight">Analitik & Laporan</h2>
                <p class="text-gray-500 text-sm font-medium italic">Analisis agen & masyarakat teredukasi</p>
            </div>
            <form method="GET" class="bg-white px-5 py-3 rounded-2xl shadow-sm border border-gray-100 flex flex-wrap items-end gap-3">
                <div>
                    <label class="text-[9px] font-black text-gray-400 uppercase">Dari</label>
                    <input type="date" name="mulai" value="<?= htmlspecialchars($tgl_mulai) ?>" class="block text-sm font-bold outline-none">
                </div>
                <div>
                    <label class="text-[9px] font-black text-gray-400 uppercase">Sampai</label>
                    <input type="date" name="selesai" value="<?= htmlspecialchars($tgl_selesai) ?>" class="block text-sm font-bold outline-none">
                </div>
                <button class="px-4 py-2 bg-red-800 text-white text-[10px] font-black uppercase tracking-widest rounded-xl">Terapkan</button>
            </form>
        </header>

        <!-- Ringkasan -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-5 mb-10">
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <p class="text-[10px] font-black text-gray-400 uppercase">Total Agen</p>
                <p class="text-3xl font-black mt-2"><?= $totalAgen ?></p>
                <p class="text-[10px] text-green-600 font-bold"><?= $totalAgenAktif ?> aktif</p>
            </div>
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <p class="text-[10px] font-black text-gray-400 uppercase">Masyarakat (periode)</p>
                <p class="text-3xl font-black mt-2"><?= number_format($totalMasy) ?></p>
            </div>
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <p class="text-[10px] font-black text-orange-600 uppercase">Rata Pre Masyarakat</p>
                <p class="text-3xl font-black mt-2"><?= $rataPreMasy !== null ? number_format($rataPreMasy,1) : '—' ?></p>
            </div>
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <p class="text-[10px] font-black text-red-700 uppercase">Rata Post Masyarakat</p>
                <p class="text-3xl font-black mt-2"><?= $rataPostMasy !== null ? number_format($rataPostMasy,1) : '—' ?></p>
            </div>
        </div>

        <!-- ANALISIS AGEN -->
        <h3 class="text-lg font-black text-gray-900 mb-4 flex items-center gap-2"><span class="w-6 h-1 bg-red-800 rounded-full"></span> Analisis Agen</h3>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Jenis Kelamin</h4>
                <div class="chart-wrap"><canvas id="chartJkAgen"></canvas></div>
            </div>
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Sebaran Usia</h4>
                <div class="chart-wrap"><canvas id="chartUsiaAgen"></canvas></div>
            </div>
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Pekerjaan</h4>
                <div class="chart-wrap"><canvas id="chartKerjaAgen"></canvas></div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Peta Sebaran Agen NTB</h4>
                <div id="mapAgen" class="map-box border border-gray-100"></div>
            </div>
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Pre-Test vs Post-Test Agen</h4>
                <div class="chart-wrap"><canvas id="chartNilaiAgen"></canvas></div>
            </div>
        </div>

        <?php if (count($kampusAgen) > 0): ?>
        <div class="bg-white rounded-[28px] border border-gray-100 shadow-sm overflow-hidden mb-8">
            <div class="p-6 border-b border-gray-50"><h4 class="text-xs font-black uppercase tracking-widest text-gray-500">Kampus & Jurusan Agen GAS-PAMAN</h4></div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead><tr class="bg-gray-50/50">
                        <th class="px-6 py-4 text-[10px] font-black text-gray-400 uppercase">Kampus</th>
                        <th class="px-6 py-4 text-[10px] font-black text-gray-400 uppercase">Jurusan</th>
                        <th class="px-6 py-4 text-[10px] font-black text-gray-400 uppercase text-center">Jumlah Agen</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php foreach ($kampusAgen as $k): ?>
                        <tr>
                            <td class="px-6 py-4 font-bold text-sm"><?= htmlspecialchars($k['kampus']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?= htmlspecialchars($k['jurusan'] ?: '-') ?></td>
                            <td class="px-6 py-4 text-center font-black text-orange-600"><?= $k['total'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-[28px] border border-gray-100 shadow-sm overflow-hidden mb-12">
            <div class="p-6 border-b border-gray-50 flex justify-between items-center">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500">Tabel Data Agen</h4>
                <a href="export-agen" class="px-4 py-2 bg-orange-600 text-white text-[10px] font-black uppercase tracking-widest rounded-xl"><i class="fas fa-file-excel mr-1"></i> Export Excel</a>
            </div>
            <div class="overflow-x-auto max-h-96">
                <table class="w-full text-left">
                    <thead class="sticky top-0 bg-gray-50"><tr>
                        <th class="px-4 py-3 text-[10px] font-black text-gray-400 uppercase">Nama</th>
                        <th class="px-4 py-3 text-[10px] font-black text-gray-400 uppercase">JK</th>
                        <th class="px-4 py-3 text-[10px] font-black text-gray-400 uppercase">Usia</th>
                        <th class="px-4 py-3 text-[10px] font-black text-gray-400 uppercase">Pekerjaan</th>
                        <th class="px-4 py-3 text-[10px] font-black text-gray-400 uppercase">Kampus</th>
                        <th class="px-4 py-3 text-[10px] font-black text-gray-400 uppercase">Jurusan</th>
                        <th class="px-4 py-3 text-[10px] font-black text-gray-400 uppercase">Alamat</th>
                        <th class="px-4 py-3 text-[10px] font-black text-gray-400 uppercase text-center">Pre</th>
                        <th class="px-4 py-3 text-[10px] font-black text-gray-400 uppercase text-center">Post</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php foreach ($tabelAgen as $a): ?>
                        <tr class="text-sm">
                            <td class="px-4 py-3 font-bold"><?= htmlspecialchars($a['nama']) ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($a['jenis_kelamin'] ?: '-') ?></td>
                            <td class="px-4 py-3"><?= $a['usia'] ?: '-' ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($a['pekerjaan'] ?: '-') ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($a['kampus'] ?: '-') ?></td>
                            <td class="px-4 py-3"><?= htmlspecialchars($a['jurusan'] ?: '-') ?></td>
                            <td class="px-4 py-3 text-xs italic text-gray-500 max-w-[120px] truncate"><?= htmlspecialchars($a['alamat'] ?: '-') ?></td>
                            <td class="px-4 py-3 text-center font-black text-orange-600"><?= $a['nilai_pre'] !== null ? number_format($a['nilai_pre'],0) : '—' ?></td>
                            <td class="px-4 py-3 text-center font-black text-red-700"><?= $a['nilai_post'] !== null ? number_format($a['nilai_post'],0) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ANALISIS MASYARAKAT -->
        <h3 class="text-lg font-black text-gray-900 mb-4 flex items-center gap-2"><span class="w-6 h-1 bg-orange-500 rounded-full"></span> Analisis Masyarakat</h3>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Jenis Kelamin</h4>
                <div class="chart-wrap"><canvas id="chartJkMasy"></canvas></div>
            </div>
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Sebaran Usia</h4>
                <div class="chart-wrap"><canvas id="chartUsiaMasy"></canvas></div>
            </div>
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Pekerjaan</h4>
                <div class="chart-wrap"><canvas id="chartKerjaMasy"></canvas></div>
            </div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Peta Sebaran Masyarakat</h4>
                <div id="mapMasy" class="map-box border border-gray-100"></div>
            </div>
            <div class="bg-white p-6 rounded-[28px] border border-gray-100 shadow-sm">
                <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Tren Laporan Masuk</h4>
                <div class="chart-wrap"><canvas id="chartTren"></canvas></div>
            </div>
        </div>

        <!-- Export laporan -->
        <div class="bg-white rounded-[28px] border border-gray-100 shadow-sm p-8">
            <h4 class="text-xs font-black uppercase tracking-widest text-gray-500 mb-4">Export Laporan Periode</h4>
            <p class="text-sm text-gray-500 mb-4">Unduh data laporan masyarakat dalam rentang tanggal yang dipilih (CSV / Excel).</p>
            <a href="export-laporan?tgl_mulai=<?= urlencode($tgl_mulai) ?>&tgl_selesai=<?= urlencode($tgl_selesai) ?>"
               class="inline-flex items-center gap-2 px-6 py-4 bg-red-800 hover:bg-black text-white text-[10px] font-black uppercase tracking-widest rounded-2xl transition-all">
                <i class="fas fa-file-export"></i> Export Excel Laporan
            </a>
        </div>
    </main>

    <script>
    const warna = ['#991b1b','#ea580c','#f59e0b','#fb923c','#fca5a5','#16a34a','#64748b','#c2410c'];
    function pie(id, labels, data) {
        new Chart(document.getElementById(id), {
            type: 'doughnut',
            data: { labels, datasets: [{ data, backgroundColor: warna }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } } }
        });
    }
    function bar(id, labels, data, color) {
        new Chart(document.getElementById(id), {
            type: 'bar',
            data: { labels, datasets: [{ data, backgroundColor: color || '#ea580c', borderRadius: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    pie('chartJkAgen', <?= json_encode(array_column($jkAgen,'label')) ?>, <?= json_encode(array_map('intval', array_column($jkAgen,'total'))) ?>);
    bar('chartUsiaAgen', <?= json_encode(array_column($usiaAgen,'label')) ?>, <?= json_encode(array_map('intval', array_column($usiaAgen,'total'))) ?>, '#991b1b');
    bar('chartKerjaAgen', <?= json_encode(array_column($kerjaAgen,'label')) ?>, <?= json_encode(array_map('intval', array_column($kerjaAgen,'total'))) ?>);

    pie('chartJkMasy', <?= json_encode(array_keys($jkMasy)) ?>, <?= json_encode(array_values($jkMasy)) ?>);
    bar('chartUsiaMasy', <?= json_encode(array_keys($usiaMasy)) ?>, <?= json_encode(array_values($usiaMasy)) ?>, '#991b1b');
    bar('chartKerjaMasy', <?= json_encode(array_keys($kerjaMasy)) ?>, <?= json_encode(array_values($kerjaMasy)) ?>);

    new Chart(document.getElementById('chartNilaiAgen'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($labelBulan) ?>,
            datasets: [
                { label: 'Pre-Test', data: <?= json_encode($dataPre) ?>, backgroundColor: '#94a3b8', borderRadius: 6 },
                { label: 'Post-Test', data: <?= json_encode($dataPost) ?>, backgroundColor: '#ea580c', borderRadius: 6 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, max: 100 } } }
    });
    new Chart(document.getElementById('chartTren'), {
        type: 'line',
        data: { labels: <?= json_encode($labelTren) ?>, datasets: [{ label: 'Laporan', data: <?= json_encode($dataTren) ?>, borderColor: '#ea580c', backgroundColor: 'rgba(234,88,12,0.1)', fill: true, tension: 0.35 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });

    function initMap(id, markers, color) {
        const map = L.map(id).setView([-8.55, 117.0], 8);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM' }).addTo(map);
        markers.forEach(m => {
            L.circleMarker([m.lat, m.lng], { radius: Math.max(8, Math.min(28, 8 + m.total * 2)), color, fillColor: color, fillOpacity: 0.55, weight: 2 })
                .addTo(map).bindPopup('<b>' + m.nama + '</b><br>Total: ' + m.total);
        });
    }
    initMap('mapAgen', <?= json_encode($mapAgen['markers']) ?>, '#991b1b');
    initMap('mapMasy', <?= json_encode($mapMasy['markers']) ?>, '#ea580c');
    </script>
</body>
</html>