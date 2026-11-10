<?php
/**
 * SLiMS Self-Checkout (Peminjaman Mandiri) Frontend
 * Antarmuka pinjam mandiri satu mode tunggal yang koheren dengan SLiMS 9 Bulian (ala visitor_template.php).
 * Untuk tampilan layar penuh di komputer pinjam mandiri, cukup tekan tombol F11 pada keyboard.
 *
 * @author Gusnaldi Kristiadi Syah
 */

defined('INDEX_AUTH') || die('Direct access not allowed!');

// Delegasikan ke API controller jika ada parameter action (baik GET maupun POST)
if (isset($_GET['action']) || isset($_POST['action'])) {
    require __DIR__ . '/api.php';
    exit;
}

require_once __DIR__ . '/../lib/CheckoutEngine.php';
use SLiMS\Plugins\SelfCheckout\CheckoutEngine;

try {
    $engine = new CheckoutEngine();
    $config = $engine->getConfig();
} catch (Exception $e) {
    die("Gagal memuat konfigurasi pinjam mandiri: " . htmlspecialchars($e->getMessage()));
}

// Bersihkan output buffer SLiMS agar halaman pinjam mandiri dirender penuh tanpa template OPAC
while (ob_get_level()) {
    ob_end_clean();
}

global $sysconf;

// Data instansi perpustakaan & pinjam mandiri
$libraryName = !empty($sysconf['library_name']) ? $sysconf['library_name'] : ($config['kiosk_title'] ?? 'Perpustakaan');
$librarySubname = !empty($sysconf['library_subname']) ? $sysconf['library_subname'] : '';
$kioskTitle = !empty($config['kiosk_title']) ? $config['kiosk_title'] : $libraryName;
$inactivityTimeout = (int)($config['inactivity_timeout'] ?? 60);
$successTimeout = (int)($config['success_timeout'] ?? 10);
$apiBaseUrl = 'index.php?p=pinjam_mandiri';

// Resolusi logo instansi perpustakaan resmi (tanpa memaksakan logo SLiMS)
$webBase = defined('SWB') ? SWB : '/bulian/';
$institutionLogo = '';
if (!empty($sysconf['logo_image'])) {
    if (file_exists(SB . 'images/default/' . $sysconf['logo_image'])) {
        $institutionLogo = $webBase . 'images/default/' . $sysconf['logo_image'];
    }
}
$faviconPath = $webBase . 'images/default/webicon.png';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title><?= htmlspecialchars($kioskTitle) ?> — <?= __('Pinjam Mandiri') ?></title>
    <link rel="shortcut icon" href="<?= $faviconPath ?>" type="image/x-icon">

    <!-- Framework & CSS Asli SLiMS 9 Bulian -->
    <link rel="stylesheet" href="<?= $webBase ?>template/default/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $webBase ?>template/default/assets/plugin/font-awesome/css/fontawesome-all.min.css">
    <link rel="stylesheet" href="<?= $webBase ?>template/default/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="<?= $webBase ?>template/default/assets/plugin/vegas/vegas.min.css">
    <link rel="stylesheet" href="<?= $webBase ?>template/default/assets/css/style.css">
    <link rel="stylesheet" href="<?= $webBase ?>js/datepicker/css/datepicker-bs4.min.css">

    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
            background-color: #343a40;
            color: #212529;
            min-height: 100vh;
            user-select: none;
            overflow-x: hidden;
        }

        #toastArea {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1080;
            min-width: 300px;
            max-width: 420px;
        }

        /* Komposisi Sidebar 60% Kiri, 40% Kanan pada Desktop */
        @media (min-width: 768px) {
            .kiosk-panel-left {
                width: 60% !important;
                flex: 0 0 60% !important;
                max-width: 60% !important;
            }
            .kiosk-panel-right {
                width: 40% !important;
                flex: 0 0 40% !important;
                max-width: 40% !important;
            }
        }
    </style>
