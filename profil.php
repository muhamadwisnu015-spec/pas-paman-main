<?php
require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/ntb_wilayah_data.php';
require_once 'core/ntb_helper.php';
require_once 'core/geocoding.php';
cek_login();

$user_id = $_SESSION['user_id'];
$message = '';

// Ambil data user terbaru
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (($_GET['msg'] ?? '') === 'perlu_ttd') {
    $message = "<div class='bg-red-50 text-red-600 p-4 rounded-2xl mb-8 font-bold text-center border border-red-100'>Unggah gambar tanda tangan elektronik terlebih dahulu sebelum bisa menyetujui sertifikat.</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update_profil'])) {
            $isAgen = ($user['role'] === 'agen');
            $nama = htmlspecialchars(trim($_POST['nama'] ?? ''));
            $jenis_kelamin = in_array($_POST['jenis_kelamin'] ?? '', ['Pria', 'Wanita'], true) ? $_POST['jenis_kelamin'] : ($user['jenis_kelamin'] ?? 'Pria');
            $usia = filter_var($_POST['usia'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 120]]);
            $kab_kota = trim($_POST['kab_kota'] ?? '');
            $kecamatan = trim($_POST['kecamatan'] ?? '');
            $desa = trim($_POST['desa'] ?? '');
            $alamat_detail = htmlspecialchars(trim($_POST['alamat_detail'] ?? ''));
            $alamat = ($kab_kota && $kecamatan && $desa)
                ? ntb_format_alamat($kab_kota, $kecamatan, $desa, $alamat_detail)
                : htmlspecialchars(trim($_POST['alamat'] ?? ''));
            // Kabalai gak punya field alamat lagi di form (dihapus), alamatnya
            // selalu dikunci ke alamat kantor BBPOM di Mataram.
            if ($user['role'] === 'kabalai') {
                $alamat = 'Jl. Catur Warga, Mataram Timur, kecamatan Mataram, Nusa tenggara barat 83121';
            }
            $nama_instansi = htmlspecialchars(trim($_POST['nama_instansi'] ?? ''));
            $nomor_hp = htmlspecialchars(trim($_POST['nomor_hp'] ?? ''));
            $pekerjaan = htmlspecialchars(trim($_POST['pekerjaan'] ?? ($user['pekerjaan'] ?? '')));

            // Sumber utama titik peta sebaran agen: pin yang digeser manual
            // di peta form (hidden input latitude/longitude). Kalau kosong
            // (mis. agen submit tanpa sempat geser/JS gagal), baru fallback
            // ke geocoding dari alamat, lalu titik tengah kecamatan.
            $latitude = filter_var($_POST['latitude'] ?? '', FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($_POST['longitude'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($latitude === false) $latitude = null;
            if ($longitude === false) $longitude = null;

            if (($latitude === null || $longitude === null) && $alamat !== '') {
                $geo = geocode_alamat($alamat);
                if ($geo) {
                    $latitude = $geo['lat'];
                    $longitude = $geo['lng'];
                } elseif ($kab_kota !== '' || $kecamatan !== '') {
                    $coords = ntb_coords_from_wilayah($kab_kota, $kecamatan);
                    $latitude = $coords['lat'];
                    $longitude = $coords['lng'];
                }
            }

            // Field khusus agen
            $agen_id = $isAgen ? htmlspecialchars(trim($_POST['agen_id'] ?? '')) : ($user['agen_id'] ?? null);
            $nik = $isAgen ? htmlspecialchars(trim($_POST['nik'] ?? ($user['nik'] ?? ''))) : ($user['nik'] ?? '');

            if ($nama === '') throw new Exception("Nama tidak boleh kosong.");
            if ($isAgen && $agen_id === '') throw new Exception("ID Agen wajib diisi.");

            $foto_lama = $user['foto_profil'];
            $new_foto_name = $foto_lama;

            if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['foto'];

                if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                    throw new Exception("Upload foto gagal. Silakan pilih ulang file fotonya.");
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);

                $allowedFoto = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif',
                    'image/jpg' => 'jpg',
                    'image/pjpeg' => 'jpg',
                    'image/x-png' => 'png',
                ];
                if (!isset($allowedFoto[$mime])) {
                    throw new Exception("Format foto tidak didukung. Gunakan JPG, PNG, WEBP, atau GIF.");
                }
                if ($file['size'] > 3 * 1024 * 1024) throw new Exception("Ukuran foto maksimal adalah 3MB.");

                $ext = $allowedFoto[$mime];
                $new_foto_name = "AVATAR_" . bin2hex(random_bytes(8)) . "." . $ext;

                if (!move_uploaded_file($file['tmp_name'], "uploads/" . $new_foto_name)) {
                    throw new Exception("Foto tidak dapat disimpan. Periksa izin tulis folder uploads.");
                }
            }

            try {
                $colsU = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('latitude', $colsU, true)) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN latitude DECIMAL(10,7) NULL");
                    $pdo->exec("ALTER TABLE users ADD COLUMN longitude DECIMAL(10,7) NULL");
                }
            } catch (Throwable $e) {}
            $update = $pdo->prepare("UPDATE users SET nama = ?, agen_id = ?, jenis_kelamin = ?, usia = ?, alamat = ?, nama_instansi = ?, nik = ?, nomor_hp = ?, pekerjaan = ?, foto_profil = ?, latitude = COALESCE(?, latitude), longitude = COALESCE(?, longitude) WHERE id = ?");
            $update->execute([
                $nama,
                $agen_id,
                $jenis_kelamin,
                $usia !== false ? $usia : null,
                $alamat,
                $nama_instansi,
                $nik,
                $nomor_hp,
                $pekerjaan,
                $new_foto_name,
                $latitude,
                $longitude,
                $user_id
            ]);

            if ($new_foto_name !== $foto_lama && $foto_lama && $foto_lama !== 'default.png' && file_exists("uploads/" . $foto_lama)) {
                unlink("uploads/" . $foto_lama);
            }

            $_SESSION['nama'] = $nama;
            $message = $isAgen
                ? "<div class='bg-orange-50 text-orange-600 p-4 rounded-2xl mb-8 font-bold text-center border border-orange-100 shadow-sm'>Profil & ID Agen Berhasil Diperbarui!</div>"
                : "<div class='bg-orange-50 text-orange-600 p-4 rounded-2xl mb-8 font-bold text-center border border-orange-100 shadow-sm'>Profil Berhasil Diperbarui!</div>";

            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        }

        if (isset($_POST['update_ttd']) && $user['role'] === 'kabalai') {
            $ttd_lama = $user['tanda_tangan'];
            $metode = $_POST['ttd_metode'] ?? 'manual';
            $new_ttd_name = null;
            $metodeLabel = 'manual';

            if ($metode === 'upload') {
                // Metode 2: upload foto PNG/JPG tanda tangan
                if (!isset($_FILES['tanda_tangan']) || $_FILES['tanda_tangan']['error'] === UPLOAD_ERR_NO_FILE) {
                    throw new Exception("Silakan pilih file gambar tanda tangan (PNG/JPG).");
                }
                $file = $_FILES['tanda_tangan'];
                if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                    throw new Exception("Upload tanda tangan gagal. Silakan pilih ulang filenya.");
                }
                if ($file['size'] > 2 * 1024 * 1024) {
                    throw new Exception("Ukuran gambar tanda tangan maksimal 2MB.");
                }
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime])) {
                    throw new Exception("Format tanda tangan harus PNG, JPG, atau WEBP.");
                }
                $new_ttd_name = "TTD_" . bin2hex(random_bytes(8)) . "." . $allowed[$mime];
                if (!move_uploaded_file($file['tmp_name'], "uploads/" . $new_ttd_name)) {
                    throw new Exception("Tanda tangan tidak dapat disimpan. Periksa izin tulis folder uploads.");
                }
                $metodeLabel = 'upload foto';
            } else {
                // Metode 1: canvas manual
                $dataUrl = trim($_POST['ttd_data'] ?? '');
                if ($dataUrl === '' || !preg_match('#^data:image/\w+;base64,#', $dataUrl)) {
                    throw new Exception("Silakan buat tanda tangan manual di kotak, atau ganti ke metode Upload Foto.");
                }
                $raw = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $dataUrl), true);
                if ($raw === false || strlen($raw) < 100) {
                    throw new Exception("Data tanda tangan tidak valid. Coba tanda tangani ulang.");
                }
                if (strlen($raw) > 1.5 * 1024 * 1024) {
                    throw new Exception("Ukuran tanda tangan terlalu besar.");
                }
                $new_ttd_name = "TTD_" . bin2hex(random_bytes(8)) . ".png";
                if (file_put_contents("uploads/" . $new_ttd_name, $raw) === false) {
                    throw new Exception("Tanda tangan tidak dapat disimpan. Periksa izin tulis folder uploads.");
                }
                $metodeLabel = 'manual';
            }

            $update = $pdo->prepare("UPDATE users SET tanda_tangan = ? WHERE id = ?");
            $update->execute([$new_ttd_name, $user_id]);

            if ($new_ttd_name !== $ttd_lama && $ttd_lama && file_exists("uploads/" . $ttd_lama)) {
                unlink("uploads/" . $ttd_lama);
            }

            $message = "<div class='bg-orange-50 text-orange-600 p-4 rounded-2xl mb-8 font-bold text-center border border-orange-100 shadow-sm'>Tanda tangan ({$metodeLabel}) berhasil disimpan!</div>";

            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
        }

        if (isset($_POST['change_password'])) {
            $pass_lama = $_POST['pass_lama'];
            $pass_baru = $_POST['pass_baru'];
            $konfirmasi = $_POST['konfirmasi'];

            if (!password_verify($pass_lama, $user['password'])) throw new Exception("Password lama salah.");
            if ($pass_baru !== $konfirmasi) throw new Exception("Konfirmasi tidak cocok.");
            if (strlen($pass_baru) < 6) throw new Exception("Minimal 6 karakter.");

            $hash_baru = password_hash($pass_baru, PASSWORD_DEFAULT);
            $updatePass = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updatePass->execute([$hash_baru, $user_id]);

            $message = "<div class='bg-orange-50 text-orange-600 p-4 rounded-2xl mb-8 font-bold text-center border border-orange-100'>Password Berhasil Diganti!</div>";
        }
    } catch (Exception $e) {
        $message = "<div class='bg-red-50 text-red-600 p-4 rounded-2xl mb-8 font-bold text-center border border-red-100'>" . $e->getMessage() . "</div>";
    }
}

