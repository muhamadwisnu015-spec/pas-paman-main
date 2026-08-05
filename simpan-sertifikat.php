<?php
/**
 * Simpan backup PDF sertifikat yang sudah ditandatangani ke server.
 * Dipanggil dari halaman sertifikat (client upload base64 PDF).
 */
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

$role = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$hasilId = (int)($_POST['hasil_id'] ?? 0);
$pdfData = $_POST['pdf_data'] ?? '';

if ($hasilId <= 0 || $pdfData === '') {
    echo json_encode(['ok' => false, 'msg' => 'Data tidak lengkap']);
    exit;
}

// Pastikan kolom backup ada
try {
    $cols = $pdo->query("SHOW COLUMNS FROM hasil_test")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('sertifikat_file', $cols, true)) {
        $pdo->exec("ALTER TABLE hasil_test ADD COLUMN sertifikat_file VARCHAR(255) NULL");
    }
} catch (Throwable $e) {
    // lanjut
}

// Ambil hasil_test
$stmt = $pdo->prepare("
    SELECT ht.*, bs.jenis FROM hasil_test ht
    JOIN bank_soal bs ON bs.id = ht.bank_soal_id
    WHERE ht.id = ? AND ht.status_sertifikat = 'disetujui'
");
$stmt->execute([$hasilId]);
$hasil = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hasil) {
    echo json_encode(['ok' => false, 'msg' => 'Sertifikat tidak ditemukan / belum disetujui']);
    exit;
}

// Agen hanya milik sendiri; kabalai/admin boleh
if ($role === 'agen' && (int)$hasil['user_id'] !== $userId) {
    echo json_encode(['ok' => false, 'msg' => 'Akses ditolak']);
    exit;
}
if (!in_array($role, ['agen', 'kabalai', 'admin'], true)) {
    echo json_encode(['ok' => false, 'msg' => 'Akses ditolak']);
    exit;
}

// Decode base64 PDF
if (preg_match('#^data:application/pdf;base64,#', $pdfData)) {
    $pdfData = preg_replace('#^data:application/pdf;base64,#', '', $pdfData);
}
$bin = base64_decode($pdfData, true);
if ($bin === false || strlen($bin) < 100) {
    echo json_encode(['ok' => false, 'msg' => 'PDF tidak valid']);
    exit;
}
if (strlen($bin) > 8 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'msg' => 'PDF terlalu besar']);
    exit;
}

$dir = __DIR__ . '/uploads/sertifikat';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

$fname = 'SERT_' . $hasilId . '_' . bin2hex(random_bytes(6)) . '.pdf';
$path = $dir . '/' . $fname;
if (file_put_contents($path, $bin) === false) {
    echo json_encode(['ok' => false, 'msg' => 'Gagal menulis file']);
    exit;
}

// Hapus file lama jika ada
if (!empty($hasil['sertifikat_file'])) {
    $old = $dir . '/' . basename($hasil['sertifikat_file']);
    if (is_file($old)) @unlink($old);
}

$rel = 'sertifikat/' . $fname;
$upd = $pdo->prepare("UPDATE hasil_test SET sertifikat_file = ? WHERE id = ?");
$upd->execute([$rel, $hasilId]);

echo json_encode(['ok' => true, 'file' => $rel]);
