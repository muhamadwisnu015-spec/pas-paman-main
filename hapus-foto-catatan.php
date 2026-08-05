<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();

$file_id = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
$catatan_id = isset($_GET['catatan_id']) ? (int)$_GET['catatan_id'] : 0;

if ($file_id > 0) {
    // Ambil nama file untuk dihapus secara fisik
    $stmt = $pdo->prepare("SELECT file_path FROM catatan_files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();

    if ($file) {
        // Hapus file dari folder
        if (file_exists("uploads/" . $file['file_path'])) {
            unlink("uploads/" . $file['file_path']);
        }
        // Hapus record dari database
        $pdo->prepare("DELETE FROM catatan_files WHERE id = ?")->execute([$file_id]);
    }
}
header("Location: edit-catatan?id=" . $catatan_id);
exit;