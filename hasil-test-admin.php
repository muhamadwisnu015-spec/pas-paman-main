<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();
if (!in_array($_SESSION['role'], ['admin', 'staff', 'kabalai'])) {
    header("Location: dashboard");
    exit;
}
$bolehEditNilai = in_array($_SESSION['role'], ['admin', 'staff']);

$filterJenis = $_GET['jenis'] ?? '';
$filterAgen  = $_GET['agen_id'] ?? '';

$params = [];
$where  = ["bs.jenis IS NOT NULL"];

if (in_array($filterJenis, ['pre_test', 'post_test'])) {
    $where[] = "bs.jenis = ?";
    $params[] = $filterJenis;
}
if ($filterAgen) {
    $where[] = "ht.user_id = ?";
    $params[] = (int)$filterAgen;
}

$whereStr = implode(' AND ', $where);

$stmtHasil = $pdo->prepare("
    SELECT ht.*, bs.judul, bs.jenis, bs.tanggal as tanggal_test, u.nama as nama_agen, u.agen_id as kode_agen
    FROM hasil_test ht
    JOIN bank_soal bs ON bs.id = ht.bank_soal_id
    JOIN users u ON u.id = ht.user_id
    WHERE $whereStr
    ORDER BY bs.tanggal DESC, ht.created_at DESC
");
$stmtHasil->execute($params);
$hasilList = $stmtHasil->fetchAll();

$daftarAgen = $pdo->query("SELECT id, nama, agen_id FROM users WHERE role = 'agen' ORDER BY nama")->fetchAll();

$statPreTest  = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM hasil_test ht JOIN bank_soal bs ON bs.id = ht.bank_soal_id WHERE bs.jenis = 'pre_test'")->fetchColumn() ?: 0;
$statPostTest = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM hasil_test ht JOIN bank_soal bs ON bs.id = ht.bank_soal_id WHERE bs.jenis = 'post_test'")->fetchColumn() ?: 0;
$rataPreTest  = $pdo->query("SELECT AVG(ht.nilai) FROM hasil_test ht JOIN bank_soal bs ON bs.id = ht.bank_soal_id WHERE bs.jenis = 'pre_test'")->fetchColumn();
$rataPostTest = $pdo->query("SELECT AVG(ht.nilai) FROM hasil_test ht JOIN bank_soal bs ON bs.id = ht.bank_soal_id WHERE bs.jenis = 'post_test'")->fetchColumn();

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType    = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

function nilaiBadge($nilai) {
    if ($nilai >= 80) return ['bg-green-100 text-green-700', 'Sangat Baik'];
    if ($nilai >= 60) return ['bg-orange-100 text-orange-700', 'Cukup'];
    return ['bg-red-100 text-red-700', 'Perlu Belajar'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Test Agen | BBPOM GAS-PAMAN</title>
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
                    <h1 class="text-2xl font-black text-gray-900 tracking-tight">Hasil Test Agen</h1>
                    <p class="text-sm text-gray-400 font-medium mt-1">Rekap nilai Pre-Test &amp; Post-Test seluruh agen</p>
                </div>
                <?php if ($bolehEditNilai): ?>
                <a href="edit-nilai-test" class="inline-flex items-center gap-2 bg-red-800 hover:bg-black text-white text-xs font-black uppercase tracking-widest px-6 py-4 rounded-2xl transition-all shadow-lg">
                    <i class="fas fa-pen"></i> Input Nilai Manual
                </a>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10">
                <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6">
                    <p class="text-[9px] font-black uppercase tracking-widest text-orange-600 mb-2">Sudah Pre-Test</p>
                    <p class="text-3xl font-black text-gray-900"><?= $statPreTest ?></p>
                    <p class="text-[10px] text-gray-400">agen</p>
                </div>
                <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6">
                    <p class="text-[9px] font-black uppercase tracking-widest text-red-700 mb-2">Sudah Post-Test</p>
                    <p class="text-3xl font-black text-gray-900"><?= $statPostTest ?></p>
                    <p class="text-[10px] text-gray-400">agen</p>
                </div>
                <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6">
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Rata Pre-Test</p>
                    <p class="text-3xl font-black text-gray-900"><?= $rataPreTest ? number_format($rataPreTest, 1) : '—' ?></p>
                    <p class="text-[10px] text-gray-400">dari 100</p>
                </div>
                <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6">
                    <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Rata Post-Test</p>
                    <p class="text-3xl font-black text-gray-900"><?= $rataPostTest ? number_format($rataPostTest, 1) : '—' ?></p>
                    <p class="text-[10px] text-gray-400">dari 100</p>
                </div>
            </div>

            <form method="GET" class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 mb-6 flex flex-wrap gap-4 items-end">
                <div>
                    <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Jenis Test</label>
                    <select name="jenis" class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none focus:border-orange-500">
                        <option value="">Semua</option>
                        <option value="pre_test" <?= $filterJenis === 'pre_test' ? 'selected' : '' ?>>Pre-Test</option>
                        <option value="post_test" <?= $filterJenis === 'post_test' ? 'selected' : '' ?>>Post-Test</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[9px] font-black uppercase tracking-widest text-gray-400 mb-2">Agen</label>
                    <select name="agen_id" class="px-4 py-3 rounded-xl border border-gray-200 text-sm font-semibold outline-none focus:border-orange-500">
                        <option value="">Semua Agen</option>
                        <?php foreach ($daftarAgen as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $filterAgen == $a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['nama']) ?> (<?= $a['agen_id'] ?: '-' ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="px-6 py-3 bg-red-800 hover:bg-black text-white text-xs font-black uppercase tracking-widest rounded-xl transition-all">
                    <i class="fas fa-search mr-1"></i> Filter
                </button>
                <a href="hasil-test-admin" class="px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-600 text-xs font-black uppercase tracking-widest rounded-xl transition-all">Reset</a>
            </form>

            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-gray-50/50">
                                <th class="px-6 py-5 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Agen</th>
                                <th class="px-6 py-5 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Jenis</th>
                                <th class="px-6 py-5 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Paket Soal</th>
                                <th class="px-6 py-5 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] text-center">Nilai</th>
                                <th class="px-6 py-5 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em]">Waktu</th>
                                <th class="px-6 py-5 text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if (count($hasilList) > 0): foreach ($hasilList as $h):
                                [$cls, $ket] = nilaiBadge((float)$h['nilai']);
                            ?>
                            <tr class="hover:bg-orange-50/30 transition-all">
                                <td class="px-6 py-5">
                                    <p class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($h['nama_agen']) ?></p>
                                    <p class="text-[10px] text-gray-400"><?= htmlspecialchars($h['kode_agen'] ?: '-') ?>
                                        <?php if (!empty($h['is_manual'])): ?>
                                        <span class="ml-1 px-1.5 py-0.5 bg-purple-100 text-purple-700 text-[8px] font-black uppercase rounded">Manual</span>
                                        <?php endif; ?>
                                    </p>
                                </td>
                                <td class="px-6 py-5">
                                    <?php if ($h['jenis'] === 'pre_test'): ?>
                                    <span class="px-3 py-1 bg-orange-100 text-orange-700 text-[9px] font-black uppercase tracking-widest rounded-lg">Pre-Test</span>
                                    <?php else: ?>
                                    <span class="px-3 py-1 bg-red-100 text-red-700 text-[9px] font-black uppercase tracking-widest rounded-lg">Post-Test</span>
                                    <?php endif; ?>
                                    <?php if ($h['jenis'] === 'post_test' && isset($h['status_sertifikat'])): ?>
                                    <p class="text-[9px] mt-1 font-bold <?= $h['status_sertifikat']==='disetujui'?'text-green-600':($h['status_sertifikat']==='menunggu_ttd'?'text-orange-600':'text-gray-400') ?>">
                                        <?= str_replace('_', ' ', $h['status_sertifikat']) ?>
                                    </p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-5 text-sm text-gray-600 font-medium">
                                    <?= htmlspecialchars($h['judul']) ?>
                                    <?php if ($h['tanggal_test']): ?>
                                    <p class="text-[10px] text-orange-500 font-bold mt-0.5"><i class="fas fa-calendar-alt mr-1"></i><?= (new DateTime($h['tanggal_test']))->format('d M Y') ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-5 text-center">
                                    <p class="text-xl font-black text-gray-900"><?= number_format($h['nilai'], 0) ?></p>
                                    <p class="text-[9px] text-gray-400"><?= $h['jawaban_benar'] ?>/<?= $h['total_pertanyaan'] ?></p>
                                    <span class="text-[8px] font-black px-2 py-0.5 rounded-lg <?= $cls ?>"><?= $ket ?></span>
                                </td>
                                <td class="px-6 py-5 text-xs text-gray-500"><?= date('d M Y, H:i', strtotime($h['waktu_selesai'])) ?></td>
                                <td class="px-6 py-5 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <a href="detail-hasil-test?id=<?= $h['id'] ?>" class="w-9 h-9 rounded-xl bg-gray-50 hover:bg-orange-50 text-gray-400 hover:text-orange-600 flex items-center justify-center transition-all" title="Detail">
                                            <i class="fas fa-eye text-sm"></i>
                                        </a>
                                        <?php if ($bolehEditNilai): ?>
                                        <a href="edit-nilai-test?id=<?= $h['id'] ?>" class="w-9 h-9 rounded-xl bg-orange-50 hover:bg-orange-100 text-orange-600 flex items-center justify-center transition-all" title="Edit / isi manual">
                                            <i class="fas fa-pencil text-sm"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr>
                                <td colspan="6" class="px-6 py-16 text-center italic text-gray-400">Belum ada data hasil test. Gunakan tombol <b>Input Nilai Manual</b> untuk mengisi.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
    <?php if ($flashMessage): ?>
    <script>
    Swal.fire({ icon: '<?= $flashType ?: 'success' ?>', title: 'Info', text: '<?= addslashes($flashMessage) ?>', confirmButtonColor: '#991b1b', customClass: { popup: 'rounded-[32px]' } });
    </script>
    <?php endif; ?>
</body>
</html>