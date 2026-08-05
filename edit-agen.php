<?php
require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/ntb_wilayah_data.php';
require_once 'core/ntb_helper.php';
cek_login();

if ($_SESSION['role'] !== 'admin') {
    header("Location: dashboard");
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) { header("Location: daftar-agen"); exit; }

$message = '';
$swal_type = '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role IN ('agen', 'staff')");
$stmt->execute([$id]);
$agen = $stmt->fetch();
if (!$agen) { die("User tidak ditemukan."); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agen_id       = htmlspecialchars(trim($_POST['agen_id'] ?? ''));
    $nama          = htmlspecialchars(trim($_POST['nama'] ?? ''));
    $email         = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $nik           = htmlspecialchars(trim($_POST['nik'] ?? ''));
    $usia          = filter_var($_POST['usia'], FILTER_SANITIZE_NUMBER_INT);
    $nama_instansi = htmlspecialchars(trim($_POST['nama_instansi'] ?? ''));
    $nomor_hp      = htmlspecialchars(trim($_POST['nomor_hp'] ?? ''));
    $status        = $_POST['status'] ?? 'aktif';
    $password_baru = $_POST['password_baru'] ?? '';
    $jenis_kelamin = in_array($_POST['jenis_kelamin'] ?? '', ['Pria', 'Wanita']) ? $_POST['jenis_kelamin'] : ($agen['jenis_kelamin'] ?? 'Pria');
    $kab_kota = trim($_POST['kab_kota'] ?? '');
    $kecamatan = trim($_POST['kecamatan'] ?? '');
    $desa = trim($_POST['desa'] ?? '');
    $alamat_detail = htmlspecialchars(trim($_POST['alamat_detail'] ?? ''));
    $alamat = ($kab_kota && $kecamatan && $desa)
        ? ntb_format_alamat($kab_kota, $kecamatan, $desa, $alamat_detail)
        : htmlspecialchars(trim($_POST['alamat'] ?? ''));
    $pekerjaan     = htmlspecialchars(trim($_POST['pekerjaan'] ?? ''));
    $kampus        = htmlspecialchars(trim($_POST['kampus'] ?? ''));
    $jurusan       = htmlspecialchars(trim($_POST['jurusan'] ?? ''));
    $magang_mulai   = !empty($_POST['magang_mulai']) ? $_POST['magang_mulai'] : null;
    $magang_selesai = !empty($_POST['magang_selesai']) ? $_POST['magang_selesai'] : null;

    try {
        $role_baru = in_array($_POST['role'] ?? '', ['agen', 'staff']) ? $_POST['role'] : 'agen';
        $sql = "UPDATE users SET
            agen_id = ?, nama = ?, email = ?, nik = ?, nama_instansi = ?, nomor_hp = ?,
            usia = ?, status = ?, role = ?, jenis_kelamin = ?, alamat = ?,
            pekerjaan = ?, kampus = ?, jurusan = ?, magang_mulai = ?, magang_selesai = ?
            WHERE id = ?";
        $params = [
            $agen_id, $nama, $email, $nik, $nama_instansi, $nomor_hp,
            $usia, $status, $role_baru, $jenis_kelamin, $alamat,
            $pekerjaan, $kampus, $jurusan, $magang_mulai, $magang_selesai, $id
        ];
        $pdo->prepare($sql)->execute($params);

        if (!empty($password_baru)) {
            if (strlen($password_baru) < 6) throw new Exception("Password minimal 6 karakter.");
            $hashed = password_hash($password_baru, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $id]);
        }

        $swal_type = 'success';
        $message = "Data agen berhasil diperbarui!";
        $stmt->execute([$id]);
        $agen = $stmt->fetch();
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
    <title>Edit Agen | BBPOM Diary</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }</style>
