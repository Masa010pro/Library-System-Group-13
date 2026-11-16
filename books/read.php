<?php
require_once '../includes/auth_check.php';
require_roles(['admin', 'librarian', 'student']);
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/ebooks.php';

if (($_SESSION['role'] ?? '') === 'student') {
    $memberId = (int)($_SESSION['member_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT member_id FROM members WHERE member_id = ? AND status = 'active'");
    $stmt->execute([$memberId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        exit('Only active student members can use the online reader.');
    }
}

$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$bookId || $bookId < 1) {
    http_response_code(404);
    exit('Book not found.');
}

$stmt = $pdo->prepare(
    'SELECT books.book_id, books.title, books.digital_file_path, authors.name AS author_name
     FROM books
     INNER JOIN authors ON authors.author_id = books.author_id
     WHERE books.book_id = ?'
);
$stmt->execute([$bookId]);
$book = $stmt->fetch();
$filePath = $book ? ebook_resolve_path($book['digital_file_path']) : null;
if (!$book || $filePath === null) {
    http_response_code(404);
    exit('No online edition is available for this book.');
}

$format = strtolower(pathinfo($book['digital_file_path'], PATHINFO_EXTENSION));
$pageTitle = 'Read ' . $book['title'];
$extraStylesheet = '/library-system/assets/css/ebook-reader.css?v=20261114a';
require '../includes/header.php';
require '../includes/navbar.php';
?>
<main class="container ebook-reader-page">
    <header class="page-heading">
        <div>
            <h1><?= e($book['title']) ?></h1>
            <p><?= e($book['author_name']) ?> · <?= e(strtoupper($format)) ?> edition</p>
        </div>
        <a class="button button-secondary" href="index.php">Back to books</a>
    </header>

    <?php if ($format === 'pdf'): ?>
        <p class="ebook-reader-note">
            If the reader does not appear in your browser,
            <a href="ebook_content.php?id=<?= e($bookId) ?>" target="_blank" rel="noopener noreferrer">open the PDF in a new tab</a>.
        </p>
        <iframe class="ebook-pdf-viewer"
                src="ebook_content.php?id=<?= e($bookId) ?>#toolbar=1&navpanes=0&view=FitH"
                title="Online reader for <?= e($book['title']) ?>"></iframe>
    <?php else: ?>
        <nav class="ebook-reader-controls" aria-label="EPUB reading controls">
            <button class="button button-secondary" type="button" data-epub-previous disabled>Previous page</button>
            <span data-epub-status role="status" aria-live="polite">Opening ebook…</span>
            <button class="button button-secondary" type="button" data-epub-next disabled>Next page</button>
        </nav>
        <div class="epub-reader-frame">
            <div id="epub-reader"
                 data-epub-url="ebook_content.php?id=<?= e($bookId) ?>"
                 aria-label="EPUB book pages"></div>
        </div>
        <p class="ebook-reader-note">
            EPUB reading needs an internet connection to load the reader component.
        </p>
        <script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js" defer></script>
        <script src="https://cdn.jsdelivr.net/npm/epubjs@0.3.93/dist/epub.min.js" defer></script>
        <script src="/library-system/assets/js/ebook-reader.js?v=20261114a" defer></script>
    <?php endif; ?>
</main>
<?php require '../includes/footer.php'; ?>
