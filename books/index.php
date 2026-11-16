<?php
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/google_books.php';

$source = is_string($_GET['source'] ?? null) ? $_GET['source'] : 'local';
if (!in_array($source, ['local', 'google'], true)) {
    $source = 'local';
}
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$isStudent = ($_SESSION['role'] ?? '') === 'student';
$books = [];
$googleItems = [];
$googleError = '';
$googleTotal = 0;
$categories = [];

if ($source === 'google') {
    if ($search !== '') {
        if (text_length($search) > 100) {
            $googleError = 'Search terms must be no more than 100 characters.';
        } else {
            try {
                $response = google_books_api_request('volumes', [
                    'q' => $search,
                    'maxResults' => 12,
                    'printType' => 'books',
                    'orderBy' => 'relevance',
                ]);
                $googleTotal = (int)($response['totalItems'] ?? 0);
                $items = isset($response['items']) && is_array($response['items'])
                    ? $response['items']
                    : [];
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $googleItems[] = $item;
                    }
                }
            } catch (GoogleBooksApiException $exception) {
                $googleError = $exception->getMessage();
            }
        }
    }

    if (!$isStudent) {
        $categories = $pdo->query('SELECT category_id, name FROM categories ORDER BY name')->fetchAll();
    }
} else {
    $sql = 'SELECT books.book_id, books.isbn, books.title, books.cover_isbn,
                   books.digital_file_path,
                   books.available_copies, books.total_copies,
                   authors.name AS author_name
            FROM books
            INNER JOIN authors ON authors.author_id = books.author_id';
    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE books.title LIKE ? OR books.isbn LIKE ? OR authors.name LIKE ?';
        $pattern = '%' . $search . '%';
        $params = [$pattern, $pattern, $pattern];
    }

    $sql .= ' ORDER BY books.title';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll();
}

