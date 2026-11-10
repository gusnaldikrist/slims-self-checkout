<?php
/**
 * Komponen Workspace UI Pinjam Mandiri (Self-Checkout)
 * Dipakai bersama oleh Mode OPAC dan Mode Kiosk Fullscreen.
 * Mengikuti 100% Design Tokens SLiMS 9 Bulian (Audit Dembrandt & DESIGN.md).
 *
 * @author Gusnaldi Kristiadi Syah
 */
defined('INDEX_AUTH') || die('Direct access not allowed!');
?>

<!-- ========================================================= -->
<!-- STATE 1: FORM LOGIN / IDENTIFIKASI PEMUSTAKA              -->
<!-- ========================================================= -->
<div id="viewLogin" class="w-100">
    <div class="mb-4 text-left">
        <h3 class="font-light text-dark mb-1"><?= __('Identifikasi Pemustaka') ?></h3>
        <p class="lead text-muted mb-0" style="font-size: 0.95rem; color: #596169;">
            <?= __('Silakan pindai kartu anggota Anda atau masukkan ID dan tanggal lahir untuk memulai peminjaman.') ?>
        </p>
    </div>

    <!-- Kotak Peringatan Error -->
    <div id="loginAlertBox" class="alert alert-danger d-none" role="alert" style="border-radius: 4px;">
        <div class="d-flex align-items-center">
            <i class="fas fa-exclamation-circle mr-2 fa-lg"></i>
            <span id="loginAlertMsg"></span>
        </div>
    </div>

    <form id="formMemberAuth" onsubmit="handleAuthSubmit(event)">
        <!-- Field ID Anggota -->
        <div class="form-group mb-3 text-left">
            <label for="txtMemberId" class="font-weight-normal text-dark">
                <?= __('ID Anggota') ?>
            </label>
            <input type="text" class="form-control form-control-lg" id="txtMemberId"
                   placeholder="<?= __('Pindai barcode kartu atau ketik ID...') ?>" autofocus required autocomplete="off" style="border-radius: 4px;">
            <small class="form-text" style="color: #596169;">
                <?= __('Arahkan scanner ke kartu perpustakaan Anda atau ketik ID anggota.') ?>
            </small>
        </div>

        <!-- Field Tanggal Lahir (Ketik Bebas atau Pilih dari Kalender) -->
        <div class="form-group mb-4 text-left">
            <label for="txtBirthDate" class="font-weight-normal text-dark">
                <?= __('Tanggal Lahir Pemustaka') ?>
            </label>
            <div class="input-group">
                <input type="text" class="form-control form-control-lg bg-white" id="txtBirthDate" 
                       placeholder="<?= __('HH/BB/TTTT (misal: 01/01/2000)') ?>" 
                       maxlength="10" autocomplete="off" required style="border-radius: 4px 0 0 4px;">
                <div class="input-group-append">
                    <button class="btn btn-outline-secondary px-3" type="button" id="btnToggleDatePicker" title="<?= __('Buka Kalender') ?>" style="border-radius: 0 4px 4px 0;" tabindex="-1">
                        <i class="fas fa-calendar-alt text-primary fa-lg"></i>
                    </button>
                </div>
            </div>
            <small class="form-text" style="color: #596169;">
                <?= __('Ketik tanggal lahir format HH/BB/TTTT (misal: 01/01/2000) atau klik ikon kalender.') ?>
            </small>
        </div>

        <!-- Tombol Masuk Sesi -->
        <button type="submit" id="btnSubmitAuth" class="btn btn-primary btn-lg btn-block font-weight-bold py-3 mt-4" style="border-radius: 4px;">
            <i class="fas fa-sign-in-alt mr-2"></i> <?= __('Masuk ke Sesi Pinjam') ?>
        </button>
    </form>

    <div class="mt-4 pt-3 border-top text-left" style="font-size: 0.875rem; color: #596169;">
        <div class="d-flex align-items-center">
            <i class="fas fa-info-circle text-info mr-2"></i>
            <span><?= __('Pastikan keanggotaan masih aktif dan tidak ada tunggakan denda atau buku terlambat.') ?></span>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- STATE 2: SESI PEMINJAMAN AKTIF & KERANJANG                -->
