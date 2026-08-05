<?php
require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/log_laporan.php';
require_once 'core/ntb_wilayah_data.php';
require_once 'core/ntb_helper.php';
require_once 'core/geocoding.php';
cek_login();

if ($_SESSION['role'] !== 'agen') {
    header('Location: dashboard');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';

$stmt = $pdo->prepare("SELECT * FROM catatan_harian WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $user_id]);
$catatan = $stmt->fetch();

if (!$catatan) {
    header('Location: riwayat');
    exit;
}

// Hanya boleh edit jika belum di-approve (pending / revisi)
if (!in_array($catatan['status_review'], ['pending', 'revisi'], true)) {
    $_SESSION['flash_type'] = 'error';
    $_SESSION['flash_message'] = 'Laporan yang sudah disetujui admin tidak bisa diedit.';
    header('Location: detail-catatan?id=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tanggal = $_POST['tanggal'] ?? '';
    $nama_konsumen = htmlspecialchars(trim($_POST['nama_konsumen'] ?? ''));
    $informasi = htmlspecialchars(trim($_POST['informasi'] ?? ''));
    $lokasi = htmlspecialchars(trim($_POST['lokasi'] ?? ''));
    $usia = filter_var($_POST['usia'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
    $jenis_kelamin = $_POST['jenis_kelamin'] ?? '';
    $pekerjaan = htmlspecialchars(trim($_POST['pekerjaan'] ?? ''));
    $nilai_pre_test = filter_var($_POST['nilai_pre_test'] ?? null, FILTER_VALIDATE_FLOAT);
    $nilai_post_test = filter_var($_POST['nilai_post_test'] ?? null, FILTER_VALIDATE_FLOAT);
    $kab_kota = trim($_POST['kab_kota'] ?? '');
    $kecamatan = trim($_POST['kecamatan'] ?? '');
    $desa = trim($_POST['desa'] ?? '');
    $alamat_detail = htmlspecialchars(trim($_POST['alamat_detail'] ?? ''));
    $alamat = ($kab_kota && $kecamatan && $desa)
        ? ntb_format_alamat($kab_kota, $kecamatan, $desa, $alamat_detail)
        : htmlspecialchars(trim($_POST['alamat'] ?? ''));
    $no_hp = htmlspecialchars(trim($_POST['no_hp'] ?? ''));

    // Sumber utama titik peta: pin yang digeser manual oleh agen di peta form
    // (posisi akhir dikirim lewat hidden input latitude/longitude).
    $latitude = filter_var($_POST['latitude'] ?? '', FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($_POST['longitude'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($latitude === false) $latitude = null;
    if ($longitude === false) $longitude = null;

    // Cadangan kalau pin gak terkirim/JS gagal: geocoding dari alamat terbaru,
    // lalu titik lama yang tersimpan, baru fallback titik tengah kecamatan.
    if ($latitude === null || $longitude === null) {
        $geo = geocode_alamat($alamat);
        if ($geo) {
            $latitude = $geo['lat'];
            $longitude = $geo['lng'];
        } elseif (!empty($catatan['latitude']) && !empty($catatan['longitude'])) {
            $latitude = (float)$catatan['latitude'];
            $longitude = (float)$catatan['longitude'];
        } else {
            $coords = ntb_coords_from_wilayah($kab_kota ?? '', $kecamatan ?? '');
            $latitude = $coords['lat'];
            $longitude = $coords['lng'];
        }
    }

    try {
        if (!$tanggal || $nama_konsumen === '') throw new Exception('Tanggal dan nama konsumen wajib diisi.');
        if ($usia === false || !in_array($jenis_kelamin, ['Pria', 'Wanita'], true) || $pekerjaan === '') {
            throw new Exception('Usia, jenis kelamin, dan pekerjaan wajib diisi dengan benar.');
        }
        if ($no_hp === '') throw new Exception('No. WhatsApp / HP konsumen wajib diisi.');
        if ($nilai_pre_test === false || $nilai_post_test === false || $nilai_pre_test < 0 || $nilai_pre_test > 100 || $nilai_post_test < 0 || $nilai_post_test > 100) {
            throw new Exception('Nilai pre-test dan post-test harus diisi dari 0 sampai 100.');
        }

        // Cek kolom opsional (beberapa instalasi belum menjalankan migrasi penuh)
        $cols = $pdo->query("SHOW COLUMNS FROM catatan_harian")->fetchAll(PDO::FETCH_COLUMN);
        $hasLampiran = in_array('lampiran_hasil_test', $cols, true);
        $hasNilaiPre  = in_array('nilai_pre_test', $cols, true);
        $hasNilaiPost = in_array('nilai_post_test', $cols, true);

        $lampiran_hasil_test = $catatan['lampiran_hasil_test'] ?? null;
        if ($hasLampiran && !empty($_FILES['lampiran_hasil_test']['name'])) {
            $file = $_FILES['lampiran_hasil_test'];
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new Exception('Lampiran hasil test gagal diunggah.');
            }
            if ($file['size'] > 5 * 1024 * 1024) throw new Exception('Lampiran hasil test maksimal 5MB.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
            if (!isset($extensions[$mime])) throw new Exception('Lampiran hanya boleh JPG, PNG, atau PDF.');
            $namaFile = 'HASIL_TEST_' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
            if (!move_uploaded_file($file['tmp_name'], 'uploads/' . $namaFile)) {
                throw new Exception('Lampiran tidak dapat disimpan.');
            }
            $lampiran_hasil_test = $namaFile;
        }

        $pdo->beginTransaction();

        // Setelah diedit, status kembali pending agar admin review ulang
        $sets = [
            'tanggal = ?',
            'nama_konsumen = ?',
            'usia = ?',
            'jenis_kelamin = ?',
            'pekerjaan = ?',
            'informasi = ?',
            'lokasi = ?',
            'alamat = ?',
            'no_hp = ?',
            "status_review = 'pending'",
        ];
        $vals = [
            $tanggal, $nama_konsumen, $usia, $jenis_kelamin, $pekerjaan,
            $informasi, $lokasi, $alamat, $no_hp,
        ];
        if ($hasNilaiPre) {
            $sets[] = 'nilai_pre_test = ?';
            $vals[] = $nilai_pre_test;
        }
        if ($hasNilaiPost) {
            $sets[] = 'nilai_post_test = ?';
            $vals[] = $nilai_post_test;
        }
        if ($hasLampiran) {
            $sets[] = 'lampiran_hasil_test = ?';
            $vals[] = $lampiran_hasil_test;
        }
        if (in_array('latitude', $cols, true)) {
            $sets[] = 'latitude = ?';
            $vals[] = $latitude;
        }
        if (in_array('longitude', $cols, true)) {
            $sets[] = 'longitude = ?';
            $vals[] = $longitude;
        }
        $vals[] = $id;
        $vals[] = $user_id;

        $sql = 'UPDATE catatan_harian SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?';
        $pdo->prepare($sql)->execute($vals);

        // Opsional: tambah bukti kegiatan baru
        if (!empty($_FILES['bukti_kegiatan']['name'][0])) {
            foreach ($_FILES['bukti_kegiatan']['tmp_name'] as $key => $tmp_name) {
                if ($key >= 5) break;
                if (!is_uploaded_file($tmp_name)) continue;
                $file_size = $_FILES['bukti_kegiatan']['size'][$key];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp_name);
                $is_image = in_array($mime, ['image/jpeg', 'image/png'], true);
                $is_video = ($mime === 'video/mp4');
                if ($is_image && $file_size > 3 * 1024 * 1024) throw new Exception('Foto terlalu besar (Max 3MB)');
                if ($is_video && $file_size > 10 * 1024 * 1024) throw new Exception('Video terlalu besar (Max 10MB)');
                if (!$is_image && !$is_video) continue;
                $ext = pathinfo($_FILES['bukti_kegiatan']['name'][$key], PATHINFO_EXTENSION);
                $new_name = bin2hex(random_bytes(10)) . '.' . $ext;
                if (move_uploaded_file($tmp_name, 'uploads/' . $new_name)) {
                    $pdo->prepare("INSERT INTO catatan_files (catatan_id, file_path) VALUES (?, ?)")->execute([$id, $new_name]);
                }
            }
        }

        $pdo->commit();

        // Bootstrap GPS profil agen HANYA kalau profil agen belum punya titik
        // sendiri - jangan timpa titik alamat rumah/kantor agen yang sudah
        // ditepatkan manual di profil dengan alamat konsumen pada laporan ini.
        if ($latitude !== null && $longitude !== null) {
            try {
                $colsU = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('latitude', $colsU, true)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN latitude DECIMAL(10,7) NULL");
                    $pdo->exec("ALTER TABLE users ADD COLUMN longitude DECIMAL(10,7) NULL");
                }
                $pdo->prepare("UPDATE users SET latitude = ?, longitude = ? WHERE id = ? AND (latitude IS NULL OR longitude IS NULL)")->execute([$latitude, $longitude, $user_id]);
            } catch (Throwable $e) { /* ignore */ }
        }

        log_laporan($pdo, $user_id, 'edit', $id, 'Mengedit laporan: ' . $nama_konsumen);
        header('Location: detail-catatan?id=' . $id . '&msg=edited');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='bg-red-100 text-red-700 p-4 rounded-2xl mb-6 font-bold text-center border border-red-200'>Gagal: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Laporan | BBPOM Diary</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }</style>
</head>
<body class="bg-gray-50 flex flex-col md:flex-row min-h-screen">
    <?php include 'views/includes/sidebar.php'; ?>
    <main class="flex-1 p-6 md:p-12 overflow-y-auto">
        <div class="max-w-4xl mx-auto">
            <div class="mb-8">
                <a href="detail-catatan?id=<?= $id ?>" class="inline-flex items-center text-red-800 font-black text-xs uppercase tracking-widest hover:translate-x-1 transition-transform">
                    <i class="fas fa-arrow-left mr-2"></i> Kembali ke Detail
                </a>
            </div>
            <h2 class="text-3xl font-black text-gray-900 mb-2 tracking-tight">Edit Laporan</h2>
            <p class="text-gray-400 font-medium mb-8 italic">Perbaiki data laporan. Setelah disimpan, status kembali ke <b>pending</b> untuk direview admin.</p>
            <?= $message ?>

            <form method="POST" enctype="multipart/form-data" class="bg-white p-8 md:p-12 rounded-[48px] shadow-2xl shadow-red-900/10 border border-gray-100 space-y-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Tanggal Kegiatan *</label>
                        <input type="date" name="tanggal" required value="<?= htmlspecialchars($catatan['tanggal']) ?>"
                               class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Nama Konsumen / Masyarakat *</label>
                        <input type="text" name="nama_konsumen" required value="<?= htmlspecialchars($catatan['nama_konsumen']) ?>"
                               class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold">
                    </div>
                </div>

                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Lokasi Kegiatan (NTB) *</p>
                    <?php
                    $partsAlamat = ntb_parse_alamat_parts($catatan['alamat'] ?? '');
                    $alamatPrefix = 'catatan';
                    $alamatRequired = true;
                    $alamatShowDetail = true;
                    $alamatKab = $partsAlamat['kab'];
                    $alamatKec = $partsAlamat['kec'];
                    $alamatDesa = $partsAlamat['desa'];
                    $alamatDetail = $partsAlamat['detail'];
                    include 'views/includes/alamat_dropdown.php';
                    ?>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">No. WhatsApp / HP Konsumen *</label>
                    <input type="tel" name="no_hp" required value="<?= htmlspecialchars($catatan['no_hp'] ?? '') ?>" placeholder="08xxxxxxxxxx"
                           class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold font-mono">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Usia *</label>
                        <input type="number" name="usia" min="1" max="120" required value="<?= htmlspecialchars((string)($catatan['usia'] ?? '')) ?>"
                               class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Jenis Kelamin *</label>
                        <select name="jenis_kelamin" required class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-bold">
                            <option value="Pria" <?= ($catatan['jenis_kelamin'] ?? '') === 'Pria' ? 'selected' : '' ?>>Pria</option>
                            <option value="Wanita" <?= ($catatan['jenis_kelamin'] ?? '') === 'Wanita' ? 'selected' : '' ?>>Wanita</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Pekerjaan *</label>
                    <input type="text" name="pekerjaan" required value="<?= htmlspecialchars($catatan['pekerjaan'] ?? '') ?>"
                           class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold">
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Materi Informasi *</label>
                    <textarea name="informasi" rows="4" required class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold"><?= htmlspecialchars($catatan['informasi'] ?? '') ?></textarea>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Tempat Kegiatan *</label>
                    <input type="text" name="lokasi" required value="<?= htmlspecialchars($catatan['lokasi'] ?? '') ?>"
                           class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Nilai Pre-Test *</label>
                        <input type="number" name="nilai_pre_test" min="0" max="100" step="0.01" required
                               value="<?= htmlspecialchars((string)($catatan['nilai_pre_test'] ?? '')) ?>"
                               class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Nilai Post-Test *</label>
                        <input type="number" name="nilai_post_test" min="0" max="100" step="0.01" required
                               value="<?= htmlspecialchars((string)($catatan['nilai_post_test'] ?? '')) ?>"
                               class="w-full px-5 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-600 outline-none font-semibold">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Ganti Lampiran Hasil Test (opsional)</label>
                    <input type="file" name="lampiran_hasil_test" accept="image/jpeg,image/png,application/pdf"
                           class="w-full px-5 py-3 rounded-2xl bg-white border border-gray-100 text-sm font-semibold file:mr-4 file:rounded-xl file:border-0 file:bg-orange-100 file:px-4 file:py-2 file:text-[10px] file:font-black file:uppercase file:text-orange-700">
                </div>

                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Tambah Bukti Kegiatan (opsional)</label>
                    <input type="file" name="bukti_kegiatan[]" multiple accept="image/*,video/mp4"
                           class="w-full px-5 py-3 rounded-2xl bg-white border border-gray-100 text-sm font-semibold file:mr-4 file:rounded-xl file:border-0 file:bg-orange-100 file:px-4 file:py-2 file:text-[10px] file:font-black file:uppercase file:text-orange-700">
                </div>

                
                <input type="hidden" name="latitude" id="latitude" value="<?= htmlspecialchars((string)($catatan['latitude'] ?? '')) ?>">
                <input type="hidden" name="longitude" id="longitude" value="<?= htmlspecialchars((string)($catatan['longitude'] ?? '')) ?>">
                <div class="bg-green-50 border border-green-100 rounded-2xl p-5 space-y-4">
                    <div>
                        <p class="text-[10px] font-black text-green-700 uppercase tracking-widest">Titik Lokasi di Peta Sebaran <span class="text-red-800">*</span></p>
                        <p class="text-[11px] text-gray-500 font-semibold mt-1">Data peta di banyak desa NTB belum lengkap - tebakan otomatis dari alamat kadang cuma sampai level kecamatan, <b>belum tentu tepat ke desa/alamatnya</b>. Wajib cek & geser pin merah sampai benar-benar menunjuk lokasi yang dimaksud.</p>
                    </div>
                    <div id="peta-lokasi" class="w-full rounded-2xl overflow-hidden border border-green-200" style="height: 320px;"></div>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <p id="gps-status" class="text-xs text-gray-500 font-semibold"><?= (!empty($catatan['latitude']) ? 'Titik tersimpan - geser pin kalau kurang tepat' : 'Belum ada titik — isi Alamat di atas atau klik "Ambil GPS"') ?></p>
                            <p id="gps-coords" class="text-[11px] font-mono text-gray-400 mt-1"><?php if (!empty($catatan['latitude'])): ?><?= htmlspecialchars($catatan['latitude']) ?>, <?= htmlspecialchars($catatan['longitude']) ?><?php endif; ?></p>
                            <button type="button" id="btn-cari-ulang" class="text-[10px] font-bold text-green-700 underline mt-1">Cari ulang titik dari alamat</button>
                        </div>
                        <button type="button" id="btn-gps" class="px-5 py-3 bg-green-600 hover:bg-green-700 text-white text-[10px] font-black uppercase tracking-widest rounded-xl shrink-0">Ambil GPS</button>
                    </div>
                </div>

                <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-5 rounded-[28px] shadow-xl uppercase text-xs tracking-widest">
                    Simpan Perubahan Laporan
                </button>
            </form>
        </div>
    </main>

<script src="assets/js/peta-pin.js"></script>
<script src="assets/js/ntb-alamat.js"></script>
<script>
(function() {
    const data = <?= json_encode(ntb_wilayah_hierarki(), JSON_UNESCAPED_UNICODE) ?>;
    const kab = document.getElementById('catatan_kab');
    const kec = document.getElementById('catatan_kec');
    const desa = document.getElementById('catatan_desa');
    const detail = document.getElementById('catatan_detail');
    const full = document.getElementById('catatan_full');
    initNtbAlamat(kab, kec, desa, data, {
        kab: <?= json_encode($partsAlamat['kab'] ?? '') ?>,
        kec: <?= json_encode($partsAlamat['kec'] ?? '') ?>,
        desa: <?= json_encode($partsAlamat['desa'] ?? '') ?>
    });

    const peta = initPetaPin({
        mapId: 'peta-lokasi', latId: 'latitude', lngId: 'longitude',
        statusId: 'gps-status', coordsId: 'gps-coords', gpsBtnId: 'btn-gps',
        geocodeUrl: 'ajax-geocode.php',
        getAlamat: function() { return ntbComposeAlamat(kab?.value, kec?.value, desa?.value, detail?.value || ''); },
        initialLat: <?= json_encode($catatan['latitude'] ?? null) ?>,
        initialLng: <?= json_encode($catatan['longitude'] ?? null) ?>
    });
    [kab, kec, desa].forEach(function(el) {
        el?.addEventListener('change', function() { peta && peta.cariDariAlamat(); });
    });
    detail?.addEventListener('input', function() { peta && peta.cariDariAlamat(); });
    document.getElementById('btn-cari-ulang')?.addEventListener('click', function() { peta && peta.cariUlangDariAlamat(); });

    document.querySelector('form')?.addEventListener('submit', function() {
        if (full) full.value = ntbComposeAlamat(kab?.value, kec?.value, desa?.value, detail?.value || '');
    }, true);
})();
</script>

</body>
</html>