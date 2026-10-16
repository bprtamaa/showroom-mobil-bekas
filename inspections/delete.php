<?php
// inspections/delete.php
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid (CSRF).', 'danger');
        redirect('inspections/');
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($id) {
        try {
            $stmt = $db->prepare("DELETE FROM vehicle_inspections WHERE id = ?");
            $stmt->execute([$id]);

            showAlert('Laporan inspeksi kendaraan berhasil dihapus.', 'success');
        } catch (PDOException $e) {
            showAlert('Gagal menghapus laporan inspeksi: ' . $e->getMessage(), 'danger');
        }
    } else {
        showAlert('ID laporan tidak valid.', 'danger');
    }
}

redirect('inspections/');
