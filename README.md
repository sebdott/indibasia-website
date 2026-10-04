# INDIBA PHP mirror

A local PHP copy of the English public pages at https://indiba.com, including regional URLs and downloaded assets. The downloader follows the English public sitemaps and internal navigation, saves the original HTML, and rewrites page and asset URLs for local use.

## Run with Docker

Start Docker Desktop (Linux containers) or Docker Engine, then run from this directory:

```sh
make up
```

Open **http://127.0.0.1:9000/** or **http://127.0.0.1:9000/admin/**. `make up` generates private local credentials in `.env`, starts MySQL 8.4, applies migrations, seeds the downloaded content, and starts the website after initialization succeeds. To use another website port, run `make up PORT=9001`.

Without Make, the equivalent command is:

```sh
docker compose run --rm --build --no-deps web php tools/local-db-setup.php
docker compose up -d --build --wait --wait-timeout 180 web
```

Local MySQL is available to desktop database clients at **127.0.0.1:3307**. Its database name, username, and password are in `.env`; `MYSQL_PORT` changes the host port. MySQL data persists in the `indiba_mysql-data` Docker volume across container recreation and `make down`. Changing `.env` passwords does not change accounts already stored in that volume.

The schema starts with the seven CMS tables inspected on the remote database. Versioned migration files live in `database/migrations/`; the content seeder lives in `database/seeders/`. Use `make db-migrate` to apply pending schema changes and `make db-seed` to add missing content. Both can be rerun; seeding keeps existing page edits, publication states, uploads, and administrator passwords. See [database/README.md](database/README.md) for details.

The admin has separate **Pages**, **Master pages**, **News**, and **Events** sections with independent lists, searches, status counts, and Trash. Master pages contain archive screens that render listings, filters, or pagination, including News and Events landing pages and event brand/category filters. Fixed pages such as Home and Rehabilitation remain in Pages; individual news articles and events have their own sections. Migration `004_master_pages_and_events` identifies existing content from its original HTML without changing content, URLs, versions, or update dates. New news and event drafts default to `/news/` and `/events/` paths. The editor's **Content type** setting lets you move an item between sections, and changing its URL preserves its section. Duplicates and revisions retain the content type. Apply pending migrations to an existing installation before running this version of the admin.

Docker uses the local database by default and leaves `pw.txt` unchanged. To run a separate instance against the remote credentials instead:

```sh
docker compose -f compose.remote.yaml up -d --build --wait web
```

That instance opens at **http://127.0.0.1:9001/** and depends on the remote database being reachable. Stop it with `docker compose -f compose.remote.yaml down`. Local seeding indexes downloaded snapshots; it does not copy edits or accounts from the remote database.

The container runs PHP 8.4 with Apache and serves only `public/`. Compose mounts this project, so the downloaded assets, saved pages, and English dictionary are immediately available. Changes to files appear without rebuilding the image. Storage, the downloader, and local Windows tools stay outside the web document root.

Downloaded assets and snapshots are excluded from the image build context and accessed through the project mount. Keep `public/assets/`, `storage/manifest.json`, and `storage/pages/` when moving this project to another machine. The standalone image needs the same project mounted at `/var/www/indiba`.

Useful commands:

```sh
make check
make logs
make ps
make restart
make shell
make down
```

`make down` removes the container and network; the project files and downloads remain on your machine. Run `make help` for all commands. Downloader and translation tools also run inside the container:

```sh
make download
make download ARGS="--retry-failed"
make translations
make rebuild
make translation-merge FILES="translated/fr.json translated/es.json"
```

## Management portal

The English management portal is available at **http://127.0.0.1:9000/admin/** after installation. It includes administrator sign-in, searchable pages, text and image editing, full HTML editing, new pages, draft/published status, an image/PDF library, uploads, activity history, and password changes.

The portal uses locally hosted Roboto fonts, a dark navigation sidebar, and responsive layouts for desktop and mobile. Font files and their SIL Open Font License are bundled under `public/admin/fonts/`; loading the portal requires no external font service. Editor controls and the preview banner use Roboto while page previews retain the public website's typography.