<!-- ========================================================= -->
<div id="viewSession" class="w-100 d-none text-left">
    <!-- Profil Bar Pemustaka (Desain Halus Sesuai SLiMS Bulian) -->
    <div class="card border-0 shadow-sm bg-light p-3 mb-4" style="border-radius: 4px;">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between">
            <div class="d-flex align-items-center mb-2 mb-sm-0">
                <div class="mr-3 text-primary">
                    <i class="fas fa-user-circle fa-2x"></i>
                </div>
                <div>
                    <h5 class="font-weight-bold mb-0 text-dark" id="lblMemberName">-</h5>
                    <div style="font-size: 0.85rem; color: #596169;">
                        <span>ID: <strong id="lblMemberId" class="text-dark">-</strong></span>
                        <span class="mx-2">&bull;</span>
                        <span class="badge badge-secondary px-2 py-1" id="lblMemberType" style="border-radius: 4px;">-</span>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center">
                <!-- Badge Kuota Sisa -->
                <span class="badge badge-info px-3 py-2 mr-2" style="font-size: 0.85rem; border-radius: 4px; background-color: #17a2b8; color: #ffffff;">
                    <i class="fas fa-book-reader mr-1"></i> <?= __('Sisa: ') ?><strong id="lblRemainingQuota">0</strong> <?= __('Buku') ?>
                </span>

                <!-- Tombol Batalkan Sesi -->
                <button type="button" class="btn btn-outline-danger btn-sm font-weight-bold" onclick="handleCancelSession()" style="border-radius: 4px;">
                    <i class="fas fa-times-circle mr-1"></i> <?= __('Batalkan Sesi') ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Navigasi Tab Sesi: Pinjam Baru vs Pinjaman Aktif -->
    <ul class="nav nav-pills mb-3 border-bottom pb-2" id="sessionTabs" role="tablist">
        <li class="nav-item mr-2">
            <a class="nav-link active font-weight-bold px-3 py-2" id="tab-checkout" href="javascript:void(0)" onclick="switchSessionTab('checkout')" style="border-radius: 4px;">
                <i class="fas fa-cart-plus mr-1"></i> <?= __('Pinjam Buku Baru') ?>
                <span class="badge badge-pill badge-light ml-1" id="badgeNavCartCount">0</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link font-weight-bold px-3 py-2 text-dark" id="tab-loans" href="javascript:void(0)" onclick="switchSessionTab('loans')" style="border-radius: 4px; background: #e9ecef;">
                <i class="fas fa-book-reader mr-1 text-primary"></i> <?= __('Buku Sedang Dipinjam & Perpanjangan') ?>
                <span class="badge badge-pill badge-secondary ml-1" id="badgeNavActiveLoansCount">0</span>
            </a>
        </li>
    </ul>

    <!-- ========================================================= -->
    <!-- PANEL 1: PINJAM BUKU BARU                                 -->
    <!-- ========================================================= -->
    <div id="panelCheckoutTab">
        <!-- Kotak Scan Barcode Eksemplar -->
        <form id="formScanItem" onsubmit="handleItemScan(event)" class="mb-4">
            <div class="form-group mb-2">
                <label for="txtItemBarcode" class="font-weight-bold text-dark h5 mb-2">
                    <i class="fas fa-barcode text-primary mr-1"></i> <?= __('Pindai Barcode Eksemplar Buku') ?>
                </label>
                <div class="input-group">
                    <input type="text" class="form-control form-control-lg font-weight-bold" id="txtItemBarcode"
                           placeholder="<?= __('Pindai atau ketik kode eksemplar...') ?>" autocomplete="off" style="border-radius: 4px 0 0 4px;">
                    <div class="input-group-append">
                        <button type="submit" id="btnScanSubmit" class="btn btn-primary btn-lg px-4 font-weight-bold" style="border-radius: 0 4px 4px 0;">
                            <i class="fas fa-plus mr-1"></i> <?= __('Tambah') ?>
                        </button>
                    </div>
                </div>
                <small class="form-text" style="color: #596169;">
                    <?= __('Dekatkan barcode buku ke alat pemindai scanner. Jika buku yang di-scan sedang Anda pinjam, sistem otomatis membuka menu perpanjangan.') ?>
                </small>
            </div>
        </form>

        <!-- Header Tabel Keranjang & Counter -->
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="font-weight-bold text-dark mb-0">
                <i class="fas fa-shopping-basket text-primary mr-1"></i> <?= __('Daftar Keranjang Peminjaman') ?>
                <span class="badge badge-pill badge-primary ml-1" id="badgeCartCount" style="border-radius: 50rem;">0</span>
            </h6>
            <small style="color: #596169;"><?= __('Periksa judul buku sebelum menyelesaikan transaksi') ?></small>
        </div>

        <!-- Tabel Keranjang Buku SLiMS -->
        <div class="table-responsive border rounded mb-4" style="border-radius: 4px; background: #ffffff;">
            <table class="table table-striped table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width: 45px;">#</th>
                        <th style="width: 140px;"><?= __('Kode') ?></th>
                        <th><?= __('Judul Buku') ?></th>
                        <th style="width: 130px;"><?= __('Jatuh Tempo') ?></th>
                        <th style="width: 60px;" class="text-center"><?= __('Aksi') ?></th>
                    </tr>
                </thead>
                <tbody id="cartTableRows">
                    <!-- Baris item dinamis -->
                </tbody>
            </table>

            <!-- Notifikasi Keranjang Kosong -->
            <div id="cartEmptyNotice" class="text-center py-5 text-muted">
                <i class="fas fa-book-open fa-3x text-muted mb-2 d-block opacity-50"></i>
                <p class="font-weight-bold text-dark mb-1"><?= __('Keranjang peminjaman masih kosong.') ?></p>
                <small style="color: #596169;"><?= __('Silakan pindai barcode eksemplar buku untuk menambahkannya.') ?></small>
            </div>
        </div>

        <!-- Footer Checkout -->
        <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between pt-2">
            <div class="mb-3 mb-sm-0" style="font-size: 0.875rem; color: #596169;">
                <i class="fas fa-calendar-check text-success mr-1"></i>
                <?= __('Jatuh tempo otomatis disesuaikan dengan hari libur perpustakaan.') ?>
            </div>
            <button type="button" id="btnSubmitCheckout" class="btn btn-success btn-lg px-4 py-3 font-weight-bold shadow-sm"
                    disabled onclick="handleCheckoutFinish()" style="border-radius: 4px;">
                <i class="fas fa-check-circle mr-2"></i>
                <span id="btnSubmitCheckoutText"><?= __('Selesaikan Peminjaman (0 Buku)') ?></span>
            </button>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- PANEL 2: PINJAMAN AKTIF & PERPANJANGAN (RENEWAL)          -->
    <!-- ========================================================= -->
    <div id="panelLoansTab" class="d-none">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h6 class="font-weight-bold text-dark mb-0">
                    <i class="fas fa-list-alt text-primary mr-1"></i> <?= __('Daftar Buku yang Sedang Anda Pinjam') ?>
                </h6>
                <small style="color: #596169;"><?= __('Klik tombol Perpanjang untuk memperpanjang masa pinjam secara mandiri.') ?></small>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadActiveLoans(currentMember ? currentMember.member_id : '')" title="<?= __('Segarkan Daftar') ?>" style="border-radius: 4px;">
                <i class="fas fa-sync-alt mr-1"></i> <?= __('Segarkan') ?>
            </button>
        </div>

        <div class="table-responsive border rounded mb-3" style="border-radius: 4px; background: #ffffff;">
            <table class="table table-striped table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width: 40px;">#</th>
                        <th style="width: 130px;"><?= __('Kode') ?></th>
                        <th><?= __('Judul Buku') ?></th>
                        <th style="width: 120px;"><?= __('Tgl Pinjam') ?></th>
                        <th style="width: 130px;"><?= __('Jatuh Tempo') ?></th>
                        <th style="width: 120px;" class="text-center"><?= __('Perpanjangan') ?></th>
                        <th style="width: 140px;" class="text-center"><?= __('Aksi') ?></th>
                    </tr>
                </thead>
                <tbody id="activeLoansTableRows">
                    <!-- Baris pinjaman aktif dinamis -->
                </tbody>
            </table>

            <!-- Notifikasi Tidak Ada Pinjaman Aktif -->
            <div id="activeLoansEmptyNotice" class="text-center py-5 text-muted d-none">
                <i class="fas fa-check-circle fa-3x text-success mb-2 d-block"></i>
                <p class="font-weight-bold text-dark mb-1"><?= __('Tidak ada pinjaman buku aktif saat ini.') ?></p>
                <small style="color: #596169;"><?= __('Semua buku telah dikembalikan atau Anda belum memiliki transaksi berjalan.') ?></small>
            </div>
        </div>

        <div class="alert alert-light border py-2 px-3 text-left mb-0" style="border-radius: 4px; font-size: 0.85rem; color: #596169;">
            <i class="fas fa-info-circle text-info mr-1"></i>
            <?= __('Ketentuan: Buku tidak dapat diperpanjang jika sudah melewati batas tanggal jatuh tempo (terlambat), kuota perpanjangan habis, atau sedang dipesan/direservasi oleh pemustaka lain.') ?>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- STATE 3: KONFIRMASI SUKSES PAPERLESS                      -->
