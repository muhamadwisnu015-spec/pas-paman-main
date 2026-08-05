<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();
cek_staff_atau_admin();

$id       = (int)($_GET['id'] ?? 0);
$nonaktif = isset($_GET['nonaktif']);

if (!$id) { header("Location: daftar-soal"); exit; }

$stmt = $pdo->prepare("SELECT id, jenis FROM bank_soal WHERE id = ?");
$stmt->execute([$id]);
$soal = $stmt->fetch();
if (!$soal) { header("Location: daftar-soal"); exit; }

try {
    if ($nonaktif) {
        $pdo->prepare("UPDATE bank_soal SET status = 'nonaktif' WHERE id = ?")->execute([$id]);
        $_SESSION['flash_message'] = 'Paket soal dinonaktifkan.';
    } else {
        $pdo->prepare("UPDATE bank_soal SET status = 'aktif' WHERE id = ?")->execute([$id]);
        $_SESSION['flash_message'] = 'Paket soal berhasil diaktifkan!';
    }
    $_SESSION['flash_type'] = 'success';
} catch (Exception $e) {
    $_SESSION['flash_message'] = 'Gagal: ' . $e->getMessage();
    $_SESSION['flash_type'] = 'error';
}

header("Location: daftar-soal");
exit;
