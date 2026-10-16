# 🚗 Sistem Manajemen Showroom Mobil Bekas

Aplikasi web modern, responsif, dan komprehensif untuk mengelola operasional bisnis showroom mobil bekas. Dibangun dengan PHP Native dan MySQL, sistem ini dirancang untuk mempermudah manajemen inventaris, transaksi penjualan, perawatan kendaraan, serta pelaporan keuangan yang akurat.

## ✨ Fitur Utama

### 1. Dashboard Interaktif
- Ringkasan statistik real-time (total mobil, terjual, pelanggan, pendapatan).
- Visualisasi data penjualan bulanan dan tahunan.
- Aktivitas terbaru dan notifikasi stok rendah.

### 2. Manajemen Inventaris (Inventory)
- **Kendaraan**: Kelola stok mobil dengan detail lengkap (Foto, Spesifikasi, Harga Beli/Jual).
- **Data Master**: Manajemen Merek, Model, Tipe Mobil, dan Profil Warna.
- **Status Stok**: Pelacakan status mobil (Tersedia, Terjual, Booking).

### 3. Perawatan & Perbaikan (Maintenance)
- Pencatatan log perawatan/servis untuk setiap unit mobil.
- Perhitungan biaya perbaikan yang otomatis terintegrasi ke dalam HPP (Harga Pokok Penjualan).
- Riwayat servis lengkap untuk transparansi kualitas unit.

### 4. Transaksi & Penjualan
- **Penjualan**: Pencatatan transaksi cash maupun kredit.
- **Leasing**: Manajemen mitra leasing untuk pembelian kredit.
- **Dokumen**: Cetak Surat Jalan, Kwitansi, dan Tanda Jadi.

### 5. Laporan & Keuangan (Finance)
- **Laporan Laba Rugi (Gross Profit)**: Analisis keuntungan bersih (Net Sales) dikurangi HPP (termasuk biaya perbaikan).
- **Laporan Penjualan**: Breakdown harian, bulanan, dan tahunan.
- **Laporan Stok**: Analisis perputaran inventaris.

### 6. Manajemen Sistem
- **Pengaturan Modul**: Ubah identitas perusahaan, logo, dan background login via antarmuka admin.
- **Manajemen Pengguna**: Kelola akun staff/admin dengan role akses.
- **Audit Log**: Mencatat aktivitas penting dalam sistem.

### 7. Modern UI/UX
- Desain antarmuka bersih menggunakan Bootstrap 5 dan kustom CSS.
- **Clean URL**: URL yang rapi dan user-friendly (tanpa ekstensi `.php`).
- Responsif untuk akses via desktop, tablet, dan mobile.

---

## 🛠️ Teknologi

- **Bahasa Pemrograman**: PHP 7.4 / 8.x (Native)
- **Database**: MySQL / MariaDB
- **Frontend**: HTML5, CSS3, Bootstrap 5, Vanilla JS (Chart.js untuk grafik)
- **Web Server**: Apache (dengan `mod_rewrite` aktif)

---

## 💻 Panduan Instalasi

Berikut langkah-langkah untuk menjalankan aplikasi ini di komputer lokal (Localhost) menggunakan XAMPP/WAMP:

### 1. Persiapan Lingkungan
- Pastikan **XAMPP** atau **WAMP** sudah terinstal.
- Pastikan service **Apache** dan **MySQL** sudah berjalan.
- **Penting**: Pastikan modul `mod_rewrite` pada Apache sudah aktif (untuk fitur Clean URL).

### 2. Instalasi Database
1. Buka **phpMyAdmin** (biasanya di `http://localhost/phpmyadmin`).
2. Buat database baru dengan nama `showroom_mobil_bekas`.
3. Buka menu **Import**.
4. Pilih file SQL dari folder proyek: `/database/showroom_mobil_bekas.sql`.
5. Klik **Go/Kirim** untuk melakukan import.

### 3. Konfigurasi Aplikasi
1. Salin folder `showroom` ke dalam direktori root web server (contoh: `C:\xampp\htdocs\showroom`).
2. Buka file `/config/database.php` dan sesuaikan kredensial database jika perlu:
   ```php
   private $host = "localhost";
   private $db_name = "showroom_mobil_bekas";
   private $username = "root";     // Default user XAMPP
   private $password = "";         // Default password XAMPP (kosong)
   ```

### 4. Akses Aplikasi
1. Buka browser (Chrome/Edge/Firefox).
2. Akses alamat: `http://localhost/showroom/`
3. Halaman akan otomatis diarahkan ke login.

### Default Login
- **Username**: `admin`
- **Password**: `admin123` *(Segera ganti password setelah login pertama)*

---

## 📂 Struktur Direktori

```
showroom/
├── assets/             # File statis (CSS, JS, Images)
├── auth/               # Modul autentikasi (Login/Logout)
├── config/             # Koneksi database & konfigurasi dasar
├── database/           # File backup/dump SQL
├── includes/           # Komponen layout (Header, Sidebar, Footer)
├── reports/            # Modul pelaporan (Keuangan, Penjualan)
├── uploads/            # Direktori penyimpanan file upload user
│   ├── cars/           # Foto mobil
│   └── settings/       # Logo & background perusahaan
├── ...                 # Folder modul (vehicles, users, brands, dll)
├── .htaccess           # Konfigurasi routing & keamanan server
└── index.php           # Entry point aplikasi
```

---

## 📝 Catatan Pengembang
- Pastikan folder `/uploads` memiliki izin tulis (write permission) agar fitur upload foto berfungsi.
- Jika mengalami error 404 atau 500 pada URL, periksa kembali konfigurasi `.htaccess` dan pastikan `AllowOverride All` aktif pada konfigurasi Apache (httpd.conf).
