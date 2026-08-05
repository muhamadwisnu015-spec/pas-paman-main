<?php
require_once 'config/database.php';
require_once 'core/auth.php';

header('Content-Type: application/json');
cek_login();

// Cuma admin yang boleh geser pin di peta (kabalai hanya lihat)
if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['sukses' => false, 'pesan' => 'Akses ditolak. Hanya admin yang boleh mengubah titik peta.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$tipe = $data['tipe'] ?? '';
$id   = (int)($data['id'] ?? 0);
$lat  = filter_var($data['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng  = filter_var($data['lng'] ?? null, FILTER_VALIDATE_FLOAT);

if (!$id || $lat === false || $lng === false || !in_array($tipe, ['agen', 'catatan'], true)) {
    http_response_code(400);
    echo json_encode(['sukses' => false, 'pesan' => 'Data koordinat tidak valid.']);
    exit;
}

try {
    if ($tipe === 'agen') {
        $stmt = $pdo->prepare("UPDATE users SET latitude = ?, longitude = ?, koordinat_manual = 1 WHERE id = ? AND role = 'agen'");
    } else {
        $stmt = $pdo->prepare("UPDATE catatan_harian SET latitude = ?, longitude = ?, koordinat_manual = 1 WHERE id = ?");
    }
    $stmt->execute([$lat, $lng, $id]);

    echo json_encode(['sukses' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['sukses' => false, 'pesan' => 'Gagal menyimpan koordinat baru.']);
}
