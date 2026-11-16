# Library System - Group 13

A PHP and MySQL library management application for books, authors, categories,
members, loans, reservations, and fines.

## Run locally with XAMPP

1. Place this project at `C:\xampp\htdocs\Library-system`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Import `database/library_db.sql` using phpMyAdmin. This creates the
   `library_db` database and sample records.
4. If your MySQL credentials differ from the XAMPP defaults, update
   `config/database.php`.
5. Open `http://localhost/Library-system/`.

The sample sign-in is `admin` / `password123`. Change the password before
using the application outside a local demo environment.

Book covers are loaded from the Open Library Covers API using each book's cover
ISBN, or its ISBN when no separate cover ISBN is set.

## Student member access

For an existing database, run `database/migrate_member_login.sql` once to add
the nullable password-hash column required for member sign-in. Fresh installs
already include this column in `database/library_db.sql`.

Administrators and librarians can add or edit a member and optionally set or
reset that member's student portal password.

The sign-in page also offers public student/member registration for
demonstrations. New registrations receive a generated member number, are
activated immediately, and are signed in automatically. Public registration
cannot create librarian or administrator accounts. Students can browse the
catalog, view only their own loans and reservations, and reserve unavailable
books. Staff sign in with their existing staff username and password.

For a production deployment, replace immediate public activation with an
appropriate verification or approval process.

## Google Books catalogue

The Books page includes a Google Books search. Administrators and librarians
can choose a local category and import a result with an ISBN; each imported
title starts with one available copy. Its first listed author is matched or
created in the local authors table. Students can browse Google Books results but cannot import them. The existing
database schema is reused, and API requests are made server-side so the key is
not sent to browsers.

Google requires an API key for public Books API requests. Enable the Google
Books API in Google Cloud and create a key from the
[Google Cloud Credentials page](https://console.cloud.google.com/apis/credentials).
For XAMPP, copy `config/google_books.local.example.php` to
`config/google_books.local.php` and enter the key in the new file. That local
file is ignored by Git; never commit or share the key. Alternatively, set the
`GOOGLE_BOOKS_API_KEY` environment variable available to Apache and restart
Apache. The Google Books tab displays a configuration or quota message if the
key is missing or the API limit is reached. PHP cURL or `allow_url_fopen` must
be enabled for outbound HTTPS requests.

See the [Google Books API documentation](https://developers.google.com/books/docs/v1/using)
for API-key setup and usage details.

## Reservations and student reviews

Students can reserve any title, including one with copies currently available.
Requests form a queue; librarians issue copies to the oldest active request
first. Existing databases do not need a reservations migration.

Active student members can leave one rating and review per title and update
their review later. Existing databases must run
`database/migrate_book_reviews.sql` once; fresh installs create the review
table from `database/library_db.sql`.

## Online ebook reader

Administrators and librarians can open a book's edit page and upload an
authorized PDF or EPUB file (up to 30 MB). Uploaded files are stored outside
the public web directory and served only to signed-in active student members
and staff. A "Read online" action appears in the local catalogue when a digital
edition has been uploaded. Existing databases must run
`database/migrate_book_ebooks.sql` once; fresh installs already include the
required column in `database/library_db.sql`.

PDFs use the browser's built-in viewer. EPUB reading uses epub.js 0.3.93 and
JSZip 3.10.1 from jsDelivr, so the reader needs an internet connection.
XAMPP's PHP `fileinfo` and `zip` extensions must be enabled; if ZIP is disabled,
uncomment `extension=zip` in `php.ini` and restart Apache. The reader provides
sign-in access control, not DRM, so only upload files the library is authorized
to distribute.

## Research and open-access libraries

The navigation includes direct title searches for Open Library, Project
Gutenberg, Google Books, and the Directory of Open Access Books. Open Library
borrowing depends on availability; Project Gutenberg focuses on public-domain
books, and DOAB lists open-access scholarly titles. External catalog links do
not copy or host those books in this application.
