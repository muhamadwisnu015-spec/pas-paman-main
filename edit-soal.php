<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();
cek_staff_atau_admin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: daftar-soal"); exit; }

$bankSoal = $pdo->prepare("SELECT * FROM bank_soal WHERE id = ?");
$bankSoal->execute([$id]);
$bankSoal = $bankSoal->fetch();
if (!$bankSoal) { header("Location: daftar-soal"); exit; }

$pertanyaanList = $pdo->prepare("SELECT * FROM pertanyaan WHERE bank_soal_id = ? ORDER BY urutan");
$pertanyaanList->execute([$id]);
$pertanyaanList = $pertanyaanList->fetchAll();

$opsiMap = [];
if (!empty($pertanyaanList)) {
    $pids = array_column($pertanyaanList, 'id');
    $placeholders = implode(',', array_fill(0, count($pids), '?'));
    $stmtOpsi = $pdo->prepare("SELECT * FROM opsi_jawaban WHERE pertanyaan_id IN ($placeholders) ORDER BY id");
    $stmtOpsi->execute($pids);
    foreach ($stmtOpsi->fetchAll() as $opsi) {
        $opsiMap[$opsi['pertanyaan_id']][] = $opsi;
    }
}

$message = '';
$swal_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $judul     = htmlspecialchars(trim($_POST['judul']));
    $deskripsi = htmlspecialchars(trim($_POST['deskripsi']));
    $jenis     = in_array($_POST['jenis'], ['pre_test', 'post_test']) ? $_POST['jenis'] : $bankSoal['jenis'];
    $tanggal   = $_POST['tanggal'] ?? $bankSoal['tanggal'];
    $tanggal   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ? $tanggal : $bankSoal['tanggal'];
    $pertanyaans = $_POST['pertanyaan'] ?? [];
    $opsis       = $_POST['opsi'] ?? [];
    $benars      = $_POST['benar'] ?? [];

    if (empty($judul)) {
        $swal_type = 'error'; $message = 'Judul tidak boleh kosong.';
    } elseif (count(array_filter($pertanyaans, fn($t) => trim($t) !== '')) === 0) {
        $swal_type = 'error'; $message = 'Tambahkan minimal 1 pertanyaan.';
    } else {
        try {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE bank_soal SET judul = ?, deskripsi = ?, jenis = ?, tanggal = ? WHERE id = ?")
                ->execute([$judul, $deskripsi, $jenis, $tanggal, $id]);

            // Hapus semua pertanyaan & opsi lama (cascade)
            $pdo->prepare("DELETE FROM pertanyaan WHERE bank_soal_id = ?")->execute([$id]);

            foreach ($pertanyaans as $idx => $teks) {
                $teks = htmlspecialchars(trim($teks));
                if (empty($teks)) continue;
                $pdo->prepare("INSERT INTO pertanyaan (bank_soal_id, teks_pertanyaan, urutan) VALUES (?, ?, ?)")
                    ->execute([$id, $teks, $idx + 1]);
                $pId = $pdo->lastInsertId();

                $opsiList = $opsis[$idx] ?? [];
                $benarIdx = isset($benars[$idx]) ? (int)$benars[$idx] : -1;
                foreach ($opsiList as $oIdx => $teksOpsi) {
                    $teksOpsi = htmlspecialchars(trim($teksOpsi));
                    if (empty($teksOpsi)) continue;
                    $adalahBenar = ($oIdx === $benarIdx) ? 1 : 0;
                    $pdo->prepare("INSERT INTO opsi_jawaban (pertanyaan_id, teks_opsi, adalah_benar) VALUES (?, ?, ?)")
                        ->execute([$pId, $teksOpsi, $adalahBenar]);
                }
            }

            $pdo->commit();
            $_SESSION['flash_message'] = 'Paket soal berhasil diperbarui!';
            $_SESSION['flash_type'] = 'success';
            header("Location: daftar-soal");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $swal_type = 'error';
            $message = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Soal | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .opsi-row input[type="radio"] { accent-color: #991b1b; }
    </style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">

    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-10 overflow-y-auto">
        <div class="max-w-3xl mx-auto">

            <header class="mb-8">
                <a href="daftar-soal" class="inline-flex items-center text-red-800 font-black text-xs uppercase tracking-widest hover:translate-x-1 transition-transform">
                    <i class="fas fa-arrow-left mr-2"></i> Kembali ke Daftar Soal
                </a>
            </header>

            <div class="bg-white rounded-[40px] shadow-sm border border-gray-100 overflow-hidden">
                <div class="bg-red-800 p-8 text-white">
                    <h2 class="text-2xl font-black tracking-tight">Edit Paket Soal</h2>
                    <p class="text-orange-200 text-xs font-medium mt-1"><?= htmlspecialchars($bankSoal['judul']) ?></p>
                </div>

                <form action="" method="POST" class="p-8 space-y-8" id="formSoal">

                    <div class="space-y-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Judul Paket Soal</label>
                            <input type="text" name="judul" required
                                   value="<?= htmlspecialchars($bankSoal['judul']) ?>"
                                   class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold text-sm">
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Jenis Test</label>
                                <select name="jenis" class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-bold text-sm">
                                    <option value="pre_test" <?= $bankSoal['jenis'] === 'pre_test' ? 'selected' : '' ?>>Pre-Test</option>
                                    <option value="post_test" <?= $bankSoal['jenis'] === 'post_test' ? 'selected' : '' ?>>Post-Test</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mb-2">Tanggal Test</label>
                                <input type="date" name="tanggal" required
                                       value="<?= htmlspecialchars($bankSoal['tanggal'] ?? date('Y-m-d')) ?>"
                                       class="w-full px-5 py-4 rounded-2xl bg-orange-50/30 border border-orange-100 focus:border-orange-500 outline-none font-bold text-sm text-orange-700">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Deskripsi (Opsional)</label>
                                <input type="text" name="deskripsi"
                                       value="<?= htmlspecialchars($bankSoal['deskripsi']) ?>"
                                       class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold text-sm">
                            </div>
                        </div>
                    </div>

                    <hr class="border-gray-100">

                    <div>
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-xs font-black uppercase tracking-[0.2em] text-gray-600">Daftar Pertanyaan</h3>
                            <span class="text-[10px] text-gray-400" id="jumlahSoal">0 soal</span>
                        </div>
                        <div id="soalContainer" class="space-y-6"></div>
                    </div>

                    <button type="button" id="btnTambahSoal"
                            class="w-full py-4 border-2 border-dashed border-orange-300 rounded-3xl text-orange-600 font-black text-xs uppercase tracking-widest hover:bg-orange-50 hover:border-orange-500 transition-all flex items-center justify-center gap-2">
                        <i class="fas fa-plus-circle"></i> Tambah Pertanyaan
                    </button>

                    <button type="submit"
                            class="w-full bg-red-800 hover:bg-black text-white font-black py-5 rounded-[28px] shadow-xl transition-all transform active:scale-[0.98] hover:-translate-y-1 text-sm uppercase tracking-widest">
                        Simpan Perubahan
                    </button>

                </form>
            </div>
        </div>
    </main>

    <template id="templateSoal">
        <div class="soal-block bg-gray-50 rounded-3xl p-6 border border-gray-100" data-index="__IDX__">
            <div class="flex justify-between items-start mb-4">
                <span class="text-[10px] font-black uppercase tracking-widest text-red-800">Soal #<span class="soal-nomor">__NUM__</span></span>
                <button type="button" class="btn-hapus-soal text-gray-300 hover:text-red-600 transition-colors">
                    <i class="fas fa-times-circle text-lg"></i>
                </button>
            </div>
            <textarea name="pertanyaan[__IDX__]" rows="2" required
                      placeholder="Tulis pertanyaan di sini..."
                      class="w-full px-4 py-3 rounded-2xl bg-white border border-gray-200 focus:border-orange-500 outline-none font-semibold text-sm resize-none mb-4">__TEKS__</textarea>
            <p class="text-[9px] font-black uppercase tracking-widest text-gray-400 mb-3">Pilihan Jawaban <span class="text-red-700">*</span> Centang yang benar</p>
            <div class="opsi-container space-y-2"></div>
            <button type="button" class="btn-tambah-opsi mt-3 text-[10px] font-black text-orange-600 hover:text-orange-800 uppercase tracking-widest flex items-center gap-1 transition-colors">
                <i class="fas fa-plus text-xs"></i> Tambah Opsi
            </button>
        </div>
    </template>

    <template id="templateOpsi">
        <div class="opsi-row flex items-center gap-3">
            <input type="radio" name="benar[__IDX__]" value="__OIDX__" class="w-4 h-4 shrink-0" title="Tandai sebagai jawaban benar" __CHECKED__>
            <input type="text" name="opsi[__IDX__][__OIDX__]" required
                   placeholder="Opsi jawaban..."
                   value="__TEKSOPSI__"
                   class="flex-1 px-4 py-2.5 rounded-xl bg-white border border-gray-200 focus:border-orange-500 outline-none font-semibold text-sm">
            <button type="button" class="btn-hapus-opsi text-gray-200 hover:text-red-500 transition-colors shrink-0">
                <i class="fas fa-minus-circle"></i>
            </button>
        </div>
    </template>

    <script>
    // Data soal yang sudah ada (dari PHP)
    const existingData = <?= json_encode(array_map(function($p) use ($opsiMap) {
        return [
            'teks' => $p['teks_pertanyaan'],
            'opsi' => array_map(function($o) {
                return ['teks' => $o['teks_opsi'], 'benar' => (bool)$o['adalah_benar']];
            }, $opsiMap[$p['id']] ?? [])
        ];
    }, $pertanyaanList)) ?>;

    let soalCount = 0;

    function updateNomor() {
        document.querySelectorAll('#soalContainer .soal-block').forEach((el, i) => {
            el.querySelector('.soal-nomor').textContent = i + 1;
        });
        document.getElementById('jumlahSoal').textContent = soalCount + ' soal';
    }

    function tambahOpsi(soalEl, soalIdx, teksOpsi = '', isBenar = false) {
        const container = soalEl.querySelector('.opsi-container');
        const oIdx = container.children.length;
        const tpl = document.getElementById('templateOpsi').innerHTML
            .replaceAll('__IDX__', soalIdx)
            .replaceAll('__OIDX__', oIdx)
            .replace('__CHECKED__', isBenar ? 'checked' : '')
            .replace('__TEKSOPSI__', teksOpsi.replace(/"/g, '&quot;'));
        const div = document.createElement('div');
        div.innerHTML = tpl;
        const opsiRow = div.firstElementChild;
        opsiRow.querySelector('.btn-hapus-opsi').addEventListener('click', function() {
            if (container.children.length <= 2) {
                alert('Minimal 2 opsi jawaban per soal.');
                return;
            }
            opsiRow.remove();
            reindexOpsi(soalEl, soalIdx);
        });
        container.appendChild(opsiRow);
    }

    function reindexOpsi(soalEl, soalIdx) {
        soalEl.querySelectorAll('.opsi-container .opsi-row').forEach((row, i) => {
            const radio = row.querySelector('input[type="radio"]');
            const text = row.querySelector('input[type="text"]');
            radio.value = i;
            radio.name = 'benar[' + soalIdx + ']';
            text.name = 'opsi[' + soalIdx + '][' + i + ']';
        });
    }

    function tambahSoal(teks = '', opsiData = []) {
        const idx = soalCount;
        const tpl = document.getElementById('templateSoal').innerHTML
            .replaceAll('__IDX__', idx)
            .replaceAll('__NUM__', soalCount + 1)
            .replace('__TEKS__', teks.replace(/</g, '&lt;').replace(/>/g, '&gt;'));
        const div = document.createElement('div');
        div.innerHTML = tpl;
        const soalEl = div.firstElementChild;

        soalEl.querySelector('.btn-hapus-soal').addEventListener('click', function() {
            soalEl.remove();
            soalCount--;
            updateNomor();
        });

        soalEl.querySelector('.btn-tambah-opsi').addEventListener('click', function() {
            const container = soalEl.querySelector('.opsi-container');
            if (container.children.length >= 5) {
                alert('Maksimal 5 opsi jawaban per soal.');
                return;
            }
            tambahOpsi(soalEl, idx);
        });

        document.getElementById('soalContainer').appendChild(soalEl);

        if (opsiData.length > 0) {
            opsiData.forEach(o => tambahOpsi(soalEl, idx, o.teks, o.benar));
        } else {
            tambahOpsi(soalEl, idx);
            tambahOpsi(soalEl, idx);
            tambahOpsi(soalEl, idx);
            tambahOpsi(soalEl, idx);
        }

        soalCount++;
        updateNomor();
    }

    document.getElementById('btnTambahSoal').addEventListener('click', () => tambahSoal());

    document.getElementById('formSoal').addEventListener('submit', function(e) {
        const soals = document.querySelectorAll('#soalContainer .soal-block');
        if (soals.length === 0) { e.preventDefault(); alert('Tambahkan minimal 1 pertanyaan.'); return; }
        let valid = true;
        soals.forEach(soal => {
            const checked = soal.querySelector('input[type="radio"]:checked');
            if (!checked) {
                valid = false;
                soal.style.outline = '2px solid #dc2626';
                soal.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                soal.style.outline = '';
            }
        });
        if (!valid) { e.preventDefault(); alert('Tandai jawaban yang benar untuk setiap soal!'); }
    });

    // Load data existing
    if (existingData.length > 0) {
        existingData.forEach(s => tambahSoal(s.teks, s.opsi));
    } else {
        tambahSoal();
    }
    </script>

    <?php if ($swal_type): ?>
    <script>
    Swal.fire({
        icon: '<?= $swal_type ?>',
        title: '<?= $swal_type === 'success' ? 'Berhasil' : 'Gagal' ?>',
        text: '<?= addslashes($message) ?>',
        confirmButtonColor: '#991b1b',
        customClass: { popup: 'rounded-[32px]' }
    });
    </script>
    <?php endif; ?>
</body>
</html>
