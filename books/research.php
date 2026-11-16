<?php
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';

$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$error = '';
if (text_length($query) > 120) {
    $error = 'Search terms must be no more than 120 characters.';
    $query = '';
}

$encodedQuery = rawurlencode($query);
$libraries = [
    [
        'name' => 'Open Library',
        'url' => $query === '' ? 'https://openlibrary.org/' : 'https://openlibrary.org/search?q=' . $encodedQuery,
        'description' => 'Explore a public book catalog. Loans and digital copies depend on title availability.',
    ],
    [
        'name' => 'Project Gutenberg',
        'url' => $query === '' ? 'https://www.gutenberg.org/' : 'https://www.gutenberg.org/ebooks/search/?query=' . $encodedQuery,
        'description' => 'Read and download free public-domain ebooks.',
    ],
    [
        'name' => 'Directory of Open Access Books',
        'url' => 'https://www.doabooks.org/',
        'description' => 'Find peer-reviewed, openly accessible scholarly books.',
    ],
    [
        'name' => 'Google Books',
        'url' => $query === '' ? 'https://books.google.com/' : 'https://books.google.com/books?q=' . $encodedQuery,
        'description' => 'Research book details and publisher-provided previews.',
    ],
];

$pageTitle = 'Research & open libraries';
$extraStylesheet = '/library-system/assets/css/book-details.css?v=20261114a';
require '../includes/header.php';
require '../includes/navbar.php';
?>
<main class="container research-page">
    <header class="page-heading">
        <div>
            <h1>Research &amp; open libraries</h1>
            <p>Search trusted catalogs and discover public-domain or open-access reading.</p>
        </div>
    </header>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form class="catalog-search" method="get" role="search">
        <label class="sr-only" for="research-query">Search for a book</label>
        <input id="research-query" name="q" type="search" maxlength="120"
               value="<?= e($query) ?>" placeholder="Search title, author, or subject">
        <button class="button" type="submit">Search libraries</button>
        <a class="button button-secondary" href="/library-system/books/index.php">Local catalog</a>
    </form>
    <section class="research-link-grid" aria-label="External research libraries">
        <?php foreach ($libraries as $library): ?>
            <article class="card research-link-card">
                <h2><?= e($library['name']) ?></h2>
                <p><?= e($library['description']) ?></p>
                <a class="button button-accent" href="<?= e($library['url']) ?>"
                   target="_blank" rel="noopener noreferrer">
                    <?= $query === '' ? 'Visit library' : 'Search ' . e($library['name']) ?>
                </a>
            </article>
        <?php endforeach; ?>
    </section>
    <p class="research-disclaimer">
        External catalogs have their own access and lending rules. This system links to them but does not copy or host their books.
    </p>
</main>
<?php require '../includes/footer.php'; ?>