<!-- ========================================================= -->
<div id="viewSuccess" class="w-100 d-none text-center">
    <div class="mb-3 text-success">
        <i class="fas fa-check-circle fa-4x"></i>
    </div>
    <h3 class="font-light text-dark mb-1"><?= __('Peminjaman Berhasil!') ?></h3>
    <p class="lead text-muted mb-4" style="font-size: 0.95rem; color: #596169;">
        <?= __('Data peminjaman telah resmi tercatat dalam sistem sirkulasi perpustakaan. Harap rawat koleksi dan kembalikan sebelum jatuh tempo.') ?>
    </p>

    <h6 class="font-weight-bold text-dark text-left mb-2">
        <i class="fas fa-receipt text-primary mr-1"></i> <?= __('Ringkasan Peminjaman:') ?>
    </h6>
    <div class="table-responsive border rounded mb-4" style="border-radius: 4px; background: #ffffff;">
        <table class="table table-striped table-sm text-left mb-0">
            <thead class="thead-light">
                <tr>
                    <th style="width: 40px;">#</th>
                    <th style="width: 140px;"><?= __('Kode Eksemplar') ?></th>
                    <th><?= __('Judul Buku') ?></th>
                    <th class="text-center" style="width: 130px;"><?= __('Jatuh Tempo') ?></th>
                </tr>
            </thead>
            <tbody id="successTableRows">
                <!-- Baris detail sukses -->
            </tbody>
        </table>
    </div>

    <!-- Hitung Mundur Reset Otomatis -->
    <div class="alert alert-secondary py-2 mb-4 font-weight-bold" style="border-radius: 4px;">
        <i class="far fa-clock mr-1 text-primary"></i> <?= __('Layar otomatis kembali ke awal dalam ') ?>
        <span id="lblSuccessCountdown" class="text-danger font-weight-bold"><?= $successTimeout ?></span> <?= __('detik.') ?>
    </div>

    <button type="button" class="btn btn-primary btn-lg px-5 py-3 font-weight-bold" onclick="resetToLoginView()" style="border-radius: 4px;">
        <i class="fas fa-check mr-2"></i> <?= __('Selesai Sekarang') ?>
    </button>