Database credentials are read from `pw.txt` outside the web document root. The existing Apache `SetEnv DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and `DB_CHARSET` format is supported. Environment variables with those names override the file. An optional `DB_SSL_CA` value supplies a CA certificate path for MySQL TLS. `pw.txt` is excluded from Git and Docker builds; keep it private on the server.

```sh
make up
make db-check
make db-migrate
make db-seed
```

Installation creates only tables beginning with `indiba_cms_` and indexes the existing downloaded pages and assets. It can be rerun without overwriting portal edits or administrator passwords. The first local installation creates an `admin` account and writes its random initial password to `storage/local-admin-bootstrap.txt`. Open that file locally to sign in, then change the password in **Your account**. The bootstrap file is removed after changing the password. The standalone installer defaults to `storage/admin-bootstrap.txt`; `CMS_BOOTSTRAP_FILE` changes this path. `make cms-install` remains a shortcut for running migrations and seeding together.

Use **Pages** to manage content with All pages, Published, Drafts, and Trash tabs. Search and filter by region or page group, sort the list, and select pages for bulk publishing, draft status, or Trash. Duplicate makes a separate draft. Trash hides a page without deleting its content; restoring returns it to draft status. Regional homepages cannot be moved to Trash.

The default **Classic editor** uses locally hosted TinyMCE 8.9.2. It edits the main page content with headings, font family and size, colors, alignment, lists, links, tables, images, media embeds, accordions, undo/redo, search/replace, preview, and full screen. Custom controls insert two/three columns, callouts, and styled buttons. **Add media** and **Media library** insert existing images or PDF links. Image paste/drop and image-dialog uploads save through the authenticated upload handler before the page save completes. Upload types and the 20 MB limit are enforced by PHP.

The Classic editor has **Visual** and **Code** buttons above the content. Code edits the main content's HTML, keeps unsaved changes when switching views, and supports Add media and saving directly. Switching back to Visual or saving applies the editor's HTML filtering. The separate **HTML source** tab edits the full page document.

Classic editing retains the outer page layout, header, and footer. The banner is edited in **Banner header** and hidden from the Classic editor, which shows the page body. Saving body changes preserves the banner, including when replacing the body in Code view. Original scripts, styles, forms, SVG icons, and embedded widgets are represented by protected content in the editor and restored from the current server-side page when saving. No-op classic saves do not replace the main content with TinyMCE's normalized markup. Pages without a recognized main content container can use the other editing modes.

The **Simple editor** edits individual headings and paragraphs with formatting. New and duplicated pages can add paragraph, heading, and quote blocks. **All text & images** also exposes navigation, footer, button text, and labels. **HTML source** remains available for full document and background-image changes. Use **Choose image** to select a file directly from the media library. Editing a page does not update the translation dictionary automatically, and navigation/footer edits affect that individual page.

TinyMCE is bundled under `public/vendor/tinymce/` in GPL mode, with its original license file. No cloud API key, third-party editor account, or Node runtime is required to run the PHP site. The files are copied into the Docker image. `public/cms-content.css` styles columns, callouts, buttons, captions, and tables on the public site. Existing portal accounts and database schema continue to work without a new migration.

Every editor includes a **Banner header** panel for Pages, Master pages, News, and Events. Existing banner images and wording are loaded from the page, including CSS background images. Use **Choose featured image** to select media, paste an HTTPS image URL, or remove the image, then edit the banner title, subtitle, description, and existing button text. Choosing an image on a video banner replaces its video background when saved. Responsive banner copies have their own controls. New drafts include a banner; pages without a recognized banner can add one through the same panel. Banner edits are saved with the page HTML, so previews, duplicates, revisions, and restoring original content retain the same behavior without a schema migration.

News and Events title headers often use a decorative pattern while the original featured attachment is declared separately. When no foreground image or saved selection exists in that header, the featured image preview uses the page's declared attachment. Background previews use a dark surface so transparent patterns remain visible. Saving unchanged fields preserves the original page layout; selected replacements and removed images take precedence on subsequent edits.

Page settings include a group, page path, search title, and search description. Changing a path creates a permanent redirect from the previous URL. Regional homepage paths remain fixed. New paths must remain English and cannot use reserved admin, asset, or foreign-language prefixes.

Save a published page to make its changes visible immediately. Draft and trashed pages return HTTP 404 to public visitors. **Preview saved page** opens an administrator-only static preview, including drafts; save before previewing. Scripts, forms, and embedded frames are disabled in previews. Use **View live** on a published page to check interactive behavior.

Every content or status change keeps the previous page version in **Revision history**. Restoring a version saves it as a draft, keeps the current path, and records the version it replaced. **Restore original content** returns a downloaded page to its saved snapshot while preserving the replaced content in history. Revisions contain page content and metadata; media files are kept separately on disk.

For an existing portal, `make cms-upgrade` runs pending migrations without re-importing content or resetting accounts. The migration runner adopts the existing tables, adds missing page management columns, revisions, and redirects, and records applied versions in `indiba_cms_migrations`. Fresh `make up` or `make cms-install` installations include these automatically.

Use **Media library** to find existing files or upload JPG, PNG, WebP, GIF, and PDF files up to 20 MB. Copy a file URL into the page editor. Uploads are stored in `public/uploads/`; the database stores their metadata. The page editor changes ordinary image elements; CSS background images and links can be changed using HTML source.

The media library has thumbnail grid and list views, search, file-type/date/source filters, and bulk selection. Click a thumbnail to open **Attachment details** with a large preview, filename, upload date/author, file size, and image dimensions. Edit the title, alternative text, caption, and description, then choose **Save details**. **Copy URL**, **Download file**, and previous/next attachment controls are available in the popup. Alternative text is used when inserting an image through the editor's media picker; changing it does not rewrite images already inserted into pages. **Move to Trash** removes files from the library and pickers without deleting files already used on pages. Select **Trash** in the source filter to restore files individually or in bulk. Apply migration `005_media_library` to existing portals with `make db-migrate`.

`node tools/check-media.cjs` verifies upload, grid/list views, filters, attachment previews, metadata saving, clipboard, bulk Trash/restore, picker integration, access checks, and mobile layout on the isolated test portal at port 9002.

The database stores page titles, publication state, administrator hashes, media metadata, activity, and edited/new page HTML. Original unedited HTML continues to use `storage/pages/`, and all media stays on disk. Back up the database together with `storage/`, `public/assets/`, `public/uploads/`, and `locales/`. The crawler's rebuild command updates original snapshots without overwriting saved portal edits. After installation, the public site and portal require a working database connection. A database outage returns HTTP 503 so unpublished pages stay hidden.

Administrators can open **Users** to search and filter accounts, add users, edit usernames/display names/email, choose a role, reset passwords, and deactivate or reactivate access. **Administrators** manage both content and users; **Editors** manage content and media and can change their own passwords. Email is optional contact information; passwords are shared privately and no reset email is sent. Deactivation preserves activity attribution and revokes access on the next request. Password resets and account edits invalidate existing sessions; an administrator editing their own details keeps the current session. The portal prevents self-deactivation/self-demotion and requires at least one active administrator, including during simultaneous changes. Apply migration `006_user_management` with `make db-migrate` on existing installations. Existing accounts remain active Administrators with the same passwords.

`node tools/check-users.cjs` verifies user creation/editing, roles, password changes/resets, deactivation/reactivation, session revocation, validation, stale edits, CSRF, last-administrator protection, and mobile layout on the isolated test portal at port 9002.

Login uses password hashes, session expiry, CSRF protection, and throttling. Account access and session versions are checked on every portal request. Page saves reject conflicting versions. Upload types are checked on the server and executable upload extensions are blocked by Apache. The portal does not enable the source website's checkout, account system, or contact-submission backend.

Admin sessions persist in the stack's `php-sessions` Docker volume across container rebuilds. The local and remote stacks keep separate session volumes, and each portal port uses a separate session cookie. Sessions still expire after 30 minutes of inactivity. An expired form returns HTTP 403 with a refreshed form for retrying; editor uploads receive a JSON error. Reopen `/admin/` after the first update to this session configuration to load a fresh sign-in form.

`node tools/check-admin-sessions.cjs` checks expired-form recovery and CSRF rejection using the local bootstrap credentials. Add `--recreate` to verify sessions across recreation of the local web container; this temporarily restarts the website. `CMS_SESSION_SECONDARY_URL` optionally checks cookie isolation against a second local portal port.

Portal browser tests run against a separate local database and web container on port 9002, with an isolated import manifest; `tools/check-cms.cjs`, `tools/check-classic.cjs`, and `tools/check-banners.cjs` must not be pointed at the running site on port 9000. `php tools/check-classic.php` checks protected original markup and content filtering without writing to the database. `php tools/check-banners.php` checks banner discovery, wording, featured images, and combined Classic edits without database writes. Screenshots and test results are saved under `storage/`.

## Run on this Windows machine

```powershell
.\start.ps1
```

Run `make up` first to prepare local MySQL and `.env`. The script uses those local database settings and serves PHP directly on Windows at **http://127.0.0.1:8082/**. Keep the terminal open while using the site. You can select another port with `./start.ps1 -Port 9000`. Use `./start.ps1 -RemoteDatabase` to use the original remote credentials instead.

The homepage follows the Asia version served by the source site from Malaysia. The other English regional homepage is `/us/`. The original English root URLs are also retained. Spanish, French, and Italian pages and navigation options are excluded.

## Download or resume

```powershell
.\download.ps1
```

The command resumes its saved queue and skips successful downloads. To retry unavailable URLs:

```powershell
.\download.ps1 -RetryFailed
```

Stop gracefully by creating `storage/stop` in a second terminal. Remove this file before resuming. Ctrl+C also requests a graceful stop on Windows.

```powershell
New-Item -ItemType File storage/stop
Remove-Item -LiteralPath storage/stop
```

`storage/report.json` records totals, remaining work, external links, and failed URLs. `complete: true` means the queue has finished; inspect `failures` for source URLs that could not be downloaded. The source's sitemaps include several nonexistent URLs.

## Use your own PHP installation

PHP 8.1 or newer is required. Enable the curl and DOM extensions for downloads and PDO MySQL for the management portal. Before portal installation, the snapshot can run without a database. WordPress and Composer are not required.

```sh
php tools/mirror.php --workers=4 --delay=1
php -S 127.0.0.1:8082 -t public public/router.php
```

The downloader also supports `--max-pages=20`, `--seed=https://indiba.com/asia/`, `--skip-sitemaps`, `--assets-only`, and `--retry-failed`. A page limit applies to the current run; the remaining queue is saved. `--assets-only` downloads queued assets without adding new seed pages.

