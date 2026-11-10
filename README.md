# Plugin SLiMS: Pinjam Mandiri (Self-Checkout) & Perpanjangan Buku

Plugin untuk aplikasi **SLiMS 9 Bulian** yang menyediakan anjungan mandiri (*self-checkout kiosk*) bagi pemustaka untuk meminjam dan memperpanjang masa pinjam buku menggunakan kartu anggota dan barcode scanner.

Anjungan berjalan otomatis, aman, dan tanpa kertas (*paperless*), sehingga pemustaka dapat bertransaksi tanpa perlu mengantre di meja sirkulasi.

---

## ✅ Telah Diuji Pada (Work Tested On)

Plugin ini telah diuji dan berfungsi dengan baik pada:
* **Aplikasi**: SLiMS 9 Bulian (v9.4.0 ke atas, teruji di SLiMS 9.6.1 / 9.8.0)
* **Perangkat**: PC Desktop, Mini PC, Layar Sentuh (*Touchscreen*), dan Laptop
* **Pemindai Barcode**: Scanner USB & Wireless (Mode HID / Keyboard)

---

## 💡 Mengapa Menggunakan Plugin Ini?

1. **Mandiri & Tanpa Antre**: Pemustaka dapat meminjam atau memperpanjang buku sendiri dengan memindai barcode kartu anggota dan barcode fisik buku.
2. **Perpanjangan Buku (Renewal) Cerdas**:
   * **1-Klik di Layar**: Menampilkan daftar buku yang sedang dipinjam beserta batas tanggal kembali dan tombol perpanjang langsung.
   * **Scan Ulang Otomatis**: Jika buku yang sedang dipinjam di-scan di form peminjaman, sistem otomatis mendeteksi dan menawarkan konfirmasi perpanjangan.
3. **Validasi Sirkulasi Akurat**: Memeriksa sisa kuota pinjam, denda tertunggak, masa aktif anggota, dan otomatis melompati hari libur operasional perpustakaan.
4. **100% Pure Plugin**: Tidak mengubah berkas inti (*core*) SLiMS maupun skema database bawaan sehingga aman diperbarui kapan saja.

---

## 📦 Cara Memasang Plugin

Ikuti 4 langkah mudah berikut:

1. Klik tombol hijau **Code** di bagian atas halaman GitHub ini, lalu pilih **Download ZIP**.
2. Buka (ekstrak) file ZIP yang sudah diunduh di komputer Anda.
3. Pastikan nama folder hasil ekstrak adalah:
   ```text
   slims_self_checkout
   ```
4. Salin (*copy*) folder `slims_self_checkout` ke dalam folder **plugins** pada komputer atau server SLiMS Anda.
   * Contoh lokasi: `C:\slims9_bulian\plugins\slims_self_checkout\`

---

## 🚀 Cara Mengaktifkan dan Menggunakan

### Langkah 1: Mengaktifkan Plugin
1. Masuk ke halaman admin SLiMS Anda.
2. Klik menu **System** (Sistem) di bilah navigasi atas.
3. Klik menu **Plugins** di menu sebelah kiri.
4. Cari nama plugin **SLiMS Self-Checkout (Peminjaman Mandiri)**.
5. Ubah tombol sakelar di sebelah kanan menjadi **Enabled** (Aktif).

### Langkah 2: Mengatur Petugas Sirkulasi
1. Klik menu **Circulation** (Sirkulasi) di bilah navigasi atas.
2. Klik submenu **Pengaturan Pinjam Mandiri** di sebelah kiri.
3. Pilih akun staf yang bertugas mencatat transaksi pinjam mandiri (misal akun staf sirkulasi).
4. Tentukan batas waktu *idle* dan klik **Simpan Pengaturan**.

### Langkah 3: Membuka Layar Pinjam Mandiri
Buka peramban (*web browser*) di komputer anjungan dan buka tautan:
```text
http://localhost/bulian/index.php?p=pinjam_mandiri
```
*(Atau klik tombol tautan langsung yang ada pada kolom keterangan plugin di menu System -> Plugins).*

---

## 📖 Alur Penggunaan oleh Pemustaka

1. **Identifikasi Pemustaka**: Pindai barcode kartu anggota (atau ketik ID) dan masukkan tanggal lahir.
2. **Peminjaman Buku**: Pindai barcode buku yang ingin dipinjam satu per satu, lalu klik **Selesaikan Peminjaman**.
3. **Perpanjangan Buku**: Buka tab **Buku Sedang Dipinjam & Perpanjangan** dan klik tombol **Perpanjang**, atau cukup pindai barcode buku yang sedang dipinjam.
4. **Selesai**: Layar menampilkan ringkasan transaksi berhasil (*paperless*), lalu otomatis kembali ke layar awal untuk pemustaka berikutnya.

---

## 👤 Penulis & Lisensi

* **Penulis**: **Gusnaldi Kristiadi Syah**
* **Profil GitHub**: [@gusnaldikrist](https://github.com/gusnaldikrist)
* **Lisensi**: GNU General Public License v3.0 (GPL-3.0)
