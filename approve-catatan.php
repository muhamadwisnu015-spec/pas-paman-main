<?php
require_once 'config/database.php';
require_once 'core/auth.php';

// Proteksi Admin
cek_login();
if ($_SESSION['role'] !== 'admin') {
    header("Location: dashboard");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    // Update status menjadi approved
    $stmt = $pdo->prepare("UPDATE catatan_harian SET status_review = 'approved' WHERE id = ?");
    if ($stmt->execute([$id])) {
        header("Location: riwayat?msg=approved");
    } else {
        die("Gagal melakukan approve.");
    }
}