## Deploy

Upload `config.php`, `lib/`, `database/`, `tools/`, `locales/en.json`, `public/`, and `storage/manifest.json` plus `storage/pages/`. Set the web server's document root to **public/**. Keep storage, database scripts, and tools outside the document root. Apache can use the included `.htaccess`; other servers must route nonexistent files to `public/index.php`. No deployment was performed as part of creating this copy.

Do not upload `.tools/`: it contains the portable Windows PHP runtime and local browser tooling used here. Downloaded assets are in `public/assets/`, outside source control. Original snapshots and crawl state are in `storage/`.

## Scope

This is a public frontend snapshot. Source PHP files, the WordPress database, accounts, orders, login sessions, submissions, and private documents cannot be obtained from public pages. Forms display an unavailable message and POST/API requests return HTTP 501. Implement your own backend or restore a WordPress backup to reproduce those features.

Directly linked public images, fonts, scripts, stylesheets, videos, and documents are downloaded. Hosted players, external services, social profiles, and third-party applications remain external; their servers and streaming videos are not part of the snapshot. Site-owned analytics scripts are removed from the rendered pages.

The crawl excludes administrative endpoints, submission records, and unbounded combinations of filters. Originals remain in `storage/raw/`; local pages are regenerated from those originals as asset downloads complete.

