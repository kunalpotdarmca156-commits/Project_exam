<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['student_id'])) {
    header('Location: login.html');
    exit;
}

function back($status, $msg) {
    header('Location: student_dashboard.php?status=' . $status . '&msg=' . urlencode($msg) . '&tab=profile');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: student_dashboard.php');
    exit;
}

$student_id = $_SESSION['student_id'];

// ---- handle image uploads (avatar or banner) ----
function handle_upload($fieldName, $subfolder, $studentId) {
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null; // nothing uploaded, not an error
    }
    $tmpPath = $_FILES[$fieldName]['tmp_name'];

    // getimagesize() doesn't depend on the fileinfo extension, so it's more
    // reliably available across XAMPP installs than mime_content_type().
    $imgInfo = @getimagesize($tmpPath);
    if ($imgInfo === false) {
        return false; // not a valid image
    }
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $ext = $mimeToExt[$imgInfo['mime']] ?? null;
    if (!$ext) {
        return false; // valid image but unsupported type
    }

    $dir = __DIR__ . '/uploads/' . $subfolder . '/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $filename = 'student_' . $studentId . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $destPath = $dir . $filename;
    if (!move_uploaded_file($tmpPath, $destPath)) {
        return false;
    }
    return 'uploads/' . $subfolder . '/' . $filename;
}

// If this request is just an image upload (from the avatar/banner click), handle it and return.
if (!empty($_FILES['avatar']['name']) || !empty($_FILES['banner']['name'])) {
    if (!empty($_FILES['avatar']['name'])) {
        $path = handle_upload('avatar', 'avatars', $student_id);
        if ($path === false) back('error', 'Avatar must be a JPG, PNG, WEBP, or GIF image.');
        if ($path) {
            $stmt = $conn->prepare("UPDATE students SET profile_pic = ? WHERE id = ?");
            $stmt->bind_param("si", $path, $student_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    if (!empty($_FILES['banner']['name'])) {
        $path = handle_upload('banner', 'banners', $student_id);
        if ($path === false) back('error', 'Banner must be a JPG, PNG, WEBP, or GIF image.');
        if ($path) {
            $stmt = $conn->prepare("UPDATE students SET banner_image = ? WHERE id = ?");
            $stmt->bind_param("si", $path, $student_id);
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

if ($full_name === '') {
    back('error', 'Please enter your name.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back('error', 'Enter a valid email, or leave it blank.');
}

if ($email !== '') {
    $check = $conn->prepare("SELECT id FROM students WHERE email = ? AND id <> ?");
    $check->bind_param("si", $email, $student_id);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $check->close();
        back('error', 'Another account already uses this email.');
    }
    $check->close();
}

$emailValue = $email !== '' ? $email : null;

if ($new_password !== '' || $confirm_password !== '') {
    if ($new_password !== $confirm_password) {
        back('error', 'New passwords do not match.');
    }
    if (strlen($new_password) < 6) {
        back('error', 'New password must be at least 6 characters.');
    }
    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE students SET full_name = ?, email = ?, password = ? WHERE id = ?");
    $stmt->bind_param("sssi", $full_name, $emailValue, $hashed, $student_id);
} else {
    $stmt = $conn->prepare("UPDATE students SET full_name = ?, email = ? WHERE id = ?");
    $stmt->bind_param("ssi", $full_name, $emailValue, $student_id);
}
if (!$stmt->execute()) {
    back('error', 'Database error: ' . $stmt->error);
}
$stmt->close();

$_SESSION['student_name'] = $full_name;
$conn->close();

back('success', 'Profile updated successfully.');
