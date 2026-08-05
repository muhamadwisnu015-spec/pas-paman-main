# Catatan Implementasi Perubahan Admin GAS-PAMAN

## File yang diubah / baru

### Baru
- `core/ntb_helper.php` — deteksi wilayah NTB + agregasi peta Leaflet
- `database/migrations/20260801_admin_enhancements.sql` — migrasi DB
- `edit-nilai-test.php` — form input/edit nilai pre/post manual
- `export-agen.php` — export CSV data agen

### Diperbarui
- `admin-dashboard.php` — kartu klikable, masyarakat teredukasi, peta NTB, rata-rata test agen/masyarakat, sertifikat
- `daftar-agen.php` — filter periode, ringkasan pre/post, tabel lengkap (JK, usia, telp, pekerjaan, pre, post)
- `edit-agen.php` — field pekerjaan, kampus, jurusan, alamat, jenis kelamin
- `hasil-test-admin.php` — tombol pensil + input nilai manual
- `riwayat.php` — kartu Laporan Masuk / Sudah Review / Menunggu Review + kolom demografi & nilai masyarakat
- `analytics.php` — analisis agen & masyarakat lengkap (grafik, peta, tabel, export)

## Cara deploy
1. Jalankan migrasi SQL di phpMyAdmin / mysql client:
   ```
   source database/migrations/20260801_admin_enhancements.sql
   ```
   (Abaikan error "Duplicate column" jika kolom sudah ada.)

2. Copy semua file di folder ini ke root project (timpa yang sama namanya).
   Pastikan folder `core/` berisi `ntb_helper.php`.

3. Pastikan server bisa akses CDN Leaflet & Chart.js (internet).

## Belum dikerjakan (menunggu data customer)
### Bank Soal 5 kategori
Struktur DB sudah disiapkan (`pertanyaan.kategori`):
- umum (3)
- komoditi_pangan (3)
- kosmetik (3)
- obat_bahan_alam (3)
- obat (3)

Total 15 soal diacak untuk pre/post. **Isi bank soal belum dikirim customer** — setelah dikirim, akan diisi + logic randomisasi di `pre-test.php` / `post-test.php` / `submit-test.php`.

### Profil agen (self-edit)
`profil.php` sebaiknya juga ditambah field `pekerjaan`, `kampus`, `jurusan` agar agen bisa isi sendiri. Admin sudah bisa isi lewat `edit-agen.php`.

## Catatan teknis peta
Peta memakai keyword matching dari teks alamat ke kabupaten/kota NTB (bukan geocoding GPS). Semakin lengkap alamat agen/masyarakat, semakin akurat sebarannya.
