<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$current_page = basename($_SERVER['PHP_SELF'], ".php");
$role = $_SESSION['role'] ?? 'agen';
?>

<div class="md:hidden bg-red-700 text-white p-4 flex justify-between items-center shadow-md sticky top-0 z-50">
    <div class="flex items-center space-x-3">
        <div class="w-8 h-8 bg-white rounded-full overflow-hidden border border-white/20">
            <img src="/views/gas-paman.png" alt="Logo" class="w-full h-full object-cover">
        </div>
        <h1 class="text-lg font-black tracking-tighter uppercase">GAS-PAMAN</h1>
    </div>
    <button id="mobileMenuBtn" class="p-2 focus:outline-none hover:bg-red-800 rounded-lg transition-colors">
        <i class="fas fa-bars text-xl"></i>
    </button>
</div>

<div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-40 hidden md:hidden transition-opacity"></div>

<aside id="mainSidebar" class="fixed inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 w-72 bg-red-800 text-white flex flex-col shadow-2xl transition-transform duration-300 ease-in-out z-50 overflow-y-auto">
    
    <div class="p-8 text-center border-b border-white/10 hidden md:block">
        <div class="relative inline-block group">
            <div class="absolute inset-0 bg-orange-400 rounded-full blur-xl opacity-20 group-hover:opacity-40 transition-opacity"></div>
            
            <div class="relative bg-white p-1 rounded-full shadow-2xl inline-block overflow-hidden w-24 h-24 border-4 border-white/20">
                <img src="/views/gas-paman.png" alt="Logo GAS-PAMAN" class="w-full h-full object-cover rounded-full">
            </div>
        </div>
        <h2 class="text-white font-black text-lg mt-4 tracking-tighter leading-none">GAS-PAMAN</h2>
        <p class="text-[9px] uppercase tracking-[0.3em] text-orange-400 mt-2 font-black">BBPOM DI MATARAM</p>
    </div>

    <div class="flex justify-end p-4 md:hidden">
        <button id="closeSidebar" class="p-2 text-white/60 hover:text-white">
            <i class="fas fa-times text-2xl"></i>
        </button>
    </div>
    
    <nav class="flex-1 p-6 space-y-2">
        <?php $dash_url = ($role === 'admin') ? 'admin-dashboard' : 'dashboard'; ?>
        <a href="<?= $dash_url ?>" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= ($current_page == $dash_url) ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-th-large w-5 text-white"></i>
            <span class="text-sm">Dashboard</span>
        </a>

        <?php if ($role === 'agen'): ?>
        <a href="tambah-catatan" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= ($current_page == 'tambah-catatan') ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-plus-circle w-5 text-white"></i>
            <span class="text-sm">Tambah Catatan</span>
        </a>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
        <a href="daftar-agen" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= (in_array($current_page, ['daftar-agen', 'detail-agen'])) ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-users-cog w-5 text-white"></i>
            <span class="text-sm">Manajemen Agen</span>
        </a>
        <a href="analytics" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= ($current_page == 'analytics') ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-chart-line w-5 text-white"></i>
            <span class="text-sm">Analitik & Laporan</span>
        </a>
        <?php endif; ?>

        <?php if (in_array($role, ['admin', 'staff'])): ?>
        <a href="daftar-soal" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= (in_array($current_page, ['daftar-soal', 'tambah-soal', 'edit-soal'])) ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-question-circle w-5 text-white"></i>
            <span class="text-sm">Kelola Soal</span>
        </a>
        <a href="hasil-test-admin" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= ($current_page == 'hasil-test-admin') ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-chart-bar w-5 text-white"></i>
            <span class="text-sm">Hasil Test Agen</span>
        </a>
        <?php endif; ?>

        <?php if ($role === 'agen'): ?>
        <a href="pre-test" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= ($current_page == 'pre-test') ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-clipboard-list w-5 text-white"></i>
            <span class="text-sm">Pre-Test</span>
        </a>
        <a href="post-test" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= ($current_page == 'post-test') ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-clipboard-check w-5 text-white"></i>
            <span class="text-sm">Post-Test</span>
        </a>
        <a href="hasil-test" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= ($current_page == 'hasil-test') ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-star w-5 text-white"></i>
            <span class="text-sm">Hasil Test Saya</span>
        </a>
        <?php endif; ?>

        <a href="riwayat" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= ($current_page == 'riwayat') ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-history w-5 text-white"></i>
            <span class="text-sm"><?= ($role === 'admin') ? 'Monitoring Laporan' : 'Riwayat Catatan' ?></span>
        </a>

        <a href="profil" class="flex items-center space-x-3 p-4 transition-all rounded-xl <?= ($current_page == 'profil') ? 'bg-orange-600 border-l-4 border-white font-semibold shadow-lg shadow-orange-900/20' : 'hover:bg-white/10 opacity-70 hover:opacity-100' ?>">
            <i class="fas fa-user-circle w-5 text-white"></i>
            <span class="text-sm">Profil</span>
        </a>
    </nav>

    <div class="p-6 mt-auto">
        <a href="logout" class="flex items-center space-x-3 p-4 bg-white/5 hover:bg-white text-white hover:text-red-800 rounded-2xl transition-all group border border-white/10">
            <i class="fas fa-sign-out-alt w-5 text-center"></i>
            <span class="text-sm font-bold">Keluar</span>
        </a>
    </div>
</aside>

<script>
    // Pastikan DOM sudah dimuat sepenuhnya
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const closeSidebar = document.getElementById('closeSidebar');
        const mainSidebar = document.getElementById('mainSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            // Toggle class transform untuk menggeser sidebar
            mainSidebar.classList.toggle('-translate-x-full');
            // Toggle hidden untuk menampilkan/menyembunyikan overlay hitam
            sidebarOverlay.classList.toggle('hidden');
            // Mencegah scroll pada body saat menu terbuka
            document.body.classList.toggle('overflow-hidden');
        }

        // Listener untuk tombol buka (Hamburger)
        if(mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function(e) {
                e.preventDefault();
                toggleSidebar();
            });
        }

        // Listener untuk tombol tutup (X)
        if(closeSidebar) {
            closeSidebar.addEventListener('click', function(e) {
                e.preventDefault();
                toggleSidebar();
            });
        }

        // Listener untuk klik di area luar sidebar (Overlay)
        if(sidebarOverlay) {
            sidebarOverlay.addEventListener('click', function() {
                toggleSidebar();
            });
        }
    });
</script>