$sudah_lengkap = profil_lengkap($user);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil & Ubah ID | BBPOM GAS-PAMAN</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #fcfdfe; }
        
        .soft-input {
            width: 100%; height: 54px; padding: 0 22px; font-size: 0.95rem; font-weight: 600;
            border-radius: 18px; background-color: #ffffff; border: 1.5px solid #f1f3f6;
            color: #1f2937; transition: all 0.3s ease;
        }
        .soft-input:focus {
            border-color: #f97316;
            box-shadow: 0 10px 20px -10px rgba(249, 115, 22, 0.2);
            outline: none; transform: translateY(-1px);
        }

        .file-label-soft {
            display: flex; align-items: center; gap: 12px; padding: 14px 22px;
            background-color: #991b1b; color: white; border-radius: 18px;
            cursor: pointer; font-size: 12px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.3s;
        }

    </style>
</head>
<body class="flex flex-col md:flex-row min-h-screen">
    <?php include 'views/includes/sidebar.php'; ?>

    <main class="flex-1 p-6 lg:p-12 overflow-y-auto">
        <div class="max-w-6xl mx-auto">
            
            <header class="mb-10">
                <?php if ($user['role'] === 'kabalai'): ?>
                <h2 class="text-3xl font-black text-gray-900 tracking-tight">Profil Kepala Balai</h2>
                <p class="text-gray-400 font-bold text-sm mt-1 italic">Kelola data akun dan tanda tangan elektronik untuk persetujuan sertifikat</p>
                <?php elseif (in_array($user['role'], ['admin', 'staff'], true)): ?>
                <h2 class="text-3xl font-black text-gray-900 tracking-tight">Profil <?= $user['role'] === 'admin' ? 'Admin' : 'Staff' ?></h2>
                <p class="text-gray-400 font-bold text-sm mt-1 italic">Kelola data akun dan keamanan panel BBPOM</p>
                <?php else: ?>
                <h2 class="text-3xl font-black text-gray-900 tracking-tight">Pengaturan Agen</h2>
                <p class="text-gray-400 font-bold text-sm mt-1 italic">Update data diri dan Identitas Agen</p>
                <?php endif; ?>
            </header>
            
            <?= $message ?>

            <?php if ($user['role'] === 'kabalai'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-start">

                <div class="lg:col-span-4 flex flex-col items-center gap-6">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.4em]">Foto Profil</p>
                    <div class="w-full bg-white rounded-[32px] border border-gray-100 shadow-sm p-8 flex flex-col items-center gap-5">
                        <div class="relative">
                            <img id="admin-avatar-preview" src="uploads/<?= htmlspecialchars($user['foto_profil'] ?: 'default.png') ?>" alt="Foto Profil"
                                 class="w-36 h-36 rounded-full object-cover border-4 border-red-50 shadow-lg">
                            <span class="absolute -bottom-1 -right-1 w-10 h-10 bg-red-800 text-white rounded-2xl flex items-center justify-center shadow-lg">
                                <i class="fas fa-user-tie text-sm"></i>
                            </span>
                        </div>
                        <div class="text-center">
                            <p class="font-black text-gray-900 text-lg leading-tight"><?= htmlspecialchars($user['nama']) ?></p>
                            <p class="text-[10px] font-black uppercase tracking-widest text-red-800 mt-2">Kepala BBPOM</p>
                            <p class="text-xs text-gray-400 font-semibold mt-1"><?= htmlspecialchars($user['email'] ?? '') ?></p>
                        </div>
                    </div>

                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.4em]">Tanda Tangan Elektronik</p>
                    <div id="ttd" class="w-full bg-white rounded-[32px] border border-gray-100 shadow-sm p-8 flex flex-col items-center gap-4">
                        <?php if (!empty($user['tanda_tangan'])): ?>
                            <img src="uploads/<?= htmlspecialchars($user['tanda_tangan']) ?>" alt="Tanda Tangan" class="w-full max-w-[220px] h-auto object-contain">
                            <span class="px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-widest bg-orange-100 text-orange-700">Sudah Terpasang</span>
                        <?php else: ?>
                            <i class="fas fa-signature text-4xl text-gray-200"></i>
                            <p class="text-xs text-gray-400 font-semibold text-center">Belum ada tanda tangan elektronik. Sertifikat tidak bisa disetujui sebelum ini diunggah.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="lg:col-span-8 space-y-8">
                    <div class="bg-white p-10 rounded-[45px] shadow-sm border border-gray-50">
                        <div class="flex items-center gap-4 mb-10 pb-6 border-b border-gray-50">
                            <div class="w-12 h-12 bg-red-800 rounded-2xl flex items-center justify-center text-white shadow-lg">
                                <i class="fas fa-user-edit text-xl"></i>
                            </div>
                            <h3 class="font-black text-xl text-gray-900">Data Akun</h3>
                        </div>

                        <form action="" method="POST" enctype="multipart/form-data" class="space-y-8" id="profil-form">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">

                                <div class="md:col-span-2">
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Nama Lengkap</label>
                                    <input type="text" name="nama" value="<?= htmlspecialchars($user['nama']) ?>" required class="soft-input">
                                </div>

                                <div class="md:col-span-2">
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Alamat Kantor (Default)</label>
                                    <div class="w-full p-5 rounded-[22px] bg-gray-50 border border-gray-100 font-semibold text-gray-700 text-sm leading-relaxed">
                                        <i class="fas fa-map-marker-alt text-red-700 mr-2"></i>
                                        Jl. Catur Warga, Mataram Timur, kecamatan Mataram, Nusa tenggara barat 83121
                                    </div>
                                    <p class="text-[10px] text-gray-400 font-semibold mt-2 ml-1">Alamat ini dikunci sebagai alamat resmi Kepala Balai BBPOM di Mataram.</p>
                                </div>

                                <div class="md:col-span-1">
                                    <label class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] block mb-2.5 ml-1">Ganti Foto Profil</label>
                                    <label class="file-label-soft">
                                        <i class="fas fa-camera text-base"></i>
                                        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp,image/gif,image/*" class="hidden" id="foto-profil-input">
                                        <span id="foto-profil-label">Pilih Gambar</span>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" name="update_profil" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-5 rounded-[22px] shadow-xl transform active:scale-[0.98] uppercase text-[11px] tracking-widest mt-4" id="simpan-profil">
                                Simpan Perubahan
                            </button>
                        </form>
                    </div>

                    <div class="bg-white p-10 rounded-[45px] shadow-sm border border-gray-50">
                        <div class="flex items-center gap-4 mb-8 pb-6 border-b border-gray-50">
                            <div class="w-12 h-12 bg-red-800 rounded-2xl flex items-center justify-center text-white shadow-lg">
                                <i class="fas fa-signature text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-black text-xl text-gray-900">Tanda Tangan</h3>
                                <p class="text-[11px] text-gray-400 font-semibold mt-0.5">Pilih salah satu metode di bawah</p>
                            </div>
                        </div>

                        <!-- Toggle metode -->
                        <div class="flex flex-wrap gap-2 mb-6 p-1.5 bg-gray-50 rounded-2xl border border-gray-100">
                            <button type="button" id="tab-ttd-manual"
                                    class="flex-1 min-w-[140px] px-4 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest bg-orange-600 text-white shadow-sm transition-all">
                                <i class="fas fa-pen-nib mr-1"></i> Tulis Manual
                            </button>
                            <button type="button" id="tab-ttd-upload"
                                    class="flex-1 min-w-[140px] px-4 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest bg-transparent text-gray-500 hover:text-gray-800 transition-all">
                                <i class="fas fa-image mr-1"></i> Upload Foto
                            </button>
                        </div>

                        <form action="" method="POST" enctype="multipart/form-data" class="space-y-6" id="form-ttd-manual">
                            <input type="hidden" name="ttd_data" id="ttd_data" value="">
                            <input type="hidden" name="ttd_metode" id="ttd_metode" value="manual">

                            <!-- Metode 1: Canvas -->
                            <div id="panel-ttd-manual" class="space-y-3">
                                <label class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] block ml-1">Gambar Tanda Tangan di Kotak Ini</label>
                                <div class="rounded-2xl border-2 border-dashed border-gray-200 bg-white overflow-hidden">
                                    <canvas id="ttd-canvas" width="640" height="220" class="w-full touch-none cursor-crosshair bg-white" style="max-height:220px"></canvas>
                                </div>
                                <div class="flex flex-wrap gap-3">
                                    <button type="button" id="ttd-clear" class="px-4 py-2 rounded-xl bg-gray-100 text-gray-600 text-[10px] font-black uppercase tracking-widest">Hapus</button>
                                </div>
                                <p class="text-[11px] text-gray-400 font-semibold ml-1">Tanda tangan digambar langsung, lalu ditempel rapi di atas nama Kepala Balai pada sertifikat.</p>
                            </div>

                            <!-- Metode 2: Upload -->
                            <div id="panel-ttd-upload" class="space-y-3 hidden">
                                <label class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] block ml-1">Upload Gambar Tanda Tangan</label>
                                <label class="file-label-soft w-fit cursor-pointer inline-flex items-center gap-2 px-5 py-3 rounded-2xl bg-gray-50 border border-gray-100 hover:border-orange-300 transition-all">
                                    <i class="fas fa-upload text-base text-orange-600"></i>
                                    <input type="file" name="tanda_tangan" accept="image/png,image/jpeg,image/webp,image/*" class="hidden" id="ttd-input">
                                    <span id="ttd-label" class="text-[11px] font-bold text-gray-600">Pilih PNG / JPG</span>
                                </label>
                                <p class="text-[11px] text-gray-400 font-semibold ml-1">Disarankan PNG transparan, maks. 2MB. Akan ditempel di posisi yang sama pada sertifikat.</p>
                            </div>

                            <button type="submit" name="update_ttd" id="btn-simpan-ttd" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-5 rounded-[22px] shadow-xl transform active:scale-[0.98] uppercase text-[11px] tracking-widest">
                                Simpan Tanda Tangan
                            </button>
                        </form>
                    </div>

                    <div class="bg-white p-10 rounded-[45px] shadow-sm border border-gray-50">
                        <div class="flex items-center gap-4 mb-8">
                            <div class="w-10 h-10 bg-red-800 rounded-xl flex items-center justify-center text-white shadow-lg">
                                <i class="fas fa-lock text-sm"></i>
                            </div>
                            <h3 class="font-black text-gray-900 uppercase text-xs tracking-widest">Keamanan</h3>
                        </div>
                        <form action="" method="POST" class="space-y-5">
                            <input type="password" name="pass_lama" required placeholder="Password Lama" class="soft-input">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <input type="password" name="pass_baru" required placeholder="Sandi Baru" class="soft-input">
                                <input type="password" name="konfirmasi" required placeholder="Ulangi Sandi" class="soft-input">
                            </div>
                            <button type="submit" name="change_password" class="w-full bg-gray-900 hover:bg-black text-white font-black py-5 rounded-[22px] uppercase text-[11px] tracking-widest">
                                Update Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php elseif (in_array($user['role'], ['admin', 'staff'], true)): ?>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-start">

                <div class="lg:col-span-4 flex flex-col items-center gap-6">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.4em]">Foto Profil</p>
                    <div class="w-full bg-white rounded-[32px] border border-gray-100 shadow-sm p-8 flex flex-col items-center gap-5">
                        <div class="relative">
                            <img id="admin-avatar-preview" src="uploads/<?= htmlspecialchars($user['foto_profil'] ?: 'default.png') ?>" alt="Foto Profil"
                                 class="w-36 h-36 rounded-full object-cover border-4 border-red-50 shadow-lg">
                            <span class="absolute -bottom-1 -right-1 w-10 h-10 bg-red-800 text-white rounded-2xl flex items-center justify-center shadow-lg">
                                <i class="fas <?= $user['role'] === 'admin' ? 'fa-user-shield' : 'fa-user-tie' ?> text-sm"></i>
                            </span>
                        </div>
                        <div class="text-center">
                            <p class="font-black text-gray-900 text-lg leading-tight"><?= htmlspecialchars($user['nama']) ?></p>
                            <p class="text-[10px] font-black uppercase tracking-widest text-red-800 mt-2">
                                <?= $user['role'] === 'admin' ? 'Admin BBPOM' : 'Staff BBPOM' ?>
                            </p>
                            <p class="text-xs text-gray-400 font-semibold mt-1"><?= htmlspecialchars($user['email'] ?? '') ?></p>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-8 space-y-8">
                    <div class="bg-white p-10 rounded-[45px] shadow-sm border border-gray-50">
                        <div class="flex items-center gap-4 mb-10 pb-6 border-b border-gray-50">
                            <div class="w-12 h-12 bg-red-800 rounded-2xl flex items-center justify-center text-white shadow-lg">
                                <i class="fas fa-user-edit text-xl"></i>
                            </div>
                            <h3 class="font-black text-xl text-gray-900">Data Akun</h3>
                        </div>

                        <form action="" method="POST" enctype="multipart/form-data" class="space-y-8" id="profil-form">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">

                                <div class="md:col-span-2">
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Nama Lengkap</label>
                                    <input type="text" name="nama" value="<?= htmlspecialchars($user['nama']) ?>" required class="soft-input">
                                </div>

                                <div class="md:col-span-1">
                                    <label class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] block mb-2.5 ml-1">Ganti Foto Profil</label>
                                    <label class="file-label-soft">
                                        <i class="fas fa-camera text-base"></i>
                                        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp,image/gif,image/*" class="hidden" id="foto-profil-input">
                                        <span id="foto-profil-label">Pilih Gambar</span>
                                    </label>
                                </div>

                                <div class="md:col-span-1">
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Usia</label>
                                    <input type="number" name="usia" min="1" max="120" value="<?= htmlspecialchars($user['usia'] ?? '') ?>" class="soft-input">
                                </div>

                                <div>
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">No. WhatsApp</label>
                                    <input type="text" name="nomor_hp" value="<?= htmlspecialchars($user['nomor_hp'] ?? '') ?>" class="soft-input">
                                </div>

                                <div>
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Instansi / Unit Kerja</label>
                                    <input type="text" name="nama_instansi" value="<?= htmlspecialchars($user['nama_instansi'] ?? '') ?>" class="soft-input">
                                </div>

                                <div class="md:col-span-2">
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-3 ml-1">Jenis Kelamin</label>
                                    <div class="grid grid-cols-2 gap-4">
                                        <label class="flex items-center justify-center h-[54px] border rounded-[18px] cursor-pointer transition-all <?= ($user['jenis_kelamin'] == 'Pria') ? 'bg-orange-50 border-orange-500 text-orange-700 font-bold' : 'bg-gray-50 border-gray-100 text-gray-400' ?>">
                                            <input type="radio" name="jenis_kelamin" value="Pria" class="hidden" <?= ($user['jenis_kelamin'] == 'Pria') ? 'checked' : '' ?>> PRIA
                                        </label>
                                        <label class="flex items-center justify-center h-[54px] border rounded-[18px] cursor-pointer transition-all <?= ($user['jenis_kelamin'] == 'Wanita') ? 'bg-orange-50 border-orange-500 text-orange-700 font-bold' : 'bg-gray-50 border-gray-100 text-gray-400' ?>">
                                            <input type="radio" name="jenis_kelamin" value="Wanita" class="hidden" <?= ($user['jenis_kelamin'] == 'Wanita') ? 'checked' : '' ?>> WANITA
                                        </label>
                                    </div>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Alamat</label>
                                    <?php
                                    $partsAlamat1 = ntb_parse_alamat_parts($user['alamat'] ?? '');
                                    $alamatPrefix = 'profil1';
                                    $alamatRequired = false;
                                    $alamatShowDetail = true;
                                    $alamatKab = $partsAlamat1['kab'];
                                    $alamatKec = $partsAlamat1['kec'];
                                    $alamatDesa = $partsAlamat1['desa'];
                                    $alamatDetail = $partsAlamat1['detail'];
                                    include 'views/includes/alamat_dropdown.php';
                                    ?>
                                </div>
                            </div>

                            <button type="submit" name="update_profil" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-5 rounded-[22px] shadow-xl transform active:scale-[0.98] uppercase text-[11px] tracking-widest mt-4" id="simpan-profil">
                                Simpan Perubahan
                            </button>
                        </form>
                    </div>

                    <div class="bg-white p-10 rounded-[45px] shadow-sm border border-gray-50">
                        <div class="flex items-center gap-4 mb-8">
                            <div class="w-10 h-10 bg-red-800 rounded-xl flex items-center justify-center text-white shadow-lg">
                                <i class="fas fa-lock text-sm"></i>
                            </div>
                            <h3 class="font-black text-gray-900 uppercase text-xs tracking-widest">Keamanan</h3>
                        </div>
                        <form action="" method="POST" class="space-y-5">
                            <input type="password" name="pass_lama" required placeholder="Password Lama" class="soft-input">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <input type="password" name="pass_baru" required placeholder="Sandi Baru" class="soft-input">
                                <input type="password" name="konfirmasi" required placeholder="Ulangi Sandi" class="soft-input">
                            </div>
                            <button type="submit" name="change_password" class="w-full bg-gray-900 hover:bg-black text-white font-black py-5 rounded-[22px] uppercase text-[11px] tracking-widest">
                                Update Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php else: ?>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-start">
                
                <div class="lg:col-span-4 flex flex-col items-center gap-6">
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-[0.4em]">ID Card Agen</p>

                    <?php if ($sudah_lengkap): ?>
                        <?php
                            $card_data = $user;
                            $card_size = 'lg';
                            $card_download = true;
                            $card_uid = 'profil';
                            include 'views/includes/id-card.php';
                        ?>
                    <?php else: ?>
                        <div class="w-[300px] h-[534px] rounded-[32px] border-2 border-dashed border-gray-200 bg-gray-50 flex flex-col items-center justify-center text-center p-8 gap-3">
                            <i class="fas fa-id-card text-3xl text-gray-300"></i>
                            <p class="text-sm font-black text-gray-500">ID Card Belum Dibuat</p>
                            <p class="text-xs text-gray-400 font-semibold leading-relaxed">Lengkapi ID Agen, nama, jenis kelamin, instansi, nomor HP, dan unggah foto profil untuk membuat ID Card otomatis.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="lg:col-span-8 space-y-8">
                    <div class="bg-white p-10 rounded-[45px] shadow-sm border border-gray-50">
                        <div class="flex items-center gap-4 mb-10 pb-6 border-b border-gray-50">
                            <div class="w-12 h-12 bg-red-800 rounded-2xl flex items-center justify-center text-white shadow-lg">
                                <i class="fas fa-user-edit text-xl"></i>
                            </div>
                            <h3 class="font-black text-xl text-gray-900">Biodata & ID Agen</h3>
                        </div>

                        <form action="" method="POST" enctype="multipart/form-data" class="space-y-8" id="profil-form">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                
                                <div class="md:col-span-2">
                                    <label class="text-[10px] font-black text-red-800 uppercase tracking-[0.2em] block mb-2.5 ml-1">ID Agen (Contoh: AG-001)</label>
                                    <input type="text" name="agen_id" value="<?= htmlspecialchars($user['agen_id'] ?? '') ?>" required class="soft-input border-red-100 bg-red-50/30">
                                </div>

                                <div class="md:col-span-2">
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Nama Lengkap</label>
                                    <input type="text" name="nama" value="<?= htmlspecialchars($user['nama']) ?>" required class="soft-input">
                                </div>

                                <div class="md:col-span-1">
                                    <label class="text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] block mb-2.5 ml-1">Ganti Foto Profil</label>
                                    <label class="file-label-soft">
                                        <i class="fas fa-camera text-base"></i>
                                        <input type="file" name="foto" accept="image/jpeg,image/png,image/webp,image/gif,image/*" class="hidden" id="foto-profil-input">
                                        <span id="foto-profil-label">Pilih Gambar</span>
                                    </label>
                                </div>

                                <div>
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Usia</label>
                                    <input type="number" name="usia" min="1" max="120" value="<?= htmlspecialchars($user['usia'] ?? '') ?>" class="soft-input">
                                </div>

                                <div>
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">No. WhatsApp</label>
                                    <input type="text" name="nomor_hp" value="<?= htmlspecialchars($user['nomor_hp'] ?? '') ?>" class="soft-input">
                                </div>

                                <div>
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Pekerjaan</label>
                                    <input type="text" name="pekerjaan" value="<?= htmlspecialchars($user['pekerjaan'] ?? '') ?>" placeholder="Contoh: Mahasiswa, Guru, Wiraswasta" class="soft-input">
                                </div>

                                <div>
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Instansi / Universitas</label>
                                    <input type="text" name="nama_instansi" value="<?= htmlspecialchars($user['nama_instansi'] ?? '') ?>" class="soft-input">
                                </div>

                                <div class="md:col-span-2">
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-3 ml-1">Jenis Kelamin</label>
                                    <div class="grid grid-cols-2 gap-4">
                                        <label class="flex items-center justify-center h-[54px] border rounded-[18px] cursor-pointer transition-all <?= ($user['jenis_kelamin'] == 'Pria') ? 'bg-orange-50 border-orange-500 text-orange-700 font-bold' : 'bg-gray-50 border-gray-100 text-gray-400' ?>">
                                            <input type="radio" name="jenis_kelamin" value="Pria" class="hidden" <?= ($user['jenis_kelamin'] == 'Pria') ? 'checked' : '' ?>> PRIA
                                        </label>
                                        <label class="flex items-center justify-center h-[54px] border rounded-[18px] cursor-pointer transition-all <?= ($user['jenis_kelamin'] == 'Wanita') ? 'bg-orange-50 border-orange-500 text-orange-700 font-bold' : 'bg-gray-50 border-gray-100 text-gray-400' ?>">
                                            <input type="radio" name="jenis_kelamin" value="Wanita" class="hidden" <?= ($user['jenis_kelamin'] == 'Wanita') ? 'checked' : '' ?>> WANITA
                                        </label>
                                    </div>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Alamat</label>
                                    <?php
                                    $partsAlamat2 = ntb_parse_alamat_parts($user['alamat'] ?? '');
                                    $alamatPrefix = 'profil2';
                                    $alamatRequired = false;
                                    $alamatShowDetail = true;
                                    $alamatKab = $partsAlamat2['kab'];
                                    $alamatKec = $partsAlamat2['kec'];
                                    $alamatDesa = $partsAlamat2['desa'];
                                    $alamatDetail = $partsAlamat2['detail'];
                                    include 'views/includes/alamat_dropdown.php';
                                    ?>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] block mb-2.5 ml-1">Titik Lokasi di Peta Sebaran Agen</label>
                                    <p class="text-[11px] text-gray-400 font-semibold mb-3 -mt-1">Data peta di banyak desa NTB belum lengkap - tebakan otomatis dari alamat kadang cuma sampai level kecamatan, <b>belum tentu tepat</b>. Wajib cek & geser pin merah sampai benar-benar menunjuk lokasi Anda. Titik ini yang dipakai untuk peta sebaran agen.</p>
                                    <input type="hidden" name="latitude" id="latitude" value="<?= htmlspecialchars((string)($user['latitude'] ?? '')) ?>">
                                    <input type="hidden" name="longitude" id="longitude" value="<?= htmlspecialchars((string)($user['longitude'] ?? '')) ?>">
                                    <div id="peta-lokasi" class="w-full rounded-2xl overflow-hidden border border-gray-100" style="height: 320px;"></div>
                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mt-3">
                                        <div>
                                            <p id="gps-status" class="text-xs text-gray-500 font-semibold"><?= (!empty($user['latitude']) ? 'Titik tersimpan - geser pin kalau kurang tepat' : 'Belum ada titik — isi Alamat di atas atau klik "Ambil GPS"') ?></p>
                                            <p id="gps-coords" class="text-[11px] font-mono text-gray-400 mt-1"><?php if (!empty($user['latitude'])): ?><?= htmlspecialchars($user['latitude']) ?>, <?= htmlspecialchars($user['longitude']) ?><?php endif; ?></p>
                                            <button type="button" id="btn-cari-ulang" class="text-[10px] font-bold text-green-700 underline mt-1">Cari ulang titik dari alamat</button>
                                        </div>
                                        <button type="button" id="btn-gps" class="px-5 py-3 bg-green-600 hover:bg-green-700 text-white text-[10px] font-black uppercase tracking-widest rounded-xl shrink-0">
                                            <i class="fas fa-location-crosshairs mr-1"></i> Ambil GPS
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" name="update_profil" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-5 rounded-[22px] shadow-xl transform active:scale-[0.98] uppercase text-[11px] tracking-widest mt-4" id="simpan-profil">
                                Simpan Perubahan
                            </button>
                        </form>
                    </div>

                    <div class="bg-white p-10 rounded-[45px] shadow-sm border border-gray-50">
                        <div class="flex items-center gap-4 mb-8">
                            <div class="w-10 h-10 bg-red-800 rounded-xl flex items-center justify-center text-white shadow-lg">
                                <i class="fas fa-lock text-sm"></i>
                            </div>
                            <h3 class="font-black text-gray-900 uppercase text-xs tracking-widest">Keamanan</h3>
                        </div>
                        <form action="" method="POST" class="space-y-5">
                            <input type="password" name="pass_lama" required placeholder="Password Lama" class="soft-input">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <input type="password" name="pass_baru" required placeholder="Sandi Baru" class="soft-input">
                                <input type="password" name="konfirmasi" required placeholder="Ulangi Sandi" class="soft-input">
                            </div>
                            <button type="submit" name="change_password" class="w-full bg-gray-900 hover:bg-black text-white font-black py-5 rounded-[22px] uppercase text-[11px] tracking-widest">
                                Update Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        const radios = document.querySelectorAll('input[name="jenis_kelamin"]');
        radios.forEach(radio => {
            radio.addEventListener('change', () => {
                radios.forEach(r => {
                    r.parentElement.classList.remove('bg-orange-50', 'border-orange-500', 'text-orange-700', 'font-bold');
                    r.parentElement.classList.add('bg-gray-50', 'border-gray-100', 'text-gray-400');
                });
                if (radio.checked) {
                    radio.parentElement.classList.add('bg-orange-50', 'border-orange-500', 'text-orange-700', 'font-bold');
                    radio.parentElement.classList.remove('bg-gray-50', 'border-gray-100', 'text-gray-400');
                }
            });
        });

        const fotoInput = document.getElementById('foto-profil-input');
        const fotoLabel = document.getElementById('foto-profil-label');
        const profilForm = document.getElementById('profil-form');
        const simpanProfil = document.getElementById('simpan-profil');

        const ttdInput = document.getElementById('ttd-input');
        const ttdLabel = document.getElementById('ttd-label');
        ttdInput?.addEventListener('change', () => {
            const file = ttdInput.files?.[0];
            if (file) ttdLabel.textContent = file.name;
        });
        let backgroundRemovalPromise = null;
        let fotoSudahDiproses = false;

        function tampilkanPreview(file) {
            const previewUrl = URL.createObjectURL(file);
            document.querySelectorAll('[data-idcard-photo]').forEach(photo => photo.src = previewUrl);
            const adminAvatar = document.getElementById('admin-avatar-preview');
            if (adminAvatar) adminAvatar.src = previewUrl;
        }

        fotoInput?.addEventListener('change', () => {
            const file = fotoInput.files?.[0];
            if (!file) return;

            fotoLabel.textContent = file.name;
            fotoSudahDiproses = false;
            tampilkanPreview(file);

            // Hapus latar foto di browser lalu ganti file upload dengan PNG transparan.
            backgroundRemovalPromise = (async () => {
                simpanProfil.disabled = true;
                simpanProfil.classList.add('opacity-60', 'cursor-wait');
                fotoLabel.textContent = 'Menghapus background...';

                try {
                    const { removeBackground } = await import('https://cdn.jsdelivr.net/npm/@imgly/background-removal@1.6.0/+esm');
                    const fotoTanpaBackground = await removeBackground(file);
                    const namaPng = file.name.replace(/\.[^.]+$/, '') + '-tanpa-background.png';
                    const filePng = new File([fotoTanpaBackground], namaPng, { type: 'image/png' });
                    const files = new DataTransfer();
                    files.items.add(filePng);
                    fotoInput.files = files.files;
                    fotoSudahDiproses = true;
                    fotoLabel.textContent = namaPng;
                    tampilkanPreview(filePng);
                } catch (error) {
                    console.error('Background removal gagal, pakai foto asli:', error);
                    // Tetap izinkan upload file asli (JPG/PNG/WEBP/GIF)
                    fotoSudahDiproses = true;
                    fotoLabel.textContent = file.name + ' (asli)';
                    tampilkanPreview(file);
                } finally {
                    simpanProfil.disabled = false;
                    simpanProfil.classList.remove('opacity-60', 'cursor-wait');
                }
            })();
        });

        profilForm?.addEventListener('submit', async event => {
            if (backgroundRemovalPromise && !fotoSudahDiproses) {
                event.preventDefault();
                await backgroundRemovalPromise;
                if (fotoSudahDiproses) profilForm.requestSubmit();
            }
        });
    
        // Tanda tangan: manual canvas + upload foto
        (function initTtdDual() {
            const canvas = document.getElementById('ttd-canvas');
            const form = document.getElementById('form-ttd-manual');
            const hidden = document.getElementById('ttd_data');
            const metodeEl = document.getElementById('ttd_metode');
            const btnClear = document.getElementById('ttd-clear');
            const tabManual = document.getElementById('tab-ttd-manual');
            const tabUpload = document.getElementById('tab-ttd-upload');
            const panelManual = document.getElementById('panel-ttd-manual');
            const panelUpload = document.getElementById('panel-ttd-upload');
            const ttdInput = document.getElementById('ttd-input');
            const ttdLabel = document.getElementById('ttd-label');
            let drawing = false;
            let hasInk = false;
            let metode = 'manual';

            function setTab(m) {
                metode = m;
                if (metodeEl) metodeEl.value = m;
                const active = 'flex-1 min-w-[140px] px-4 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest bg-orange-600 text-white shadow-sm transition-all';
                const idle = 'flex-1 min-w-[140px] px-4 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest bg-transparent text-gray-500 hover:text-gray-800 transition-all';
                if (m === 'manual') {
                    tabManual && (tabManual.className = active);
                    tabUpload && (tabUpload.className = idle);
                    panelManual?.classList.remove('hidden');
                    panelUpload?.classList.add('hidden');
                } else {
                    tabUpload && (tabUpload.className = active);
                    tabManual && (tabManual.className = idle);
                    panelUpload?.classList.remove('hidden');
                    panelManual?.classList.add('hidden');
                }
            }
            tabManual?.addEventListener('click', () => setTab('manual'));
            tabUpload?.addEventListener('click', () => setTab('upload'));
            ttdInput?.addEventListener('change', () => {
                const f = ttdInput.files?.[0];
                if (f && ttdLabel) ttdLabel.textContent = f.name;
            });

            if (canvas) {
                const ctx = canvas.getContext('2d');
                function pos(e) {
                    const r = canvas.getBoundingClientRect();
                    const scaleX = canvas.width / r.width;
                    const scaleY = canvas.height / r.height;
                    const src = e.touches ? e.touches[0] : e;
                    return { x: (src.clientX - r.left) * scaleX, y: (src.clientY - r.top) * scaleY };
                }
                function start(e) {
                    e.preventDefault();
                    drawing = true;
                    const p = pos(e);
                    ctx.beginPath();
                    ctx.moveTo(p.x, p.y);
                }
                function move(e) {
                    if (!drawing) return;
                    e.preventDefault();
                    const p = pos(e);
                    ctx.lineWidth = 2.5;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    ctx.strokeStyle = '#111827';
                    ctx.lineTo(p.x, p.y);
                    ctx.stroke();
                    hasInk = true;
                }
                function end() { drawing = false; }
                canvas.addEventListener('mousedown', start);
                canvas.addEventListener('mousemove', move);
                window.addEventListener('mouseup', end);
                canvas.addEventListener('touchstart', start, { passive: false });
                canvas.addEventListener('touchmove', move, { passive: false });
                canvas.addEventListener('touchend', end);
                btnClear?.addEventListener('click', () => {
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    hasInk = false;
                    if (hidden) hidden.value = '';
                });
            }

            form?.addEventListener('submit', (e) => {
                if (metode === 'manual') {
                    if (!hasInk) {
                        e.preventDefault();
                        alert('Silakan buat tanda tangan di kotak terlebih dahulu, atau pilih metode Upload Foto.');
                        return;
                    }
                    if (hidden && canvas) hidden.value = canvas.toDataURL('image/png');
                } else {
                    if (!ttdInput?.files?.length) {
                        e.preventDefault();
                        alert('Silakan pilih file gambar tanda tangan.');
                        return;
                    }
                }
            });
        })();

    </script>

<script src="assets/js/peta-pin.js"></script>
<script src="assets/js/ntb-alamat.js"></script>
<script>
(function() {
    const data = <?= json_encode(ntb_wilayah_hierarki(), JSON_UNESCAPED_UNICODE) ?>;
    document.querySelectorAll('[data-ntb-alamat]').forEach(function(wrap) {
        const prefix = wrap.getAttribute('data-ntb-alamat');
        const kab = document.getElementById(prefix + '_kab');
        const kec = document.getElementById(prefix + '_kec');
        const desa = document.getElementById(prefix + '_desa');
        const detail = document.getElementById(prefix + '_detail');
        initNtbAlamat(kab, kec, desa, data, {
            kab: wrap.getAttribute('data-default-kab') || '',
            kec: wrap.getAttribute('data-default-kec') || '',
            desa: wrap.getAttribute('data-default-desa') || ''
        });

        // Peta pin cuma ada di form alamat agen (ada #peta-lokasi di sebelahnya)
        if (document.getElementById('peta-lokasi')) {
            const peta = initPetaPin({
                mapId: 'peta-lokasi', latId: 'latitude', lngId: 'longitude',
                statusId: 'gps-status', coordsId: 'gps-coords', gpsBtnId: 'btn-gps',
                geocodeUrl: 'ajax-geocode.php',
                getAlamat: function() { return ntbComposeAlamat(kab?.value, kec?.value, desa?.value, detail?.value || ''); },
                initialLat: <?= json_encode($user['latitude'] ?? null) ?>,
                initialLng: <?= json_encode($user['longitude'] ?? null) ?>
            });
            [kab, kec, desa].forEach(function(el) {
                el?.addEventListener('change', function() { peta && peta.cariDariAlamat(); });
            });
            detail?.addEventListener('input', function() { peta && peta.cariDariAlamat(); });
            document.getElementById('btn-cari-ulang')?.addEventListener('click', function() { peta && peta.cariUlangDariAlamat(); });
        }
    });
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            form.querySelectorAll('[data-ntb-alamat]').forEach(function(wrap) {
                const prefix = wrap.getAttribute('data-ntb-alamat');
                const full = document.getElementById(prefix + '_full');
                const detail = document.getElementById(prefix + '_detail');
                if (full) {
                    full.value = ntbComposeAlamat(
                        document.getElementById(prefix + '_kab')?.value,
                        document.getElementById(prefix + '_kec')?.value,
                        document.getElementById(prefix + '_desa')?.value,
                        detail?.value || ''
                    );
                }
            });
        }, true);
    });
})();
</script>

</body>
</html>