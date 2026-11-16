<?php
require_once '../includes/auth_check.php';
require_roles(['admin', 'librarian']);
require_once '../config/database.php';
require_once '../includes/functions.php';

$errors = [];
$book = [
    'isbn' => '',
    'title' => '',
    'author_id' => '',
    'category_id' => '',
    'publisher' => '',
    'year_published' => '',
    'total_copies' => '1',
    'available_copies' => '1',
    'cover_isbn' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($book as $field => $default) {
        $book[$field] = trim($_POST[$field] ?? $default);
    }

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please submit the form again.';
    }
    if ($book['isbn'] === '' || !preg_match('/\A(?:[0-9]{9}[0-9Xx]|[0-9]{13})\z/', str_replace(['-', ' '], '', $book['isbn']))) {
        $errors[] = 'Enter a 10- or 13-character ISBN.';
    }
    if ($book['title'] === '' || text_length($book['title']) > 200) {
        $errors[] = 'A title of at most 200 characters is required.';
    }
    if (text_length($book['publisher']) > 120 || text_length($book['cover_isbn']) > 20) {
        $errors[] = 'Publisher or cover ISBN exceeds the allowed length.';
    }
    if ($book['year_published'] !== '' && (!ctype_digit($book['year_published'])
        || (int)$book['year_published'] < 1000 || (int)$book['year_published'] > 2155)) {
        $errors[] = 'Enter a publication year between 1000 and 2155.';
    }
    if (!ctype_digit($book['total_copies']) || !ctype_digit($book['available_copies'])
        || (int)$book['available_copies'] > (int)$book['total_copies']) {
        $errors[] = 'Copy counts must be non-negative, and available copies cannot exceed total copies.';
    }
    if (!ctype_digit($book['author_id']) || !ctype_digit($book['category_id'])) {
        $errors[] = 'Choose an author and category.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO books
                    (isbn, title, author_id, category_id, publisher, year_published,
                     total_copies, available_copies, cover_isbn)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $book['isbn'],
                $book['title'],
                (int)$book['author_id'],
                (int)$book['category_id'],
                $book['publisher'] !== '' ? $book['publisher'] : null,
                $book['year_published'] !== '' ? (int)$book['year_published'] : null,
                (int)$book['total_copies'],
                (int)$book['available_copies'],
                $book['cover_isbn'] !== '' ? $book['cover_isbn'] : null,
            ]);
            set_flash('success', 'Book added.');
            header('Location: index.php');
            exit;
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $errors[] = 'That ISBN is already in the collection, or the selected author/category is unavailable.';
            } else {
                throw $exception;
            }
        }
    }
}

$authors = $pdo->query('SELECT author_id, name FROM authors ORDER BY name')->fetchAll();
$categories = $pdo->query('SELECT category_id, name FROM categories ORDER BY name')->fetchAll();
$pageTitle = 'Add book';
require '../includes/header.php';
require '../includes/navbar.php';
?>
<main class="container">
    <header class="page-heading"><h1>Add book</h1></header>
    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <ul class="error-list"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if (!$authors || !$categories): ?>
        <p class="alert alert-warning">Add at least one author and one category before adding a book.</p>
    <?php else: ?>
        <form class="card form-grid" method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label for="title">Title</label>
                <input id="title" name="title" maxlength="200" required value="<?= e($book['title']) ?>">
            </div>
            <div class="field">
                <label for="isbn">ISBN</label>
                <input id="isbn" name="isbn" maxlength="20" required value="<?= e($book['isbn']) ?>">
            </div>
            <div class="field">
                <label for="author_id">Author</label>
                <select id="author_id" name="author_id" required>
                    <option value="">Select an author</option>
                    <?php foreach ($authors as $author): ?>
                        <option value="<?= e($author['author_id']) ?>"<?= $book['author_id'] == $author['author_id'] ? ' selected' : '' ?>><?= e($author['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id" required>
                    <option value="">Select a category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category['category_id']) ?>"<?= $book['category_id'] == $category['category_id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="publisher">Publisher (optional)</label>
                <input id="publisher" name="publisher" maxlength="120" value="<?= e($book['publisher']) ?>">
            </div>
            <div class="field">
                <label for="year_published">Year published (optional)</label>
                <input id="year_published" name="year_published" type="number" min="1000" max="2155" value="<?= e($book['year_published']) ?>">
            </div>
            <div class="field">
                <label for="total_copies">Total copies</label>
                <input id="total_copies" name="total_copies" type="number" min="0" required value="<?= e($book['total_copies']) ?>">
            </div>
            <div class="field">
                <label for="available_copies">Available copies</label>
                <input id="available_copies" name="available_copies" type="number" min="0" required value="<?= e($book['available_copies']) ?>">
            </div>
            <div class="field field-full">
                <label for="cover_isbn">Cover ISBN (optional; defaults to book ISBN)</label>
                <input id="cover_isbn" name="cover_isbn" maxlength="20" value="<?= e($book['cover_isbn']) ?>">
            </div>
            <div class="field-full form-actions">
                <button class="button" type="submit">Save book</button>
                <a class="button button-secondary" href="index.php">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</main>
<?php require '../includes/footer.php'; ?>