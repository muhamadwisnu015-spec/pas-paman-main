<?php
/**
 * Endpoint AJAX kecil: terima teks alamat, balikin titik koordinat awal
 * (hasil geocoding Nominatim) buat posisi awal pin di peta form.
 * Posisi akhir tetap ditentukan manual oleh agen dengan menggeser pin -
 * ini cuma bantu supaya gak mulai dari peta kosong / posisi acak.
 */
require_once 'config/database.php';
require_once 'core/auth.php';
require_once 'core/geocoding.php';
cek_login();

header('Content-Type: application/json');

$alamat = trim($_POST['alamat'] ?? $_GET['alamat'] ?? '');
if ($alamat === '') {
    echo json_encode(['ok' => false, 'message' => 'Alamat kosong']);
    exit;
}

$geo = geocode_alamat($alamat);
if (!$geo) {
    echo json_encode(['ok' => false, 'message' => 'Alamat tidak ditemukan, geser pin manual di peta']);
    exit;
}

echo json_encode([
    'ok'      => true,
    'lat'     => $geo['lat'],
    'lng'     => $geo['lng'],
    'precise' => $geo['precise'] ?? false,
]);