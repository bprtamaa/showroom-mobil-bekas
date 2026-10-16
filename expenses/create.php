<?php
// expenses/create.php - Redirect to modal on expenses index
require_once '../config/config.php';
requireLogin();

// Redirect to expenses index with create modal triggered
header("Location: " . BASE_URL . "expenses/?action=create");
exit;
