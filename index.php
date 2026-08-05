<?php
session_start();

/** * Logika Pengalihan (Gateway)
 * Mengecek apakah sesi user_id sudah terbentuk atau belum
 */
if (isset($_SESSION['user_id'])) {
    
    // Jika sudah login, cek rolenya untuk diarahkan ke dashboard yang tepat
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin-dashboard");
    } elseif ($_SESSION['role'] === 'kabalai') {
        header("Location: kabalai-dashboard");
    } else {
        header("Location: dashboard");
    }
    exit;

} else {
    // Jika belum login, arahkan ke halaman login
    header("Location: login");
    exit;
}
?>