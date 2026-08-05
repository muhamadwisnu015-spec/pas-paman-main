<?php
require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/log_laporan.php';
cek_login();
cek_admin_atau_kabalai();
$isAdmin = ($_SESSION['role'] === 'admin');

// Filter periode (berdasarkan created_at user ATAU tanggal test)
$tgl_mulai   = $_GET['mulai'] ?? '';
$tgl_selesai = $_GET['selesai'] ?? '';
$search      = $_GET['q'] ?? '';

$where = ["u.role = 'agen'"];
$params = [];

if ($search) {
    $where[] = "(u.nama LIKE ? OR u.email LIKE ? OR u.alamat LIKE ? OR u.agen_id LIKE ? OR u.kampus LIKE ? OR u.jurusan LIKE ?)";
    $q = "%$search%";
    array_push($params, $q, $q, $q, $q, $q, $q);
}

// Filter periode: agen yang terdaftar dalam rentang, ATAU yang punya test dalam rentang
// Untuk statistik rata-rata test: filter by waktu_selesai test
$filterPeriode = ($tgl_mulai && $tgl_selesai);
if ($filterPeriode) {
    $where[] = "DATE(u.created_at) BETWEEN ? AND ?";
    $params[] = $tgl_mulai;
    $params[] = $tgl_selesai;
}

$whereStr = implode(' AND ', $where);

// Query utama + subquery pre/post terbaru
$sql = "
    SELECT u.*,
        (SELECT COUNT(*) FROM catatan_harian WHERE user_id = u.id) as jml_laporan,
        (SELECT ht.nilai FROM hasil_test ht
            JOIN bank_soal bs ON bs.id = ht.bank_soal_id
            WHERE ht.user_id = u.id AND bs.jenis = 'pre_test'
            " . ($filterPeriode ? " AND DATE(ht.waktu_selesai) BETWEEN " . $pdo->quote($tgl_mulai) . " AND " . $pdo->quote($tgl_selesai) : "") . "
            ORDER BY ht.waktu_selesai DESC LIMIT 1) as nilai_pre,
        (SELECT ht.nilai FROM hasil_test ht
            JOIN bank_soal bs ON bs.id = ht.bank_soal_id
            WHERE ht.user_id = u.id AND bs.jenis = 'post_test'
            " . ($filterPeriode ? " AND DATE(ht.waktu_selesai) BETWEEN " . $pdo->quote($tgl_mulai) . " AND " . $pdo->quote($tgl_selesai) : "") . "
            ORDER BY ht.waktu_selesai DESC LIMIT 1) as nilai_post
    FROM users u
    WHERE $whereStr
    ORDER BY u.nama ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$agen = $stmt->fetchAll();