$pageTitle = $source === 'google' ? 'Google Books' : ($isStudent ? 'Browse books' : 'Books');
$extraStylesheet = '/library-system/assets/css/books-3d.css?v=20261114b';
require '../includes/header.php';
require '../includes/navbar.php';
?>
<main class="container books-main">
    <?php show_flash(); ?>
    <header class="page-heading">
        <div>
            <h1><?= $source === 'google' ? 'Search Google Books' : ($isStudent ? 'Explore the collection' : 'Books') ?></h1>
            <p><?= $source === 'google'
                ? "Search Google's public book catalogue. Library staff can add selected titles to the local collection."
                : ($isStudent
                    ? 'Search the library and see what is ready to borrow.'
                    : 'Browse the collection and manage book details.') ?></p>
        </div>
        <?php if (!$isStudent && $source === 'local'): ?>
            <a class="button button-accent" href="create.php">Add a book</a>
        <?php endif; ?>
    </header>

    <nav class="catalog-tabs" aria-label="Catalog source">
        <a class="<?= $source === 'local' ? 'is-active' : '' ?>"
           href="index.php?source=local"<?= $source === 'local' ? ' aria-current="page"' : '' ?>>
            Library collection
        </a>
        <a class="<?= $source === 'google' ? 'is-active' : '' ?>"
           href="index.php?source=google"<?= $source === 'google' ? ' aria-current="page"' : '' ?>>
            Google Books
        </a>
    </nav>

    <form class="catalog-search" method="get" role="search">
        <input type="hidden" name="source" value="<?= e($source) ?>">
        <label class="sr-only" for="book-search">
            <?= $source === 'google' ? 'Search Google Books' : 'Search library books' ?>
        </label>
        <input id="book-search" name="q" type="search" value="<?= e($search) ?>"
               placeholder="<?= $source === 'google' ? 'Search title, author, or ISBN' : 'Search title, author, or ISBN' ?>">
        <button class="button" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="button button-secondary" href="index.php?source=<?= e($source) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if ($source === 'google'): ?>
        <?php if ($googleError !== ''): ?>
            <div class="alert alert-warning" role="alert">
                <?= e($googleError) ?>
                <?php if ($search !== ''): ?>
                    <a href="https://books.google.com/books?q=<?= e(rawurlencode($search)) ?>"
                       target="_blank" rel="noopener noreferrer">Open this search on Google Books</a>.
                <?php endif; ?>
            </div>
        <?php elseif ($search === ''): ?>
            <p class="card empty-state">Enter a title, author, or ISBN to search Google Books.</p>
        <?php elseif (!$googleItems): ?>
            <p class="card empty-state">Google Books returned no matches for this search.</p>
        <?php else: ?>
            <p class="external-catalog-note">
                Showing <?= e(count($googleItems)) ?> of <?= e($googleTotal) ?> Google Books results.
                Students can browse results; only staff can add books to the library collection.
            </p>
            <?php if (!$isStudent && !$categories): ?>
                <p class="alert alert-warning">Create a library category before importing books.</p>
            <?php endif; ?>
            <section class="book-grid" aria-label="Google Books results">
                <?php foreach ($googleItems as $item): ?>
                    <?php
                    $metadata = google_books_extract_metadata($item);
                    $canImport = preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $metadata['id']) === 1
                        && $metadata['isbn'] !== null
                        && $metadata['title'] !== ''
                        && text_length($metadata['title']) <= 200
                        && (!$metadata['authors'] || text_length($metadata['authors'][0]) <= 100);
                    ?>
                    <article class="book-container">
                        <div class="volume<?= $metadata['cover_url'] === '' ? ' cover-unavailable' : '' ?>">
                            <?php if ($metadata['cover_url'] !== ''): ?>
                                <img src="<?= e($metadata['cover_url']) ?>"
                                     alt="Cover of <?= e($metadata['title']) ?>"
                                     loading="lazy"
                                     onerror="this.onerror=null; this.parentElement.classList.add('cover-unavailable')">
                            <?php endif; ?>
                            <span class="cover-unavailable-label" aria-hidden="true">Cover unavailable</span>
                        </div>
                        <div class="book-details">
                            <strong><?= e($metadata['title'] !== '' ? $metadata['title'] : 'Untitled book') ?></strong>
                            <span class="book-author">
                                <?= e($metadata['authors'] ? implode(', ', $metadata['authors']) : 'Author not listed') ?>
                            </span>
                            <?php if ($metadata['publisher'] !== '' || $metadata['published_year'] !== null): ?>
                                <span class="book-author">
                                    <?= e($metadata['publisher']) ?><?= $metadata['publisher'] !== '' && $metadata['published_year'] !== null ? ' - ' : '' ?><?= e($metadata['published_year'] ?? '') ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($metadata['isbn'] !== null): ?>
                                <span class="book-author">ISBN <?= e($metadata['isbn']) ?></span>
                            <?php else: ?>
                                <span class="book-availability is-unavailable">No valid ISBN for import</span>
                            <?php endif; ?>
                            <div class="actions book-actions">
                                <?php if ($metadata['preview_url'] !== ''): ?>
                                    <a class="button button-secondary" href="<?= e($metadata['preview_url']) ?>"
                                       target="_blank" rel="noopener noreferrer">View on Google Books</a>
                                <?php endif; ?>
                            </div>
                            <?php if (!$isStudent && $canImport && $categories): ?>
                                <form class="google-import-form" method="post" action="google_import.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="volume_id" value="<?= e($metadata['id']) ?>">
                                    <input type="hidden" name="q" value="<?= e($search) ?>">
                                    <label for="category-<?= e($metadata['id']) ?>">Add to category</label>
                                    <select id="category-<?= e($metadata['id']) ?>" name="category_id" required>
                                        <option value="">Select a category</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= e($category['category_id']) ?>"><?= e($category['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="button button-accent" type="submit">Import to library</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    <?php elseif (!$books): ?>
        <p class="card empty-state">
            <?= $search !== '' ? 'No books matched your search.' : 'No books have been added to the library yet.' ?>
        </p>
    <?php else: ?>
        <section class="book-grid" aria-label="Library book collection">
            <?php foreach ($books as $book): ?>
                <?php
                $coverIsbn = str_replace(
                    ['-', ' '],
                    '',
                    trim((string)($book['cover_isbn'] ?: $book['isbn']))
                );
                $hasValidCoverIsbn = preg_match('/\A[0-9Xx]{10,13}\z/', $coverIsbn) === 1;
                ?>
                <article class="book-container">
                    <div class="volume<?= $hasValidCoverIsbn ? '' : ' cover-unavailable' ?>">
                        <?php if ($hasValidCoverIsbn): ?>
                            <img
                                src="https://covers.openlibrary.org/b/isbn/<?= e($coverIsbn) ?>-M.jpg"
                                alt="Cover of <?= e($book['title']) ?>"
                                loading="lazy"
                                onerror="this.onerror=null; this.parentElement.classList.add('cover-unavailable')">
                        <?php endif; ?>
                        <span class="cover-unavailable-label" aria-hidden="true">Cover unavailable</span>
                    </div>
                    <div class="book-details">
                        <strong><?= e($book['title']) ?></strong>
                        <span class="book-author"><?= e($book['author_name']) ?></span>
                        <span class="book-availability<?= (int)$book['available_copies'] > 0 ? ' is-available' : ' is-unavailable' ?>">
                            <?= (int)$book['available_copies'] > 0
                                ? e($book['available_copies']) . ' of ' . e($book['total_copies']) . ' available'
                                : 'Currently unavailable' ?>
                        </span>
                        <div class="actions book-actions">
                            <a class="button button-secondary" href="view.php?id=<?= e($book['book_id']) ?>">Details &amp; reviews</a>
                            <?php if (!empty($book['digital_file_path'])): ?>
                                <a class="button button-accent" href="read.php?id=<?= e($book['book_id']) ?>">Read online</a>
                            <?php elseif ($isStudent): ?>
                                <span class="book-availability is-unavailable">Online edition not available</span>
                            <?php endif; ?>
                            <?php if ($isStudent): ?>
                                <a class="button button-accent"
                                   href="../reservations/create.php?book_id=<?= e($book['book_id']) ?>">
                                    <?= (int)$book['available_copies'] > 0 ? 'Reserve a copy' : 'Join reservation queue' ?>
                                </a>
                            <?php elseif (!$isStudent): ?>
                                <a class="button button-secondary" href="edit.php?id=<?= e($book['book_id']) ?>">Edit</a>
                                <form class="inline-form" method="post" action="delete.php"
                                      data-confirm="Delete this book?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="book_id" value="<?= e($book['book_id']) ?>">
                                    <button class="button button-danger" type="submit">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<?php require '../includes/footer.php'; ?>
