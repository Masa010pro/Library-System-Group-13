<?php
require_once '../includes/auth_check.php';
require_roles(['admin', 'librarian']);
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/ebooks.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Use the ebook upload or removal form.');
}

$bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
if (!$bookId || $bookId < 1) {
    set_flash('danger', 'Invalid book.');
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT digital_file_path FROM books WHERE book_id = ?');
$stmt->execute([$bookId]);
$book = $stmt->fetch();
if (!$book) {
    set_flash('danger', 'Book not found.');
    header('Location: index.php');
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    set_flash('danger', 'Your session expired. Please submit the form again.');
    header('Location: edit.php?id=' . $bookId);
    exit;
}

$action = $_POST['action'] ?? '';
if ($action === 'remove') {
    $stmt = $pdo->prepare('UPDATE books SET digital_file_path = NULL WHERE book_id = ?');
    $stmt->execute([$bookId]);

    $oldPath = ebook_resolve_path($book['digital_file_path']);
    if ($oldPath !== null && !unlink($oldPath)) {
        set_flash('warning', 'The edition was removed from the catalogue, but its private file could not be deleted. Contact the administrator.');
    } else {
        set_flash('success', 'Online reading edition removed.');
    }
} elseif ($action === 'upload') {
    $error = '';
    $extension = ebook_validate_upload($_FILES['ebook'] ?? null, $error);
    if ($extension === null) {
        set_flash('danger', $error);
        header('Location: edit.php?id=' . $bookId);
        exit;
    }

    $storageDirectory = ebook_storage_directory();
    if (!is_dir($storageDirectory)
        && !mkdir($storageDirectory, 0750, true)
        && !is_dir($storageDirectory)) {
        set_flash('danger', 'The private ebook folder could not be created. Check server folder permissions.');
        header('Location: edit.php?id=' . $bookId);
        exit;
    }

    $storedPath = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $storageDirectory . DIRECTORY_SEPARATOR . $storedPath;
    if (!move_uploaded_file($_FILES['ebook']['tmp_name'], $destination)) {
        set_flash('danger', 'The ebook could not be saved to private storage. Check server folder permissions.');
        header('Location: edit.php?id=' . $bookId);
        exit;
    }

    try {
        $stmt = $pdo->prepare('UPDATE books SET digital_file_path = ? WHERE book_id = ?');
        $stmt->execute([$storedPath, $bookId]);
    } catch (PDOException $exception) {
        if (is_file($destination)) {
            unlink($destination);
        }
        throw $exception;
    }

    $oldPath = ebook_resolve_path($book['digital_file_path']);
    if ($oldPath !== null && !unlink($oldPath)) {
        set_flash('warning', 'The new edition was uploaded, but the previous private file could not be deleted. Contact the administrator.');
    } else {
        set_flash('success', 'Online reading edition uploaded.');
    }
} else {
    set_flash('danger', 'Invalid ebook action.');
}

header('Location: edit.php?id=' . $bookId);
exit;