## English text and batch translations

The site uses **English only**. Extract all captured page text into a single dictionary:

```sh
php tools/translations.php extract
```

`locales/en.json` contains stable keys for text nodes and accessibility labels across every captured English page. Existing English edits are preserved on subsequent extractions. `storage/translation-context.json` maps keys to example page URLs. Script code and CSS are excluded from translation.

Edit the English dictionary, then regenerate the local pages:

```sh
php tools/mirror.php --rebuild
```

For future translations, give a translator the English JSON and preserve its keys. Import multiple returned language files in one command:

```sh
php tools/translations.php merge translated/fr.json translated/es.json translated/it.json
```

The importer validates every file before writing any, preserves all English keys, and fills missing translations with English. It prepares language files; it does not change the public site's English-only setting. It does not perform machine translation or call a paid service.

## Files

`docker compose exec -T web php tools/repair-links.php` repairs the local Compose site's missing product pages using captured content and assets, and builds `storage/recovered-catalog.json` for local archive navigation. AH-100, AEROFLOW, VET905, and EDNA series use recovered overviews rather than unavailable original specifications. The Asia EQUUS page reuses captured EQUUS content with regional navigation. Recovered pages appear in the portal under the **Recovered pages** group and can be edited normally. The repair command preserves CMS edits and publication states; reruns can refresh only untouched generated snapshots.

Missing training/scientific-literature indexes and uncaptured archive filter combinations use the catalog, including search, pagination, OR within a category, and AND across categories. Regional indexes prefer regional articles and fall back to global articles where needed. Draft and trashed CMS entries are excluded. Known obsolete paths redirect to matching captured content. Unknown URLs continue to return 404.

`docker compose exec -T web php tools/check-links.php http://127.0.0.1` audits all published/snapshot document routes and internal page links, including links in CMS-edited HTML. It records remaining unavailable references in `storage/links-check.json` and exits with status 1 when broken links remain. Foreign-language, administrative, and non-document links are excluded. `node tools/check-links.cjs` checks recovered products, images, desktop/mobile layouts, archive search/filtering/pagination, redirects, and genuine 404 behavior against the local portal. Back up the recovered catalog alongside the database and snapshot files.

- `tools/mirror.php`: PHP downloader and URL rewriting.
- `public/index.php`: page routing, WordPress asset aliases, and file ranges.
- `public/mirror.js`: local navigation, dynamic asset mapping, and form handling.
- `config.php`: source, regions, asset hosts, exclusions, and storage paths.
- `locales/en.json`: the English text dictionary.
- `tools/translations.php`: extraction and batch import of translation dictionaries.
- `tools/verify.php`: checks file integrity and local HTTP routes.
- `tools/check-interactions.cjs`: checks product pages, the About page, tabs, mobile navigation, and local form handling.
- `storage/browser-check.json`: browser validation results, when generated.
- `storage/interactions-check.json`: navigation and form validation results.

Run `php tools/verify.php` while the local server is running. Browser checks additionally require Playwright and an installed Chromium browser; `tools/check-browser.cjs` uses the tooling downloaded into `.tools/browser/` on this machine.
