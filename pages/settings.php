<?php
/**
 * SLiMS Self-Checkout Admin Settings
 * Halaman pengaturan modul sirkulasi admin untuk konfigurasi peminjaman mandiri.
 *
 * @author Gusnaldi Kristiadi Syah
 */

defined('INDEX_AUTH') || die('Direct access not allowed!');

// Pastikan sesi admin aktif untuk pengecekan hak akses
if (session_status() === PHP_SESSION_NONE) {
    require_once SB . 'admin/default/session.inc.php';
    if (php_sapi_name() !== 'cli') {
        require_once SB . 'admin/default/session_check.inc.php';
    }
}

// Cek hak akses modul sirkulasi (read)
if (!utility::havePrivilege('circulation', 'r')) {
    echo '<div class="errorBox">' . __('You are not authorized to view this section') . '</div>';
    exit;
}

require_once __DIR__ . '/../lib/CheckoutEngine.php';
use SLiMS\Plugins\SelfCheckout\CheckoutEngine;

try {
    $engine = new CheckoutEngine();
} catch (Exception $e) {
    echo '<div class="errorBox">Gagal menginisialisasi sistem: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}

$savedMessage = '';
$errorMessage = '';

// Proses penyimpanan konfigurasi
if (isset($_POST['saveSettings'])) {
    if (!utility::havePrivilege('circulation', 'w')) {
        $errorMessage = __('Anda tidak memiliki hak akses untuk mengubah pengaturan ini.');
    } else {
        $title = trim($_POST['kiosk_title'] ?? '');
        $uid = (int)($_POST['staff_uid'] ?? 1);
        $inactivity = max(15, (int)($_POST['inactivity_timeout'] ?? 60));
        $success = max(3, (int)($_POST['success_timeout'] ?? 10));

        $saveData = [
            'kiosk_title' => !empty($title) ? $title : 'Peminjaman Mandiri Perpustakaan',
            'staff_uid' => $uid > 0 ? $uid : 1,
            'inactivity_timeout' => $inactivity,
            'success_timeout' => $success
        ];

        if ($engine->saveConfig($saveData)) {
            $savedMessage = __('Pengaturan peminjaman mandiri berhasil disimpan.');
        } else {
            $errorMessage = __('Gagal menyimpan berkas konfigurasi. Periksa izin tulis (write permission) folder.');
        }
    }
}

$config = $engine->getConfig();
$currentTitle = htmlspecialchars($config['kiosk_title'] ?? 'Peminjaman Mandiri Perpustakaan');
$currentUid = (int)($config['staff_uid'] ?? 1);
$currentInactivity = (int)($config['inactivity_timeout'] ?? 60);
$currentSuccess = (int)($config['success_timeout'] ?? 10);

// Ambil daftar pengguna (staff/pustakawan) dari database
$userList = [];
try {
    $pdo = \SLiMS\DB::getInstance();
    $q = $pdo->query("SELECT user_id, username, realname FROM user ORDER BY realname ASC");
    if ($q) {
        $userList = $q->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $errorMessage = 'Gagal membaca daftar pengguna: ' . $e->getMessage();
}

$kioskUrl = SWB . 'index.php?p=pinjam_mandiri';
?>

<div class="menuBox">
    <div class="menuBoxInner circulationIcon">
        <div class="per_title">
            <h2><?= __('Pengaturan Pinjam Mandiri (Self-Checkout)') ?></h2>
        </div>
        <div class="sub_section">
            <div class="btn-group">
                <a href="<?= $kioskUrl ?>" target="_blank" class="btn btn-success" title="<?= __('Buka layar pinjam mandiri di tab baru') ?>">
                    <i class="fa fa-external-link"></i> <?= __('Buka Layar Pinjam Mandiri') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="contentArea">
    <?php if (!empty($savedMessage)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa fa-check-circle"></i> <?= $savedMessage ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa fa-exclamation-triangle"></i> <?= $errorMessage ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Kolom Pengaturan -->
        <div class="col-md-7">
            <div class="card mb-4">
                <div class="card-header font-weight-bold bg-white text-dark border-bottom">
                    <i class="fa fa-cog text-primary mr-1"></i> <?= __('Konfigurasi Operasional Kiosk') ?>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="form-group">
                            <label for="kiosk_title" class="font-weight-bold"><?= __('Nama Perpustakaan / Judul Layar Kiosk') ?></label>
                            <input type="text" class="form-control" id="kiosk_title" name="kiosk_title" value="<?= $currentTitle ?>" required>
                            <small class="form-text text-muted"><?= __('Nama yang ditampilkan pada header atas layar peminjaman mandiri.') ?></small>
                        </div>

                        <div class="form-group">
                            <label for="staff_uid" class="font-weight-bold"><?= __('Akun Petugas Pencatat Sirkulasi Mandiri') ?></label>
                            <select class="form-control" id="staff_uid" name="staff_uid">
                                <?php foreach ($userList as $u): ?>
                                    <option value="<?= (int)$u['user_id'] ?>" <?= ((int)$u['user_id'] === $currentUid) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['realname']) ?> (<?= htmlspecialchars($u['username']) ?>) — ID: <?= (int)$u['user_id'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                <?= __('Setiap transaksi peminjaman mandiri akan dicatat ke tabel sirkulasi (`uid`) atas nama akun staf ini, sehingga laporan peminjaman mandiri dapat terpisah rapi di modul Pelaporan.') ?>
                            </small>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="inactivity_timeout" class="font-weight-bold"><?= __('Batas Waktu Idle (Detik)') ?></label>
                                <input type="number" class="form-control" id="inactivity_timeout" name="inactivity_timeout" value="<?= $currentInactivity ?>" min="15" max="300" required>
                                <small class="form-text text-muted"><?= __('Waktu tidak ada aktivitas sebelum modal peringatan muncul (default: 60 detik).') ?></small>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="success_timeout" class="font-weight-bold"><?= __('Durasi Layar Sukses (Detik)') ?></label>
                                <input type="number" class="form-control" id="success_timeout" name="success_timeout" value="<?= $currentSuccess ?>" min="3" max="60" required>
                                <small class="form-text text-muted"><?= __('Hitung mundur layar sukses sebelum otomatis kembali ke halaman login (default: 10 detik).') ?></small>
                            </div>
                        </div>

                        <hr>
                        <button type="submit" name="saveSettings" class="btn btn-primary px-4 font-weight-bold">
                            <i class="fa fa-save"></i> <?= __('Simpan Pengaturan') ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Kolom Petunjuk Operasional -->
        <div class="col-md-5">
            <div class="card bg-light mb-4">
                <div class="card-header font-weight-bold">
                    <i class="fa fa-desktop"></i> <?= __('Petunjuk Operasional Komputer Pinjam Mandiri') ?>
                </div>
                <div class="card-body">
                    <h6 class="font-weight-bold text-dark"><?= __('Alamat URL Pinjam Mandiri:') ?></h6>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control form-control-sm bg-white" id="kioskUrlInput" value="<?= $kioskUrl ?>" readonly>
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="navigator.clipboard.writeText(document.getElementById('kioskUrlInput').value); alert('Tautan Pinjam Mandiri berhasil disalin ke clipboard!');">
                                <i class="fa fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    <h6 class="font-weight-bold text-dark mt-3"><?= __('Cara Mengoperasikan di Perpustakaan:') ?></h6>
                    <ol class="pl-3" style="line-height: 1.6; font-size: 0.95rem;">
                        <li>Buka browser (Google Chrome, Microsoft Edge, dll.) pada komputer khusus pemustaka.</li>
                        <li>Akses tautan pinjam mandiri di atas (komputer peminjam <strong>tidak memerlukan login admin</strong>).</li>
                        <li>Tekan tombol <strong>F11</strong> pada keyboard untuk mengaktifkan tampilan layar penuh (Full Screen).</li>
                        <li>Pastikan perangkat USB Barcode Scanner terhubung ke komputer dan siap membaca barcode kartu serta buku.</li>
                    </ol>

                    <div class="alert alert-info py-2 px-3 mt-3 mb-0" style="font-size: 0.875rem;">
                        <i class="fa fa-info-circle"></i> <strong>Tips Kiosk Terminal:</strong> Browser dapat diluncurkan otomatis saat komputer menyala dengan argumen shortcut: <code>--kiosk "<?= $kioskUrl ?>"</code> agar browser terkunci dan pemustaka fokus pada peminjaman.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
