<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

$uploadDir = dirname(__DIR__) . '/uploads/';

// Create upload directory if not exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST method required']);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
$folder = isset($_POST['folder']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['folder']) : 'general';

// Validate file
$maxSize = 10 * 1024 * 1024; // 10MB
if ($file['size'] > $maxSize) {
    echo json_encode(['error' => 'File too large. Max 10MB']);
    exit;
}

$allowedTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'application/zip' => 'zip',
    'application/x-zip-compressed' => 'zip'
];

$mimeType = mime_content_type($file['tmp_name']);
if (!isset($allowedTypes[$mimeType])) {
    echo json_encode(['error' => 'File type not allowed. Allowed: jpg, png, gif, webp, zip']);
    exit;
}

$ext = $allowedTypes[$mimeType];
$targetDir = $uploadDir . $folder . '/';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

// Generate unique filename
$filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$targetPath = $targetDir . $filename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $publicUrl = $baseUrl . '/uploads/' . $folder . '/' . $filename;

    echo json_encode([
        'success' => true,
        'url' => $publicUrl,
        'filename' => $filename,
        'size' => $file['size'],
        'type' => $ext
    ]);
} else {
    echo json_encode(['error' => 'Failed to save file']);
}
