<?php
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

$role = $_SESSION['role'] ?? '';
$memberId = (int)($_SESSION['member_id'] ?? 0);
if ($role === 'student') {
    $stmt = $pdo->prepare("SELECT member_id FROM members WHERE member_id = ? AND status = 'active'");
    $stmt->execute([$memberId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        exit('Only active student members can review books.');
    }
}

$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookId = filter_input(INPUT_POST, 'book_id', FILTER_VALIDATE_INT);
}
if (!$bookId || $bookId < 1) {
    http_response_code(404);
    exit('Book not found.');
}

$stmt = $pdo->prepare(
    'SELECT books.book_id, books.isbn, books.title, books.available_copies,
            books.total_copies, books.digital_file_path, authors.name AS author_name
     FROM books
     INNER JOIN authors ON authors.author_id = books.author_id
     WHERE books.book_id = ?'
);
$stmt->execute([$bookId]);
$book = $stmt->fetch();
if (!$book) {
    http_response_code(404);
    exit('Book not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($role !== 'student') {
        http_response_code(403);
        exit('Only student members can submit book reviews.');
    }

    $ratingValue = is_string($_POST['rating'] ?? null) ? $_POST['rating'] : '';
    $reviewText = is_string($_POST['review_text'] ?? null) ? trim($_POST['review_text']) : '';
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('danger', 'Your session expired. Please submit the review again.');
    } elseif (!ctype_digit($ratingValue) || (int)$ratingValue < 1 || (int)$ratingValue > 5) {
        set_flash('danger', 'Choose a rating from 1 to 5.');
    } elseif ($reviewText === '' || text_length($reviewText) > 2000) {
        set_flash('danger', 'Write a review of 1 to 2,000 characters.');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO book_reviews (book_id, member_id, rating, review_text)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 rating = VALUES(rating),
                 review_text = VALUES(review_text),
                 updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$bookId, $memberId, (int)$ratingValue, $reviewText]);
        set_flash('success', 'Your review has been saved.');
    }

    header('Location: view.php?id=' . $bookId . '#review-form');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT book_reviews.review_id, book_reviews.rating, book_reviews.review_text,
            book_reviews.updated_at, members.full_name
     FROM book_reviews
     INNER JOIN members ON members.member_id = book_reviews.member_id
     WHERE book_reviews.book_id = ? AND members.status = 'active'
     ORDER BY book_reviews.updated_at DESC, book_reviews.review_id DESC"
);
$stmt->execute([$bookId]);
$reviews = $stmt->fetchAll();

$averageRating = $reviews
    ? array_sum(array_column($reviews, 'rating')) / count($reviews)
    : null;
$myReview = null;
if ($role === 'student') {
    $stmt = $pdo->prepare(
        'SELECT rating, review_text FROM book_reviews WHERE book_id = ? AND member_id = ?'
    );
    $stmt->execute([$bookId, $memberId]);
    $myReview = $stmt->fetch() ?: null;
}

$researchQuery = rawurlencode($book['title'] . ' ' . $book['author_name']);
$researchLinks = [
    ['Google Books', 'https://books.google.com/books?q=' . $researchQuery, 'Publisher information and previews when available.'],
    ['Open Library', 'https://openlibrary.org/search?q=' . $researchQuery, 'Search a shared book catalog; digital borrowing depends on availability.'],
    ['Project Gutenberg', 'https://www.gutenberg.org/ebooks/search/?query=' . $researchQuery, 'Read and download public-domain ebooks.'],
    ['Directory of Open Access Books', 'https://www.doabooks.org/', 'Find peer-reviewed open-access scholarly books.'],
];

$pageTitle = $book['title'];
$extraStylesheet = '/library-system/assets/css/book-details.css?v=20261114a';
require '../includes/header.php';
require '../includes/navbar.php';
?>
<main class="container book-details-page">
    <?php show_flash(); ?>
    <header class="page-heading">
        <div>
            <h1><?= e($book['title']) ?></h1>
            <p>By <?= e($book['author_name']) ?> · ISBN <?= e($book['isbn']) ?></p>
        </div>
        <a class="button button-secondary" href="index.php">Back to books</a>
    </header>

    <section class="card book-summary">
        <p>
            <?= (int)$book['available_copies'] > 0
                ? e($book['available_copies']) . ' of ' . e($book['total_copies']) . ' physical copies available'
                : 'No physical copies are currently available' ?>
        </p>
        <p>
            <?= $averageRating === null
                ? 'No student reviews yet.'
                : 'Student rating: ' . e(number_format($averageRating, 1)) . ' / 5 (' . e(count($reviews)) . ' reviews)' ?>
        </p>
        <div class="actions">
            <?php if (!empty($book['digital_file_path'])): ?>
                <a class="button button-accent" href="read.php?id=<?= e($bookId) ?>">Read online</a>
            <?php else: ?>
                <span class="book-availability is-unavailable">Online edition not available</span>
            <?php endif; ?>
            <?php if ($role === 'student'): ?>
                <a class="button" href="../reservations/create.php?book_id=<?= e($bookId) ?>">Reserve a copy</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="card research-links" aria-labelledby="research-heading">
        <h2 id="research-heading">Research this book</h2>
        <p>Explore catalogs, publisher previews, public-domain texts, and open-access books.</p>
        <div class="research-link-grid">
            <?php foreach ($researchLinks as [$label, $url, $description]): ?>
                <a class="research-link" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer">
                    <strong><?= e($label) ?></strong>
                    <span><?= e($description) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card review-section" aria-labelledby="reviews-heading">
        <div class="page-heading">
            <div>
                <h2 id="reviews-heading">Student reviews</h2>
                <p><?= e(count($reviews)) ?> review<?= count($reviews) === 1 ? '' : 's' ?></p>
            </div>
        </div>
        <?php if ($role === 'student'): ?>
            <form id="review-form" class="form-grid review-form" method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="book_id" value="<?= e($bookId) ?>">
                <div class="field">
                    <label for="rating">Your rating</label>
                    <select id="rating" name="rating" required>
                        <option value="">Select a rating</option>
                        <?php foreach ([5, 4, 3, 2, 1] as $rating): ?>
                            <option value="<?= $rating ?>"<?= $myReview && (int)$myReview['rating'] === $rating ? ' selected' : '' ?>>
                                <?= $rating ?> / 5
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field field-full">
                    <label for="review_text">Your review</label>
                    <textarea id="review_text" name="review_text" rows="4" maxlength="2000" required><?= e($myReview['review_text'] ?? '') ?></textarea>
                </div>
                <div class="field-full">
                    <button class="button button-accent" type="submit">
                        <?= $myReview ? 'Update your review' : 'Submit review' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <?php if (!$reviews): ?>
            <p class="empty-state">Be the first to review this title.</p>
        <?php else: ?>
            <div class="review-list">
                <?php foreach ($reviews as $review): ?>
                    <article class="book-review">
                        <header>
                            <strong><?= e($review['full_name']) ?></strong>
                            <span><?= e($review['rating']) ?> / 5 · <?= e($review['updated_at']) ?></span>
                        </header>
                        <p><?= nl2br(e($review['review_text'])) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php require '../includes/footer.php'; ?>
