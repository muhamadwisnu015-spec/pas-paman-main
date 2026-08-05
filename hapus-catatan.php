<?php
require_once 'config/database.php';
require_once 'core/auth.php';

// Pastikan hanya Admin yang bisa mengakses file ini
cek_login();
if ($_SESSION['role'] !== 'admin') {
    header("Location: dashboard");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    try {
        $pdo->beginTransaction();

        // 1. Ambil nama file foto agar bisa dihapus dari folder
        $stmtFile = $pdo->prepare("SELECT file_path FROM catatan_files WHERE catatan_id = ?");
        $stmtFile->execute([$id]);
        $files = $stmtFile->fetchAll();

        foreach ($files as $f) {
            $path = "uploads/" . $f['file_path'];
            if (file_exists($path)) {
                unlink($path); // Hapus file fisik
            }
        }

        // 2. Hapus data di database (Cascade akan menghapus catatan_files otomatis jika FK diset)
        $stmtDelete = $pdo->prepare("DELETE FROM catatan_harian WHERE id = ?");
        $stmtDelete->execute([$id]);

        $pdo->commit();
        header("Location: riwayat?msg=success_delete");
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Gagal menghapus data: " . $e->getMessage());
    }
}