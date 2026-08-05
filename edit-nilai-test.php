
<?php
/**
 * Input / update manual nilai Pre-Test atau Post-Test agen.
 * Dipakai karena banyak agen mengerjakan hard-copy, bukan di aplikasi.
 */
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();
cek_staff_atau_admin();

$message = '';
$swal_type = '';

$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$existing = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("
        SELECT ht.*, bs.judul, bs.jenis, u.nama as nama_agen, u.agen_id
        FROM hasil_test ht
        JOIN bank_soal bs ON bs.id = ht.bank_soal_id
        JOIN users u ON u.id = ht.user_id
        WHERE ht.id = ?
    ");
    $stmt->execute([$editId]);
    $existing = $stmt->fetch();
}

$daftarAgen = $pdo->query("SELECT id, nama, agen_id FROM users WHERE role = 'agen' AND status = 'aktif' ORDER BY nama")->fetchAll();
$daftarSoal = $pdo->query("SELECT id, judul, jenis, tanggal FROM bank_soal ORDER BY tanggal DESC, jenis ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId     = (int)($_POST['user_id'] ?? 0);
    $bankId     = (int)($_POST['bank_soal_id'] ?? 0);
    $nilai      = filter_var($_POST['nilai'] ?? null, FILTER_VALIDATE_FLOAT);
    $benar      = max(0, (int)($_POST['jawaban_benar'] ?? 0));
    $total      = max(1, (int)($_POST['total_pertanyaan'] ?? 15));
    $tanggal    = $_POST['tanggal'] ?? date('Y-m-d');
    $catatan    = htmlspecialchars(trim($_POST['catatan_manual'] ?? ''));
    $idUpdate   = (int)($_POST['id'] ?? 0);

    try {
        if (!$userId || !$bankId) throw new Exception('Agen dan paket soal wajib dipilih.');
        if ($nilai === false || $nilai < 0 || $nilai > 100) throw new Exception('Nilai harus 0–100.');

        $waktu = $tanggal . ' ' . date('H:i:s');

        // Cek jenis soal untuk status sertifikat
        $stmtJenis = $pdo->prepare("SELECT jenis FROM bank_soal WHERE id = ?");
        $stmtJenis->execute([$bankId]);
        $jenis = $stmtJenis->fetchColumn();
        if (!$jenis) throw new Exception('Paket soal tidak ditemukan.');

        $statusSert = 'belum';
        if ($jenis === 'post_test' && $nilai >= 70) {
            $statusSert = 'menunggu_ttd';
        }

        if ($idUpdate > 0) {
            $sql = "UPDATE hasil_test SET
                user_id = ?, bank_soal_id = ?, nilai = ?, jawaban_benar = ?, total_pertanyaan = ?,
                waktu_mulai = ?, waktu_selesai = ?, is_manual = 1, catatan_manual = ?,
                status_sertifikat = CASE
                    WHEN ? = 'post_test' AND ? >= 70 AND status_sertifikat = 'disetujui' THEN status_sertifikat
                    WHEN ? = 'post_test' AND ? >= 70 THEN 'menunggu_ttd'
                    ELSE 'belum'
                END
                WHERE id = ?";
            $pdo->prepare($sql)->execute([
                $userId, $bankId, $nilai, $benar, $total,
                $waktu, $waktu, $catatan,
                $jenis, $nilai, $jenis, $nilai, $idUpdate
            ]);
            $message = 'Nilai berhasil diperbarui.';
        } else {
            $sql = "INSERT INTO hasil_test
                (user_id, bank_soal_id, nilai, jawaban_benar, total_pertanyaan, waktu_mulai, waktu_selesai, status_sertifikat, is_manual, catatan_manual)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)";
            $pdo->prepare($sql)->execute([
                $userId, $bankId, $nilai, $benar, $total, $waktu, $waktu, $statusSert, $catatan
            ]);
            $message = 'Nilai manual berhasil disimpan.';
        }
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = 'success';
        header('Location: hasil-test-admin');
        exit;
    } catch (Exception $e) {
        $swal_type = 'error';
        $message = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $existing ? 'Edit' : 'Input' ?> Nilai Manual | BBPOM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }</style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">
    <?php include 'views/includes/sidebar.php'; ?>
    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
        <div class="max-w-xl mx-auto">
            <header class="mb-8">
                <a href="hasil-test-admin" class="inline-flex items-center text-red-800 font-black text-xs uppercase tracking-widest">
                    <i class="fas fa-arrow-left mr-2"></i> Kembali
                </a>
                <h2 class="text-2xl font-black text-gray-900 mt-4 tracking-tight">
                    <?= $existing ? 'Edit Nilai Manual' : 'Input Nilai Pre/Post Manual' ?>
                </h2>
                <p class="text-sm text-gray-400 mt-1">Untuk agen yang mengerjakan pre/post di luar aplikasi (hard copy).</p>
            </header>

            <form method="POST" class="bg-white rounded-[32px] border border-gray-100 shadow-sm p-8 space-y-5">
                <?php if ($existing): ?>
                <input type="hidden" name="id" value="<?= (int)$existing['id'] ?>">
                <?php endif; ?>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Agen</label>
                    <select name="user_id" required class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 font-semibold text-sm outline-none focus:border-orange-500">
                        <option value="">— Pilih Agen —</option>
                        <?php foreach ($daftarAgen as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= (($existing['user_id'] ?? '') == $a['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['nama']) ?> (<?= htmlspecialchars($a['agen_id'] ?: '-') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Paket Soal</label>
                    <select name="bank_soal_id" required class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 font-semibold text-sm outline-none focus:border-orange-500">
                        <option value="">— Pilih Paket —</option>
                        <?php foreach ($daftarSoal as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= (($existing['bank_soal_id'] ?? '') == $s['id']) ? 'selected' : '' ?>>
                            [<?= $s['jenis'] === 'pre_test' ? 'Pre' : 'Post' ?>] <?= htmlspecialchars($s['judul']) ?>
                            <?= $s['tanggal'] ? ' · ' . $s['tanggal'] : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Nilai (0–100)</label>
                        <input type="number" name="nilai" min="0" max="100" step="0.01" required
                               value="<?= htmlspecialchars($existing['nilai'] ?? '') ?>"
                               class="w-full px-4 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 font-black text-lg outline-none focus:border-orange-500">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Benar</label>
                        <input type="number" name="jawaban_benar" min="0" value="<?= htmlspecialchars($existing['jawaban_benar'] ?? '0') ?>"
                               class="w-full px-4 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 font-semibold outline-none focus:border-orange-500">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Total Soal</label>
                        <input type="number" name="total_pertanyaan" min="1" value="<?= htmlspecialchars($existing['total_pertanyaan'] ?? '15') ?>"
                               class="w-full px-4 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 font-semibold outline-none focus:border-orange-500">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Tanggal Test</label>
                    <input type="date" name="tanggal" required
                           value="<?= htmlspecialchars($existing ? date('Y-m-d', strtotime($existing['waktu_selesai'])) : date('Y-m-d')) ?>"
                           class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 font-semibold outline-none focus:border-orange-500">
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Catatan (opsional)</label>
                    <textarea name="catatan_manual" rows="2" placeholder="Contoh: Input dari hard copy 12 Juli 2026"
                              class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 font-semibold text-sm outline-none focus:border-orange-500"><?= htmlspecialchars($existing['catatan_manual'] ?? '') ?></textarea>
                </div>

                <p class="text-[11px] text-gray-400 italic">
                    Jika post-test ≥ 70, status sertifikat otomatis menjadi <b>menunggu TTD</b> Kepala Balai (kecuali sudah disetujui).
                </p>

                <button type="submit" class="w-full bg-red-800 hover:bg-black text-white font-black py-4 rounded-2xl uppercase tracking-widest text-xs transition-all">
                    <i class="fas fa-save mr-2"></i> Simpan Nilai
                </button>
            </form>
        </div>
    </main>
    <?php if ($swal_type): ?>
    <script>
    Swal.fire({ icon: '<?= $swal_type ?>', title: 'Gagal', text: '<?= addslashes($message) ?>', confirmButtonColor: '#991b1b', customClass: { popup: 'rounded-[32px]' } });
    </script>
    <?php endif; ?>
</body>
</html>