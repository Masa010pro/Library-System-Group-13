<?php
require_once '../includes/auth_check.php';
require_roles(['admin', 'librarian', 'student']);
require_once '../config/database.php';
require_once '../includes/ebooks.php';

if (($_SESSION['role'] ?? '') === 'student') {
    $memberId = (int)($_SESSION['member_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT member_id FROM members WHERE member_id = ? AND status = 'active'");
    $stmt->execute([$memberId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        exit('Only active student members can read online editions.');
    }
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$bookId || $bookId < 1) {
    http_response_code(404);
    exit('Book not found.');
}

$stmt = $pdo->prepare('SELECT digital_file_path FROM books WHERE book_id = ?');
$stmt->execute([$bookId]);
$storedPath = $stmt->fetchColumn();
$filePath = ebook_resolve_path($storedPath);
if ($filePath === null) {
    http_response_code(404);
    exit('No online edition is available for this book.');
}

$extension = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
$contentType = $extension === 'pdf' ? 'application/pdf' : 'application/epub+zip';
$fileSize = filesize($filePath);
$handle = fopen($filePath, 'rb');
if ($fileSize === false || $handle === false) {
    http_response_code(500);
    exit('The online edition could not be opened.');
}

$start = 0;
$end = $fileSize - 1;
$statusCode = 200;
$range = trim($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '') {
    if (preg_match('/\Abytes=(\d*)-(\d*)\z/', $range, $matches) !== 1
        || ($matches[1] === '' && $matches[2] === '')
        || $fileSize < 1) {
        fclose($handle);
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }

    if ($matches[1] === '') {
        $suffixLength = (int)$matches[2];
        if ($suffixLength < 1) {
            fclose($handle);
            http_response_code(416);
            header('Content-Range: bytes */' . $fileSize);
            exit;
        }
        $start = max(0, $fileSize - $suffixLength);
    } else {
        $start = (int)$matches[1];
        if ($matches[2] !== '') {
            $end = (int)$matches[2];
        }
    }

    if ($start >= $fileSize || $start > $end) {
        fclose($handle);
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }

    $end = min($end, $fileSize - 1);
    $statusCode = 206;
}

$contentLength = $end - $start + 1;
http_response_code($statusCode);
header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="library-book.' . $extension . '"');
header('Content-Length: ' . $contentLength);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
if ($statusCode === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
    fseek($handle, $start);
    $remaining = $contentLength;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
}

fclose($handle);
