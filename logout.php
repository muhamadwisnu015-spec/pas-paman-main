<?php
session_start();

// Ambil nama user sebelum session dihancurkan (untuk pesan personal)
$nama_user = $_SESSION['nama'] ?? 'User';

// Hapus semua data session
session_unset();
session_destroy();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out...</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: #f8fafc;
        }
        .swal2-popup { 
            border-radius: 40px !important; 
            padding: 2rem !important;
        }
    </style>
</head>
<body>

    <script>
        Swal.fire({
            icon: 'success',
            title: 'Berhasil Keluar',
            text: 'Sampai jumpa lagi, <?= htmlspecialchars($nama_user) ?>!',
            showConfirmButton: false,
            timer: 2000, // Alert muncul selama 2 detik
            timerProgressBar: true,
            didOpen: () => {
                Swal.showLoading()
            }
        }).then(() => {
            // Setelah timer habis, arahkan ke login
            window.location.href = 'login';
        });
    </script>

</body>
</html>