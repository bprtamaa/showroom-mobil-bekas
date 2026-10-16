<?php
session_start();
require_once '../config/database.php';

// Guard: must be logged in and admin BEFORE any output
if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}
$role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;
if ($role !== 'admin') {
    $_SESSION['error'] = "Anda tidak memiliki akses ke halaman ini!";
    header('Location: ' . BASE_URL . 'dashboard/');
    exit();
}

$db = new Database();
$conn = $db->connect();

$user_id = isset($_GET['id']) ? $_GET['id'] : 0;

// Prevent deleting own account
if ($user_id == ($_SESSION['user_id'] ?? null)) {
    $_SESSION['error'] = "Anda tidak dapat menghapus akun Anda sendiri!";
    header('Location: index.php');
    exit();
}

// Get user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "Pengguna tidak ditemukan!";
    header('Location: index.php');
    exit();
}

// Check if user has transactions
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM transactions WHERE user_id = ?");
$stmt->execute([$user_id]);
$transCount = $stmt->fetch(PDO::FETCH_ASSOC);

if ($transCount['count'] > 0) {
    $_SESSION['error'] = "Tidak dapat menghapus pengguna yang memiliki transaksi! Total transaksi: " . $transCount['count'];
    header('Location: index.php');
    exit();
}

try {
    // Delete photo if exists
    if (!empty($user['photo']) && file_exists('../uploads/users/' . $user['photo'])) {
        unlink('../uploads/users/' . $user['photo']);
    }
    
    // Delete user
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    
    $_SESSION['success'] = "Pengguna berhasil dihapus!";
} catch (PDOException $e) {
    $_SESSION['error'] = "Error: " . $e->getMessage();
}

header('Location: index.php');
exit();
?>
