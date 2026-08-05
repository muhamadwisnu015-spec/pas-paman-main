<?php
require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/log_laporan.php';
require_once 'core/ntb_wilayah_data.php';
require_once 'core/ntb_helper.php';
require_once 'core/geocoding.php';
cek_login();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $tanggal = $_POST['tanggal'];
    $nama_konsumen = htmlspecialchars($_POST['nama_konsumen']);
    $informasi = htmlspecialchars($_POST['informasi']);
    $lokasi = htmlspecialchars($_POST['lokasi'] ?? ''); 
    $usia = filter_var($_POST['usia'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
    $jenis_kelamin = $_POST['jenis_kelamin'] ?? '';
    $pekerjaan = htmlspecialchars(trim($_POST['pekerjaan'] ?? ''));
    $nilai_pre_test = filter_var($_POST['nilai_pre_test'] ?? null, FILTER_VALIDATE_FLOAT);
    $nilai_post_test = filter_var($_POST['nilai_post_test'] ?? null, FILTER_VALIDATE_FLOAT);
    
    // REVISI: Ambil data Alamat dan No. HP
    $kab_kota = trim($_POST['kab_kota'] ?? '');
    $kecamatan = trim($_POST['kecamatan'] ?? '');
    $desa = trim($_POST['desa'] ?? '');
    $alamat_detail = htmlspecialchars(trim($_POST['alamat_detail'] ?? ''));
    if ($kab_kota === '' || $kecamatan === '' || $desa === '') {
        // fallback ke alamat full jika ada
        $alamat = htmlspecialchars(trim($_POST['alamat'] ?? ''));
    } else {
        $alamat = ntb_format_alamat($kab_kota, $kecamatan, $desa, $alamat_detail);
    }
    $no_hp = htmlspecialchars($_POST['no_hp']);

    // Sumber utama titik peta: pin yang digeser manual oleh agen di peta form
    // (dikirim lewat hidden input latitude/longitude). Peta itu sendiri
    // awalnya diposisikan otomatis dari alamat/GPS lewat JS, tapi posisi
    // akhir yang disimpan adalah tempat pin terakhir digeser - jadi paling
    // akurat karena sudah dikonfirmasi manual oleh agen.
    $latitude = filter_var($_POST['latitude'] ?? '', FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($_POST['longitude'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($latitude === false) $latitude = null;
    if ($longitude === false) $longitude = null;

    // Cadangan kalau pin gak sempat digeser/JS gagal: coba geocoding dari
    // alamat, baru fallback ke titik tengah kecamatan.
    if ($latitude === null || $longitude === null) {
        $geo = geocode_alamat($alamat);
        if ($geo) {
            $latitude = $geo['lat'];
            $longitude = $geo['lng'];
        } else {
            $coords = ntb_coords_from_wilayah($kab_kota ?? '', $kecamatan ?? '');
            $latitude = $coords['lat'];
            $longitude = $coords['lng'];
        }
    }
    
    try {
        if ($usia === false || !in_array($jenis_kelamin, ['Pria', 'Wanita'], true) || $pekerjaan === '') {
            throw new Exception("Usia, jenis kelamin, dan pekerjaan wajib diisi dengan benar.");
        }
        if ($nilai_pre_test === false || $nilai_post_test === false || $nilai_pre_test < 0 || $nilai_pre_test > 100 || $nilai_post_test < 0 || $nilai_post_test > 100) {
            throw new Exception("Nilai pre-test dan post-test harus diisi dari 0 sampai 100.");
        }

        if (empty($_FILES['bukti_kegiatan']['name'][0])) {
            throw new Exception("Anda harus mengunggah minimal 1 bukti kegiatan!");
        }

        $pdo->beginTransaction();

        // Pastikan kolom GPS ada
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM catatan_harian")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('latitude', $cols, true)) {
                $pdo->exec("ALTER TABLE catatan_harian ADD COLUMN latitude DECIMAL(10,7) NULL");
            }
            if (!in_array('longitude', $cols, true)) {
                $pdo->exec("ALTER TABLE catatan_harian ADD COLUMN longitude DECIMAL(10,7) NULL");
            }
        } catch (Throwable $e) { /* ignore */ }

        $simpanLampiranHasil = function () {
            $field = 'lampiran_hasil_test';
            if (empty($_FILES[$field]['name'])) return null;

            $file = $_FILES[$field];
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new Exception("Lampiran hasil pre-test dan post-test gagal diunggah.");
            }
            if ($file['size'] > 5 * 1024 * 1024) throw new Exception("Lampiran hasil test maksimal 5MB.");

            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
            if (!isset($extensions[$mime])) throw new Exception("Lampiran hasil test hanya boleh JPG, PNG, atau PDF.");

            $namaFile = 'HASIL_TEST_' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
            if (!move_uploaded_file($file['tmp_name'], 'uploads/' . $namaFile)) {
                throw new Exception("Lampiran hasil test tidak dapat disimpan.");
            }
            return $namaFile;
        };

        $lampiran_hasil_test = $simpanLampiranHasil();

        $sql = "INSERT INTO catatan_harian (user_id, tanggal, nama_konsumen, usia, jenis_kelamin, pekerjaan, informasi, lokasi, alamat, no_hp, nilai_pre_test, nilai_post_test, lampiran_hasil_test, latitude, longitude, status_review) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $tanggal, $nama_konsumen, $usia, $jenis_kelamin, $pekerjaan, $informasi, $lokasi, $alamat, $no_hp, $nilai_pre_test, $nilai_post_test, $lampiran_hasil_test, $latitude, $longitude]);
        
        $catatan_id = $pdo->lastInsertId();

        if (!empty($_FILES['bukti_kegiatan']['name'][0])) {
            foreach ($_FILES['bukti_kegiatan']['tmp_name'] as $key => $tmp_name) {
                if ($key >= 5) break; 

                $file_size = $_FILES['bukti_kegiatan']['size'][$key];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp_name);
                
                $is_image = in_array($mime, ['image/jpeg', 'image/png']);
                $is_video = in_array($mime, ['video/mp4']);

                if ($is_image && $file_size > 3 * 1024 * 1024) throw new Exception("Foto terlalu besar (Max 3MB)");
                if ($is_video && $file_size > 10 * 1024 * 1024) throw new Exception("Video terlalu besar (Max 10MB)");

                $ext = pathinfo($_FILES['bukti_kegiatan']['name'][$key], PATHINFO_EXTENSION);
                $new_name = bin2hex(random_bytes(10)) . "." . $ext;
                
                if (move_uploaded_file($tmp_name, "uploads/" . $new_name)) {
                    $stmtFile = $pdo->prepare("INSERT INTO catatan_files (catatan_id, file_path) VALUES (?, ?)");
                    $stmtFile->execute([$catatan_id, $new_name]);
                }
            }
        }

        $pdo->commit();

        // Bootstrap GPS profil agen HANYA kalau profil agen belum punya titik
        // sendiri (mis. agen baru, belum pernah isi/geser pin di halaman
        // profil). Kalau sudah ada, jangan ditimpa - itu titik alamat rumah/
        // kantor agen yang sudah sengaja ditepatkan di profil, beda tempat
        // dengan alamat konsumen pada laporan ini.
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

        // Catat log aktivitas (wajib tercatat di log_laporan)
        $logOk = false;
        try {
            if (function_exists('log_laporan')) {
                $logOk = log_laporan($pdo, (int)$user_id, 'buat', (int)$catatan_id, 'Membuat laporan untuk ' . $nama_konsumen);
            }
            if (!$logOk) {
                // Fallback langsung ke tabel (jika helper gagal)
                $stmtLog = $pdo->prepare("INSERT INTO log_laporan (catatan_id, user_id, aksi, keterangan) VALUES (?, ?, 'buat', ?)");
                $stmtLog->execute([(int)$catatan_id, (int)$user_id, 'Membuat laporan untuk ' . $nama_konsumen]);
                $logOk = true;
            }
        } catch (Throwable $logEx) {
            error_log('Gagal catat log buat laporan: ' . $logEx->getMessage());
        }

        $message = "<div class='bg-orange-100 text-orange-700 p-4 rounded-2xl mb-6 font-bold text-center border border-orange-200 animate-pulse'>Laporan Berhasil Terkirim ke Admin!</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "<div class='bg-red-100 text-red-700 p-4 rounded-2xl mb-6 font-bold text-center border border-red-200'>Gagal: " . $e->getMessage() . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Catatan | BBPOM Diary</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: #f8fafc;
        }
        /* Border warna Orange-600 pas Drag over */
        .drag-over { border-color: #ea580c !important; background-color: #fff7ed !important; }
        .swal2-popup { border-radius: 32px !important; }
    </style>
</head>
<body class="bg-gray-50 flex flex-col md:flex-row min-h-screen">
    
    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-12 overflow-y-auto">
        <div class="max-w-4xl mx-auto">
            <h2 class="text-4xl font-black text-gray-900 mb-2 tracking-tight">Formulir Laporan</h2>
            <p class="text-gray-400 font-medium mb-8 italic">Silakan lengkapi detail kegiatan edukasi Anda hari ini.</p>
            
            <?= $message ?>

            <form id="uploadForm" action="" method="POST" enctype="multipart/form-data" class="bg-white p-8 md:p-12 rounded-[48px] shadow-2xl shadow-red-900/10 border border-gray-100 space-y-8">

                <!-- Tanggal + Nama -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Tanggal Kegiatan <span class="text-red-800">*</span></label>
                        <input type="date" name="tanggal" required value="<?= date('Y-m-d') ?>"
                               class="w-full px-6 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Nama Konsumen / Masyarakat <span class="text-red-800">*</span></label>
                        <input type="text" name="nama_konsumen" required placeholder="Nama lengkap konsumen"
                               class="w-full px-6 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all font-semibold">
                    </div>
                </div>

                <!-- Lokasi NTB dropdown full width -->
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Lokasi Kegiatan (NTB) <span class="text-red-800">*</span></p>
                    <?php
                    $alamatPrefix = 'catatan';
                    $alamatRequired = true;
                    $alamatShowDetail = true;
                    $alamatKab = '';
                    $alamatKec = '';
                    $alamatDesa = '';
                    $alamatDetail = '';
                    include 'views/includes/alamat_dropdown.php';
                    ?>
                </div>

                <!-- Peta + GPS -->
                <input type="hidden" name="latitude" id="latitude" value="">
                <input type="hidden" name="longitude" id="longitude" value="">
                <div class="bg-green-50 border border-green-100 rounded-2xl p-5 space-y-4">
                    <div>
                        <p class="text-[10px] font-black text-green-700 uppercase tracking-widest">Titik Lokasi di Peta Sebaran <span class="text-red-800">*</span></p>
                        <p class="text-[11px] text-gray-500 font-semibold mt-1">Peta menebak posisi awal dari Alamat yang diisi, tapi data peta di banyak desa NTB belum lengkap - tebakan itu kadang cuma sampai level kecamatan, <b>belum tentu tepat ke desa/alamatnya</b>. Wajib cek & geser pin merah sampai benar-benar menunjuk lokasi yang dimaksud - posisi akhir pin inilah yang menentukan titik di peta sebaran.</p>
                    </div>
                    <div id="peta-lokasi" class="w-full rounded-2xl overflow-hidden border border-green-200" style="height: 320px;"></div>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <p id="gps-status" class="text-xs text-gray-500 font-semibold">Belum ada titik — isi Alamat di atas atau klik "Ambil GPS".</p>
                            <p id="gps-coords" class="text-[11px] font-mono text-gray-400 mt-1"></p>
                            <button type="button" id="btn-cari-ulang" class="text-[10px] font-bold text-green-700 underline mt-1">Cari ulang titik dari alamat</button>
                        </div>
                        <button type="button" id="btn-gps" class="px-5 py-3 bg-green-600 hover:bg-green-700 text-white text-[10px] font-black uppercase tracking-widest rounded-xl shrink-0">
                            <i class="fas fa-location-crosshairs mr-1"></i> Ambil GPS
                        </button>
                    </div>
                </div>

                <!-- No HP -->
                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">No. HP / WA Konsumen <span class="text-red-800">*</span></label>
                    <input type="tel" name="no_hp" required placeholder="Contoh: 08123456789"
                           class="w-full px-6 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all font-semibold font-mono">
                </div>

                <!-- Usia + JK -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Usia <span class="text-red-800">*</span></label>
                        <input type="number" name="usia" min="1" max="120" required placeholder="Contoh: 25"
                               class="w-full px-6 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all font-semibold">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Jenis Kelamin <span class="text-red-800">*</span></label>
                        <select name="jenis_kelamin" required
                                class="w-full px-6 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all font-bold cursor-pointer">
                            <option value="" selected disabled>Pilih jenis kelamin</option>
                            <option value="Pria">Pria</option>
                            <option value="Wanita">Wanita</option>
                        </select>
                    </div>
                </div>

                <!-- Pekerjaan -->
                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Pekerjaan <span class="text-red-800">*</span></label>
                    <input type="text" name="pekerjaan" required placeholder="Contoh: Ibu Rumah Tangga, Pegawai Swasta"
                           class="w-full px-6 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all font-semibold">
                </div>

                <!-- Materi -->
                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Materi Informasi <span class="text-red-800">*</span></label>
                    <textarea name="informasi" rows="5" required placeholder="Tuliskan materi sosialisasi yang diberikan..."
                              class="w-full px-6 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all font-semibold"></textarea>
                </div>

                <!-- Tempat kegiatan -->
                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Tempat Kegiatan <span class="text-red-800">*</span></label>
                    <input type="text" name="lokasi" required placeholder="Contoh: Aula Desa, Gedung Serbaguna, dsb"
                           class="w-full px-6 py-4 rounded-2xl bg-gray-50 border border-gray-100 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all font-semibold text-sm">
                </div>

                <!-- Hasil test -->
                <div class="rounded-[32px] border border-orange-100 bg-orange-50/40 p-6 md:p-8 space-y-5">
                    <div class="flex items-center gap-3">
                        <span class="w-10 h-10 rounded-2xl bg-orange-100 text-orange-700 flex items-center justify-center"><i class="fas fa-clipboard-check"></i></span>
                        <div>
                            <p class="text-[10px] font-black text-orange-700 uppercase tracking-widest">Hasil Test Konsumen / Masyarakat</p>
                            <p class="text-xs font-semibold text-gray-500 mt-0.5">Masukkan nilai dari lembar pre-test dan post-test hard copy.</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Nilai Pre-Test <span class="text-red-800">*</span></label>
                            <input type="number" name="nilai_pre_test" min="0" max="100" step="0.01" required placeholder="0 - 100"
                                   class="w-full px-5 py-3 rounded-2xl bg-white border border-orange-100 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all font-semibold">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Nilai Post-Test <span class="text-red-800">*</span></label>
                            <input type="number" name="nilai_post_test" min="0" max="100" step="0.01" required placeholder="0 - 100"
                                   class="w-full px-5 py-3 rounded-2xl bg-white border border-orange-100 focus:ring-4 focus:ring-orange-600/10 focus:border-orange-600 outline-none transition-all font-semibold">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 ml-1">Lampiran Lembar Hasil Test (Hard Copy)</label>
                        <input type="file" name="lampiran_hasil_test" accept="image/jpeg,image/png,application/pdf"
                               class="w-full px-5 py-3 rounded-2xl bg-white border border-orange-100 text-sm font-semibold file:mr-4 file:rounded-xl file:border-0 file:bg-orange-100 file:px-4 file:py-2 file:text-[10px] file:font-black file:uppercase file:text-orange-700 hover:file:bg-orange-200">
                        <p class="mt-2 ml-1 text-[9px] font-bold uppercase tracking-wider text-gray-400">Foto/scan lembar hard copy · JPG, PNG, atau PDF · Maks. 5MB</p>
                    </div>
                </div>

                <!-- Bukti -->
                <div>
                    <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-3 ml-1">Bukti Kegiatan (Maks 5 File) <span class="text-red-800">*</span></label>
                    <div id="dropZone" class="relative group w-full p-12 border-2 border-dashed border-gray-200 rounded-[40px] text-center transition-all cursor-pointer hover:border-orange-600 hover:bg-orange-50">
                        <input type="file" id="fileInput" name="bukti_kegiatan[]" multiple class="hidden" accept="image/*,video/mp4">
                        <div class="space-y-4">
                            <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto group-hover:scale-110 transition-transform">
                                <i class="fas fa-cloud-upload-alt text-2xl text-orange-600"></i>
                            </div>
                            <div class="text-gray-500 text-sm">
                                <span class="font-bold text-orange-600">Klik untuk upload</span> atau seret file ke sini
                            </div>
                            <p class="text-[9px] text-gray-400 uppercase tracking-[0.2em] font-black italic">Wajib: Foto 3MB, Video 10MB</p>
                        </div>
                        <div id="fileList" class="mt-6 flex flex-wrap justify-center gap-3"></div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-6 rounded-[32px] shadow-xl shadow-orange-900/10 transition-all transform active:scale-[0.98] hover:-translate-y-1 text-lg">
                    Kirim Laporan Sekarang
                </button>
            </form>
        </div>
    </main>

<script>
    // Drag and Drop Logic
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const fileList = document.getElementById('fileList');
    const uploadForm = document.getElementById('uploadForm');

    dropZone.addEventListener('click', () => fileInput.click());
    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('drag-over'); });
    ['dragleave', 'drop'].forEach(event => { dropZone.addEventListener(event, () => dropZone.classList.remove('drag-over')); });
    dropZone.addEventListener('drop', (e) => { e.preventDefault(); fileInput.files = e.dataTransfer.files; updateFileList(); });
    fileInput.addEventListener('change', updateFileList);

    function updateFileList() {
        fileList.innerHTML = "";
        if (fileInput.files.length > 0) {
            Array.from(fileInput.files).forEach(file => {
                const span = document.createElement('span');
                span.className = "px-4 py-2 bg-orange-100 text-orange-700 text-[10px] rounded-xl font-black uppercase border border-orange-200 shadow-sm";
                span.textContent = file.name;
                fileList.appendChild(span);
            });
            dropZone.classList.remove('border-red-500', 'bg-red-50');
            dropZone.style.borderColor = ""; 
        }
    }

    // Validasi Submit & SweetAlert
    uploadForm.addEventListener('submit', function(e) {
        if (fileInput.files.length === 0) {
            e.preventDefault(); 
            dropZone.classList.add('border-red-500', 'bg-red-50');
            dropZone.scrollIntoView({ behavior: 'smooth', block: 'center' });

            Swal.fire({
                icon: 'error',
                title: 'Bukti Kegiatan Kosong!',
                text: 'Anda wajib melampirkan minimal 1 foto/video sebagai bukti laporan.',
                confirmButtonColor: '#ea580c' // Orange-600
            });
            return false;
        }
    });
</script>

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
    initNtbAlamat(kab, kec, desa, data, {});

    const peta = initPetaPin({
        mapId: 'peta-lokasi', latId: 'latitude', lngId: 'longitude',
        statusId: 'gps-status', coordsId: 'gps-coords', gpsBtnId: 'btn-gps',
        geocodeUrl: 'ajax-geocode.php',
        getAlamat: function() { return ntbComposeAlamat(kab?.value, kec?.value, desa?.value, detail?.value || ''); }
    });
    [kab, kec, desa].forEach(function(el) {
        el?.addEventListener('change', function() { peta && peta.cariDariAlamat(); });
    });
    detail?.addEventListener('input', function() { peta && peta.cariDariAlamat(); });
    document.getElementById('btn-cari-ulang')?.addEventListener('click', function() { peta && peta.cariUlangDariAlamat(); });

    const form = document.getElementById('uploadForm');
    form?.addEventListener('submit', function() {
        if (full) {
            full.value = ntbComposeAlamat(kab?.value, kec?.value, desa?.value, detail?.value || '');
        }
    }, true);
})();
</script>


</body>
</html>