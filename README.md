# Buku Harian Agen GAS-PAMAN

Sistem pelaporan kegiatan edukasi lapangan berbasis web untuk **Agen GAS-PAMAN BBPOM di Mataram**. Aplikasi ini memungkinkan agen (kader) mencatat dan mengirimkan laporan kegiatan sosialisasi obat dan makanan aman kepada admin BBPOM secara real-time.

---

## Daftar Isi

- [Tentang Aplikasi](#tentang-aplikasi)
- [Teknologi yang Digunakan](#teknologi-yang-digunakan)
- [Fitur](#fitur)
- [Struktur Database](#struktur-database)
- [Panduan Instalasi](#panduan-instalasi)
- [Konfigurasi SMTP](#konfigurasi-smtp)
- [Akun Demo](#akun-demo)
- [Keamanan](#keamanan)
- [Struktur File](#struktur-file)

---

## Tentang Aplikasi

**GAS-PAMAN** (Keluarga Sadar Obat dan Makanan Aman) adalah platform digital BBPOM Mataram untuk:
- Pencatatan kegiatan edukasi agen di komunitas
- Monitoring dan review laporan oleh admin
- Evaluasi pengetahuan agen melalui Pre-Test & Post-Test
- Ekspor data laporan untuk keperluan pelaporan internal

---

## Teknologi yang Digunakan

| Komponen | Detail |
|---|---|
| **Backend** | PHP 8.x (Native, tanpa framework) |
| **Database** | MySQL / MariaDB (via PDO) |
| **Email** | PHPMailer v6 (manual include) |
| **CSS Framework** | Tailwind CSS (via CDN) |
| **UI Alert** | SweetAlert2 (via CDN) |
| **Icon** | Font Awesome 6 (via CDN) |
| **Font** | Plus Jakarta Sans (Google Fonts) |
| **CAPTCHA** | Custom PHP GD Image |
| **Server** | Apache / Nginx + PHP |

---

## Fitur

### Agen (Kader)
- **Dashboard** — ringkasan performa, total laporan, aksi hari ini, dan status test
- **Tambah Laporan** — form lengkap dengan upload bukti foto/video (drag & drop)
- **Edit & Hapus Catatan** — pengelolaan laporan sendiri
- **Riwayat Catatan** — histori seluruh laporan yang pernah dibuat
- **Pre-Test & Post-Test** — kuis evaluasi pengetahuan agen
- **Hasil Test** — melihat nilai dan riwayat pengerjaan soal
- **Profil** — update data diri, ID agen, dan foto profil (avatar)
- **Lupa Password** — reset password via link email

### Admin
- **Dashboard Admin** — statistik global (total agen, laporan masuk, individu teredukasi, laporan pending)
- **Manajemen Agen** — daftar, detail, edit, dan nonaktifkan akun agen
- **Monitoring Laporan** — review semua laporan masuk (approve / minta revisi)
- **Kelola Bank Soal** — tambah, edit, hapus soal Pre-Test & Post-Test; aktifkan/nonaktifkan paket soal
- **Hasil Test Agen** — monitoring nilai test seluruh agen
- **Export CSV** — ekspor data laporan berdasarkan rentang tanggal

### Sistem
- Registrasi mandiri untuk kader baru
- CAPTCHA numerik custom di halaman login
- Notifikasi SweetAlert2 untuk setiap aksi (berhasil, gagal, konfirmasi)
- Sidebar responsif dengan hamburger menu untuk mobile
- Upload file dengan validasi MIME type (foto maks 3MB, video MP4 maks 10MB)

---

## Struktur Database

Database: `xlaj2839_bbpom`

| Tabel | Fungsi |
|---|---|
| `users` | Data semua pengguna (admin, staff, agen) |
| `catatan_harian` | Laporan kegiatan edukasi harian |
| `catatan_files` | File bukti (foto/video) per laporan |
| `bank_soal` | Paket soal Pre-Test & Post-Test |
| `pertanyaan` | Pertanyaan dalam setiap bank soal |
| `opsi_jawaban` | Pilihan jawaban per pertanyaan |
| `hasil_test` | Rekap nilai test agen |
| `detail_jawaban` | Jawaban per soal yang dipilih agen |

---

## Panduan Instalasi

### Kebutuhan Sistem
- PHP >= 8.0 dengan ekstensi: `pdo_mysql`, `gd`, `fileinfo`
- MySQL / MariaDB >= 10.x
- Web server Apache (dengan `mod_rewrite`) atau Nginx
- Akses ke SMTP server untuk fitur reset password

### Langkah 1 — Clone / Download Project

```bash
git clone https://github.com/username/buku-harian.git
cd buku-harian
```

Atau download ZIP lalu ekstrak ke direktori web server (contoh: `htdocs/buku-harian` atau `/var/www/html/buku-harian`).

### Langkah 2 — Import Database

1. Buka **phpMyAdmin** atau MySQL client
2. Buat database baru:
   ```sql
   CREATE DATABASE xlaj2839_bbpom CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```
3. Import file SQL:
   ```bash
   mysql -u root -p xlaj2839_bbpom < database/database.sql
   ```
   Atau lewat phpMyAdmin: pilih database → tab **Import** → pilih `database/database.sql`

### Langkah 3 — Konfigurasi Koneksi Database

Edit file `config/database.php`:

```php
<?php
date_default_timezone_set('Asia/Makassar'); // Sesuaikan timezone

$host = 'localhost';        // Host database
$db   = 'xlaj2839_bbpom';  // Nama database
$user = 'xlaj2839_bbpom';  // Username database
$pass = 'YOUR_PASSWORD';    // Password database

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Koneksi Database Gagal: " . $e->getMessage());
}
```

> Ganti nilai `$user` dan `$pass` sesuai konfigurasi MySQL lokal Anda.

### Langkah 4 — Konfigurasi SMTP (Lupa Password)

Edit bagian SMTP di file `lupa-password.php`:

```php
$mail->isSMTP();
$mail->Host       = 'mail.xlabscloud.com';     // Host SMTP
$mail->SMTPAuth   = true;
$mail->Username   = 'bensalem@xlabscloud.com'; // Email pengirim
$mail->Password   = 'YOUR_SMTP_PASSWORD';      // Password email
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port       = 587;
```

Dan sesuaikan URL reset password di baris ini:

```php
$url = "http://localhost/buku-harian/reset-password-aksi?token=$token";
```

> Ganti `http://localhost/buku-harian` dengan URL domain aktual Anda.

### Langkah 5 — Izin Folder Upload

Pastikan folder `uploads/` dapat ditulis oleh web server:

```bash
chmod 755 uploads/
# atau jika perlu
chmod 777 uploads/
```

### Langkah 6 — Konfigurasi Web Server

**Apache** — pastikan `mod_rewrite` aktif. Buat atau sesuaikan `.htaccess` di root project jika diperlukan untuk URL clean (tanpa `.php`).

**Nginx** — tambahkan konfigurasi `try_files` dan pastikan PHP-FPM berjalan.

### Langkah 7 — Akses Aplikasi

Buka browser dan kunjungi:
```
http://localhost/buku-harian/
```
Aplikasi akan otomatis redirect ke halaman login.

---

## Konfigurasi SMTP

Aplikasi menggunakan **PHPMailer** (sudah disertakan di folder `phpmailer/`) untuk mengirim email reset password.

| Parameter | Nilai Default |
|---|---|
| SMTP Host | `mail.xlabscloud.com` |
| SMTP Port | `587` |
| Enkripsi | `STARTTLS` |
| Username | `bensalem@xlabscloud.com` |
| Password | `YOUR_SMTP_PASSWORD` |
| From Name | `GAS-PAMAN BBPOM` |

**Untuk Gmail / Google Workspace:**
```php
$mail->Host       = 'smtp.gmail.com';
$mail->Username   = 'emailanda@gmail.com';
$mail->Password   = 'app-password-gmail'; // Gunakan App Password, bukan password biasa
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port       = 587;
```

> **Catatan:** Untuk produksi, ganti konfigurasi SSL berikut agar lebih aman (hapus opsi `verify_peer = false`):
> ```php
> // Hapus atau komentari blok SMTPOptions ini di production
> $mail->SMTPOptions = array(
>     'ssl' => array(
>         'verify_peer'       => false,
>         'verify_peer_name'  => false,
>         'allow_self_signed' => true
>     )
> );
> ```

---

## Akun Demo

### Admin
| Field | Value |
|---|---|
| Email | `l1nux3r69@gmail.com` |
| Password | `admin123` |
| Role | Admin |

### Agen (User)
| Field | Value |
|---|---|
| Email | `klise@gmail.com` |
| Password | `klise123` |
| Role | Agen |
| ID Agen | `AG-001` |

> **Peringatan:** Ganti semua password akun demo sebelum deploy ke server produksi.

---

## Keamanan

### Proteksi yang Sudah Diterapkan

| Ancaman | Mitigasi |
|---|---|
| **SQL Injection** | Seluruh query menggunakan PDO Prepared Statements |
| **XSS (Cross-Site Scripting)** | Input di-sanitasi dengan `htmlspecialchars()` dan `filter_var()` |
| **Brute Force Login** | CAPTCHA numerik custom (PHP GD) wajib diisi setiap login |
| **Session Hijacking** | `session_regenerate_id(true)` dipanggil saat login berhasil |
| **Akses Tidak Sah** | Role-based access control (admin / staff / agen) di setiap halaman |
| **File Upload Berbahaya** | Validasi MIME type via `finfo`, bukan hanya ekstensi file |
| **Password Plaintext** | Password disimpan dengan `password_hash()` (bcrypt) |
| **Reset Password Lemah** | Token 64-karakter acak via `bin2hex(random_bytes(32))`, kedaluwarsa 1 jam |
| **Unauthorized File Akses** | Upload disimpan dengan nama acak (bukan nama asli file) |

### CAPTCHA

CAPTCHA dibuat secara native dengan library **PHP GD** (tidak bergantung layanan pihak ketiga). Kode 4 digit acak disimpan di session dan dicocokkan saat form login disubmit.

### Role & Hak Akses

| Halaman | Admin | Staff | Agen |
|---|---|---|---|
| Dashboard Admin | ✅ | ❌ | ❌ |
| Manajemen Agen | ✅ | ❌ | ❌ |
| Kelola Soal | ✅ | ✅ | ❌ |
| Hasil Test Agen | ✅ | ✅ | ❌ |
| Monitoring Laporan | ✅ | ✅ | ❌ |
| Tambah Laporan | ❌ | ❌ | ✅ |
| Pre/Post Test | ❌ | ❌ | ✅ |
| Profil | ✅ | ✅ | ✅ |
| Export CSV | ✅ | ❌ | ❌ |

---

## Struktur File

```
buku-harian/
├── config/
│   └── database.php          # Konfigurasi koneksi PDO
├── core/
│   └── auth.php              # Fungsi proteksi session & role
├── database/
│   └── database.sql          # Dump lengkap database
├── phpmailer/
│   └── src/                  # Library PHPMailer (manual)
├── uploads/                  # Folder file upload (foto/video/avatar)
├── views/
│   ├── includes/
│   │   └── sidebar.php       # Komponen navigasi sidebar
│   ├── bpom.webp
│   ├── gas-paman.png
│   ├── gas-paman-round.png
│   └── logo-gas-paman.png
│
├── index.php                 # Gateway redirect (login/dashboard)
├── login.php                 # Halaman login + CAPTCHA
├── register.php              # Registrasi agen baru
├── logout.php                # Destroy session
├── lupa-password.php         # Form lupa password (kirim email)
├── reset-password-aksi.php   # Proses reset password via token
│
├── dashboard.php             # Dashboard agen
├── tambah-catatan.php        # Form laporan baru + upload bukti
├── edit-catatan.php          # Edit laporan
├── hapus-catatan.php         # Hapus laporan
├── hapus-foto-catatan.php    # Hapus file bukti
├── detail-catatan.php        # Detail laporan
├── riwayat.php               # Riwayat laporan (agen) / Monitoring (admin)
├── profil.php                # Edit profil & ganti password
│
├── admin-dashboard.php       # Dashboard admin + statistik global
├── daftar-agen.php           # Manajemen daftar agen
├── detail-agen.php           # Detail profil agen
├── edit-agen.php             # Edit data agen oleh admin
├── hapus-agen.php            # Hapus akun agen
├── toggle-status.php         # Aktifkan/nonaktifkan agen
├── approve-catatan.php       # Approve laporan agen
├── export-laporan.php        # Export data ke CSV
│
├── daftar-soal.php           # Daftar bank soal
├── tambah-soal.php           # Tambah bank soal + pertanyaan
├── edit-soal.php             # Edit bank soal
├── hapus-soal.php            # Hapus bank soal
├── aktifkan-soal.php         # Toggle status aktif soal
│
├── pre-test.php              # Halaman pengerjaan pre-test
├── post-test.php             # Halaman pengerjaan post-test
├── submit-test.php           # Proses submit jawaban & hitung nilai
├── hasil-test.php            # Hasil test agen (view agen)
├── hasil-test-admin.php      # Hasil test semua agen (view admin)
├── detail-hasil-test.php     # Detail jawaban per test
│
└── captcha.php               # Generator gambar CAPTCHA (PHP GD)
```

---

## Lisensi

Dikembangkan untuk keperluan internal **BBPOM di Mataram** — Program GAS-PAMAN (Keluarga Sadar Obat dan Makanan Aman).

&copy; 2026 BBPOM di Mataram. All rights reserved.
