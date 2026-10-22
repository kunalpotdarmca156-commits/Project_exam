<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: login.html');
    exit;
}

function back($status, $msg) {
    header('Location: teacher_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=profile');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: teacher_dashboard.php');
    exit;
}

$teacher_id = $_SESSION['teacher_id'];

// ---- handle image uploads (avatar or banner) ----
function handle_upload($fieldName, $subfolder, $teacherId) {
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null; // nothing uploaded, not an error
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($_FILES[$fieldName]['tmp_name']);
    if (!isset($allowed[$mime])) {
        return false; // invalid type
    }
    $dir = __DIR__ . '/uploads/' . $subfolder . '/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $filename = 'teacher_' . $teacherId . '_' . time() . '.' . $allowed[$mime];
    $destPath = $dir . $filename;
    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $destPath)) {
        return false;
    }
    return 'uploads/' . $subfolder . '/' . $filename;
}

// If this request is just an image upload (from the avatar/banner click), handle it and return.
if (!empty($_FILES['avatar']['name']) || !empty($_FILES['banner']['name'])) {
    if (!empty($_FILES['avatar']['name'])) {
        $path = handle_upload('avatar', 'avatars', $teacher_id);
        if ($path === false) back('error', 'Avatar must be a JPG, PNG, WEBP, or GIF image.');
        if ($path) {
            $stmt = $conn->prepare("UPDATE teachers SET profile_pic = ? WHERE id = ?");
            $stmt->bind_param("si", $path, $teacher_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    if (!empty($_FILES['banner']['name'])) {
        $path = handle_upload('banner', 'banners', $teacher_id);
        if ($path === false) back('error', 'Banner must be a JPG, PNG, WEBP, or GIF image.');
        if ($path) {
            $stmt = $conn->prepare("UPDATE teachers SET banner_image = ? WHERE id = ?");
            $stmt->bind_param("si", $path, $teacher_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    $conn->close();
    back('success', 'Profile picture updated.');
}

// ---- otherwise this is the info form (name/email/password) ----
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if ($full_name === '' || $email === '') {
    back('error', 'Please fill in your name and email.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back('error', 'Enter a valid email.');
}

$check = $conn->prepare("SELECT id FROM teachers WHERE email = ? AND id <> ?");
$check->bind_param("si", $email, $teacher_id);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    back('error', 'Another account already uses this email.');
}
$check->close();

if ($new_password !== '' || $confirm_password !== '') {
    if ($new_password !== $confirm_password) {
        back('error', 'New passwords do not match.');
    }
    if (strlen($new_password) < 6) {
        back('error', 'New password must be at least 6 characters.');
    }
    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE teachers SET full_name = ?, email = ?, password = ? WHERE id = ?");
    $stmt->bind_param("sssi", $full_name, $email, $hashed, $teacher_id);
} else {
    $stmt = $conn->prepare("UPDATE teachers SET full_name = ?, email = ? WHERE id = ?");
    $stmt->bind_param("ssi", $full_name, $email, $teacher_id);
}
$stmt->execute();
$stmt->close();

$_SESSION['teacher_name'] = $full_name;
$conn->close();

back('success', 'Profile updated successfully.');
