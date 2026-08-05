<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();
cek_staff_atau_admin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: daftar-soal"); exit; }

$stmt = $pdo->prepare("SELECT id FROM bank_soal WHERE id = ?");
$stmt->execute([$id]);
if (!$stmt->fetch()) { header("Location: daftar-soal"); exit; }

try {
    // pertanyaan & opsi_jawaban terhapus otomatis via ON DELETE CASCADE
    $pdo->prepare("DELETE FROM bank_soal WHERE id = ?")->execute([$id]);
    $_SESSION['flash_message'] = 'Paket soal berhasil dihapus.';
    $_SESSION['flash_type'] = 'success';
} catch (Exception $e) {
    $_SESSION['flash_message'] = 'Gagal menghapus: ' . $e->getMessage();
    $_SESSION['flash_type'] = 'error';
}

header("Location: daftar-soal");
exit;
