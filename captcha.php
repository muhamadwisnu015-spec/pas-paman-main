<?php
session_start();
header("Content-type: image/png");

// Membuat kode acak
$code = rand(1000, 9999);
$_SESSION['captcha_code'] = $code;

// Membuat kanvas gambar (Sedikit lebih lebar agar angka lebih lega)
$image = imagecreatetruecolor(110, 54);

// Warna Tema Baru (Red-800 & Orange-600)
$bg_color     = imagecolorallocate($image, 153, 27, 27);  // Red-800 (Merah Tua)
$noise_color  = imagecolorallocate($image, 234, 88, 12);  // Orange-600 (Aksen Orange)
$text_color   = imagecolorallocate($image, 255, 255, 255); // Putih Bersih

// Mengisi background
imagefill($image, 0, 0, $bg_color);

// Menambahkan garis-garis gangguan (noise) dengan warna orange
for($i=0; $i<8; $i++) {
    imageline($image, rand(0,110), rand(0,54), rand(0,110), rand(0,54), $noise_color);
}

// Menambahkan titik-titik gangguan (dots)
for($i=0; $i<50; $i++) {
    imagesetpixel($image, rand(0,110), rand(0,54), $noise_color);
}

// Menuliskan angka captcha (Font size 5 adalah yang terbesar bawaan PHP)
imagestring($image, 5, 35, 18, $code, $text_color);

imagepng($image);
imagedestroy($image);
?>