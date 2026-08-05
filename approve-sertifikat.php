<?php
require_once 'config/database.php';
require_once 'core/auth.php';

// Proteksi: Hanya Kepala Balai yang boleh menandatangani sertifikat
cek_login();
cek_kabalai();

$kabalaiId = $_SESSION['user_id'];
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Kepala Balai wajib sudah punya gambar tanda tangan sebelum bisa menyetujui apapun
$stmtTtd = $pdo->prepare("SELECT tanda_tangan FROM users WHERE id = ?");
$stmtTtd->execute([$kabalaiId]);
$ttdKabalai = $stmtTtd->fetchColumn();

if (!$ttdKabalai) {
    header("Location: profil?msg=perlu_ttd");
    exit;
}

if ($id > 0) {
    // Pastikan yang ditandatangani benar-benar sertifikat post-test yang lulus & masih menunggu TTD
    $stmt = $pdo->prepare("
        SELECT ht.id FROM hasil_test ht
        JOIN bank_soal bs ON bs.id = ht.bank_soal_id
        WHERE ht.id = ? AND bs.jenis = 'post_test' AND ht.nilai >= 70 AND ht.status_sertifikat = 'menunggu_ttd'
    ");
    $stmt->execute([$id]);
    $valid = $stmt->fetchColumn();

    if ($valid) {
        $update = $pdo->prepare("UPDATE hasil_test SET status_sertifikat = 'disetujui', signed_by = ?, signed_at = NOW() WHERE id = ?");
        if ($update->execute([$kabalaiId, $id])) {
            header("Location: sertifikat-approval?msg=signed");
            exit;
        }
    }
}

header("Location: sertifikat-approval?msg=error");
exit;
