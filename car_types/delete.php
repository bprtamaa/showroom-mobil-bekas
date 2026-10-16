<?php
// car_types/delete.php
require_once '../config/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../dashboard/');
    exit();
}
// Include header which provides helpers (verifyCSRFToken, showAlert, redirect, requireLogin)
include_once __DIR__ . '/../includes/header.php';

// Ensure user is logged in
requireLogin();

// Only POST method allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    showAlert('Metode request tidak valid', 'danger');
    redirect('car_types/');
}

// Verify CSRF token
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    showAlert('Token keamanan tidak valid', 'danger');
    redirect('car_types/');
}

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    showAlert('ID jenis mobil tidak valid', 'danger');
    redirect('car_types/');
}

try {
    // Get car type data
    $stmt = $db->prepare("SELECT * FROM car_types WHERE id = ?");
    $stmt->execute([$id]);
    $carType = $stmt->fetch();
    
    if (!$carType) {
        showAlert('Jenis mobil tidak ditemukan', 'danger');
        redirect('car_types/');
    }
    
    // Check if has related models
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM models WHERE car_type_id = ?");
    $stmt->execute([$id]);
    $modelCount = $stmt->fetch()['total'];
    
    if ($modelCount > 0) {
        showAlert("Tidak dapat menghapus jenis mobil '{$carType['name']}' karena masih memiliki {$modelCount} model terkait. Hapus semua model terlebih dahulu.", 'danger');
        redirect('car_types/');
    }
    
    // Delete car type
    $stmt = $db->prepare("DELETE FROM car_types WHERE id = ?");
    if ($stmt->execute([$id])) {
        showAlert("Jenis mobil '{$carType['name']}' berhasil dihapus", 'success');
    } else {
        showAlert('Gagal menghapus jenis mobil', 'danger');
    }
    
} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
}

redirect('car_types/');
?>
