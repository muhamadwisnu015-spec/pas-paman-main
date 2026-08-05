<?php
require_once 'config/database.php';
require_once 'core/auth.php';
cek_login();
if ($_SESSION['role'] !== 'admin') exit;

$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
$stmt->execute([$id]);
$current = $stmt->fetchColumn();

$newStatus = ($current == 'aktif') ? 'nonaktif' : 'aktif';

$update = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
$update->execute([$newStatus, $id]);

header("Location: daftar-agen");