</head>
<body class="bg-grey-lightest">
    <!-- Background Slideshow Asli SLiMS (Vegas) dengan Fallback CSS -->
    <div class="vegas-slide" style="position: fixed; z-index: -1; top: 0; left: 0; right: 0; bottom: 0; background: #343a40 url('<?= $webBase ?>template/default/assets/images/slide1.jpg') center/cover no-repeat;"></div>

    <!-- Layout Split-Screen Presisi visitor_template.php SLiMS Bulian -->
    <div class="flex min-h-screen w-full" id="kiosk-app" style="background: rgba(0, 0, 0, 0.35);">
        
        <!-- Panel Kiri: Ruang Interaksi Pinjam Mandiri (60% Desktop) -->
        <div class="bg-white w-full md:w-3/5 kiosk-panel-left px-6 md:px-10 py-8 flex flex-col justify-between shadow-lg overflow-y-auto min-h-screen">
            <div>
                <!-- Brand Instansi Perpustakaan (Tanpa Logo SLiMS yang Dipaksakan) -->
                <div class="mb-4">
                    <?php if (!empty($institutionLogo)): ?>
                        <div class="mb-3">
                            <img src="<?= $institutionLogo ?>" alt="<?= htmlspecialchars($libraryName) ?>" style="max-height: 48px; max-width: 200px;" class="d-inline-block">
                        </div>
                    <?php endif; ?>
                    <h3 class="font-light mb-1 text-dark" style="font-size: 1.4rem;"><?= htmlspecialchars($libraryName) ?></h3>
                    <?php if (!empty($librarySubname)): ?>
                        <p class="text-sm text-muted mb-2"><?= htmlspecialchars($librarySubname) ?></p>
                    <?php endif; ?>
                    <div class="text-primary font-weight-bold" style="font-size: 1.05rem; letter-spacing: -0.2px;">
                        <?= __('Pinjam Mandiri') ?>
                    </div>
                </div>

                <!-- Toast Notifikasi Floating -->
                <div id="toastArea"></div>

                <!-- Workspace Komponen Self-Checkout -->
                <div class="pt-2">
                    <?php include __DIR__ . '/_kiosk_workspace.php'; ?>
                </div>
            </div>

            <!-- Footer Panel Kiri (Sesuai Konvensi SLiMS Bulian) -->
            <div class="d-flex justify-content-between align-items-center text-muted pt-4 mt-4 border-top" style="font-size: 0.85rem; color: #596169;">
                <span><i class="far fa-clock mr-1 text-info"></i> <span id="clockDisplay">--:--:--</span></span>
                <span><?= __('Ditenagai oleh') ?> <code>SLiMS 9 Bulian</code></span>
            </div>
        </div>

        <!-- Panel Kanan: Latar Penuh Panduan & Status Pemindai (40% Desktop) -->
        <div class="hidden md:flex md:w-2/5 kiosk-panel-right flex-col justify-between p-8 text-white">
            <!-- Panduan Dinamis Tengah (State-Aware) -->
            <div id="guidanceWelcome" class="my-auto px-4" style="max-width: 520px;">
                <h2 class="font-light display-4 mb-3" style="text-shadow: 0 2px 8px rgba(0,0,0,0.4);"><?= __('Pinjam Mandiri') ?></h2>
                <p class="lead mb-4" style="font-size: 1.25rem; opacity: 0.95; text-shadow: 0 1px 4px rgba(0,0,0,0.3);">
                    <?= __('Layanan pinjam mandiri untuk kenyamanan pemustaka. Pindai kartu anggota Anda pada scanner barcode untuk memulai transaksi.') ?>
                </p>
                <div class="d-flex align-items-center p-3 rounded" style="background: rgba(0, 0, 0, 0.45); backdrop-filter: blur(4px); border-radius: 4px;">
                    <i class="fas fa-info-circle fa-2x text-info mr-3"></i>
                    <span style="font-size: 0.95rem; opacity: 0.9;">
                        <?= __('Pastikan masa berlaku kartu anggota Anda masih aktif dan tidak memiliki denda pinjaman yang belum diselesaikan.') ?>
                    </span>
                </div>
            </div>

            <div id="guidanceSession" class="my-auto px-4 d-none" style="max-width: 520px;">
                <div class="card border-0 shadow-lg text-dark bg-white p-4" style="border-radius: 4px;">
                    <div class="d-flex align-items-center mb-3">
                        <div class="mr-3 text-primary"><i class="fas fa-user-circle fa-3x"></i></div>
                        <div>
                            <h5 class="mb-0 font-weight-bold" id="lblGuidanceMemberName">-</h5>
                            <small class="text-muted"><i class="fas fa-check-circle text-success mr-1"></i><?= __('Sesi Aktif Pinjam Mandiri') ?></small>
                        </div>
                    </div>
                    <hr class="my-2">
                    <h6 class="font-weight-bold text-dark mt-2 mb-2"><i class="fas fa-clipboard-list text-info mr-1"></i><?= __('Panduan Transaksi:') ?></h6>
                    <ul class="list-unstyled text-muted mb-0" style="font-size: 0.9rem; color: #596169;">
                        <li class="mb-2"><i class="fas fa-barcode text-primary mr-2"></i><?= __('Pindai barcode setiap buku yang ingin dipinjam.') ?></li>
                        <li class="mb-2"><i class="fas fa-calendar-check text-success mr-2"></i><?= __('Tanggal jatuh tempo dihitung otomatis menyesuaikan hari libur perpustakaan.') ?></li>
                        <li class="mb-0"><i class="fas fa-check-double text-warning mr-2"></i><?= __('Tekan tombol "Selesaikan Peminjaman" sebelum meninggalkan komputer pinjam mandiri.') ?></li>
                    </ul>
                </div>
            </div>

            <!-- Identitas Bawah SLiMS (Sesuai visitor_template.php) -->
            <div class="mt-auto">
                <blockquote class="blockquote mb-0">
                    <p class="mb-1 text-white" style="font-size: 1.15rem; font-weight: 300;"><?= htmlspecialchars($libraryName) ?></p>
                    <?php if (!empty($librarySubname)): ?>
                        <footer class="blockquote-footer text-light" style="opacity: 0.8;"><?= htmlspecialchars($librarySubname) ?></footer>
                    <?php endif; ?>
                </blockquote>
            </div>
        </div>
    </div>

    <!-- JS Bawaan SLiMS 9 Bulian -->
    <script src="<?= $webBase ?>template/default/assets/js/jquery.min.js"></script>
    <script src="<?= $webBase ?>template/default/assets/js/bootstrap.bundle.min.js"></script>
    <script src="<?= $webBase ?>template/default/assets/plugin/vegas/vegas.min.js"></script>
    <script src="<?= $webBase ?>js/datepicker/js/datepicker-full.min.js"></script>
    <script src="<?= $webBase ?>js/datepicker/js/locales/id.js"></script>

    <!-- Slide Vegas Background Kiosk -->
    <script>
        $('.vegas-slide').vegas({
            delay: 5000,
            timer: false,
            transition: 'fade',
            animation: 'kenburns',
            slides: [
                { src: "<?= $webBase ?>template/default/assets/images/slide1.jpg" },
                { src: "<?= $webBase ?>template/default/assets/images/slide2.jpg" },
                { src: "<?= $webBase ?>template/default/assets/images/slide3.jpg" },
                { src: "<?= $webBase ?>template/default/assets/images/slide4.jpg" }
            ]
        });
    </script>

    <!-- Logika Interaktif Pinjam Mandiri -->
    <?php include __DIR__ . '/_kiosk_script.php'; ?>
</body>
</html>
<?php
exit;
