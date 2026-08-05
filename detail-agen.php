<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();

$id = $_GET['id'] ?? null;
if (!$id || !in_array($_SESSION['role'], ['admin', 'kabalai'])) {
    header("Location: daftar-agen");
    exit;
}
$isAdmin = ($_SESSION['role'] === 'admin');

// 1. Ambil data profil agen lengkap termasuk kolom baru (agen_id, usia)
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$agen = $stmt->fetch();

if (!$agen) { exit("Agen tidak ditemukan."); }

// 2. Ambil Statistik Khusus Agen Ini
$stats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_laporan,
        SUM(CASE WHEN status_review = 'approved' THEN 1 ELSE 0 END) as total_disetujui,
        SUM(jumlah_peserta) as total_edukasi
    FROM catatan_harian WHERE user_id = ?
");
$stats->execute([$id]);
$data_stats = $stats->fetch();

// 3. Riwayat laporan agen
$laporan = $pdo->prepare("SELECT * FROM catatan_harian WHERE user_id = ? ORDER BY tanggal DESC LIMIT 10");
$laporan->execute([$id]);
$riwayat = $laporan->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Agen | <?= htmlspecialchars($agen['nama']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: #f8fafc;
        }
    </style>
</head>
<body class="bg-gray-50 flex flex-col md:flex-row min-h-screen">
    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-12 overflow-y-auto">
        <header class="mb-8 flex justify-between items-center">
            <a href="daftar-agen" class="inline-flex items-center text-red-800 font-black text-xs uppercase tracking-widest hover:translate-x-1 transition-transform">
                <i class="fas fa-arrow-left mr-2"></i> Kembali ke Daftar
            </a>
            <?php if ($isAdmin): ?>
            <a href="edit-agen?id=<?= $agen['id'] ?>" class="bg-white border border-gray-200 text-gray-600 px-6 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-orange-50 hover:text-orange-600 transition-all shadow-sm">
                <i class="fas fa-edit mr-2"></i> Edit Profil
            </a>
            <?php endif; ?>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="space-y-6">
                <div class="bg-white p-10 rounded-[48px] shadow-sm border border-gray-100 text-center relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-24 h-24 bg-red-800/5 rounded-full -mr-12 -mt-12"></div>
                    
                    <img src="uploads/<?= $agen['foto_profil'] ?: 'default.png' ?>" class="w-32 h-32 rounded-[40px] object-cover mx-auto mb-6 border-4 border-orange-50 shadow-xl relative z-10">
                    
                    <h3 class="text-xl font-black text-gray-900 leading-tight mb-2"><?= htmlspecialchars($agen['nama']) ?></h3>
                    
                    <div class="inline-block px-4 py-1.5 bg-orange-100 text-orange-700 rounded-xl text-[10px] font-black uppercase tracking-widest mb-4 border border-orange-200">
                        ID: <?= $agen['agen_id'] ?: 'BELUM DISET' ?>
                    </div>
                    
                    <div class="flex justify-center">
                        <span class="px-5 py-2 rounded-2xl text-[10px] font-black uppercase tracking-wider <?= $agen['status'] == 'aktif' ? 'bg-orange-600 text-white shadow-lg shadow-orange-200' : 'bg-red-100 text-red-700' ?>">
                            Status: <?= $agen['status'] ?>
                        </span>
                    </div>
                </div>

                <div class="bg-white p-8 rounded-[40px] shadow-sm border border-gray-100 space-y-6">
                    <h4 class="font-black text-red-800 text-[10px] uppercase tracking-[0.2em] border-b border-gray-50 pb-4">Informasi Personal</h4>
                    
                    <div class="grid grid-cols-1 gap-6">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Usia</p>
                                <p class="text-sm font-bold text-gray-700"><?= $agen['usia'] ?: '-' ?> Tahun</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Gender</p>
                                <p class="text-sm font-bold text-gray-700"><?= $agen['jenis_kelamin'] ?: '-' ?></p>
                            </div>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Pekerjaan</p>
                            <p class="text-sm font-bold text-gray-700"><?= htmlspecialchars(($agen['pekerjaan'] ?? '') !== '' ? $agen['pekerjaan'] : '-') ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Nomor HP / WA</p>
                            <p class="text-sm font-bold text-gray-700 font-mono"><?= htmlspecialchars($agen['nomor_hp'] ?: '-') ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Nama Instansi/Universitas</p>
                            <p class="text-sm font-bold text-gray-700"><?= htmlspecialchars($agen['nama_instansi'] ?: '-') ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Alamat</p>
                            <p class="text-sm font-bold text-gray-700"><?= htmlspecialchars($agen['alamat'] ?: '-') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2 space-y-8">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white p-8 rounded-[32px] border border-gray-100 shadow-sm relative overflow-hidden group">
                        <div class="absolute top-0 left-0 w-2 h-full bg-red-800"></div>
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Total Laporan</p>
                        <p class="text-3xl font-black text-gray-900 mt-2"><?= $data_stats['total_laporan'] ?></p>
                    </div>
                    <div class="bg-white p-8 rounded-[32px] border border-gray-100 shadow-sm border-l-8 border-orange-500 relative overflow-hidden group">
                        <p class="text-[10px] font-black text-orange-600 uppercase tracking-widest">Disetujui</p>
                        <p class="text-3xl font-black text-orange-600 mt-2"><?= $data_stats['total_disetujui'] ?></p>
                    </div>
                    <div class="bg-white p-8 rounded-[32px] border border-gray-100 shadow-sm relative overflow-hidden group">
                        <div class="absolute top-0 left-0 w-2 h-full bg-red-500"></div>
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Total Edukasi</p>
                        <p class="text-3xl font-black text-gray-900 mt-2"><?= number_format($data_stats['total_edukasi'] ?: 0) ?></p>
                    </div>
                </div>

                <div class="bg-white rounded-[40px] shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-8 border-b border-gray-50 flex justify-between items-center bg-gray-50/30">
                        <h4 class="font-black text-red-800 uppercase text-[10px] tracking-widest">Aktivitas Terakhir</h4>
                        <span class="text-[9px] font-bold text-gray-400 italic">LIMIT 10 DATA</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-gray-50/50">
                                    <th class="px-8 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest">Tanggal</th>
                                    <th class="px-8 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest">Nama Komunitas</th>
                                    <th class="px-8 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">Peserta</th>
                                    <th class="px-8 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if(count($riwayat) > 0): ?>
                                    <?php foreach ($riwayat as $r): ?>
                                    <tr class="hover:bg-orange-50/30 transition-all group">
                                        <td class="px-8 py-6 text-sm font-semibold text-gray-500 group-hover:text-red-800"><?= date('d M Y', strtotime($r['tanggal'])) ?></td>
                                        <td class="px-8 py-6 text-sm font-bold text-gray-800"><?= htmlspecialchars($r['nama_konsumen']) ?></td>
                                        <td class="px-8 py-6 text-sm font-black text-orange-600 text-center"><?= $r['jumlah_peserta'] ?></td>
                                        <td class="px-8 py-6">
                                            <span class="text-[9px] font-black uppercase px-3 py-1.5 rounded-xl <?= $r['status_review'] == 'approved' ? 'bg-orange-100 text-orange-700 border border-orange-200' : 'bg-red-50 text-red-700 border border-red-100' ?>">
                                                <?= $r['status_review'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="px-8 py-20 text-center text-sm text-gray-400 italic font-medium">Belum ada aktivitas yang dilaporkan.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>