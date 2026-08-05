<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();
cek_admin_atau_kabalai();

// Nama file export
$filename = "Laporan_BBPOM_" . date('Y-m-d') . ".csv";

// Header untuk download file
header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=\"$filename\"");

$output = fopen("php://output", "w");
// Header kolom CSV [cite: 236-242]
fputcsv($output, ['ID', 'Tanggal', 'Agen', 'Komunitas', 'Jumlah Peserta', 'Informasi', 'Lokasi', 'Status']);

$query = "SELECT ch.id, ch.tanggal, u.nama as nama_agen, ch.nama_konsumen, ch.jumlah_peserta, ch.informasi, ch.lokasi, ch.status_review 
          FROM catatan_harian ch 
          JOIN users u ON ch.user_id = u.id 
          ORDER BY ch.tanggal DESC";

$stmt = $pdo->query($query);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, $row);
}
fclose($output);
exit;