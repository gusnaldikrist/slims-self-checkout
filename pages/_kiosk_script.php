<?php
/**
 * Logika Interaktif Client-Side Pinjam Mandiri (SLiMS Kiosk Script)
 * Dipakai bersama oleh Mode OPAC dan Mode Kiosk Fullscreen.
 *
 * @author Gusnaldi Kristiadi Syah
 */
defined('INDEX_AUTH') || die('Direct access not allowed!');
?>
<script>
    let activeMember = null;
    let cartList = [];
    let watchdogTimeout = null;
    let watchdogInterval = null;
    let successInterval = null;
    let modalCountdownSeconds = 10;
    const TIMEOUT_LIMIT_MS = <?= $inactivityTimeout ?> * 1000;
    const SUCCESS_COUNTDOWN_SEC = <?= $successTimeout ?>;
    const API_ENDPOINT = '<?= $apiBaseUrl ?>';

    // Sintesis Suara Web Audio (Chime & Buzz)
    const AudioSynth = {
        audioCtx: null,
        getAudioCtx() {
            if (!this.audioCtx && (window.AudioContext || window.webkitAudioContext)) {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (this.audioCtx && this.audioCtx.state === 'suspended') {
                this.audioCtx.resume().catch(() => {});
            }
            return this.audioCtx;
        },
        playSuccess() {
            try {
                const ctx = this.getAudioCtx();
                if (!ctx) return;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(587.33, ctx.currentTime);
                osc.frequency.setValueAtTime(880.00, ctx.currentTime + 0.1);
                gain.gain.setValueAtTime(0.12, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.35);
            } catch(e) {}
        },
        playWarning() {
            try {
                const ctx = this.getAudioCtx();
                if (!ctx) return;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(440, ctx.currentTime);
                osc.frequency.setValueAtTime(659.25, ctx.currentTime + 0.1);
                gain.gain.setValueAtTime(0.14, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.3);
            } catch(e) {}
        },
        playError() {
            try {
                const ctx = this.getAudioCtx();
                if (!ctx) return;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(220, ctx.currentTime);
                osc.frequency.setValueAtTime(164.81, ctx.currentTime + 0.12);
                gain.gain.setValueAtTime(0.18, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.45);
            } catch(e) {}
        }
    };

    // Jam Digital Realtime SLiMS
    function renderClock() {
        const now = new Date();
        const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        const day = days[now.getDay()];
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        const ss = String(now.getSeconds()).padStart(2, '0');
        const el = document.getElementById('clockDisplay');
        if (el) el.textContent = `${day}, ${hh}:${mm}:${ss}`;
    }
    setInterval(renderClock, 1000);
    renderClock();

    // Tampilkan Toast Notifikasi
    function triggerToast(message, type = 'info') {
        let box = document.getElementById('toastArea');
        if (!box) {
            box = document.createElement('div');
            box.id = 'toastArea';
            document.body.appendChild(box);
        }
        const alertClass = (type === 'success') ? 'alert-success' : ((type === 'error') ? 'alert-danger' : 'alert-info');
        const iconClass = (type === 'success') ? 'fa-check-circle' : ((type === 'error') ? 'fa-exclamation-triangle' : 'fa-info-circle');

        const item = document.createElement('div');
        item.className = `alert ${alertClass} alert-dismissible fade show shadow-sm mb-2`;
        item.style.borderRadius = '4px';
        item.role = 'alert';
        item.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="fas ${iconClass} mr-2"></i>
                <span>${message}</span>
                <button type="button" class="close ml-auto" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        `;
        box.appendChild(item);
        setTimeout(() => { $(item).alert('close'); }, 4000);
    }

    // Inactivity Watchdog
    function restartWatchdog() {
        clearTimeout(watchdogTimeout);
        clearInterval(watchdogInterval);
        $('#modalInactivityWatchdog').modal('hide');

        if (activeMember) {
            watchdogTimeout = setTimeout(showWatchdogWarning, TIMEOUT_LIMIT_MS);
        }
    }

    function showWatchdogWarning() {
        modalCountdownSeconds = 10;
        const el = document.getElementById('lblModalCountdown');
        if (el) el.textContent = modalCountdownSeconds;
        $('#modalInactivityWatchdog').modal('show');
        AudioSynth.playError();

        watchdogInterval = setInterval(() => {
            modalCountdownSeconds--;
            if (el) el.textContent = modalCountdownSeconds;
            if (modalCountdownSeconds <= 0) {
                clearInterval(watchdogInterval);
                handleCancelSession('Sesi dibatalkan otomatis karena tidak ada aktivitas.');
            }
        }, 1000);
    }

    function handleKeepSessionAlive() {
        restartWatchdog();
        const inp = document.getElementById('txtItemBarcode');
        if (inp) inp.focus();
    }

    // Normalisasi Input Tanggal Lahir (Mendukung DD/MM/YYYY, DD-MM-YYYY, YYYY-MM-DD, atau DDMMYYYY)
    function normalizeDate(val) {
        if (!val) return '';
        val = val.trim();
        // Format DD/MM/YYYY atau DD-MM-YYYY
        const dmy = val.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
        if (dmy) {
            const dayNum = parseInt(dmy[1], 10);
            const monthNum = parseInt(dmy[2], 10);
            const yearNum = parseInt(dmy[3], 10);
            if (dayNum < 1 || dayNum > 31 || monthNum < 1 || monthNum > 12 || yearNum < 1900 || yearNum > 2100) {
                return null;
            }
            const day = String(dayNum).padStart(2, '0');
            const month = String(monthNum).padStart(2, '0');
            const year = String(yearNum);
            return `${year}-${month}-${day}`;
        }
        // Format DDMMYYYY (8 digit angka tanpa pemisah)
        const raw = val.match(/^(\d{2})(\d{2})(\d{4})$/);
        if (raw) {
            const dayNum = parseInt(raw[1], 10);
            const monthNum = parseInt(raw[2], 10);
            const yearNum = parseInt(raw[3], 10);
            if (dayNum < 1 || dayNum > 31 || monthNum < 1 || monthNum > 12 || yearNum < 1900 || yearNum > 2100) {
                return null;
            }
            return `${yearNum}-${String(monthNum).padStart(2, '0')}-${String(dayNum).padStart(2, '0')}`;
        }
        // Format YYYY-MM-DD atau YYYY/MM/DD
        const ymd = val.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/);
        if (ymd) {
            const yearNum = parseInt(ymd[1], 10);
            const monthNum = parseInt(ymd[2], 10);
            const dayNum = parseInt(ymd[3], 10);
            if (dayNum < 1 || dayNum > 31 || monthNum < 1 || monthNum > 12 || yearNum < 1900 || yearNum > 2100) {
                return null;
            }
            return `${yearNum}-${String(monthNum).padStart(2, '0')}-${String(dayNum).padStart(2, '0')}`;
        }
        return null;
    }

    // Inisialisasi Datepicker & Auto-Formatter Tanggal Lahir
    let birthDatePicker = null;
    document.addEventListener('DOMContentLoaded', () => {
        const txtBirthDate = document.getElementById('txtBirthDate');
        const btnToggle = document.getElementById('btnToggleDatePicker');

        if (txtBirthDate && typeof Datepicker !== 'undefined') {
            birthDatePicker = new Datepicker(txtBirthDate, {
                buttonClass: 'btn',
                format: 'dd/mm/yyyy',
                autohide: true,
                language: 'id',
                todayHighlight: true,
                showOnFocus: false,
                showOnClick: false,
                updateOnBlur: false
            });

            // Proteksi teks input agar tidak pernah di-reset oleh unfocus / hide datepicker saat klik di luar
            function preserveInputVal() {
                if (txtBirthDate.dataset.lastVal && txtBirthDate.value !== txtBirthDate.dataset.lastVal) {
                    txtBirthDate.value = txtBirthDate.dataset.lastVal;
                }
            }
            txtBirthDate.addEventListener('blur', preserveInputVal);
            txtBirthDate.addEventListener('hide', preserveInputVal);

            // Sinkronkan data saat pemustaka memilih tanggal di kalender
            txtBirthDate.addEventListener('changeDate', function() {
                this.dataset.lastVal = this.value;
            });

            if (btnToggle) {
                btnToggle.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (txtBirthDate.value && txtBirthDate.value.length === 10) {
                        try {
                            birthDatePicker.setDate(txtBirthDate.value, { render: false });
                        } catch (_) {}
                    }
                    birthDatePicker.show();
                });
            }
        }

        // Auto-masking cerdas saat mengetik tanggal lahir (mencegah dobel slash dan terpotong di karakter ke-9)
        if (txtBirthDate) {
            txtBirthDate.addEventListener('input', function(e) {
                const isDelete = e.inputType && e.inputType.startsWith('delete');
                if (isDelete) {
                    this.dataset.lastVal = this.value;
                    return; // Keleluasaan penuh saat menghapus (backspace/delete)
                }

                const currentVal = this.value;
                const digits = currentVal.replace(/\D/g, '').slice(0, 8); // Maksimal 8 digit (DDMMYYYY)
                if (digits.length === 0) {
                    this.value = '';
                    this.dataset.lastVal = '';
                    return;
                }

                let formatted = '';
                if (digits.length <= 2) {
                    formatted = digits;
                    if (currentVal.endsWith('/') || digits.length === 2) {
                        formatted += '/';
                    }
                } else if (digits.length <= 4) {
                    formatted = digits.slice(0, 2) + '/' + digits.slice(2);
                    if (currentVal.endsWith('/') || digits.length === 4) {
                        formatted += '/';
                    }
                } else {
                    formatted = digits.slice(0, 2) + '/' + digits.slice(2, 4) + '/' + digits.slice(4, 8);
                }

                this.value = formatted;
                this.dataset.lastVal = formatted;

                // Sinkronkan ke datepicker jika format sudah lengkap 10 karakter (DD/MM/YYYY)
                if (birthDatePicker && formatted.length === 10) {
                    try {
                        birthDatePicker.setDate(formatted, { render: false });
                    } catch (_) {}
                }
            });
        }
    });

    // Autentikasi Pemustaka (State 1 -> State 2)
    async function handleAuthSubmit(e) {
        e.preventDefault();
        const memId = document.getElementById('txtMemberId').value.trim();
        const rawBDate = document.getElementById('txtBirthDate').value.trim();
        const alertBox = document.getElementById('loginAlertBox');
        const alertMsg = document.getElementById('loginAlertMsg');
        const btnSubmit = document.getElementById('btnSubmitAuth');

        if (!memId || !rawBDate) return;

        const bDate = normalizeDate(rawBDate);
        if (!bDate || !/^\d{4}-\d{2}-\d{2}$/.test(bDate)) {
            AudioSynth.playError();
            alertMsg.textContent = 'Format tanggal lahir tidak valid. Masukkan format HH/BB/TTTT (misal: 01/01/2000) atau pilih dari kalender.';
            alertBox.classList.remove('d-none');
            return;
        }

        // Validasi kewajaran tanggal (hari 1-31, bulan 1-12, tahun realistis)
        const dateParts = bDate.split('-');
        const yNum = parseInt(dateParts[0], 10);
        const mNum = parseInt(dateParts[1], 10);
        const dNum = parseInt(dateParts[2], 10);
        const currentYear = new Date().getFullYear();

        if (yNum < 1920 || yNum > currentYear || mNum < 1 || mNum > 12 || dNum < 1 || dNum > 31) {
            AudioSynth.playError();
            alertMsg.textContent = 'Tanggal lahir tidak masuk akal. Periksa kembali tanggal, bulan, atau tahun yang Anda masukkan.';
            alertBox.classList.remove('d-none');
            return;
        }

        alertBox.classList.add('d-none');
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Memvalidasi data...';

        try {
            const response = await fetch(`${API_ENDPOINT}&action=verify_member`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ member_id: memId, birth_date: bDate })
            });

            const res = await response.json();
            const memberData = res.member || res.data;

            if (res.status === true && memberData) {
                activeMember = memberData;
                cartList = [];
                AudioSynth.playSuccess();
                switchView('session');
                switchSessionTab('checkout');
                loadActiveLoans(activeMember.member_id);
            } else {
                AudioSynth.playError();
                alertMsg.textContent = res.message || 'ID Anggota atau Tanggal Lahir tidak cocok.';
                alertBox.classList.remove('d-none');
                document.getElementById('txtMemberId').focus();
            }
        } catch (err) {
            AudioSynth.playError();
            alertMsg.textContent = 'Gagal menghubungi server SLiMS: ' + err.message;
            alertBox.classList.remove('d-none');
        } finally {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i class="fas fa-sign-in-alt mr-2"></i> Masuk ke Sesi Pinjam';
        }
    }

    // Scan Barcode Eksemplar Buku
    async function handleItemScan(e) {
        e.preventDefault();
        if (!activeMember) return;

        const barcodeInput = document.getElementById('txtItemBarcode');
        const itemCode = barcodeInput.value.trim();
        const btnScan = document.getElementById('btnScanSubmit');

        if (!itemCode) return;

        restartWatchdog();
        btnScan.disabled = true;

        try {
            const cartCodes = cartList.map(i => i.item_code);
            const response = await fetch(`${API_ENDPOINT}&action=scan_item`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    member_id: activeMember.member_id,
                    item_code: itemCode,
                    cart: JSON.stringify(cartCodes)
                })
            });

            const res = await response.json();

            // Cek apakah barcode yang di-scan adalah buku yang sedang dipinjam pemustaka ini
            if (res.status === true && res.is_renewal_candidate && res.loan) {
                barcodeInput.value = '';
                AudioSynth.playWarning();
                openRenewModal(res.loan, res.item);
                return;
            }

            const itemData = res.item || res.data;

            if (res.status === true && itemData) {
                cartList.push(itemData);
                AudioSynth.playSuccess();
                triggerToast(`Buku "${itemData.title}" ditambahkan ke keranjang.`, 'success');
                renderCartItems();
                barcodeInput.value = '';
            } else {
                AudioSynth.playError();
                triggerToast(res.message || 'Eksemplar tidak dapat dipinjam.', 'error');
            }
        } catch (err) {
            AudioSynth.playError();
            triggerToast('Terjadi kesalahan koneksi server: ' + err.message, 'error');
        } finally {
            btnScan.disabled = false;
            barcodeInput.focus();
        }
    }

    // Navigasi Tab Antara Pinjam Baru dan Pinjaman Aktif
    let currentSessionTab = 'checkout';
    function switchSessionTab(tab) {
        currentSessionTab = tab;
        const btnTabCheckout = document.getElementById('tab-checkout');
        const btnTabLoans = document.getElementById('tab-loans');
        const panelCheckout = document.getElementById('panelCheckoutTab');
        const panelLoans = document.getElementById('panelLoansTab');

        if (!panelCheckout || !panelLoans) return;

        if (tab === 'checkout') {
            panelCheckout.classList.remove('d-none');
            panelLoans.classList.add('d-none');
            if (btnTabCheckout) {
                btnTabCheckout.className = 'nav-link active font-weight-bold px-3 py-2';
                btnTabCheckout.style.background = '';
            }
            if (btnTabLoans) {
                btnTabLoans.className = 'nav-link font-weight-bold px-3 py-2 text-dark';
                btnTabLoans.style.background = '#e9ecef';
            }
            const barcodeInp = document.getElementById('txtItemBarcode');
            if (barcodeInp) barcodeInp.focus();
        } else {
            panelCheckout.classList.add('d-none');
            panelLoans.classList.remove('d-none');
            if (btnTabLoans) {
                btnTabLoans.className = 'nav-link active font-weight-bold px-3 py-2';
                btnTabLoans.style.background = '';
            }
            if (btnTabCheckout) {
                btnTabCheckout.className = 'nav-link font-weight-bold px-3 py-2 text-dark';
                btnTabCheckout.style.background = '#e9ecef';
            }
            if (activeMember) {
                loadActiveLoans(activeMember.member_id);
            }
        }
    }

    // Variabel & Handler Pinjaman Aktif / Perpanjangan
    let activeLoansList = [];
    let currentRenewCandidate = null;

    async function loadActiveLoans(memberId) {
        if (!memberId) return;
        const tbody = document.getElementById('activeLoansTableRows');
        const emptyNotice = document.getElementById('activeLoansEmptyNotice');
        const badgeCount = document.getElementById('badgeNavActiveLoansCount');

        if (!tbody) return;

        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin mr-2"></i> Memuat daftar pinjaman...</td></tr>';
        if (emptyNotice) emptyNotice.classList.add('d-none');

        try {
            const resp = await fetch(`${API_ENDPOINT}&action=get_active_loans&member_id=${encodeURIComponent(memberId)}`);
            const res = await resp.json();

            if (res.status === true && Array.isArray(res.data)) {
                activeLoansList = res.data;
                if (badgeCount) badgeCount.textContent = activeLoansList.length;

                if (activeLoansList.length === 0) {
                    tbody.innerHTML = '';
                    if (emptyNotice) emptyNotice.classList.remove('d-none');
                    return;
                }

                if (emptyNotice) emptyNotice.classList.add('d-none');
                let html = '';
                activeLoansList.forEach((loan, idx) => {
                    const isOverdue = loan.is_overdue;
                    const dueBadgeClass = isOverdue ? 'badge badge-danger text-white' : 'badge badge-light border text-dark';
                    const overdueNote = isOverdue ? '<span class="d-block text-danger small font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i> Terlambat</span>' : '';

                    let actionHtml = '';
                    if (loan.can_renew) {
                        actionHtml = `
                            <button type="button" class="btn btn-sm btn-primary font-weight-bold" 
                                    onclick="triggerRenewFromList('${escapeHtml(loan.item_code)}')" 
                                    style="border-radius: 4px;">
                                <i class="fas fa-sync-alt mr-1"></i> Perpanjang
                            </button>
                        `;
                    } else {
                        actionHtml = `
                            <span class="badge badge-light border text-danger p-1" title="${escapeHtml(loan.cannot_renew_reason || 'Tidak dapat diperpanjang')}" style="font-size: 0.8rem;">
                                <i class="fas fa-ban mr-1"></i> Tidak Bisa
                            </span>
                        `;
                    }

                    html += `
                        <tr>
                            <td class="align-middle">${idx + 1}</td>
                            <td class="align-middle font-weight-bold font-monospace text-primary">${escapeHtml(loan.item_code)}</td>
                            <td class="align-middle">
                                <span class="font-weight-bold text-dark">${escapeHtml(loan.title)}</span>
                                <small class="text-muted d-block">No. Panggil: ${escapeHtml(loan.call_number || '-')}</small>
                            </td>
                            <td class="align-middle text-muted">${escapeHtml(loan.loan_date)}</td>
                            <td class="align-middle">
                                <span class="${dueBadgeClass}">${escapeHtml(loan.due_date)}</span>
                                ${overdueNote}
                            </td>
                            <td class="align-middle text-center">
                                <span class="badge badge-secondary px-2 py-1">${loan.renewed} / ${loan.reborrow_limit}x</span>
                            </td>
                            <td class="align-middle text-center">
                                ${actionHtml}
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">${escapeHtml(res.message || 'Gagal memuat pinjaman')}</td></tr>`;
            }
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger">Koneksi bermasalah: ${escapeHtml(err.message)}</td></tr>`;
        }
    }

    function triggerRenewFromList(itemCode) {
        const found = activeLoansList.find(l => l.item_code.toUpperCase() === itemCode.toUpperCase());
        if (found) {
            openRenewModal(found, { item_code: found.item_code, title: found.title });
        }
    }

    function openRenewModal(loanData, itemData) {
        currentRenewCandidate = { loan: loanData, item: itemData };
        const modalTitle = document.getElementById('modalRenewTitle');
        const modalCode = document.getElementById('modalRenewItemCode');
        const modalOldDue = document.getElementById('modalRenewOldDue');
        const modalNewDue = document.getElementById('modalRenewNewDue');
        const modalQuota = document.getElementById('modalRenewQuotaBadge');
        const btnSubmit = document.getElementById('btnConfirmRenewSubmit');
        const errorBox = document.getElementById('modalRenewErrorMsg');

        if (modalTitle) modalTitle.textContent = (itemData && itemData.title) || loanData.title || '-';
        if (modalCode) modalCode.textContent = (itemData && itemData.item_code) || loanData.item_code || '-';
        if (modalOldDue) modalOldDue.textContent = loanData.due_date || '-';
        if (modalNewDue) modalNewDue.textContent = loanData.new_due_date || '-';
        if (modalQuota) modalQuota.textContent = `${loanData.renewed || 0} / ${loanData.reborrow_limit || 1} kali`;

        if (loanData.can_renew) {
            if (btnSubmit) btnSubmit.disabled = false;
            if (errorBox) {
                errorBox.classList.add('d-none');
                errorBox.textContent = '';
            }
        } else {
            if (btnSubmit) btnSubmit.disabled = true;
            if (errorBox) {
                errorBox.textContent = loanData.cannot_renew_reason || 'Buku ini tidak dapat diperpanjang.';
                errorBox.classList.remove('d-none');
            }
        }

        $('#modalConfirmRenew').modal('show');
    }

    function closeRenewModal() {
        $('#modalConfirmRenew').modal('hide');
        currentRenewCandidate = null;
        const barcodeInp = document.getElementById('txtItemBarcode');
        if (barcodeInp && currentSessionTab === 'checkout') {
            barcodeInp.focus();
        }
    }

    async function executeRenewCurrentItem() {
        if (!currentRenewCandidate || !activeMember) return;

        const itemCode = (currentRenewCandidate.item && currentRenewCandidate.item.item_code) || currentRenewCandidate.loan.item_code;
        const btnSubmit = document.getElementById('btnConfirmRenewSubmit');
        const errorBox = document.getElementById('modalRenewErrorMsg');

        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Memproses...';
        }
        if (errorBox) errorBox.classList.add('d-none');

        try {
            const resp = await fetch(`${API_ENDPOINT}&action=renew_loan`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    member_id: activeMember.member_id,
                    item_code: itemCode
                })
            });

            const res = await resp.json();

            if (res.status === true) {
                AudioSynth.playSuccess();
                triggerToast(res.message || 'Masa pinjam berhasil diperpanjang.', 'success');
                closeRenewModal();
                // Refresh list pinjaman aktif
                loadActiveLoans(activeMember.member_id);
            } else {
                AudioSynth.playError();
                if (errorBox) {
                    errorBox.textContent = res.message || 'Gagal memperpanjang pinjaman.';
                    errorBox.classList.remove('d-none');
                }
            }
        } catch (err) {
            AudioSynth.playError();
            if (errorBox) {
                errorBox.textContent = 'Terjadi kesalahan sistem: ' + err.message;
                errorBox.classList.remove('d-none');
            }
        } finally {
            if (btnSubmit) {
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Ya, Perpanjang Sekarang';
            }
        }
    }

    // Render Keranjang Pinjaman
    function renderCartItems() {
        const tbody = document.getElementById('cartTableRows');
        const emptyNotice = document.getElementById('cartEmptyNotice');
        const badgeCount = document.getElementById('badgeCartCount');
        const btnSubmit = document.getElementById('btnSubmitCheckout');
        let btnSubmitText = document.getElementById('btnSubmitCheckoutText');
        const remainingQuotaEl = document.getElementById('lblRemainingQuota');

        if (!tbody) return;

        // Pastikan struktur span teks tombol ada jika sebelumnya tertimpa spinner
        if (btnSubmit && !btnSubmitText) {
            btnSubmit.innerHTML = '<i class="fas fa-check-circle mr-2"></i> <span id="btnSubmitCheckoutText"></span>';
            btnSubmitText = document.getElementById('btnSubmitCheckoutText');
        }

        tbody.innerHTML = '';
        if (badgeCount) badgeCount.textContent = cartList.length;
        const badgeNavCart = document.getElementById('badgeNavCartCount');
        if (badgeNavCart) badgeNavCart.textContent = cartList.length;

        if (activeMember && remainingQuotaEl) {
            const remaining = Math.max(0, activeMember.remaining_quota - cartList.length);
            remainingQuotaEl.textContent = remaining;
        }

        if (cartList.length === 0) {
            if (emptyNotice) emptyNotice.classList.remove('d-none');
            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<i class="fas fa-check-circle mr-2"></i> <span id="btnSubmitCheckoutText">Selesaikan Peminjaman (0 Buku)</span>';
            }
            return;
        }

        if (emptyNotice) emptyNotice.classList.add('d-none');
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = `<i class="fas fa-check-circle mr-2"></i> <span id="btnSubmitCheckoutText">Selesaikan Peminjaman (${cartList.length} Buku)</span>`;
        }

        cartList.forEach((item, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${idx + 1}</td>
                <td><strong class="text-primary font-weight-bold">${escapeHtml(item.item_code)}</strong></td>
                <td>
                    <div class="font-weight-bold text-dark">${escapeHtml(item.title)}</div>
                    <small style="color: #596169;">${escapeHtml(item.author || '')}</small>
                </td>
                <td><span class="badge badge-success px-2 py-1 font-weight-bold" style="border-radius: 4px;">${escapeHtml(item.due_date || '-')}</span></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeCartItem(${idx})" title="Hapus buku" style="border-radius: 4px;">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
    }

    // Hapus Item dari Keranjang
    function removeCartItem(idx) {
        restartWatchdog();
        if (idx >= 0 && idx < cartList.length) {
            const removed = cartList.splice(idx, 1)[0];
            triggerToast(`Buku "${removed.title}" dihapus dari keranjang.`, 'info');
            renderCartItems();
            document.getElementById('txtItemBarcode').focus();
        }
    }

    // Selesaikan Peminjaman (Checkout Final)
    async function handleCheckoutFinish() {
        if (!activeMember || cartList.length === 0) return;

        restartWatchdog();
        const btnFinish = document.getElementById('btnSubmitCheckout');
        if (btnFinish) {
            btnFinish.disabled = true;
            btnFinish.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> <span id="btnSubmitCheckoutText">Menyimpan transaksi...</span>';
        }

        try {
            const codes = cartList.map(i => i.item_code);
            const payloadItems = cartList.map(i => ({
                item_code: i.item_code,
                title: i.title || '',
                due_date: i.due_date || '',
                loan_date: i.loan_date || '',
                loan_rules_id: i.loan_rules_id || 0
            }));

            const response = await fetch(`${API_ENDPOINT}&action=checkout`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    member_id: activeMember.member_id,
                    item_codes: JSON.stringify(codes),
                    items: JSON.stringify(payloadItems)
                })
            });

            const textResponse = await response.text();
            let res;
            try {
                res = JSON.parse(textResponse);
            } catch (jsonErr) {
                console.error('Non-JSON response:', textResponse);
                throw new Error('Respon server tidak valid (bukan JSON murni).');
            }

            if (res.status === true) {
                AudioSynth.playSuccess();
                const loansList = res.loans || (res.data ? res.data.items : null) || cartList;
                renderSuccessDetails(loansList);
                switchView('success');
            } else {
                AudioSynth.playError();
                triggerToast(res.message || 'Gagal menyimpan transaksi peminjaman.', 'error');
                if (btnFinish) {
                    btnFinish.disabled = false;
                }
                renderCartItems();
            }
        } catch (err) {
            AudioSynth.playError();
            triggerToast('Kesalahan server: ' + err.message, 'error');
            if (btnFinish) {
                btnFinish.disabled = false;
            }
            renderCartItems();
        }
    }

    // Render Layar Sukses Paperless
    function renderSuccessDetails(loans) {
        const tbody = document.getElementById('successTableRows');
        tbody.innerHTML = '';

        loans.forEach((l, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${idx + 1}</td>
                <td><strong>${escapeHtml(l.item_code)}</strong></td>
                <td>${escapeHtml(l.title)}</td>
                <td class="text-center font-weight-bold text-success">${escapeHtml(l.due_date)}</td>
            `;
            tbody.appendChild(tr);
        });

        // Timer auto-reset
        let rem = SUCCESS_COUNTDOWN_SEC;
        const el = document.getElementById('lblSuccessCountdown');
        if (el) el.textContent = rem;

        clearInterval(successInterval);
        successInterval = setInterval(() => {
            rem--;
            if (el) el.textContent = rem;
            if (rem <= 0) {
                clearInterval(successInterval);
                resetToLoginView();
            }
        }, 1000);
    }

    // Batalkan Sesi
    async function handleCancelSession(reason = '') {
        clearTimeout(watchdogTimeout);
        clearInterval(watchdogInterval);
        $('#modalInactivityWatchdog').modal('hide');

        if (activeMember) {
            try {
                await fetch(`${API_ENDPOINT}&action=cancel`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ member_id: activeMember.member_id })
                });
            } catch(e) {}
        }

        if (reason) triggerToast(reason, 'info');
        resetToLoginView();
    }

    // Reset ke Form Login
    function resetToLoginView() {
        clearTimeout(watchdogTimeout);
        clearInterval(watchdogInterval);
        clearInterval(successInterval);
        $('#modalInactivityWatchdog').modal('hide');
        $('#modalConfirmRenew').modal('hide');

        activeMember = null;
        cartList = [];
        activeLoansList = [];
        currentRenewCandidate = null;

        const badgeNavLoans = document.getElementById('badgeNavActiveLoansCount');
        if (badgeNavLoans) badgeNavLoans.textContent = '0';
        const badgeNavCart = document.getElementById('badgeNavCartCount');
        if (badgeNavCart) badgeNavCart.textContent = '0';
        switchSessionTab('checkout');

        const f = document.getElementById('formMemberAuth');
        if (f) f.reset();
        if (birthDatePicker) {
            try { birthDatePicker.setDate({clear: true}); } catch(e) {}
        }
        const alertBox = document.getElementById('loginAlertBox');
        if (alertBox) alertBox.classList.add('d-none');

        switchView('login');
    }

    // Ganti Tampilan (State 1, 2, 3)
    function switchView(view) {
        const vLogin = document.getElementById('viewLogin');
        const vSession = document.getElementById('viewSession');
        const vSuccess = document.getElementById('viewSuccess');

        if (vLogin) vLogin.classList.add('d-none');
        if (vSession) vSession.classList.add('d-none');
        if (vSuccess) vSuccess.classList.add('d-none');

        const gWelcome = document.getElementById('guidanceWelcome');
        const gSession = document.getElementById('guidanceSession');

        if (view === 'login') {
            if (vLogin) vLogin.classList.remove('d-none');
            if (gWelcome) gWelcome.classList.remove('d-none');
            if (gSession) gSession.classList.add('d-none');
            const inp = document.getElementById('txtMemberId');
            if (inp) inp.focus();
        } else if (view === 'session') {
            if (vSession) vSession.classList.remove('d-none');
            document.getElementById('lblMemberName').textContent = activeMember.member_name || '-';
            document.getElementById('lblMemberId').textContent = activeMember.member_id || '-';
            document.getElementById('lblMemberType').textContent = activeMember.member_type_name || '-';
            document.getElementById('lblRemainingQuota').textContent = activeMember.remaining_quota || 0;

            const gName = document.getElementById('lblGuidanceMemberName');
            if (gName) gName.textContent = activeMember.member_name || '-';
            if (gWelcome) gWelcome.classList.add('d-none');
            if (gSession) gSession.classList.remove('d-none');

            renderCartItems();
            restartWatchdog();
            const inp = document.getElementById('txtItemBarcode');
            if (inp) inp.focus();
        } else if (view === 'success') {
            if (vSuccess) vSuccess.classList.remove('d-none');
            if (gWelcome) gWelcome.classList.remove('d-none');
            if (gSession) gSession.classList.add('d-none');
        }
    }

    // Auto-refocus scanner input saat sesi aktif
    document.addEventListener('click', (e) => {
        const vSession = document.getElementById('viewSession');
        if (vSession && !vSession.classList.contains('d-none')) {
            const target = e.target;
            if (!target.closest('button') && !target.closest('a') && !target.closest('input')) {
                const inp = document.getElementById('txtItemBarcode');
                if (inp) inp.focus();
            }
        }
    });

    // Deteksi aktivitas untuk me-reset watchdog timer
    ['mousemove', 'keydown', 'touchstart'].forEach(evt => {
        document.addEventListener(evt, () => {
            const vSession = document.getElementById('viewSession');
            if (activeMember && vSession && !vSession.classList.contains('d-none')) {
                restartWatchdog();
            }
        }, { passive: true });
    });

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
</script>