</div>

<!-- ========================================================= -->
<!-- MODAL PERINGATAN INACTIVITY WATCHDOG                      -->
<!-- ========================================================= -->
<div class="modal fade" id="modalInactivityWatchdog" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 4px; border: 1px solid #ced4da;">
            <div class="modal-header bg-warning py-3">
                <h5 class="modal-title font-weight-bold text-dark">
                    <i class="fas fa-exclamation-triangle mr-2"></i> <?= __('Peringatan Batas Waktu Aktivitas') ?>
                </h5>
            </div>
            <div class="modal-body p-4 text-center">
                <p class="h5 font-weight-bold text-dark mb-2"><?= __('Apakah Anda masih meminjam buku?') ?></p>
                <p class="mb-4" style="color: #596169;">
                    <?= __('Sesi Anda akan otomatis dibatalkan dalam ') ?>
                    <strong id="lblModalCountdown" class="text-danger font-weight-bold" style="font-size: 1.5rem;">10</strong>
                    <?= __(' detik untuk menjaga privasi keanggotaan Anda.') ?>
                </p>
                <div class="d-flex justify-content-center">
                    <button type="button" class="btn btn-primary btn-lg px-4 mr-3 font-weight-bold" onclick="handleKeepSessionAlive()" style="border-radius: 4px;">
                        <i class="fas fa-hand-pointer mr-1"></i> <?= __('Lanjutkan Sesi') ?>
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-lg px-4 font-weight-bold" onclick="handleCancelSession()" style="border-radius: 4px;">
                        <i class="fas fa-sign-out-alt mr-1"></i> <?= __('Keluar Sekarang') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL KONFIRMASI PERPANJANGAN BUKU CERDAS                -->
