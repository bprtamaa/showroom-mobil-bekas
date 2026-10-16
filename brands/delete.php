<?php
// brands/delete.php
require_once '../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . 'auth/login.php');
            exit;
        }
    }
}

if (!function_exists('showAlert')) {
    function showAlert($msg, $type = 'success') {
        $_SESSION['alert'] = [
            'message' => $msg,
            'type' => $type
        ];
    }
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header('Location: ' . BASE_URL . $url);
        exit;
    }
}

if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

requireLogin();

// Check if user is admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    showAlert('Akses ditolak. Hanya admin yang dapat menghapus data merek.', 'danger');
    redirect('dashboard/');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    showAlert('Metode tidak valid', 'danger');
    redirect('brands/');
}

// Verify CSRF token
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    showAlert('Token keamanan tidak valid', 'danger');
    redirect('brands/');
}

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    showAlert('ID merek tidak valid', 'danger');
    redirect('brands/');
}

try {
    // Get brand data
    $stmt = $db->prepare("SELECT * FROM brands WHERE id = ?");
    $stmt->execute([$id]);
    $brand = $stmt->fetch();
    
    if (!$brand) {
        showAlert('Merek tidak ditemukan', 'danger');
        redirect('brands/');
    }
    
    // Check if brand has related models
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM models WHERE brand_id = ?");
    $stmt->execute([$id]);
    $modelCount = $stmt->fetch()['total'];
    
    if ($modelCount > 0) {
        showAlert("Tidak dapat menghapus merek ini karena masih memiliki $modelCount model terkait. Hapus model terlebih dahulu.", 'danger');
        redirect('brands/');
    }
    
    // Delete logo file if exists
    if ($brand['logo'] && file_exists(UPLOADS_PATH . 'brands/' . $brand['logo'])) {
        unlink(UPLOADS_PATH . 'brands/' . $brand['logo']);
    }
    
    // Delete brand
    $stmt = $db->prepare("DELETE FROM brands WHERE id = ?");
    if ($stmt->execute([$id])) {
        showAlert('Merek berhasil dihapus', 'success');
    } else {
        showAlert('Gagal menghapus merek', 'danger');
    }
    
} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
}

redirect('brands/');
?>
