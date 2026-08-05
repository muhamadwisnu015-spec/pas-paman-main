<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();

if ($_SESSION['role'] !== 'agen') {
    header("Location: admin-dashboard");
    exit;
}

$userId   = $_SESSION['user_id'];
$hasilId  = (int)($_GET['id'] ?? 0);
if (!$hasilId) { header("Location: hasil-test"); exit; }

// Ambil data hasil test — pastikan milik agen ini
$stmtHasil = $pdo->prepare("
    SELECT ht.*, bs.judul, bs.jenis, bs.tanggal as tanggal_test
    FROM hasil_test ht
    JOIN bank_soal bs ON bs.id = ht.bank_soal_id
    WHERE ht.id = ? AND ht.user_id = ?
");
$stmtHasil->execute([$hasilId, $userId]);
$hasil = $stmtHasil->fetch();
if (!$hasil) { header("Location: hasil-test"); exit; }

// Ambil semua pertanyaan dalam bank soal ini, beserta jawaban agen & jawaban benar
$stmtSoal = $pdo->prepare("
    SELECT
        p.id as pid,
        p.teks_pertanyaan,
        p.urutan,
        dj.opsi_id as dipilih_id
    FROM pertanyaan p
    LEFT JOIN detail_jawaban dj ON dj.pertanyaan_id = p.id AND dj.hasil_test_id = ?
    WHERE p.bank_soal_id = ?
    ORDER BY p.urutan
");
$stmtSoal->execute([$hasilId, $hasil['bank_soal_id']]);
$soalList = $stmtSoal->fetchAll();

// Ambil semua opsi per pertanyaan sekaligus
$pids = array_column($soalList, 'pid');
$opsiMap = [];
if (!empty($pids)) {
    $ph = implode(',', array_fill(0, count($pids), '?'));
    $stmtOpsi = $pdo->prepare("SELECT * FROM opsi_jawaban WHERE pertanyaan_id IN ($ph) ORDER BY id");
    $stmtOpsi->execute($pids);
    foreach ($stmtOpsi->fetchAll() as $opsi) {
        $opsiMap[$opsi['pertanyaan_id']][] = $opsi;
    }
}

function nilaiBadge($nilai) {
    if ($nilai >= 80) return ['bg-green-100 text-green-700', 'Sangat Baik'];
    if ($nilai >= 60) return ['bg-orange-100 text-orange-700', 'Cukup'];
    return ['bg-red-100 text-red-700', 'Perlu Belajar'];
}
[$badgeCls, $badgeKet] = nilaiBadge($hasil['nilai']);
$isPreTest = $hasil['jenis'] === 'pre_test';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Hasil Test | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
    </style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
        <div class="max-w-3xl mx-auto">

            <header class="mb-8">
                <a href="hasil-test" class="inline-flex items-center text-red-800 font-black text-xs uppercase tracking-widest hover:translate-x-1 transition-transform">
                    <i class="fas fa-arrow-left mr-2"></i> Kembali ke Hasil Test
                </a>
            </header>

            <!-- Ringkasan Nilai -->
            <div class="<?= $isPreTest ? 'bg-orange-600' : 'bg-red-800' ?> text-white rounded-3xl p-6 mb-8">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <p class="text-[9px] font-black uppercase tracking-widest <?= $isPreTest ? 'text-orange-200' : 'text-red-300' ?> mb-1">
                            <?= $isPreTest ? 'Pre-Test' : 'Post-Test' ?>
                        </p>
                        <h1 class="text-xl font-black tracking-tight"><?= htmlspecialchars($hasil['judul']) ?></h1>
                        <p class="text-xs <?= $isPreTest ? 'text-orange-200' : 'text-red-200' ?> mt-1">
                            <i class="fas fa-calendar-alt mr-1"></i>
                            <?= $hasil['tanggal_test'] ? (new DateTime($hasil['tanggal_test']))->format('d F Y') : '' ?>
                            &middot; Dikerjakan <?= date('H:i', strtotime($hasil['waktu_selesai'])) ?>
                        </p>
                    </div>
                    <div class="text-center bg-white/20 rounded-2xl px-6 py-4 shrink-0">
                        <p class="text-4xl font-black"><?= number_format($hasil['nilai'], 0) ?><span class="text-xl opacity-60">/100</span></p>
                        <p class="text-xs <?= $isPreTest ? 'text-orange-100' : 'text-red-100' ?> mt-1"><?= $hasil['jawaban_benar'] ?>/<?= $hasil['total_pertanyaan'] ?> benar</p>
                        <span class="mt-2 inline-block px-3 py-1 rounded-xl text-[9px] font-black uppercase tracking-widest <?= $badgeCls ?>"><?= $badgeKet ?></span>
                    </div>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-6 mb-8">
                <div class="flex justify-between text-xs font-bold text-gray-500 mb-2">
                    <span>Jawaban Benar</span>
                    <span><?= $hasil['jawaban_benar'] ?> / <?= $hasil['total_pertanyaan'] ?></span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-3">
                    <?php $pct = $hasil['total_pertanyaan'] > 0 ? ($hasil['jawaban_benar'] / $hasil['total_pertanyaan']) * 100 : 0; ?>
                    <div class="h-3 rounded-full transition-all <?= $pct >= 80 ? 'bg-green-500' : ($pct >= 60 ? 'bg-orange-500' : 'bg-red-600') ?>"
                         style="width: <?= $pct ?>%"></div>
                </div>
                <div class="flex gap-4 mt-4 text-xs">
                    <span class="flex items-center gap-1.5 text-green-600 font-bold"><span class="w-3 h-3 rounded-full bg-green-100 border-2 border-green-500 inline-block"></span>Benar: <?= $hasil['jawaban_benar'] ?></span>
                    <span class="flex items-center gap-1.5 text-red-600 font-bold"><span class="w-3 h-3 rounded-full bg-red-100 border-2 border-red-500 inline-block"></span>Salah: <?= $hasil['total_pertanyaan'] - $hasil['jawaban_benar'] ?></span>
                </div>
            </div>

            <!-- Detail Tiap Soal -->
            <h2 class="text-xs font-black uppercase tracking-[0.2em] text-gray-400 mb-4 flex items-center gap-2">
                <span class="w-5 h-1 bg-red-800 rounded-full"></span>
                Pembahasan Soal
            </h2>

            <div class="space-y-5">
            <?php foreach ($soalList as $no => $soal):
                $opsiList   = $opsiMap[$soal['pid']] ?? [];
                $dipilihId  = $soal['dipilih_id'];

                // Cari opsi benar dan cek apakah jawaban agen benar
                $opsiBenar  = null;
                $isBenar    = false;
                foreach ($opsiList as $o) {
                    if ($o['adalah_benar']) $opsiBenar = $o;
                    if ($o['id'] == $dipilihId && $o['adalah_benar']) $isBenar = true;
                }
                $tidakMenjawab = !$dipilihId;
            ?>
            <div class="bg-white rounded-3xl border <?= $isBenar ? 'border-green-200' : 'border-red-200' ?> shadow-sm overflow-hidden">
                <!-- Header soal -->
                <div class="flex items-center gap-3 px-6 py-4 <?= $isBenar ? 'bg-green-50' : 'bg-red-50' ?> border-b <?= $isBenar ? 'border-green-100' : 'border-red-100' ?>">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0 <?= $isBenar ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600' ?>">
                        <i class="fas <?= $isBenar ? 'fa-check' : 'fa-times' ?> text-sm"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-[9px] font-black uppercase tracking-widest <?= $isBenar ? 'text-green-600' : 'text-red-600' ?>">
                            Soal <?= $no + 1 ?> &mdash; <?= $isBenar ? 'Benar' : ($tidakMenjawab ? 'Tidak Dijawab' : 'Salah') ?>
                        </p>
                    </div>
                </div>

                <div class="px-6 py-5">
                    <!-- Teks pertanyaan -->
                    <p class="font-bold text-gray-900 leading-relaxed mb-5"><?= htmlspecialchars($soal['teks_pertanyaan']) ?></p>

                    <!-- Semua opsi dengan keterangan -->
                    <div class="space-y-2">
                    <?php foreach ($opsiList as $opsi):
                        $isDipilih = ($opsi['id'] == $dipilihId);
                        $isOpsiBenar = (bool)$opsi['adalah_benar'];

                        if ($isOpsiBenar && $isDipilih) {
                            // Dipilih & benar
                            $rowCls  = 'border-green-400 bg-green-50';
                            $dotCls  = 'bg-green-500';
                            $textCls = 'text-green-800';
                            $badge   = '<span class="text-[8px] font-black px-2 py-0.5 bg-green-200 text-green-700 rounded-lg uppercase tracking-widest">Jawaban Kamu ✓</span>';
                        } elseif ($isOpsiBenar && !$isDipilih) {
                            // Benar tapi tidak dipilih
                            $rowCls  = 'border-green-300 bg-green-50/50';
                            $dotCls  = 'bg-green-400';
                            $textCls = 'text-green-700';
                            $badge   = '<span class="text-[8px] font-black px-2 py-0.5 bg-green-100 text-green-600 rounded-lg uppercase tracking-widest">Jawaban Benar</span>';
                        } elseif (!$isOpsiBenar && $isDipilih) {
                            // Dipilih tapi salah
                            $rowCls  = 'border-red-400 bg-red-50';
                            $dotCls  = 'bg-red-500';
                            $textCls = 'text-red-800';
                            $badge   = '<span class="text-[8px] font-black px-2 py-0.5 bg-red-200 text-red-700 rounded-lg uppercase tracking-widest">Jawaban Kamu ✗</span>';
                        } else {
                            // Tidak dipilih & salah
                            $rowCls  = 'border-gray-100 bg-white';
                            $dotCls  = 'bg-gray-200';
                            $textCls = 'text-gray-600';
                            $badge   = '';
                        }
                    ?>
                    <div class="flex items-start gap-3 p-3.5 rounded-2xl border <?= $rowCls ?> transition-all">
                        <span class="w-3 h-3 rounded-full <?= $dotCls ?> shrink-0 mt-1"></span>
                        <div class="flex-1 flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold <?= $textCls ?>"><?= htmlspecialchars($opsi['teks_opsi']) ?></span>
                            <?= $badge ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($tidakMenjawab): ?>
                    <p class="text-xs text-red-500 font-bold mt-2 italic">
                        <i class="fas fa-exclamation-circle mr-1"></i>Soal ini tidak dijawab.
                        Jawaban benar: <span class="not-italic"><?= htmlspecialchars($opsiBenar->teks_opsi ?? '-') ?></span>
                    </p>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <div class="mt-8 text-center">
                <a href="hasil-test" class="inline-flex items-center gap-2 bg-red-800 hover:bg-black text-white text-xs font-black uppercase tracking-widest px-8 py-4 rounded-2xl transition-all">
                    <i class="fas fa-arrow-left"></i> Kembali ke Hasil Test
                </a>
            </div>

        </div>
    </main>
</body>
</html>
