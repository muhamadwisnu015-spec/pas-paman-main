<?php
require_once 'config/database.php';
session_start();

// Proteksi: Hanya Admin yang boleh menghapus
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login");
    exit;
}

$id = $_GET['id'] ?? null;

if ($id) {
    try {
        // Mulai transaksi untuk menjaga integritas data
        $pdo->beginTransaction();

        /** * 1. Hapus catatan harian milik agen terlebih dahulu.
         * Nama tabel disesuaikan menjadi 'catatan_harian' sesuai database Anda.
         */
        $stmtCatatan = $pdo->prepare("DELETE FROM catatan_harian WHERE user_id = ?");
        $stmtCatatan->execute([$id]);

        /** * 2. Hapus user agen dari tabel users
         */
        $stmtUser = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'agen'");
        $stmtUser->execute([$id]);

        $pdo->commit();

        // Redirect kembali ke daftar agen dengan status sukses
        header("Location: daftar-agen?status=terhapus");
        exit;

    } catch (Exception $e) {
        // Batalkan semua perubahan jika ada error
        $pdo->rollBack();
        die("Gagal menghapus data: " . $e->getMessage());
    }
} else {
    header("Location: daftar-agen");
    exit;
}