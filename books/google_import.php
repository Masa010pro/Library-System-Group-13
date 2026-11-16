<?php
require_once '../includes/auth_check.php';
require_roles(['admin', 'librarian']);
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/google_books.php';

$search = is_string($_POST['q'] ?? null) ? trim($_POST['q']) : '';
$errors = [];
if (text_length($search) > 100) {
    $errors[] = 'Search terms are too long to return to the results page.';
    $search = '';
}
$redirect = 'index.php?source=google&q=' . rawurlencode($search);
$volumeId = is_string($_POST['volume_id'] ?? null) ? trim($_POST['volume_id']) : '';
$categoryId = is_string($_POST['category_id'] ?? null) ? trim($_POST['category_id']) : '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?source=google');
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Your session expired. Search again and retry the import.';
}
if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $volumeId)) {
    $errors[] = 'The selected Google Books volume is invalid.';
}
if (!ctype_digit($categoryId) || (int)$categoryId < 1) {
    $errors[] = 'Choose a category for this book.';
}

if (!$errors) {
    $stmt = $pdo->prepare('SELECT category_id FROM categories WHERE category_id = ?');
    $stmt->execute([(int)$categoryId]);
    if (!$stmt->fetchColumn()) {
        $errors[] = 'The selected category is no longer available.';
    }
}

$metadata = null;
if (!$errors) {
    try {
        $volume = google_books_api_request('volumes/' . $volumeId);
        $metadata = google_books_extract_metadata($volume);
    } catch (GoogleBooksApiException $exception) {
        $errors[] = $exception->getMessage();
    }
}

if (!$errors) {
    if ($metadata['id'] !== $volumeId || $metadata['title'] === ''
        || text_length($metadata['title']) > 200) {
        $errors[] = 'Google Books returned a missing or invalid title.';
    }
    if ($metadata['isbn'] === null) {
        $errors[] = 'This Google Books result has no valid ISBN and cannot be added to the library.';
    }
    if ($metadata['authors'] && text_length($metadata['authors'][0]) > 100) {
        $errors[] = 'The first author name is too long to add to the library.';
    }
    if (text_length($metadata['publisher']) > 120) {
        $metadata['publisher'] = '';
    }
}

if (!$errors) {
    $stmt = $pdo->prepare(
        "SELECT book_id FROM books
         WHERE REPLACE(REPLACE(isbn, '-', ''), ' ', '') = ? LIMIT 1"
    );
    $stmt->execute([$metadata['isbn']]);
    if ($stmt->fetchColumn()) {
        $errors[] = 'This ISBN is already in the library.';
    }
}

if (!$errors) {
    $authorName = $metadata['authors'][0] ?? 'Unknown author';
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT author_id FROM authors WHERE name = ? LIMIT 1');
        $stmt->execute([$authorName]);
        $authorId = $stmt->fetchColumn();
        if (!$authorId) {
            $stmt = $pdo->prepare('INSERT INTO authors (name) VALUES (?)');
            $stmt->execute([$authorName]);
            $authorId = (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare(
            'INSERT INTO books
                (isbn, title, author_id, category_id, publisher, year_published,
                 total_copies, available_copies, cover_isbn)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?)'
        );
        $stmt->execute([
            $metadata['isbn'],
            $metadata['title'],
            (int)$authorId,
            (int)$categoryId,
            $metadata['publisher'] !== '' ? $metadata['publisher'] : null,
            $metadata['published_year'],
            $metadata['isbn'],
        ]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception->getCode() === '23000') {
            $stmt = $pdo->prepare(
                "SELECT book_id FROM books
                 WHERE REPLACE(REPLACE(isbn, '-', ''), ' ', '') = ? LIMIT 1"
            );
            $stmt->execute([$metadata['isbn']]);
            if ($stmt->fetchColumn()) {
                $errors[] = 'This ISBN was added to the library by another user.';
            } else {
                throw $exception;
            }
        } else {
            throw $exception;
        }
    }
}

if ($errors) {
    set_flash('danger', implode(' ', $errors));
} else {
    set_flash('success', 'Imported "' . $metadata['title'] . '" from Google Books.');
}
header('Location: ' . $redirect);
exit;
