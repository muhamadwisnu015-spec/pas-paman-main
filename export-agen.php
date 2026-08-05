
<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();
if ($_SESSION['role'] !== 'admin') { header("Location: dashboard"); exit; }

$tgl_mulai   = $_GET['mulai'] ?? '';
$tgl_selesai = $_GET['selesai'] ?? '';
$filterPeriode = ($tgl_mulai && $tgl_selesai);

$where = ["u.role = 'agen'"];
$params = [];
if ($filterPeriode) {
    $where[] = "DATE(u.created_at) BETWEEN ? AND ?";
    $params[] = $tgl_mulai;
    $params[] = $tgl_selesai;
}
$whereStr = implode(' AND ', $where);

$sql = "
    SELECT u.*,
        (SELECT ht.nilai FROM hasil_test ht JOIN bank_soal bs ON bs.id = ht.bank_soal_id
         WHERE ht.user_id = u.id AND bs.jenis = 'pre_test'
         " . ($filterPeriode ? " AND DATE(ht.waktu_selesai) BETWEEN " . $pdo->quote($tgl_mulai) . " AND " . $pdo->quote($tgl_selesai) : "") . "
         ORDER BY ht.waktu_selesai DESC LIMIT 1) as nilai_pre,
        (SELECT ht.nilai FROM hasil_test ht JOIN bank_soal bs ON bs.id = ht.bank_soal_id
         WHERE ht.user_id = u.id AND bs.jenis = 'post_test'
         " . ($filterPeriode ? " AND DATE(ht.waktu_selesai) BETWEEN " . $pdo->quote($tgl_mulai) . " AND " . $pdo->quote($tgl_selesai) : "") . "
         ORDER BY ht.waktu_selesai DESC LIMIT 1) as nilai_post
    FROM users u WHERE $whereStr ORDER BY u.nama ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filename = 'daftar-agen-gaspaman-' . date('Ymd-His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
// BOM Excel UTF-8
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['No', 'ID Agen', 'Nama', 'Email', 'Alamat', 'Jenis Kelamin', 'Usia', 'No. Telp', 'Pekerjaan', 'Kampus', 'Jurusan', 'Instansi', 'Pre-Test', 'Post-Test', 'Status', 'Terdaftar']);

$no = 1;
foreach ($rows as $r) {
    fputcsv($out, [
        $no++,
        $r['agen_id'] ?? '',
        $r['nama'] ?? '',
        $r['email'] ?? '',
        $r['alamat'] ?? '',
        $r['jenis_kelamin'] ?? '',
        $r['usia'] ?? '',
        $r['nomor_hp'] ?? '',
        $r['pekerjaan'] ?? '',
        $r['kampus'] ?? '',
        $r['jurusan'] ?? '',
        $r['nama_instansi'] ?? '',
        $r['nilai_pre'] !== null ? number_format((float)$r['nilai_pre'], 1, '.', '') : '',
        $r['nilai_post'] !== null ? number_format((float)$r['nilai_post'], 1, '.', '') : '',
        $r['status'] ?? '',
        $r['created_at'] ?? '',
    ]);
}
fclose($out);
exit;