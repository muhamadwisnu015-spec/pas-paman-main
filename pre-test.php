<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();

if ($_SESSION['role'] !== 'agen') {
    header("Location: admin-dashboard");
    exit;
}

$userId = $_SESSION['user_id'];

$flashMessage = $_SESSION['flash_message'] ?? '';
$flashType    = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Ambil pre-test aktif untuk hari ini
$stmtBank = $pdo->prepare("SELECT * FROM bank_soal WHERE jenis = 'pre_test' AND status = 'aktif' AND tanggal = CURDATE() LIMIT 1");
$stmtBank->execute();
$bankSoal = $stmtBank->fetch();

if (!$bankSoal) {
    $_SESSION['flash_message'] = 'Tidak ada Pre-Test untuk hari ini.';
    $_SESSION['flash_type'] = 'info';
    header("Location: dashboard");
    exit;
}

// Cek apakah sudah dikerjakan
$stmtCek = $pdo->prepare("SELECT id FROM hasil_test WHERE user_id = ? AND bank_soal_id = ?");
$stmtCek->execute([$userId, $bankSoal['id']]);
if ($stmtCek->fetch()) {
    $_SESSION['flash_message'] = 'Kamu sudah mengerjakan Pre-Test ini.';
    $_SESSION['flash_type'] = 'info';
    header("Location: hasil-test");
    exit;
}

// Ambil semua pertanyaan beserta opsi
$stmtP = $pdo->prepare("SELECT * FROM pertanyaan WHERE bank_soal_id = ? ORDER BY urutan");
$stmtP->execute([$bankSoal['id']]);
$pertanyaanList = $stmtP->fetchAll();

$opsiMap = [];
if (!empty($pertanyaanList)) {
    $pids = array_column($pertanyaanList, 'id');
    $ph   = implode(',', array_fill(0, count($pids), '?'));
    $stmtO = $pdo->prepare("SELECT * FROM opsi_jawaban WHERE pertanyaan_id IN ($ph) ORDER BY id");
    $stmtO->execute($pids);
    foreach ($stmtO->fetchAll() as $opsi) {
        $opsiMap[$opsi['pertanyaan_id']][] = $opsi;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pre-Test | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        input[type="radio"] { accent-color: #991b1b; }
        .soal-card { scroll-margin-top: 100px; }
    </style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
        <div class="max-w-3xl mx-auto">

            <div class="bg-orange-600 text-white rounded-3xl p-6 mb-8 flex items-center gap-4">
                <div class="w-12 h-12 bg-white/20 rounded-2xl flex items-center justify-center shrink-0">
                    <i class="fas fa-clipboard-list text-xl"></i>
                </div>
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-orange-200">Pre-Test</p>
                    <h1 class="text-xl font-black tracking-tight"><?= htmlspecialchars($bankSoal['judul']) ?></h1>
                    <?php if ($bankSoal['deskripsi']): ?>
                    <p class="text-xs text-orange-100 mt-1"><?= htmlspecialchars($bankSoal['deskripsi']) ?></p>
                    <?php endif; ?>
                    <p class="text-xs font-bold text-orange-200 mt-1">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        <?= $bankSoal['tanggal'] ? (new DateTime($bankSoal['tanggal']))->format('d F Y') : '' ?>
                        &middot; <?= count($pertanyaanList) ?> soal &middot; Kerjakan dengan jujur
                    </p>
                </div>
            </div>

            <form action="submit-test" method="POST" id="formTest">
                <input type="hidden" name="bank_soal_id" value="<?= $bankSoal['id'] ?>">
                <input type="hidden" name="waktu_mulai" id="waktuMulai" value="<?= date('Y-m-d H:i:s') ?>">

                <div class="space-y-6">
                    <?php foreach ($pertanyaanList as $no => $p): ?>
                    <div class="soal-card bg-white rounded-3xl border border-gray-100 shadow-sm p-6" id="soal-<?= $p['id'] ?>">
                        <p class="text-[9px] font-black uppercase tracking-widest text-orange-600 mb-2">Soal <?= $no + 1 ?></p>
                        <p class="font-bold text-gray-900 mb-4 leading-relaxed"><?= htmlspecialchars($p['teks_pertanyaan']) ?></p>
                        <div class="space-y-3">
                            <?php foreach ($opsiMap[$p['id']] ?? [] as $opsi): ?>
                            <label class="flex items-start gap-3 p-4 rounded-2xl border border-gray-100 hover:border-orange-300 hover:bg-orange-50/50 cursor-pointer transition-all has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50">
                                <input type="radio" name="jawaban[<?= $p['id'] ?>]" value="<?= $opsi['id'] ?>" class="mt-0.5 w-4 h-4 shrink-0">
                                <span class="text-sm font-semibold text-gray-700"><?= htmlspecialchars($opsi['teks_opsi']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-8">
                    <button type="button" id="btnSubmit"
                            class="w-full bg-red-800 hover:bg-black text-white font-black py-5 rounded-[28px] shadow-xl transition-all transform active:scale-[0.98] hover:-translate-y-1 text-sm uppercase tracking-widest">
                        <i class="fas fa-paper-plane mr-2"></i> Submit Pre-Test
                    </button>
                </div>
            </form>

            <footer class="mt-8 text-center text-[10px] text-gray-400">Pastikan semua soal sudah dijawab sebelum submit.</footer>
        </div>
    </main>

    <script>
    document.getElementById('btnSubmit').addEventListener('click', function() {
        const totalSoal = <?= count($pertanyaanList) ?>;
        const pids = <?= json_encode(array_column($pertanyaanList, 'id')) ?>;
        let unanswered = [];

        pids.forEach(pid => {
            const checked = document.querySelector('input[name="jawaban[' + pid + ']"]:checked');
            if (!checked) unanswered.push(pid);
        });

        if (unanswered.length > 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Soal Belum Dijawab',
                text: unanswered.length + ' soal belum dijawab. Pastikan semua soal terisi.',
                confirmButtonColor: '#991b1b',
                confirmButtonText: 'OK, Cek Lagi',
                customClass: { popup: 'rounded-[32px]' }
            }).then(() => {
                const el = document.getElementById('soal-' + unanswered[0]);
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
            return;
        }

        Swal.fire({
            icon: 'question',
            title: 'Submit Pre-Test?',
            text: 'Jawaban tidak bisa diubah setelah submit.',
            showCancelButton: true,
            confirmButtonColor: '#991b1b',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Ya, Submit!',
            cancelButtonText: 'Cek Dulu',
            customClass: { popup: 'rounded-[32px]' }
        }).then(result => {
            if (result.isConfirmed) {
                document.getElementById('formTest').submit();
            }
        });
    });
    </script>

    <?php if ($flashMessage): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: '<?= $flashType ?: 'info' ?>',
            title: '<?= $flashType === 'warning' ? 'Perhatian' : 'Info' ?>',
            text: '<?= addslashes($flashMessage) ?>',
            confirmButtonColor: '#991b1b',
            customClass: { popup: 'rounded-[32px]' }
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
