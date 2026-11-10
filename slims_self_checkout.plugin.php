<?php
/**
 * Plugin Name: SLiMS Self-Checkout (Peminjaman Mandiri)
 * Plugin URI: https://github.com/gusnaldikrist/slims-self-checkout
 * Description: Pinjam Mandiri (Self-Checkout) pemustaka berbasis barcode scanner kartu anggota & verifikasi tanggal lahir. URL Pinjam Mandiri: <a href="../index.php?p=pinjam_mandiri" target="_blank" class="badge badge-primary text-white p-1 ml-1 font-weight-bold"><i class="fa fa-external-link"></i> Buka Pinjam Mandiri (index.php?p=pinjam_mandiri)</a>
 * Version: 1.0.0
 * Author: Gusnaldi Kristiadi Syah
 * Author URI: https://github.com/gusnaldikrist
 */

use SLiMS\Plugins;

// Fallback helper __() jika dipanggil terisolasi (misal CLI/testing)
if (!function_exists('__')) {
    function __($text) { return $text; }
}

$plugin = Plugins::getInstance();

// 1. Registrasi Menu OPAC Publik (index.php?p=pinjam_mandiri)
$opacLabel = 'Pinjam Mandiri';
$existingOpacMenus = $plugin->getMenus('opac');
if (!isset($existingOpacMenus['pinjam_mandiri'])) {
    $plugin->registerMenu('opac', $opacLabel, __DIR__ . '/pages/kiosk.php', __('Pinjam Mandiri (Self-Checkout)'));
}

// 2. Registrasi Submenu Admin Sirkulasi (Idempotent Guard)
$circLabel = __('Pengaturan Pinjam Mandiri');
$existingCircMenus = $plugin->getMenus('circulation');
$circRegistered = false;

foreach ($existingCircMenus as $menuItem) {
    if (isset($menuItem[0]) && $menuItem[0] === $circLabel) {
        $circRegistered = true;
        break;
    }
}

if (!$circRegistered) {
    $plugin->registerMenu('circulation', $circLabel, __DIR__ . '/pages/settings.php', __('Konfigurasi Pinjam Mandiri'));
}
