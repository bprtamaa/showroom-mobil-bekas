<?php
// config/config.php

// BASE URL aplikasi
if (!defined('BASE_URL')) {
    define('BASE_URL', '/showroom/');
}

// Nama aplikasi
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Aplikasi Manajemen Showroom');
}

// Path assets
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', BASE_URL . 'assets/');
}

// Path uploads
if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', dirname(__DIR__) . '/uploads/');
}

// URL to access uploads from web
if (!defined('UPLOADS_URL')) {
    define('UPLOADS_URL', BASE_URL . 'uploads/');
}

// Include koneksi database
require_once __DIR__ . '/database.php';

// Start output buffering before session to prevent headers already sent errors
if (!ob_get_level()) {
    ob_start();
}

// Mulai session jika belum
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Otomatis logout setelah 5 menit (300 detik) inaktivitas
if (isset($_SESSION['user_id'])) {
    $timeout_duration = 300; // 5 menit
    
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
        // Hapus semua data session
        session_unset();
        session_destroy();
        
        // Restart session untuk menampilkan pesan
        session_start();
        $_SESSION['alert'] = [
            'message' => 'Sesi Anda telah berakhir. Silakan login kembali.',
            'type' => 'warning'
        ];
        
        // Redirect ke halaman login
        header('Location: ' . BASE_URL . 'auth/login.php');
        exit;
    }
    
    // Update waktu aktivitas terakhir
    $_SESSION['last_activity'] = time();
}

// Default pagination: jumlah records per halaman
if (!defined('RECORDS_PER_PAGE')) {
    define('RECORDS_PER_PAGE', 10);
}

// Global Currency & Input Helper Functions
if (!function_exists('parseCurrency')) {
    function parseCurrency($value) {
        return (int)preg_replace('/[^0-9]/', '', (string)$value);
    }
}

if (!function_exists('formatCurrency')) {
    function formatCurrency($value) {
        $n = is_numeric($value) ? (float)$value : 0.0;
        return 'Rp ' . number_format($n, 0, ',', '.');
    }
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($str) {
        return htmlspecialchars(trim((string)$str), ENT_QUOTES, 'UTF-8');
    }
}