</head>
<body class="bg-gray-50 flex flex-col md:flex-row min-h-screen">
    <?php include 'views/includes/sidebar.php'; ?>
    <main class="flex-1 p-6 md:p-12 overflow-y-auto">
        <div class="max-w-3xl mx-auto">
            <header class="mb-8">
                <a href="daftar-agen" class="inline-flex items-center text-red-800 font-black text-xs uppercase tracking-widest hover:translate-x-1 transition-transform">
                    <i class="fas fa-arrow-left mr-2"></i> Kembali ke Daftar Agen
                </a>
                <h2 class="text-3xl font-black text-gray-900 tracking-tight mt-4">Edit Data Agen</h2>
                <p class="text-gray-400 text-sm font-medium mt-1"><?= htmlspecialchars($agen['nama']) ?></p>
            </header>

            <div class="bg-white p-8 md:p-10 rounded-[40px] shadow-sm border border-gray-100">
                <form method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">ID Agen</label>
                            <input type="text" name="agen_id" value="<?= htmlspecialchars($agen['agen_id'] ?? '') ?>"
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Nama Lengkap</label>
                            <input type="text" name="nama" required value="<?= htmlspecialchars($agen['nama']) ?>"
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Email</label>
                            <input type="email" name="email" required value="<?= htmlspecialchars($agen['email']) ?>"
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm">
                        </div>
                        
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Jenis Kelamin</label>
                            <select name="jenis_kelamin" class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-bold text-sm">
                                <option value="Pria" <?= ($agen['jenis_kelamin'] ?? '') === 'Pria' ? 'selected' : '' ?>>Pria</option>
                                <option value="Wanita" <?= ($agen['jenis_kelamin'] ?? '') === 'Wanita' ? 'selected' : '' ?>>Wanita</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Usia</label>
                            <input type="number" name="usia" min="1" max="120" value="<?= htmlspecialchars($agen['usia'] ?? '') ?>"
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">No. HP / Telp</label>
                            <input type="text" name="nomor_hp" value="<?= htmlspecialchars($agen['nomor_hp'] ?? '') ?>"
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm font-mono">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Pekerjaan</label>
                            <input type="text" name="pekerjaan" value="<?= htmlspecialchars($agen['pekerjaan'] ?? '') ?>" placeholder="Contoh: Mahasiswa, Guru, ..."
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Alamat / Wilayah</label>
                            <?php
                            $partsAlamat = ntb_parse_alamat_parts($agen['alamat'] ?? '');
                            $alamatPrefix = 'agen';
                            $alamatRequired = false;
                            $alamatShowDetail = true;
                            $alamatKab = $partsAlamat['kab'];
                            $alamatKec = $partsAlamat['kec'];
                            $alamatDesa = $partsAlamat['desa'];
                            $alamatDetail = $partsAlamat['detail'];
                            include 'views/includes/alamat_dropdown.php';
                            ?>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Kampus (jika mahasiswa)</label>
                            <input type="text" name="kampus" value="<?= htmlspecialchars($agen['kampus'] ?? '') ?>" placeholder="Nama kampus"
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Jurusan</label>
                            <input type="text" name="jurusan" value="<?= htmlspecialchars($agen['jurusan'] ?? '') ?>" placeholder="Jurusan / prodi"
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Instansi / Asal</label>
                            <input type="text" name="nama_instansi" value="<?= htmlspecialchars($agen['nama_instansi'] ?? '') ?>"
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mb-2">Waktu Pelaksanaan - Mulai</label>
                            <input type="date" name="magang_mulai" value="<?= htmlspecialchars($agen['magang_mulai'] ?? '') ?>"
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mb-2">Waktu Pelaksanaan - Selesai</label>
                            <input type="date" name="magang_selesai" value="<?= htmlspecialchars($agen['magang_selesai'] ?? '') ?>"
                                   class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-semibold text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-gray-400 uppercase tracking-[0.2em] mb-2">Status</label>
                            <select name="status" class="w-full px-5 py-3.5 rounded-2xl bg-gray-50 border border-gray-100 focus:border-orange-500 outline-none font-bold text-sm">
                                <option value="aktif" <?= ($agen['status'] ?? '') == 'aktif' ? 'selected' : '' ?>>Aktif</option>
                                <option value="nonaktif" <?= ($agen['status'] ?? '') == 'nonaktif' ? 'selected' : '' ?>>Nonaktif (Blokir)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mb-2">Role</label>
                            <select name="role" class="w-full px-5 py-3.5 rounded-2xl bg-orange-50/30 border border-orange-100 focus:border-orange-500 outline-none font-bold text-sm">
                                <option value="agen" <?= ($agen['role'] ?? '') == 'agen' ? 'selected' : '' ?>>Agen</option>
                                <option value="staff" <?= ($agen['role'] ?? '') == 'staff' ? 'selected' : '' ?>>Staff (Kelola Soal)</option>
                            </select>
                        </div>
                    </div>

                    <div class="p-6 bg-orange-50/50 rounded-[28px] border border-orange-100 border-dashed">
                        <label class="block text-[10px] font-black text-orange-600 uppercase tracking-[0.2em] mb-2">Reset Password (Opsional)</label>
                        <input type="text" name="password_baru" placeholder="Kosongkan jika tidak diubah"
                               class="w-full px-5 py-3.5 rounded-2xl bg-white border border-orange-100 focus:border-orange-500 outline-none font-semibold text-sm">
                    </div>

                    <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white font-black py-5 rounded-[28px] shadow-xl transition-all active:scale-[0.98]">
                        Simpan Perubahan Data
                    </button>
                </form>
            </div>
        </div>
    </main>
    <script>
    <?php if ($swal_type): ?>
    Swal.fire({
        icon: '<?= $swal_type ?>',
        title: '<?= $swal_type == 'success' ? 'Berhasil' : 'Oops...' ?>',
        text: '<?= addslashes($message) ?>',
        confirmButtonColor: '#ea580c',
        customClass: { popup: 'rounded-[32px]' }
    });
    <?php endif; ?>
    </script>

<script src="assets/js/ntb-alamat.js"></script>
<script>
(function() {
    const data = <?= json_encode(ntb_wilayah_hierarki(), JSON_UNESCAPED_UNICODE) ?>;
    initNtbAlamat(
        document.getElementById('agen_kab'),
        document.getElementById('agen_kec'),
        document.getElementById('agen_desa'),
        data,
        { kab: <?= json_encode($partsAlamat['kab'] ?? ($alamatKab ?? '')) ?>, kec: <?= json_encode($partsAlamat['kec'] ?? ($alamatKec ?? '')) ?>, desa: <?= json_encode($partsAlamat['desa'] ?? ($alamatDesa ?? '')) ?> }
    );
    document.querySelector('form')?.addEventListener('submit', function() {
        const full = document.getElementById('agen_full');
        const detail = document.getElementById('agen_detail');
        if (full) full.value = ntbComposeAlamat(
            document.getElementById('agen_kab')?.value,
            document.getElementById('agen_kec')?.value,
            document.getElementById('agen_desa')?.value,
            detail?.value || ''
        );
    }, true);
})();
</script>

</body>
</html>