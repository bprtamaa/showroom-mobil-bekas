<?php
// index.php - Root entry point
session_start();

// Check if user is logged in
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // Redirect to dashboard
    header("Location: dashboard/");
} else {
    // Redirect to login
    header("Location: auth/login.php");
}
exit();
?>