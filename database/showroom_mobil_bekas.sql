-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 13 Jan 2026 pada 02.18
-- Versi server: 10.4.32-MariaDB
-- Versi PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `showroom_mobil_bekas`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `brands`
--

CREATE TABLE `brands` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `country` varchar(50) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `brands`
--

INSERT INTO `brands` (`id`, `name`, `country`, `logo`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Toyota', 'Japan', '68e3768461a1f.png', 'active', '2025-11-12 13:20:29', '2025-11-24 07:57:56'),
(2, 'Honda', 'Japan', '68e375bd45fc2.png', 'active', '2025-11-07 13:20:29', '2025-11-18 07:54:37'),
(4, 'Daihatsu', 'Japan', '68e37545eb6bc.png', 'active', '2025-11-07 13:20:29', '2025-11-26 11:47:34'),
(5, 'Mitsubishi', 'Japan', '68e376236aa71.jpg', 'active', '2025-09-24 13:20:29', '2025-10-06 07:56:19'),
(6, 'Mazda', 'Japan', '68e375e514e0e.png', 'active', '2025-09-30 03:20:29', '2025-10-06 07:55:17'),
(7, 'BMW', 'Germany', '694e4e2dd7121.png', 'active', '2025-11-12 06:39:34', '2025-12-26 09:10:56'),
(8, 'Hyundai', 'South Korea', '68e377aa32403.png', 'active', '2025-10-06 08:02:50', '2025-10-06 08:02:50'),
(11, 'Mercedes-Benz', 'Germany', '6923c2dfc6af9.png', 'active', '2025-11-24 02:28:47', '2025-11-24 02:28:47'),
(12, 'Suzuki', 'Japan', '6923c32eb3069.png', 'active', '2025-11-24 02:30:06', '2025-11-24 02:30:06'),
(14, 'KIA', 'South Korea', '6923c50f67eba.png', 'active', '2025-11-24 02:38:07', '2025-11-24 02:38:07'),
(17, 'Ford', 'USA', '6923c6a41b0ed.png', 'active', '2025-11-24 02:44:52', '2025-11-24 02:44:52'),
(19, 'Wuling', 'China', '694207120b177.png', 'active', '2025-12-17 01:27:46', '2025-12-17 01:27:55'),
(26, 'Nissan', 'Japan', '695c60c6d84b1.png', 'active', '2026-01-06 01:09:26', '2026-01-06 01:09:26');

-- --------------------------------------------------------

--
-- Struktur dari tabel `car_types`
--

CREATE TABLE `car_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `car_types`
--

INSERT INTO `car_types` (`id`, `name`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Sedan', 'active', '2025-10-24 13:21:23', '2026-01-07 08:49:08'),
(2, 'Hatchback', 'active', '2025-11-23 13:21:23', '2026-01-07 08:49:08'),
(3, 'SUV', 'active', '2025-10-24 13:21:23', '2026-01-07 08:49:08'),
(4, 'MPV', 'active', '2025-10-24 13:21:23', '2026-01-07 08:49:08'),
(10, 'Minivan', 'active', '2025-11-24 02:46:54', '2026-01-07 08:49:08'),
(13, 'Crossover', 'active', '2025-11-24 02:57:45', '2026-01-07 08:49:08'),
(20, 'Wagon', 'active', '2026-01-05 15:28:55', '2026-01-07 08:49:08');

-- --------------------------------------------------------

--
-- Struktur dari tabel `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `address` text DEFAULT NULL,
  `id_card` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `customers`
--

INSERT INTO `customers` (`id`, `name`, `phone`, `address`, `id_card`, `created_at`, `updated_at`) VALUES
(1, 'Ian Iswahyudi', '083836497311', 'Ampera Barat', '1335676860784756', '2025-10-06 07:01:37', '2025-11-28 13:30:09'),
(2, 'Asep Hidayat', '089663970747', 'Jl. Ahmad Yani, Kelurahan Mancogeh, Kecamatan Cipedes, Kota Tasikmalaya', '4263752352324756', '2025-10-08 02:21:51', '2025-10-08 02:21:51'),
(3, 'Haji Mustofa', '083857336732', 'Jl. Cendrawasih No. 47, Kel. Nagarawangi, Kec. Cihideung, Kota Tasikmalaya, Jawa Barat 46123', '1335676860153622', '2025-10-23 03:47:53', '2025-11-03 07:35:52'),
(5, 'Setia Budi', '083308236522', 'Jl. Bojong Tengah No.46, Cipedes, Kec. Cipedes, Kab. Tasikmalaya, Jawa Barat 46133', '3270164050020017', '2025-11-27 12:25:04', '2025-11-27 12:25:04'),
(6, 'Rafid Halim', '087621767992', 'Jl Jend A Yani 2, Jawa Barat, Bandung, 40262', '1335676020250012', '2025-11-28 13:42:05', '2025-11-28 13:42:05'),
(7, 'Yayan Ariyanto', '085842334673', 'Jl Bima 137, Bandung, Jawa Barat, 40172', '2200498220300012', '2025-11-30 07:24:40', '2025-11-30 07:24:40'),
(8, 'Muhammad Zaenal', '083847108421', 'Mancogeh, Tasikmalaya', '1303153340340013', '2025-12-02 04:21:35', '2025-12-02 04:21:35'),
(9, 'Ahmad Gozali', '089683901466', 'Jl. Peta, Kecamatan Tawang, Kota Tasikmalaya', '0284669203120013', '2025-12-06 02:26:44', '2025-12-06 02:26:44'),
(10, 'Denny Anwar', '085268302991', 'Jl. AH Nasution, Mangkubumi, Kec. Mangkubumi, Kab. Tasikmalaya, Jawa Barat 46418', '1335610260010013', '2025-12-09 05:18:49', '2025-12-09 05:18:49'),
(11, 'Dede Rohayati', '089157280993', 'Desa Sukajadi, Kecamatan Sadananya, Kab. Ciamis, Jawa Barat', '1335673110700016', '2025-12-11 12:33:11', '2025-12-11 12:33:11'),
(12, 'Ibnu Saputra', '081319103447', 'Jl. Ibu Apipah No.23, RT.004, Kahuripan, Kec. Tawang, Kota Tasikmalaya, Jawa Barat 46115', '1332370800280018', '2025-12-16 06:34:35', '2025-12-16 06:34:35'),
(13, 'Arif Hidayatullah', '0815230340559', 'Jl. Kapten Naseh No.14, Panglayungan, Kec. Cipedes, Kota Tasikmalaya, Jawa Barat 46134', '1335026002380032', '2025-12-18 07:31:49', '2025-12-18 07:31:49'),
(14, 'Muhammad Idris', '089260139441', 'Jl. HOS. Cokroaminoto No.41-43, Arjuna, Kec. Cicendo, Kota Bandung, Jawa Barat 40172', '1330200402800019', '2025-12-23 09:19:16', '2025-12-23 09:19:16'),
(15, 'Muhammad Andriani', '081578401337', 'Jl. Raya Banjar - Pangandaran, RT.3/RW.1, Babakan, Kec. Pangandaran, Kab. Pangandaran, Jawa Barat 46396', '1330100540701117', '2025-12-27 03:43:48', '2025-12-27 03:43:48'),
(16, 'Yudi Hamzah', '089724401013', 'Jl. Paseh No.91, Tuguraja, Kec. Cihideung, Kota Tasikmalaya, Jawa Barat 46125', '1330302060101119', '2025-12-27 03:48:14', '2025-12-27 03:48:14'),
(18, 'Arif Nurjaman', '0852601239447', 'Jl. Mayor S.L. Tobing, Tugujaya, Kec. Cihideung, Kab. Tasikmalaya, Jawa Barat 46126', '1330054530010005', '2025-12-31 04:26:07', '2025-12-31 04:26:07'),
(19, 'Bayu Supriadi', '089348514900', 'Jl. Panglayungan II No.15, Cipedes, Kec. Cipedes, Kota Tasikmalaya, Jawa Barat 46133', '1339101040310114', '2026-01-04 07:13:40', '2026-01-08 10:54:55'),
(22, 'Sarah Septiani', '0815864301515', 'Tasikmalaya', '1335676860093511', '2026-01-11 02:20:10', '2026-01-11 02:20:10'),
(23, 'Aditya Nugraha', '085321218490', 'Tasikmalaya', '1335691860700125', '2026-01-11 02:27:34', '2026-01-11 02:27:34'),
(24, 'Ayu Jaelani', '08956231515', 'Tasikmalaya', '1314316360122305', '2026-01-11 04:32:36', '2026-01-11 04:32:36'),
(25, 'Dani Firdaus', '081543651280', 'Tasik', '1335676101035559', '2026-01-12 22:22:57', '2026-01-12 22:22:57');

-- --------------------------------------------------------

--
-- Struktur dari tabel `models`
--

CREATE TABLE `models` (
  `id` int(11) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `car_type_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `models`
--

INSERT INTO `models` (`id`, `brand_id`, `car_type_id`, `name`, `status`, `created_at`, `updated_at`) VALUES
(2, 6, 2, '3 Hatchback', 'active', '2025-10-08 02:12:38', '2025-10-08 02:12:55'),
(4, 7, 1, '320i Sport', 'active', '2025-10-23 02:54:10', '2025-12-27 03:16:37'),
(5, 2, 1, 'Accord', 'active', '2025-10-23 03:06:35', '2025-10-23 03:06:35'),
(6, 2, 2, 'Brio', 'active', '2025-10-23 03:17:44', '2025-10-23 03:17:44'),
(7, 1, 2, 'Yaris', 'active', '2025-10-23 03:31:44', '2025-11-26 04:05:08'),
(8, 1, 3, 'Fortuner', 'active', '2025-10-23 03:41:44', '2025-10-23 03:41:44'),
(10, 5, 3, 'Pajero Sport', 'active', '2025-10-24 02:49:49', '2025-10-24 02:49:49'),
(11, 1, 4, 'Vellfire', 'active', '2025-11-06 12:08:09', '2025-12-01 15:04:56'),
(13, 1, 13, 'Raize GR', 'active', '2025-11-24 03:02:13', '2025-11-24 03:02:43'),
(15, 1, 4, 'Voxy', 'active', '2025-11-24 03:09:51', '2025-11-24 03:09:51'),
(16, 1, 4, 'Innova G', 'active', '2025-11-24 03:17:12', '2025-11-24 03:17:12'),
(17, 1, 4, 'Innova G Luxury', 'active', '2025-11-24 03:20:15', '2025-11-24 03:20:15'),
(18, 2, 2, 'Jazz RS', 'active', '2025-11-24 03:22:44', '2025-11-24 03:22:44'),
(19, 1, 3, 'Rush S TRD', 'active', '2025-11-24 03:26:03', '2025-11-24 03:26:03'),
(20, 5, 3, 'Pajero Dakar', 'active', '2025-11-24 03:28:44', '2025-11-24 03:28:44'),
(21, 1, 2, 'Yaris S TRD', 'active', '2025-11-26 04:04:05', '2025-11-26 04:04:05'),
(22, 7, 1, '328i Sport', 'active', '2025-12-01 04:09:06', '2025-12-01 15:15:29'),
(23, 2, 3, 'HR-V', 'active', '2025-12-01 05:34:26', '2025-12-01 15:15:29'),
(25, 5, 3, 'Outlander', 'active', '2025-12-01 06:02:43', '2025-12-01 15:15:29'),
(26, 12, 2, 'Ignis', 'active', '2025-12-01 06:08:17', '2025-12-01 15:15:29'),
(29, 14, 2, 'Rio', 'active', '2025-12-13 02:41:43', '2025-12-13 02:41:43'),
(30, 6, 3, 'CX-5', 'active', '2025-12-13 02:53:56', '2025-12-13 02:53:56'),
(35, 1, 4, 'Innova Zenix', 'active', '2026-01-04 07:03:30', '2026-01-04 07:03:30'),
(36, 26, 3, 'X-Trail', 'active', '2026-01-06 01:10:27', '2026-01-06 01:37:58'),
(37, 12, 3, 'XL7', 'active', '2026-01-06 01:41:04', '2026-01-06 01:46:33'),
(41, 1, 4, 'Veloz', 'active', '2026-01-10 13:01:17', '2026-01-10 13:01:17'),
(44, 8, 3, 'Creta', 'active', '2026-01-11 01:48:58', '2026-01-11 01:58:22'),
(46, 5, 4, 'Xpander', 'active', '2026-01-11 01:48:58', '2026-01-11 01:59:05'),
(47, 8, 4, 'Stargazer', 'active', '2026-01-11 01:48:58', '2026-01-11 01:58:15'),
(48, 6, 3, 'CX-5 Elite', 'active', '2026-01-11 02:35:57', '2026-01-11 02:35:57'),
(49, 14, 3, 'Seltos', 'active', '2026-01-11 02:35:57', '2026-01-11 02:41:58'),
(50, 17, 3, 'Everest', 'active', '2026-01-11 02:35:57', '2026-01-11 02:37:46');

-- --------------------------------------------------------

--
-- Struktur dari tabel `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `transaction_code` varchar(50) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sale_price` decimal(15,2) NOT NULL,
  `discount` decimal(15,2) DEFAULT 0.00,
  `tax` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank_transfer','credit','installment') NOT NULL,
  `leasing_company_id` int(11) DEFAULT NULL,
  `payment_status` enum('pending','partial','paid') DEFAULT 'pending',
  `payment_type` enum('cash','credit','leasing') DEFAULT 'cash',
  `transaction_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `transactions`
--

INSERT INTO `transactions` (`id`, `transaction_code`, `vehicle_id`, `customer_id`, `user_id`, `sale_price`, `discount`, `tax`, `total_amount`, `payment_method`, `leasing_company_id`, `payment_status`, `payment_type`, `transaction_date`, `notes`, `created_at`, `updated_at`) VALUES
(12, 'INV/20251024/7437', 9, 3, 1, 534000000.00, 1000000.00, 0.00, 533000000.00, 'cash', NULL, 'paid', 'cash', '2025-10-24', 'Transaksi dilakukan secara tunai (cash) antara pembeli dan pihak showroom.\r\nUnit mobil dalam kondisi baru dan siap serah terima, dengan nomor rangka dan mesin telah diperiksa sesuai dokumen resmi.\r\nPembeli telah melunasi seluruh pembayaran sesuai harga yang disepakati tanpa ada tunggakan.\r\nShowroom telah menyerahkan faktur, STNK, BPKB, dan buku servis lengkap kepada pembeli.\r\nKendaraan dinyatakan bebas dari sengketa, banjir, maupun kerusakan, serta telah melalui pemeriksaan standar sebelum penyerahan.\r\nProses transaksi selesai dengan tanda tangan kedua belah pihak sebagai bukti sah pembelian tunai.', '2025-10-24 02:38:26', '2026-01-05 11:18:20'),
(13, 'INV/20251103/5050', 4, 1, 1, 527000000.00, 1000000.00, 0.00, 526000000.00, 'cash', NULL, 'paid', 'cash', '2025-11-03', '', '2025-11-03 07:38:46', '2026-01-05 11:18:20'),
(14, 'INV/20251106/8406', 5, 3, 1, 134000000.00, 500000.00, 0.00, 133500000.00, 'cash', NULL, 'paid', 'cash', '2025-11-05', '', '2025-11-06 11:48:42', '2026-01-05 11:18:20'),
(15, 'INV/20251106/6446', 6, 3, 1, 150000000.00, 0.00, 0.00, 150000000.00, 'cash', NULL, 'paid', 'cash', '2025-11-06', '', '2025-11-06 11:50:26', '2026-01-05 11:18:20'),
(16, 'INV/20251106/6458', 7, 2, 1, 108000000.00, 2000000.00, 0.00, 106000000.00, 'bank_transfer', NULL, 'paid', 'cash', '2025-11-04', '', '2025-11-06 11:58:35', '2026-01-05 11:18:20'),
(17, 'INV/20251116/2640', 11, 1, 1, 446000000.00, 0.00, 0.00, 446000000.00, 'cash', NULL, 'paid', 'cash', '2025-11-14', '', '2025-11-16 08:23:08', '2026-01-05 11:18:20'),
(27, 'INV/20251127/6667', 19, 5, 1, 169000000.00, 0.00, 700000.00, 169700000.00, 'cash', NULL, 'paid', 'cash', '2025-11-27', 'Transaksi ini terdapat biaya lain sebesar Rp700.000 yang mencakup keperluan administrasi tambahan dan proses pengurusan dokumen kendaraan.', '2025-11-27 12:27:27', '2026-01-05 11:18:20'),
(29, 'INV/20251128/3161', 17, 6, 1, 211000000.00, 1000000.00, 500000.00, 210500000.00, 'bank_transfer', NULL, 'paid', 'cash', '2025-11-28', '', '2025-11-28 13:43:56', '2026-01-05 11:18:20'),
(33, 'INV/20251130/2578', 22, 7, 1, 182000000.00, 0.00, 500000.00, 182500000.00, 'installment', 1, 'pending', 'cash', '2025-11-30', '', '2025-11-30 07:25:36', '2026-01-05 11:18:20'),
(37, 'INV/20251202/8113', 25, 8, 2, 183000000.00, 0.00, 500000.00, 183500000.00, 'installment', 5, 'pending', 'cash', '2025-12-02', '', '2025-12-02 12:34:14', '2026-01-05 11:18:20'),
(38, 'INV/20251206/1736', 8, 9, 1, 215000000.00, 0.00, 700000.00, 215700000.00, 'bank_transfer', NULL, 'paid', 'cash', '2025-12-06', '', '2025-12-06 02:27:48', '2026-01-05 11:18:20'),
(58, 'INV/20251211/0492', 15, 10, 2, 246000000.00, 1000000.00, 700000.00, 245700000.00, 'installment', 8, 'pending', 'cash', '2025-12-09', '', '2025-12-11 12:38:12', '2026-01-05 11:18:20'),
(59, 'INV/20251211/7459', 21, 11, 2, 258000000.00, 500000.00, 0.00, 257500000.00, 'cash', NULL, 'paid', 'cash', '2025-12-11', '', '2025-12-11 12:39:26', '2026-01-05 11:18:20'),
(60, 'INV/20251216/7153', 24, 12, 1, 274000000.00, 1000000.00, 700000.00, 273700000.00, 'bank_transfer', NULL, 'paid', 'cash', '2025-12-16', '', '2025-12-16 06:35:53', '2026-01-05 11:18:20'),
(61, 'INV/20251218/2774', 18, 13, 1, 164000000.00, 0.00, 700000.00, 164700000.00, 'installment', 10, 'pending', 'cash', '2025-12-18', '', '2025-12-18 07:32:42', '2026-01-05 11:18:20'),
(62, 'INV/20251223/1727', 29, 11, 1, 126000000.00, 1000000.00, 700000.00, 125700000.00, 'bank_transfer', NULL, 'paid', 'cash', '2025-12-20', '', '2025-12-23 09:17:17', '2026-01-05 11:18:20'),
(63, 'INV/20251223/7894', 14, 14, 1, 218000000.00, 0.00, 700000.00, 218700000.00, 'installment', 9, 'pending', 'cash', '2025-12-23', '', '2025-12-23 09:20:20', '2026-01-05 11:18:20'),
(65, 'INV/20251227/2018', 33, 16, 1, 110000000.00, 0.00, 700000.00, 110700000.00, 'installment', 1, 'pending', 'cash', '2025-12-27', '', '2025-12-27 03:48:53', '2026-01-05 11:18:20'),
(66, 'INV/20251227/0254', 16, 15, 1, 369000000.00, 0.00, 700000.00, 369700000.00, 'bank_transfer', NULL, 'paid', 'cash', '2025-12-25', '', '2025-12-27 03:49:50', '2026-01-05 11:18:20'),
(68, 'INV/20251231/5455', 26, 18, 1, 305000000.00, 0.00, 700000.00, 305700000.00, 'installment', 4, 'pending', 'cash', '2025-12-31', '', '2025-12-31 04:27:18', '2026-01-05 11:18:20'),
(70, 'INV/20260104/9149', 20, 19, 1, 197000000.00, 0.00, 700000.00, 197700000.00, 'bank_transfer', NULL, 'paid', 'cash', '2026-01-04', '', '2026-01-04 07:14:15', '2026-01-05 11:18:20'),
(80, 'INV/20260111/8251', 12, 22, 1, 825000000.00, 3000000.00, 700000.00, 822700000.00, 'installment', 4, '', 'cash', '2026-01-11', '', '2026-01-11 02:24:09', '2026-01-11 02:24:09'),
(83, 'INV/20260111/5128', 41, 23, 1, 240000000.00, 0.00, 700000.00, 240700000.00, 'installment', 1, '', 'cash', '2026-01-08', '', '2026-01-11 02:28:10', '2026-01-11 02:28:10'),
(86, 'INV/20260111/1954', 49, 24, 1, 163000000.00, 0.00, 700000.00, 163700000.00, 'bank_transfer', NULL, 'paid', 'cash', '2026-01-05', '', '2026-01-11 04:34:26', '2026-01-11 04:34:26'),
(92, 'INV/20260112/7432', 40, 25, 1, 197000000.00, 0.00, 0.00, 197000000.00, 'cash', NULL, 'paid', 'cash', '2026-01-12', '', '2026-01-12 22:23:21', '2026-01-12 22:23:21');

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text NOT NULL,
  `role` enum('admin','manager','staff') DEFAULT 'staff',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `phone`, `address`, `role`, `status`, `created_at`, `updated_at`, `photo`) VALUES
(1, 'Admin', '$2y$10$MIGYc7K.P7OorvCRlGnIROPCOGRNyWuufB6NFSTrRU8gedQds6eQW', 'Admin', '089663970747', 'Panglayungan, Kec. Cipedes, Kota Tasikmalaya, Jawa Barat 46134', 'admin', 'active', '2025-10-24 13:20:12', '2026-01-06 10:12:39', '69267219e8880.jpeg'),
(2, 'staff1', '$2y$10$GD5YidPQyjsW.EruqO7.7eHMiFJPBYFHMQ12cuXJAekI37aw80wJi', 'Staff', '083836497311', 'babakan cikiara', 'staff', 'active', '2025-11-05 13:20:12', '2025-12-27 04:02:27', '69267250a4ab8.jpeg'),
(7, 'budi13', '$2y$10$ceibIDFbGM9OQEHx00eJ8OKQQKEVm9OQXSeZ6l3MEbA0y2478cmiy', 'Budi', '085268302991', 'bojong', 'staff', 'active', '2025-12-12 09:41:03', '2025-12-12 11:58:39', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `model_id` int(11) NOT NULL,
  `year` int(4) DEFAULT NULL,
  `chassis_number` varchar(50) NOT NULL,
  `engine_number` varchar(50) NOT NULL,
  `license_plate` varchar(20) DEFAULT NULL,
  `color` varchar(50) NOT NULL,
  `mileage` int(11) DEFAULT 0,
  `fuel_type` enum('gasoline','petrol','diesel','hybrid','electric') NOT NULL,
  `transmission` enum('manual','automatic','cvt') NOT NULL,
  `purchase_price` decimal(15,2) NOT NULL,
  `selling_price` decimal(15,2) NOT NULL,
  `purchase_date` date NOT NULL,
  `condition_rating` enum('excellent','good','fair','poor') DEFAULT 'good',
  `notes` text DEFAULT NULL,
  `images` text DEFAULT NULL,
  `status` enum('available','sold','maintenance','reserved') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `warranty_type` varchar(20) NOT NULL DEFAULT 'none',
  `warranty_period` int(11) DEFAULT NULL,
  `warranty_notes` text DEFAULT NULL,
  `warranty_expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data untuk tabel `vehicles`
--

INSERT INTO `vehicles` (`id`, `model_id`, `year`, `chassis_number`, `engine_number`, `license_plate`, `color`, `mileage`, `fuel_type`, `transmission`, `purchase_price`, `selling_price`, `purchase_date`, `condition_rating`, `notes`, `images`, `status`, `created_at`, `updated_at`, `warranty_type`, `warranty_period`, `warranty_notes`, `warranty_expiry_date`) VALUES
(4, 2, 2022, 'JKT0022XE43539', 'GEN-8847877', 'D 4632 ML', 'Hitam', 10000, 'gasoline', 'automatic', 512000000.00, 517000000.00, '2025-10-22', 'excellent', '', '[\"68f8ab62adce5.jpg\"]', 'sold', '2025-10-22 09:38:42', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(5, 4, 2011, 'WBG3311X9E2BEE', 'B48-3150228', 'B 38 NIS', 'Hitam', 35000, 'gasoline', 'automatic', 130000000.00, 134000000.00, '2025-10-23', 'good', 'Mobil dalam kondisi baik dan terawat, mesin halus, transmisi otomatis responsif, serta suspensi masih nyaman. Interior bersih dan semua fitur berfungsi normal, termasuk AC, audio, dan power window.\r\nCat eksterior masih mulus dengan sedikit gores pemakaian wajar. Kaki-kaki tidak ada bunyi, sistem rem bekerja normal.\r\nService rutin dilakukan tepat waktu di bengkel resmi/non-resmi terpercaya, riwayat servis tersedia.\r\nBan masih tebal, aki baru diganti.\r\nSurat-surat lengkap (STNK, BPKB asli) dan pajak hidup.\r\nTidak pernah terendam banjir atau tabrakan berat.', '[\"68f99ac7eae21.jpg\"]', 'sold', '2025-10-23 03:02:31', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(6, 5, 2012, 'MHRZP12XC457EC', 'L15-8789495', 'B 50 FYA', 'Putih', 11000, 'gasoline', 'automatic', 148000000.00, 150000000.00, '2025-10-23', 'excellent', 'Kondisi mobil sangat baik dan terawat, mesin halus, transmisi otomatis perpindahan gigi lancar, serta suspensi masih empuk dan stabil. Interior bersih, jok kulit rapi tanpa sobek, AC dingin, dan semua fitur seperti audio, power window, serta lampu berfungsi normal.\r\nEksterior mulus dengan beberapa gores pemakaian wajar, cat masih orisinal. Kaki-kaki tidak ada bunyi, sistem pengereman normal.\r\nService rutin dilakukan tepat waktu, riwayat servis tersedia. Ban masih tebal, aki baru, dan oli baru diganti.\r\nSurat-surat lengkap (STNK, BPKB asli), pajak hidup.\r\nMobil bebas banjir dan bebas tabrakan berat.', '[\"68f99d2b196dc.jpg\"]', 'sold', '2025-10-23 03:12:43', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(7, 6, 2017, 'MHRZP17X4CEDE0', 'L15-9772025', 'D 1196 AFS', 'Putih', 34000, 'gasoline', 'manual', 103000000.00, 108000000.00, '2025-10-14', 'excellent', 'Kondisi mobil sangat baik dan siap pakai. Mesin halus, responsif, dan tidak ada kendala pada transmisi manual/CVT. Suspensi empuk, setir ringan, dan kaki-kaki senyap tanpa bunyi.\r\nInterior bersih, jok rapi, AC dingin, serta semua fitur seperti audio, power window, dan lampu berfungsi normal.\r\nEksterior masih mulus dengan sedikit gores pemakaian wajar, cat orisinal pabrik.\r\nService rutin dilakukan di bengkel resmi, oli dan filter baru diganti. Ban masih tebal, aki dalam kondisi prima.\r\nSurat-surat lengkap (STNK, BPKB asli), pajak hidup.', '[\"68f99fb4a098a.jpg\",\"68f99fd39a535.jpeg\"]', 'sold', '2025-10-23 03:20:35', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(8, 7, 2024, 'MHF1124XEF33DE', '1NR-8236296', 'D 2235 FMA', 'Merah', 23000, 'gasoline', 'automatic', 174000000.00, 215000000.00, '2025-10-17', 'excellent', 'Kondisi mobil sangat terawat dan siap pakai. Mesin halus, transmisi matic responsif, dan kaki-kaki senyap.\r\nInterior bersih, AC dingin, fitur kelistrikan berfungsi normal.\r\nEksterior mulus dengan pemakaian wajar, cat orisinal.\r\nService rutin di bengkel resmi, ban masih tebal, aki baru.\r\nSurat-surat lengkap, pajak hidup, tidak ada riwayat tabrakan berat.', '[\"692296dd56208.jpeg\"]', 'sold', '2025-10-23 03:36:22', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(9, 8, 2024, 'MHF1124XD28F5B', '1NR-7048349', 'B 33 FAM', 'Hitam', 25000, 'gasoline', 'automatic', 527000000.00, 534000000.00, '2025-10-20', 'excellent', 'Kondisi mobil sangat prima dan seperti baru. Mesin diesel bertenaga, transmisi halus, dan suspensi empuk.\r\nInterior bersih, fitur Toyota Safety Sense berfungsi normal, AC dingin, dan semua sistem kelistrikan aktif.\r\nEksterior mulus tanpa cacat, cat orisinal pabrik.\r\nService rutin di bengkel resmi, surat-surat lengkap, tidak ada riwayat tabrakan.', '[\"68f9a4a808da3.jpeg\"]', 'sold', '2025-10-23 03:44:40', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(11, 10, 2023, 'MKMNC23X891AE9', '4A9-2256498', 'D 101 YAS', 'Hitam', 31000, 'gasoline', 'automatic', 444000000.00, 446000000.00, '2025-10-24', 'excellent', 'Mobil dalam kondisi sangat baik dan siap pakai. Mesin diesel halus, responsif, dan bebas kebocoran.\r\nTransmisi otomatis berpindah gigi dengan lancar, sistem 4WD berfungsi normal, serta suspensi terasa empuk dan stabil.\r\nInterior bersih, jok kulit terawat, dan semua fitur seperti AC, kamera 360°, layar hiburan, serta sistem keamanan aktif berfungsi sempurna.\r\nEksterior mulus tanpa penyok atau gores besar, cat hitam masih mengilap seperti baru.\r\nService rutin dilakukan di bengkel resmi Mitsubishi, surat-surat kendaraan lengkap, tidak ada riwayat tabrakan.', '[\"68faea06f210c.jpg\"]', 'sold', '2025-10-24 02:52:54', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(12, 11, 2023, 'MHF1123X5350E8', '1NR-1640993', 'B 100 MA', 'Hitam', 35000, 'gasoline', 'automatic', 790000000.00, 825000000.00, '2025-11-05', 'excellent', 'Mobil dalam kondisi sangat baik, mesin halus dan transmisi normal.\r\nInterior mewah terawat, semua fitur premium seperti pintu geser otomatis, AC, dan sistem hiburan berfungsi dengan baik.\r\nEksterior mulus, cat orisinal, bebas banjir dan tabrakan.\r\nKelengkapan dokumen lengkap (STNK, BPKB, buku servis, kunci cadangan).\r\nPerlu diperhatikan: periksa rutin motor pintu geser, sistem AC, dan kaki-kaki, karena komponen ini sering jadi titik rawan pada Vellfire.', '[\"692daeddc5ee1.jpg\"]', 'sold', '2025-11-06 13:00:25', '2026-01-11 02:24:09', 'dealer', 12, 'Garansi mencakup mesin, transmisi, AC, dan kaki-kaki', NULL),
(13, 8, 2024, 'MHF1124XDDB412', '1NR-7664436', 'D 1122 MAH', 'Putih', 80000, 'gasoline', 'automatic', 513000000.00, 523000000.00, '2025-11-19', 'excellent', '', '[\"6923d48a9a0bb.jpg\"]', 'available', '2025-11-20 05:22:27', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(14, 13, 2023, 'MHF1123XF0778D', '1NR-4950246', 'B 426 CDE', 'Merah', 50000, 'gasoline', 'automatic', 207000000.00, 218000000.00, '2025-11-24', 'excellent', 'Mobil kondisi baik, bebas tabrak/banjir, kilometer rendah. Kelengkapan umumnya lengkap: buku servis, buku manual, kunci cadangan, dan dokumen lengkap. Interior memakai aksen GR, fitur seperti head unit besar, AC digital, keyless, serta fitur keselamatan TSS pada varian tertentu. Exterior dilengkapi velg 17 inci dan body kit GR Sport.', '[\"6923d36fc2c42.jpg\"]', 'sold', '2025-11-24 03:39:27', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(15, 5, 2014, 'MHRZP14X92E0DF', 'L15-3468031', 'B 789 RFI', 'Hitam', 73000, 'gasoline', 'automatic', 232000000.00, 246000000.00, '2025-11-23', 'excellent', 'Mobil dalam kondisi sangat baik dengan mesin halus dan suspensi nyaman. Interior masih rapi dengan fitur lengkap seperti AC dual zone, layar i-MID, serta smart key. Dokumen kendaraan lengkap, termasuk STNK, BPKB, buku servis, dan kunci cadangan.', '[\"6923d6819df05.jpg\"]', 'sold', '2025-11-24 03:52:33', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(16, 15, 2017, 'MHF1117XE07CD6', '1NR-7473973', 'B 2420 BZN', 'Putih', 56000, 'gasoline', 'automatic', 357000000.00, 369000000.00, '2025-11-24', 'excellent', 'Kondisi Umum:\r\nMobil dalam kondisi terawat, bodi mulus tanpa bekas tabrak besar, cat masih orisinal di sebagian besar panel. Mesin halus, respons akselerasi normal, dan tidak ada kebocoran oli. Suspensi empuk, kaki-kaki senyap, serta transmisi matic berpindah dengan lembut.\r\n\r\nInterior:\r\nKabinnya bersih dan rapi, jok fabric/semikulit tanpa sobek, doortrim utuh, dan plafon bersih. Semua fitur bekerja normal: AC digital dual zone dingin, layar head unit berfungsi baik, dan pencahayaan interior lengkap.\r\n\r\nKelengkapan:\r\n• Kunci masih lengkap (kunci asli + cadangan smart key)\r\n• Buku servis &amp;amp; manual tersedia\r\n• Ban cadangan, dongkrak, dan tool kit lengkap\r\n• STNK &amp;amp; BPKB asli, pajak hidup\r\n• Kamera mundur + sensor parkir aktif\r\n• Jok baris tengah captain seat orisinal', '[\"6923d898b49d5.jpg\"]', 'sold', '2025-11-24 04:01:16', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(17, 16, 2010, 'MHF1110X54D4CE', '1NR-1664040', 'Z 593 YTZ', 'Silver', 105000, 'gasoline', 'automatic', 205000000.00, 211000000.00, '2025-11-24', 'good', 'Kondisi Umum:\r\nMobil dalam kondisi layak pakai dan terawat untuk usia pemakaian. Mesin halus, tidak ada suara aneh, dan tarikan masih normal. Transmisi matic berpindah dengan baik, serta tidak ditemukan kebocoran oli. Kaki-kaki masih nyaman, meski ada sedikit gejala wajar karena usia kendaraan.\r\n\r\nInterior:\r\nKabinnya rapi, jok kain masih utuh tanpa sobekan besar, dashboard bersih, dan plafon tidak kendur. AC dingin, blower normal, dan seluruh tombol-tombol pada dashboard masih berfungsi. Audio standar bekerja dengan baik.\r\n\r\nKelengkapan:\r\n• Kunci utama + cadangan lengkap\r\n• Ban cadangan, dongkrak, dan tool kit tersedia\r\n• STNK &amp; BPKB asli\r\n• Sensor parkir aktif\r\n• Karpet dasar dan karpet lantai lengkap', '[\"6923db0d5e8f5.jpg\"]', 'sold', '2025-11-24 04:11:57', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(18, 17, 2014, 'MHF1114XE597C0', '1NR-3213665', 'B 2549 KGX', 'Putih', 110000, 'gasoline', 'manual', 155000000.00, 164000000.00, '2025-11-24', 'good', 'Kondisi Umum:\r\nMobil dalam keadaan terawat, mesin responsif dan halus, perpindahan gigi manual ringan tanpa selip, serta tidak ada kebocoran oli. Kaki-kaki nyaman dan stabil, suspensi empuk khas Innova. Sistem kelistrikan normal dan indikator di dashboard berfungsi.\r\n\r\nInterior:\r\nKabinnya bersih dan rapi dengan jok kulit bawaan tipe Luxury. AC dingin, double blower bekerja baik, dashboard tidak retak, doortrim utuh. Seluruh fitur interior berfungsi seperti audio, lampu kabin, dan power window.\r\n\r\nKelengkapan:\r\n• Kunci utama + cadangan\r\n• STNK &amp; BPKB asli\r\n• Ban cadangan + tool kit lengkap\r\n• Karpet dasar, karpet lantai, dan cover jok Luxury\r\n• Sensor parkir aktif', '[\"6923df2db8257.jpg\"]', 'sold', '2025-11-24 04:29:33', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(19, 18, 2015, 'MHRZP15X73D48B', 'L15-5130526', 'Z 464 YRE', 'Hitam', 75000, 'gasoline', 'automatic', 158000000.00, 169000000.00, '2025-11-25', 'excellent', 'Kondisi Umum:\r\nMesin halus dan responsif, transmisi matic bekerja mulus tanpa jeda atau bunyi aneh. Kaki-kaki stabil, suspensi empuk, dan tidak ada kebocoran fluida. Sistem kelistrikan normal dan semua indikator dashboard berfungsi dengan baik.\r\n\r\nInterior:\r\nKabin bersih dengan jok sport RS, AC dingin, head unit berfungsi, tombol-tombol di setir responsif, serta power window dan central lock normal. Bagian interior tidak ada sobek besar atau bau menyengat.\r\n\r\nKelengkapan:\r\n• STNK &amp;amp; BPKB asli\r\n• Kunci utama + cadangan\r\n• Ban cadangan + tool kit\r\n• Karpet lantai, dongkrak, dan segitiga pengaman\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada tanda bekas banjir atau tabrakan besar\r\n• Ban masih layak pakai\r\n• Pajak hidup\r\n• Sistem rem, AC, dan CVT dalam kondisi baik\r\n• Rangka, lantai, dan body bebas keropos berlebihan', '[\"692d737fcf0c8.jpg\"]', 'sold', '2025-11-26 03:47:23', '2026-01-11 02:15:53', 'none', NULL, '', NULL),
(20, 19, 2019, 'MHF1119X883145', '1NR-7382362', 'B 1386 FTW', 'Putih', 60000, 'gasoline', 'manual', 182000000.00, 197000000.00, '2025-11-25', 'excellent', 'Kondisi Umum:\r\nMobil dalam kondisi terawat, mesin halus dan responsif, perpindahan gigi manual ringan, serta tidak ada kebocoran oli. Kaki-kaki stabil, suspensi nyaman, dan sistem kelistrikan berfungsi normal. Fitur keselamatan seperti ABS, airbag, dan Vehicle Stability Control bekerja sebagaimana mestinya.\r\n\r\nInterior:\r\nKabinnya bersih dengan jok fabric rapi, AC double blower dingin, head unit touchscreen berfungsi baik, serta tombol-tombol kontrol interior berjalan normal. Dashboard dan doortrim utuh tanpa kerusakan.\r\n\r\nKelengkapan:\r\n• STNK &amp;amp; BPKB asli\r\n• Kunci utama + cadangan\r\n• Ban cadangan + tool kit lengkap\r\n• Karpet lantai, segitiga pengaman, dongkrak\r\n• Sensor &amp;amp; kamera parkir aktif\r\n\r\nHal Penting Lain:\r\n• Kilometer rendah\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Pajak hidup/2029\r\n• Kondisi ban masih layak\r\n• Sistem rem, AC, dan steering dalam keadaan baik\r\n• Rangka dan bodi bebas keropos atau bekas perbaikan besar', '[\"692d2cf494093.jpg\"]', 'sold', '2025-11-26 03:55:11', '2026-01-11 02:15:53', 'none', NULL, '', NULL),
(21, 20, 2015, 'MKMNC15XDF6E74', '4A9-7868697', 'Z 457 LUA', 'Hitam', 80000, 'gasoline', 'automatic', 240000000.00, 258000000.00, '2025-11-26', 'good', 'Kondisi Umum:\r\nMesin masih halus dan responsif, transmisi matic berpindah mulus, serta tidak ada kebocoran oli. Suspensi empuk dan stabil, kaki-kaki kuat, dan seluruh sistem kelistrikan berfungsi baik. Fitur seperti cruise control, traction control, ABS, dan airbag bekerja normal.\r\n\r\nInterior:\r\nKabin bersih, jok kulit rapi, AC double blower dingin, head unit berfungsi, dan tombol setir serta fitur MID berjalan normal. Dashboard dan doortrim utuh tanpa retak atau kerusakan.\r\n\r\nKelengkapan:\r\n• STNK &amp;amp;amp; BPKB asli\r\n• Kunci utama + cadangan\r\n• Ban cadangan + tool kit lengkap\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n• Kamera dan sensor parkir aktif\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Pajak hidup/tahun 2027\r\n• Kondisi ban masih layak pakai\r\n• Sistem rem, AC, dan 4WD \r\n• Rangka, lantai, dan bodi bebas keropos berlebihan', '[\"692b2dd430439.jpg\"]', 'sold', '2025-11-26 04:01:34', '2026-01-11 02:15:53', 'none', NULL, NULL, NULL),
(22, 21, 2019, 'MHF1119XBB8EB4', '1NR-7900168', 'D 692 GFV', 'Hitam', 30000, 'gasoline', 'automatic', 170000000.00, 182000000.00, '2025-11-25', 'excellent', 'Kondisi Umum:\r\nMobil dalam kondisi terawat, mesin 1.5L halus dan responsif, transmisi matic berpindah mulus tanpa jeda, serta tidak ada kebocoran oli. Kaki-kaki stabil, suspensi nyaman, dan seluruh sistem kelistrikan berfungsi normal. Fitur keselamatan seperti ABS, EBD, VSC, HSA, dan airbag lengkap berjalan baik.\r\n\r\nInterior:\r\nKabin bersih dan rapi, jok fabric sport TRD terawat, AC digital dingin, head unit touchscreen berfungsi baik, serta semua tombol setir dan fitur interior bekerja normal. Dashboard dan doortrim utuh tanpa retak atau lecet besar.\r\n\r\nKelengkapan:\r\n• STNK &amp;amp; BPKB asli\r\n• Kunci utama + cadangan\r\n• Ban cadangan + tool kit\r\n• Karpet lantai, segitiga pengaman, dongkrak\r\n• Kamera belakang &amp;amp; sensor parkir aktif\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Pajak hidup/tahun 2029\r\n• Kondisi ban masih layak pakai\r\n• Fitur keselamatan lengkap berfungsi\r\n• Rangka, lantai, dan bodi bebas keropos dan bebas perbaikan besar', '[\"692bdb081d60f.jpg\"]', 'sold', '2025-11-26 04:11:02', '2026-01-11 02:15:53', 'dealer', 6, 'Garansi mencakup mesin, transmisi, dan AC.', NULL),
(24, 22, 2013, 'WBG3313XECEF79', 'B48-2007371', 'D 729 TRW', 'Merah', 46000, 'gasoline', 'automatic', 268000000.00, 274000000.00, '2025-11-30', 'excellent', 'Spesifikasi:\r\nBMW 328i Sport 2013 dibekali mesin 2.0L TwinPower Turbo dengan tenaga sekitar 184 HP, transmisi otomatis 8-percepatan yang halus, dan penggerak roda belakang (RWD). Hadir dengan karakter sporty, handling presisi, serta interior premium khas seri 3.\r\n\r\nKondisi Umum:\r\nMesin turbo masih halus dan responsif, transmisi matic berpindah gigi mulus tanpa hentakan, serta tidak ditemukan kebocoran oli. Kaki-kaki dan suspensi stabil, fitur kelistrikan bekerja normal, dan seluruh sensor serta indikator dashboard berfungsi baik. Mode berkendara seperti Comfort, Sport, dan Eco Pro berjalan dengan normal.\r\n\r\nKelengkapan:\r\n• STNK dan BPKB\r\n• Kunci utama + cadangan\r\n• Ban cadangan\r\n• Tool kit bawaan BMW\r\n• Sensor parkir dan dashcam\r\n\r\nHal Penting Lain:\r\n• Riwayat servis sangat penting, terutama oli mesin dan transmisi\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Suspensi dan kaki-kaki BMW perlu dicek rutin\r\n• Pajak hidup\r\n• Kondisi ban layak jalan\r\n• Sistem rem ABS, kontrol traksi, dan stability control berfungsi\r\n• Interior kulit dan dashboard terawat, tidak ada kerusakan besar', '[\"692d2324c2d32.jpg\"]', 'sold', '2025-12-01 04:22:37', '2026-01-11 02:15:53', 'none', NULL, '', NULL),
(25, 11, 2010, 'MHF1110XE8C9D3', '1NR-2389338', 'F 515 AE', 'Hitam', 110000, 'gasoline', 'automatic', 175000000.00, 183000000.00, '2025-12-01', 'good', 'Spesifikasi:\r\nToyota Vellfire 2010 dibekali mesin 2.4L atau 3.5L (umumnya 2.4L di Indonesia), transmisi matic CVT, kapasitas 7–8 penumpang, serta fitur kenyamanan premium. Dikenal sebagai MPV mewah dengan kabin luas, sliding door elektrik, dan suspensi yang nyaman.\r\n\r\nKondisi Umum:\r\nMesin berjalan halus, transmisi matic perpindahan lancar, dan tidak ada kebocoran oli. Fitur kelistrikan berfungsi normal, termasuk sliding door elektrik, lampu otomatis, dan sensor parkir. Suspensi empuk, kabin senyap, serta AC dual zone + rear blower dingin. Sistem audio dan panel kontrol berfungsi baik.\r\n\r\nInterior:\r\nKabin sangat luas dengan jok captain seat, kulit masih rapi, plafon bersih, dan fitur hiburan bekerja baik. Dashboard serta doortrim utuh tanpa kerusakan.\r\n\r\nKelengkapan:\r\n• STNK dan BPKB\r\n• Kunci utama + cadangan (smart key)\r\n• Tool kit lengkap dan ban cadangan\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n• Remote sliding door dan fitur elektrik aktif\r\n\r\nHal Penting Lain:\r\n• Cek kondisi sliding door elektrik (komponen sensitif)\r\n• Pastikan tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Sistem AC depan–belakang harus bekerja optimal\r\n• Pemeriksaan sistem suspensi dan kaki-kaki penting karena usia kendaraan\r\n• Pajak aktif/tahun 2026\r\n• Kilometer sesuai pemakaian secara wajar', '[\"692d2313aa0c8.jpg\"]', 'sold', '2025-12-01 05:09:39', '2026-01-11 02:15:53', 'third_party', 6, 'Garansi mencakup mesin, transmisi dan kaki-kaki', NULL),
(26, 23, 2025, 'MHRZP25X4259F8', 'L15-6679298', 'Z 935 TLA', 'Putih', 15000, 'gasoline', 'cvt', 281000000.00, 305000000.00, '2025-11-28', 'excellent', 'Spesifikasit:\r\nHonda HR-V 2025 hadir sebagai SUV modern dengan mesin bensin berteknologi efisien (1.5L), transmisi CVT yang halus, serta desain eksterior sporty. Dilengkapi fitur keselamatan canggih dari Honda Sensing dan interior yang dirancang nyaman untuk 5 penumpang.\r\n\r\nKondisi Umum:\r\nKondisi mesin responsif, transmisi CVT bekerja mulus, dan tidak terdapat kebocoran fluida. Fitur kelistrikan berfungsi baik, termasuk sistem Honda Sensing (jika tersedia), sensor parkir, dan kamera belakang. Suspensi stabil dan kabin bebas bunyi mencurigakan. AC dingin serta panel instrumen berfungsi normal.\r\n\r\nKelengkapan:\r\n• STNK dan BPKB\r\n• Kunci utama + cadangan (smart key)\r\n• Ban cadangan + tool kit lengkap\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n• Kamera belakang, sensor parkir, dan fitur hiburan aktif\r\n\r\nHal Penting Lain:\r\n• Kilometer rendah\r\n• Tidak ada tanda bekas banjir atau tabrakan besar\r\n• Ban layak pakai\r\n• Sistem rem, ABS, brake assist, serta stability control berfungsi normal\r\n• Pajak dan status administrasi lengkap\r\n• Rangka, lantai, serta bodi bebas keropos dan perbaikan besar', '[\"692d2adba85a6.jpg\"]', 'sold', '2025-12-01 05:42:51', '2026-01-11 02:15:53', 'manufacturer', 8, 'Garansi mencakup mesin, oli, AC, dan kaki-kaki', NULL),
(28, 25, 2016, 'MKMNC16X4FCE55', '4A9-6338784', 'B 1030 SJQ', 'Merah', 90000, 'gasoline', 'automatic', 165000000.00, 176000000.00, '2025-12-01', 'good', 'Spesifikasi:\r\nOutlander PX 2016 dibekali mesin 2.0L MIVEC, transmisi otomatis CVT, penggerak FWD, dan kapasitas 5 penumpang. Dikenal dengan handling stabil, ground clearance cukup tinggi, serta desain sporty khas SUV kompak.\r\n\r\nKondisi Umum:\r\nMesin berjalan halus dan responsif, transmisi matic berpindah mulus, serta tidak ditemukan kebocoran oli. Kaki-kaki stabil, suspensi nyaman, dan sistem kelistrikan bekerja normal. Fitur seperti paddle shift, ABS, airbag, sensor parkir, dan AC berfungsi baik. Kabin senyap dan tidak ada bunyi mencurigakan dari area kaki-kaki.\r\n\r\nInterior:\r\nInterior bersih, jok rapi, AC dingin, head unit berfungsi normal, dan seluruh tombol pada dashboard serta setir dapat digunakan dengan baik. Dashboard dan doortrim utuh tanpa retakan atau kerusakan besar.\r\n\r\nKelengkapan:\r\n• STNK dan BPKB\r\n• Kunci utama + cadangan\r\n• Ban cadangan + tool kit lengkap\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n• Kamera mundur &amp;amp;amp; sensor parkir aktif (tergantung unit)\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Kondisi ban masih layak pakai\r\n• Sistem rem, ABS, dan stability control berjalan normal\r\n• Suspensi perlu diperhatikan mengingat usia kendaraan\r\n• Pajak hidup/tahun 2030\r\n• Rangka dan bodi bebas perbaikan besar atau keropos', '[\"692d30a705d47.jpg\"]', 'maintenance', '2025-12-01 06:07:35', '2026-01-11 02:15:53', 'dealer', 5, 'Garansi mencakup mesin dan transmisi', NULL),
(29, 26, 2022, 'MHYZE22XB319BF', 'K15-3452308', 'B 1245 BMY', 'Biru', 72000, 'gasoline', 'automatic', 118000000.00, 126000000.00, '2025-11-27', 'excellent', 'Spesifikasi:\r\nSuzuki Ignis 2022 menggunakan mesin 1.2L yang irit, transmisi matic, dan penggerak FWD. Desain compact dan efisien, cocok untuk mobil harian dengan kabin cukup lega untuk 5 penumpang.\r\n\r\nKondisi Umum:\r\nMesin halus dan hemat bahan bakar, transmisi matic berpindah normal sesuai karakteristiknya, serta tidak terdapat kebocoran oli. Suspensi nyaman, kaki-kaki stabil, dan seluruh sistem kelistrikan berfungsi baik. AC dingin, panel instrumen bekerja normal, serta head unit berfungsi tanpa kendala.\r\n\r\nInterior:\r\nKabin bersih dan rapi, jok dalam kondisi baik, dashboard dan doortrim utuh tanpa kerusakan. Seluruh tombol interior, AC, serta fitur hiburan bekerja normal.\r\n\r\nKelengkapan:\r\n• STNK dan BPKB\r\n• Kunci utama + cadangan\r\n• Ban cadangan + tool kit\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n• Kamera belakang &amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp;amp; sensor parkir\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Kondisi ban masih layak jalan\r\n• Transmisi AGS perlu pengecekan rutin agar tetap halus\r\n• Pajak hidup/tahun 2027\r\n• Rangka dan bodi bebas keropos dan perbaikan besar', '[\"692fbc93bef32.jpg\"]', 'sold', '2025-12-01 06:14:14', '2026-01-11 02:15:53', 'dealer', 5, 'Garansi mencakup mesin dan transmisi.', NULL),
(33, 29, 2015, 'JKT0015X03F691', 'GEN-9104908', 'B 823 TAG', 'Hitam', 110000, 'gasoline', 'automatic', 97000000.00, 110000000.00, '2025-12-13', 'good', 'Spesifikasi Singkat:\r\nKIA Rio 2015 dibekali mesin bensin 1.4L yang responsif dan irit, transmisi otomatis, penggerak FWD, serta desain hatchback yang sporty dan praktis untuk penggunaan harian.\r\n\r\nKondisi Umum:\r\nMesin berjalan halus, transmisi matic berpindah dengan baik, serta tidak ditemukan kebocoran oli. Suspensi stabil, kaki-kaki senyap, dan sistem kelistrikan berfungsi normal. AC dingin, panel instrumen jelas, serta fitur dasar keselamatan bekerja baik.\r\n\r\nInterior:\r\nKabin bersih dan rapi, jok masih terawat tanpa sobekan besar, dashboard dan doortrim utuh. Sistem audio, power window, dan central lock berfungsi normal.\r\n\r\nKelengkapan:\r\n• STNK &amp; BPKB asli\r\n• Kunci utama + cadangan\r\n• Ban cadangan + tool kit lengkap\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Kondisi ban masih layak pakai\r\n• Sistem rem dan steering dalam kondisi baik\r\n• Pajak hidup/tahun 2030\r\n• Rangka dan bodi bebas keropos serta perbaikan besar', '[\"693cd484749f2.jpg\"]', 'sold', '2025-12-13 02:50:44', '2026-01-11 02:15:53', 'dealer', 3, 'garansi mencakup transmisi dan kaki-kaki.', NULL),
(34, 30, 2019, 'JKT0019XA56A19', 'GEN-4664517', 'D 154 BPA', 'Merah', 41000, 'gasoline', 'automatic', 286000000.00, 298000000.00, '2025-12-13', 'excellent', 'Spesifikasi Singkat:\r\nMazda CX-5 2019 menggunakan mesin 2.0L atau 2.5L SKYACTIV-G, transmisi otomatis 6-percepatan, penggerak AWD dan dikenal dengan handling presisi serta desain premium khas Mazda.\r\n\r\nKondisi Umum:\r\nMesin responsif dan halus, transmisi matic berpindah mulus tanpa hentakan, serta tidak ada kebocoran oli. Suspensi stabil dan nyaman, kaki-kaki senyap, serta sistem kelistrikan berfungsi normal. Fitur keselamatan seperti ABS, DSC, TCS, dan airbag bekerja baik. AC dingin dan kabin senyap.\r\n\r\nInterior:\r\nInterior bersih dan rapi dengan jok kulit, dashboard dan doortrim utuh, serta head unit layar besar berfungsi normal. Seluruh tombol di setir, panel kontrol, dan sistem audio berjalan baik.\r\n\r\nKelengkapan:\r\n• STNK dan BPKB\r\n• Kunci utama + cadangan (smart key)\r\n• Ban cadangan + tool kit lengkap\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n• Kamera belakang &amp;amp;amp;amp;amp;amp;amp; sensor parkir aktif\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Kondisi ban masih layak pakai\r\n• Pastikan servis rutin mesin &amp;amp;amp;amp;amp;amp;amp; transmisi tercatat\r\n• Pajak hidup/tahun 2029\r\n• Rangka, lantai, dan bodi bebas keropos serta perbaikan besar', '[\"693cd694b93a1.jpg\"]', 'available', '2025-12-13 02:59:32', '2026-01-11 02:15:53', 'dealer', 5, 'garansi mencakup mesin, transmisi, dan AC', NULL),
(39, 35, 2023, 'MHF1123X99C180', '1NR-3897833', 'B 49 MTO', 'Hitam', 34000, 'gasoline', 'cvt', 349000000.00, 374000000.00, '2026-01-03', 'excellent', 'Toyota Innova Zenix 2023 Hybrid dilengkapi mesin bensin bertenaga dengan teknologi hybrid yang efisien, dipadukan dengan transmisi otomatis CVT. MPV ini menawarkan ruang kabin luas untuk 7–8 penumpang, kenyamanan berkendara tinggi, serta efisiensi bahan bakar lebih baik dibanding varian non-hybrid.\r\n\r\nKondisi Umum:\r\nMesin hybrid dan sistem CVT bekerja halus, responsif, dan hemat bahan bakar. Tidak ditemukan kebocoran oli atau masalah signifikan pada sistem drivetrain hybrid. Suspensi stabil dan nyaman, sistem kelistrikan berfungsi normal, serta fitur keselamatan standar bekerja sesuai fungsi. AC dingin dan kabin senyap.\r\n\r\nInterior:\r\nKabin bersih dan rapi dengan ornamen premium, jok nyaman (bisa fabric/semikulit tergantung unit), dashboard serta doortrim utuh tanpa kerusakan besar. Head unit, panel kontrol, dan seluruh tombol interior berfungsi baik.\r\n\r\nKelengkapan:\r\n• STNK dan BPKB\r\n• Kunci utama + cadangan (smart key)\r\n• Ban cadangan + tool kit lengkap\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n• Kamera belakang &amp;amp; sensor parkir aktif\r\n• Fitur keselamatan seperti TSS (Toyota Safety Sense) aktif (jika terpasang)\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Kondisi ban masih layak pakai\r\n• Pajak hidup/tahun 2028\r\n• Rangka, lantai, dan bodi bebas keropos serta bekas perbaikan besar\r\n• Sistem hybrid perlu pengecekan komponen baterai &amp;amp; ECU sesuai servis', '[\"695a12836f533.jpg\"]', 'available', '2026-01-04 07:10:59', '2026-01-11 02:15:53', 'dealer', 3, '', NULL),
(40, 36, 2019, 'MNKCL19X5ECB75', 'HR1-5876761', 'B 269 MQT', 'Hitam', 64000, 'gasoline', 'automatic', 180300000.00, 197000000.00, '2026-01-06', 'good', 'Spesifikasi Singkat:\r\nNissan X-Trail 2019 menggunakan mesin bensin 2.0L atau 2.5L, dipadukan dengan transmisi otomatis. SUV ini menawarkan kabin luas berkapasitas 5–7 penumpang, kenyamanan berkendara, serta fitur keselamatan yang cukup lengkap.\r\n\r\nKondisi Umum:\r\nMesin bekerja halus dan responsif, transmisi matic berpindah mulus tanpa hentakan, serta tidak ditemukan kebocoran oli. Suspensi stabil dan nyaman, kaki-kaki senyap, dan sistem kelistrikan berfungsi normal. AC dingin, panel instrumen jelas, serta fitur keselamatan seperti ABS, VDC, dan airbag berjalan baik.\r\n\r\nInterior:\r\nInterior bersih dan rapi, jok terawat tanpa sobekan besar, dashboard dan doortrim utuh. Head unit berfungsi normal, tombol setir responsif, dan seluruh fitur kabin dapat digunakan dengan baik.\r\n\r\nKelengkapan:\r\n• STNK dan BPKB \r\n• Kunci utama + cadangan (smart key)\r\n• Ban cadangan + tool kit lengkap\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n• Kamera belakang dan sensor parkir aktif\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Kondisi ban masih layak pakai\r\n• Pastikan perawatan rutin CVT terdokumentasi\r\n• Pajak hidup/tahun 2029\r\n• Rangka, lantai, dan bodi bebas keropos serta perbaikan besar', '[\"695c6766ef4ad.jpg\"]', 'sold', '2026-01-06 01:37:42', '2026-01-12 22:23:21', 'none', NULL, '', NULL),
(41, 37, 2024, 'MHYZE24X64BA12', 'K15-2713768', 'B 2381 KRJ', 'Putih', 7000, 'gasoline', 'manual', 232000000.00, 240000000.00, '2026-01-03', 'excellent', 'Spesifikasi Singkat:\r\nSuzuki XL7 2024 ditenagai mesin bensin 1.5L dengan transmisi manual 5-percepatan, penggerak FWD, dan kapasitas sekitar 7 penumpang. SUV-like MPV ini dikenal hemat bahan bakar, kabin lapang, serta desain yang kekinian.\r\n\r\nKondisi Umum:\r\nMesin halus dengan respons yang baik, perpindahan gigi manual ringan dan presisi, serta tidak ditemukan kebocoran oli. Suspensi nyaman dan stabil, sistem kelistrikan bekerja normal, dan seluruh indikator dashboard aktif tanpa kendala. AC dingin dan kabin bersih.\r\n\r\nInterior:\r\nKabin rapi dan bersih, jok fabric terawat, dashboard serta doortrim utuh tanpa kerusakan besar. Fitur hiburan seperti head unit touchscreen dan konektivitas Bluetooth/USB berfungsi tanpa masalah. Power window dan central lock berjalan normal.\r\n\r\nKelengkapan:\r\n• STNK &amp; BPKB asli\r\n• Kunci utama + cadangan\r\n• Ban cadangan + tool kit lengkap\r\n• Buku servis &amp; buku manual (jika tersedia)\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n• Kamera belakang &amp; sensor parkir (jika unit terpasang)\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Kondisi ban masih layak pakai\r\n• Sistem rem, kopling, dan drivetrain manual bekerja baik\r\n• Pajak hidup/tahun 2029\r\n• Rangka, lantai, dan bodi bebas keropos serta perbaikan besar', '[\"695c696cc535f.jpg\"]', 'sold', '2026-01-06 01:46:20', '2026-01-11 02:28:10', 'manufacturer', 5, 'Garansi mencakup mesin, kaki-kaki, dan AC', NULL),
(43, 41, 2023, 'MHF1123X177FC1', '1NR-5148997', 'B 1315 HKJ', 'Putih', 12000, 'gasoline', 'automatic', 240000000.00, 255000000.00, '2026-01-08', 'excellent', 'Spesifikasi Singkat:\r\nToyota Veloz 2023 dilengkapi mesin bensin 1.5L dengan transmisi otomatis, penggerak FWD, dan kapasitas 7 penumpang. MPV ini menawarkan interior luas, efisiensi bahan bakar baik, serta fitur keselamatan dan kenyamanan modern.\r\n\r\nKondisi Umum:\r\nMesin responsif dan halus, transmisi matic berpindah mulus tanpa hentakan signifikan. Tidak ada indikasi kebocoran oli atau masalah pada sistem pendingin. Suspensi nyaman dan stabil, serta sistem kelistrikan berfungsi normal. AC dingin dan kabin relatif senyap.\r\n\r\nInterior:\r\nInterior bersih dan rapi, jok fabric/semikulit terawat, serta dashboard serta doortrim tanpa kerusakan besar. Head unit layar sentuh dan sistem audio berfungsi baik, serta AC belakang (blowers) aktif.\r\n\r\nKelengkapan:\r\n• STNK dan BPKB\r\n• Kunci utama + cadangan (smart key)\r\n• Ban cadangan + tool kit lengkap\r\n• Buku manual dan buku servis\r\n• Karpet lantai, dongkrak, segitiga pengaman\r\n• Kamera belakang dan sensor parkir aktif\r\n\r\nHal Penting Lain:\r\n• Kilometer sesuai pemakaian\r\n• Tidak ada indikasi bekas banjir atau tabrakan besar\r\n• Kondisi ban masih layak pakai\r\n• Fitur keselamatan seperti ABS, EBD, dan VSC berfungsi dengan baik\r\n• Pajak hidup/tahun 2026\r\n• Rangka, lantai, dan bodi bebas keropos dan perbaikan besar', '[\"6962505c72e68.jpg\"]', 'available', '2026-01-10 13:13:00', '2026-01-11 02:15:53', 'dealer', 3, 'garansi mencakup mesin dan kaki-kaki', NULL),
(49, 6, 2022, 'MHRZP22X598D1C', 'L15-1163614', 'B 2064 POW', 'Putih', 103000, 'gasoline', 'automatic', 147000000.00, 163000000.00, '2026-01-01', 'good', '', '[\"69630476071d0.jpg\"]', 'sold', '2026-01-10 13:51:28', '2026-01-11 04:46:26', 'dealer', 2, 'Garansi mencakup mesin', NULL),
(56, 44, 2022, 'MHXSU22X66161F', 'G4F-6580390', 'D 1226 AKG', 'Merah', 22000, 'gasoline', 'automatic', 268000000.00, 285000000.00, '2026-01-09', 'excellent', 'Hyundai Creta Prime IVT 2022, Fitur lengkap Hyundai Bluelink, Sunroof, Speaker BOSE.', '[\"696306881da67.jpg\"]', 'available', '2026-01-11 01:48:58', '2026-01-12 10:57:33', 'dealer', 2, 'Garansi mencakup mesin dan AC', NULL),
(58, 46, 2021, 'MKMNC21X9C91B8', '4A9-4103558', 'B 1065 CZU', 'Hitam', 70000, 'gasoline', 'automatic', 210000000.00, 226000000.00, '2026-01-03', 'excellent', 'Mitsubishi Xpander Ultimate 2020 Facelift, Kabin luas, Suspensi nyaman.', '[\"696305ad7bdfc.jpg\"]', 'available', '2026-01-11 01:48:58', '2026-01-11 02:15:53', 'none', NULL, '', NULL),
(59, 47, 2023, 'MHXSU23X63E94F', 'G4F-5009615', 'B 7890 HKI', 'Silver', 25000, 'gasoline', 'automatic', 190000000.00, 223000000.00, '2026-01-11', 'excellent', 'Hyundai Stargazer Prime 6-Seater Captain Seat 2023, Nyaman untuk keluarga.', '[\"696303a59783b.jpg\"]', 'available', '2026-01-11 01:48:58', '2026-01-12 10:57:43', 'dealer', 3, 'Mesin dan Kaki-kaki', NULL),
(60, 48, 2021, 'JMZKF23XA825EF', 'PE-VPS-1519464', 'F 1197 FAS', 'Putih', 75000, 'gasoline', 'automatic', 305000000.00, 314000000.00, '2026-01-11', 'excellent', 'Mazda CX-5 Elite 2023, Soul Red Crystal, Low KM, Service Record Mazda.', '[\"69630f67187ef.jpg\"]', 'available', '2026-01-11 02:35:57', '2026-01-11 02:48:07', 'dealer', 3, 'Garansi mencakup mesin dan AC', NULL),
(61, 49, 2022, 'KNASP22X756D4E', 'G4KD-2312811', 'D 1258 HKJ', 'Hitam', 33000, 'gasoline', 'automatic', 225000000.00, 239000000.00, '2026-01-11', 'excellent', 'Kia Seltos GT Line 1.4 Turbo, Sunroof, Leather Seat, Sangat Terawat.', '[\"69630e9971eb7.jpg\"]', 'available', '2026-01-11 02:35:57', '2026-01-11 02:44:41', 'none', NULL, '', NULL),
(62, 50, 2014, 'MNBXX21XD3CD4F', 'YN2S-5120039', 'D 8055 FRD', 'Black', 135000, 'diesel', 'automatic', 205000000.00, 218000000.00, '2026-01-06', 'good', 'Ford Everest Titanium 2.0 Bi-Turbo 4x4, Gagah, Siap Offroad, Interior Mewah.', '[\"69630db7b7ccc.jpg\"]', 'available', '2026-01-11 02:35:57', '2026-01-12 23:12:12', 'none', NULL, '', NULL);

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `brands`
--
ALTER TABLE `brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indeks untuk tabel `car_types`
--
ALTER TABLE `car_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indeks untuk tabel `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_card` (`id_card`);

--
-- Indeks untuk tabel `models`
--
ALTER TABLE `models`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_model` (`brand_id`,`car_type_id`,`name`),
  ADD KEY `car_type_id` (`car_type_id`);

--
-- Indeks untuk tabel `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_code` (`transaction_code`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indeks untuk tabel `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `chassis_number` (`chassis_number`),
  ADD UNIQUE KEY `engine_number` (`engine_number`),
  ADD UNIQUE KEY `license_plate` (`license_plate`),
  ADD KEY `model_id` (`model_id`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT untuk tabel `car_types`
--
ALTER TABLE `car_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT untuk tabel `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT untuk tabel `models`
--
ALTER TABLE `models`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT untuk tabel `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=95;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT untuk tabel `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `models`
--
ALTER TABLE `models`
  ADD CONSTRAINT `models_ibfk_1` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `models_ibfk_2` FOREIGN KEY (`car_type_id`) REFERENCES `car_types` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`model_id`) REFERENCES `models` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