<!-- ========================================================= -->
<div class="modal fade" id="modalConfirmRenew" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 4px; border: 1px solid #ced4da;">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title font-weight-bold text-white mb-0">
                    <i class="fas fa-sync-alt mr-2"></i> <?= __('Perpanjangan Masa Pinjam Buku') ?>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" onclick="closeRenewModal()">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4 text-left">
                <div class="alert alert-info py-2 px-3 mb-3" style="border-radius: 4px; font-size: 0.875rem;">
                    <i class="fas fa-info-circle mr-1"></i>
                    <?= __('Buku ini sedang Anda pinjam. Anda dapat memperpanjang masa pinjamnya langsung di anjungan ini.') ?>
                </div>

                <div class="mb-3">
                    <label class="text-muted small text-uppercase font-weight-bold mb-1"><?= __('Judul Buku') ?></label>
                    <h6 class="font-weight-bold text-dark mb-1" id="modalRenewTitle">-</h6>
                    <span class="badge badge-secondary px-2 py-1 font-monospace" id="modalRenewItemCode">-</span>
                </div>

                <div class="row bg-light rounded p-3 mx-0 mb-3" style="border: 1px solid #e9ecef;">
                    <div class="col-6 pl-0">
                        <small class="text-muted d-block"><?= __('Jatuh Tempo Saat Ini:') ?></small>
                        <strong class="text-dark" id="modalRenewOldDue">-</strong>
                    </div>
                    <div class="col-6 pr-0 border-left pl-3">
                        <small class="text-muted d-block"><?= __('Jatuh Tempo Baru:') ?></small>
                        <strong class="text-success font-weight-bold" id="modalRenewNewDue">-</strong>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-1" style="font-size: 0.85rem;">
                    <span class="text-muted"><?= __('Status Kuota Perpanjangan:') ?></span>
                    <span class="badge badge-primary px-2 py-1" id="modalRenewQuotaBadge">0 / 1 kali</span>
                </div>

                <div id="modalRenewErrorMsg" class="alert alert-danger d-none py-2 px-3 mt-3 mb-0" style="border-radius: 4px; font-size: 0.85rem;">
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal" onclick="closeRenewModal()" style="border-radius: 4px;">
                    <?= __('Batal') ?>
                </button>
                <button type="button" class="btn btn-primary font-weight-bold" id="btnConfirmRenewSubmit" onclick="executeRenewCurrentItem()" style="border-radius: 4px;">
                    <i class="fas fa-check-circle mr-1"></i> <?= __('Ya, Perpanjang Sekarang') ?>
                </button>
            </div>
        </div>
    </div>
</div>