// Ringkasan
$totalAgenFilter = count($agen);
$sumPre = 0; $cntPre = 0; $sumPost = 0; $cntPost = 0;
foreach ($agen as $a) {
    if ($a['nilai_pre'] !== null) { $sumPre += (float)$a['nilai_pre']; $cntPre++; }
    if ($a['nilai_post'] !== null) { $sumPost += (float)$a['nilai_post']; $cntPost++; }
}
$rataPre  = $cntPre  ? round($sumPre / $cntPre, 1) : null;
$rataPost = $cntPost ? round($sumPost / $cntPost, 1) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Agen | Admin BBPOM</title>
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

    <main class="flex-1 p-4 md:p-12 overflow-x-hidden">
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 gap-6">
            <div>
                <h2 class="text-2xl md:text-3xl font-black text-gray-900 tracking-tight">Manajemen Agen</h2>
                <p class="text-sm text-gray-500 mt-1">Kelola akun, pantau performa & nilai test kader.</p>
            </div>
            <form action="" method="GET" class="relative w-full lg:w-96">
                <input type="hidden" name="mulai" value="<?= htmlspecialchars($tgl_mulai) ?>">
                <input type="hidden" name="selesai" value="<?= htmlspecialchars($tgl_selesai) ?>">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Cari nama, email, wilayah, kampus..."
                       class="w-full pl-12 pr-4 py-3.5 rounded-2xl border border-gray-200 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all shadow-sm font-medium">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
            </form>
        </div>

        <!-- Filter Periode -->
        <form method="GET" class="bg-white rounded-[28px] border border-gray-100 shadow-sm p-6 mb-8 flex flex-wrap items-end gap-4">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
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
            <?php if ($filterPeriode || $search): ?>
            <a href="daftar-agen" class="px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-600 text-[10px] font-black uppercase tracking-widest rounded-xl">Reset</a>
            <?php endif; ?>
            <a href="export-agen<?= ($filterPeriode ? '?mulai='.urlencode($tgl_mulai).'&selesai='.urlencode($tgl_selesai) : '') ?>"
               class="ml-auto px-6 py-3 bg-orange-600 hover:bg-orange-700 text-white text-[10px] font-black uppercase tracking-widest rounded-xl transition-all">
                <i class="fas fa-file-excel mr-1"></i> Export Excel
            </a>
        </form>

        <!-- Ringkasan -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-7 rounded-[28px] border border-gray-100 shadow-sm relative overflow-hidden">
                <div class="absolute top-0 left-0 w-2 h-full bg-red-800"></div>
                <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.15em]">Jumlah Agen</p>
                <h3 class="text-4xl font-black text-gray-900 mt-3"><?= $totalAgenFilter ?></h3>
                <?php if ($filterPeriode): ?>
                <p class="text-[10px] text-gray-400 mt-1"><?= date('d M Y', strtotime($tgl_mulai)) ?> – <?= date('d M Y', strtotime($tgl_selesai)) ?></p>
                <?php endif; ?>
            </div>
            <div class="bg-white p-7 rounded-[28px] border border-gray-100 shadow-sm relative overflow-hidden">
                <div class="absolute top-0 left-0 w-2 h-full bg-orange-500"></div>
                <p class="text-[10px] font-black text-orange-600 uppercase tracking-[0.15em]">Rata-rata Pre-Test</p>
                <h3 class="text-4xl font-black text-gray-900 mt-3"><?= $rataPre !== null ? $rataPre : '—' ?></h3>
                <p class="text-[10px] text-gray-400 mt-1"><?= $cntPre ?> agen punya nilai</p>
            </div>
            <div class="bg-white p-7 rounded-[28px] border border-gray-100 shadow-sm relative overflow-hidden">
                <div class="absolute top-0 left-0 w-2 h-full bg-red-600"></div>
                <p class="text-[10px] font-black text-red-700 uppercase tracking-[0.15em]">Rata-rata Post-Test</p>
                <h3 class="text-4xl font-black text-gray-900 mt-3"><?= $rataPost !== null ? $rataPost : '—' ?></h3>
                <p class="text-[10px] text-gray-400 mt-1"><?= $cntPost ?> agen punya nilai</p>
            </div>
        </div>

        <!-- Tabel Desktop -->
        <div class="hidden md:block bg-white rounded-[32px] shadow-sm border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="bg-gray-50/50 border-b border-gray-50">
                        <tr>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest">No</th>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest">Nama Agen</th>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest">Alamat</th>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest">JK</th>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">Usia</th>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">No. WA</th>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest">Pekerjaan</th>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest">Waktu Pelaksanaan</th>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">Pre</th>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">Post</th>
                            <th class="px-5 py-5 text-[10px] font-black text-gray-400 uppercase tracking-widest text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php $no = 1; foreach ($agen as $a): ?>
                        <tr class="hover:bg-orange-50/30 transition-all group">
                            <td class="px-5 py-5 text-sm text-gray-400 font-bold"><?= $no++ ?></td>
                            <td class="px-5 py-5">
                                <div class="flex items-center space-x-3">
                                    <img src="uploads/<?= htmlspecialchars($a['foto_profil'] ?: 'default.png') ?>" class="w-9 h-9 rounded-full object-cover border border-gray-100">
                                    <div>
                                        <p class="font-bold text-gray-800 leading-tight group-hover:text-red-800 text-sm"><?= htmlspecialchars($a['nama']) ?></p>
                                        <p class="text-[10px] text-gray-400"><?= htmlspecialchars($a['agen_id'] ?: '-') ?> · <?= htmlspecialchars($a['email']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-5 text-xs text-gray-500 font-medium italic max-w-[160px]"><?= htmlspecialchars($a['alamat'] ?: 'Belum diisi') ?></td>
                            <td class="px-5 py-5 text-xs font-semibold text-gray-600"><?= htmlspecialchars($a['jenis_kelamin'] ?: '-') ?></td>
                            <td class="px-5 py-5 text-center text-sm font-bold text-gray-700"><?= $a['usia'] ?: '-' ?></td>
                            <td class="px-5 py-5 text-center">
                                <?php
                                $waAgen = wa_chat_url($a['nomor_hp'] ?? '', 'Halo ' . ($a['nama'] ?? '') . ', saya dari BBPOM Mataram terkait program GAS-PAMAN.');
                                if ($waAgen): ?>
                                <a href="<?= htmlspecialchars($waAgen) ?>" target="_blank" rel="noopener"
                                   class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-green-500 hover:bg-green-600 text-white shadow-md shadow-green-200 transition-all"
                                   title="Chat WhatsApp: <?= htmlspecialchars($a['nomor_hp'] ?? '') ?>">
                                    <i class="fab fa-whatsapp text-lg"></i>
                                </a>
                                <?php else: ?>
                                <span class="text-gray-300 text-xs" title="No. WA belum diisi">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-5 text-xs text-gray-600"><?= htmlspecialchars($a['pekerjaan'] ?: '-') ?></td>
                            <td class="px-5 py-5 text-xs text-gray-600 whitespace-nowrap">
                                <?php if (!empty($a['magang_mulai']) || !empty($a['magang_selesai'])): ?>
                                    <span class="font-semibold"><?= !empty($a['magang_mulai']) ? date('d M Y', strtotime($a['magang_mulai'])) : '—' ?></span>
                                    <span class="text-gray-300 mx-1">–</span>
                                    <span class="font-semibold"><?= !empty($a['magang_selesai']) ? date('d M Y', strtotime($a['magang_selesai'])) : '—' ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400 italic">Belum diatur</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-5 text-center font-black text-orange-600"><?= $a['nilai_pre'] !== null ? number_format($a['nilai_pre'], 0) : '—' ?></td>
                            <td class="px-5 py-5 text-center font-black text-red-700"><?= $a['nilai_post'] !== null ? number_format($a['nilai_post'], 0) : '—' ?></td>
                            <td class="px-5 py-5">
                                <div class="flex justify-center items-center space-x-3">
                                    <a href="detail-agen?id=<?= $a['id'] ?>" class="text-red-800 hover:text-orange-600 font-black text-[10px] uppercase tracking-widest">Detail</a>
                                    <?php if ($isAdmin): ?>
                                    <a href="edit-agen?id=<?= $a['id'] ?>" class="text-gray-400 hover:text-orange-600"><i class="fas fa-edit"></i></a>
                                    <button onclick="konfirmasiHapus('<?= $a['id'] ?>', '<?= htmlspecialchars(addslashes($a['nama'])) ?>')" class="text-gray-300 hover:text-red-600"><i class="fas fa-trash"></i></button>
                                    <a href="toggle-status?id=<?= $a['id'] ?>" onclick="return confirm('Ubah status aktif agen?')" class="w-7 h-7 flex items-center justify-center bg-gray-50 text-gray-400 hover:text-orange-600 rounded-lg"><i class="fas fa-power-off text-xs"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($agen)): ?>
                        <tr><td colspan="11" class="px-8 py-16 text-center italic text-gray-400">Tidak ada data agen.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mobile cards -->
        <div class="grid grid-cols-1 gap-4 md:hidden">
            <?php foreach ($agen as $a): ?>
            <div class="bg-white p-6 rounded-[32px] border border-gray-100 shadow-sm space-y-3 relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1.5 h-full <?= $a['status'] == 'aktif' ? 'bg-orange-500' : 'bg-red-800' ?>"></div>
                <div class="flex justify-between items-start">
                    <div class="flex items-center space-x-3">
                        <img src="uploads/<?= htmlspecialchars($a['foto_profil'] ?: 'default.png') ?>" class="w-12 h-12 rounded-full object-cover border-2 border-orange-50">
                        <div>
                            <p class="font-bold text-gray-900 leading-tight"><?= htmlspecialchars($a['nama']) ?></p>
                            <p class="text-[10px] text-gray-400"><?= htmlspecialchars($a['pekerjaan'] ?: 'Pekerjaan belum diisi') ?></p>
                        </div>
                    </div>
                    <div class="text-right text-xs">
                        <p class="font-black text-orange-600">Pre <?= $a['nilai_pre'] !== null ? number_format($a['nilai_pre'],0) : '—' ?></p>
                        <p class="font-black text-red-700">Post <?= $a['nilai_post'] !== null ? number_format($a['nilai_post'],0) : '—' ?></p>
                    </div>
                </div>
                <p class="text-xs text-gray-500 italic"><?= htmlspecialchars($a['alamat'] ?: 'Alamat belum diisi') ?></p>
                <p class="text-[10px] text-gray-500">
                    <span class="font-black uppercase tracking-widest text-gray-400">Waktu Pelaksanaan:</span>
                    <?php if (!empty($a['magang_mulai']) || !empty($a['magang_selesai'])): ?>
                        <?= !empty($a['magang_mulai']) ? date('d M Y', strtotime($a['magang_mulai'])) : '—' ?>
                        –
                        <?= !empty($a['magang_selesai']) ? date('d M Y', strtotime($a['magang_selesai'])) : '—' ?>
                    <?php else: ?>
                        <span class="italic text-gray-400">Belum diatur</span>
                    <?php endif; ?>
                </p>
                <div class="flex gap-2 pt-2">
                    <a href="detail-agen?id=<?= $a['id'] ?>" class="flex-1 flex items-center justify-center space-x-2 py-3 bg-red-800 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest">Detail</a>
                    <?php
                    $waAgenM = wa_chat_url($a['nomor_hp'] ?? '');
                    if ($waAgenM): ?>
                    <a href="<?= htmlspecialchars($waAgenM) ?>" target="_blank" rel="noopener"
                       class="w-12 flex items-center justify-center bg-green-500 text-white rounded-2xl" title="WhatsApp">
                        <i class="fab fa-whatsapp text-lg"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                    <a href="edit-agen?id=<?= $a['id'] ?>" class="flex-1 flex items-center justify-center space-x-2 py-3 bg-orange-100 text-orange-700 rounded-2xl text-[10px] font-black uppercase tracking-widest">Edit</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
    function konfirmasiHapus(id, nama) {
        Swal.fire({
            title: 'Hapus Agen?',
            text: "Anda akan menghapus agen " + nama + ". Semua data catatan agen ini juga akan hilang!",
            icon: 'warning', showCancelButton: true,
            confirmButtonColor: '#991b1b', cancelButtonColor: '#d1d5db',
            confirmButtonText: 'Ya, Hapus!', cancelButtonText: 'Batal',
            customClass: { popup: 'rounded-[40px]', confirmButton: 'rounded-2xl px-8 py-3 font-bold', cancelButton: 'rounded-2xl px-8 py-3 font-bold text-gray-500' }
        }).then((result) => {
            if (result.isConfirmed) window.location.href = "hapus-agen.php?id=" + id;
        });
    }
    <?php if (isset($_GET['status']) && $_GET['status'] === 'terhapus'): ?>
    Swal.fire({ icon: 'success', title: 'Data Dihapus!', text: 'Agen telah dihapus dari sistem BBPOM.', confirmButtonColor: '#ea580c', customClass: { popup: 'rounded-[40px]' } });
    <?php endif; ?>
    </script>
</body>
</html>