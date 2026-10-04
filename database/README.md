# Database setup

`make up` prepares a persistent local MySQL 8.4 database, migrates it, seeds the downloaded website, and starts PHP/Apache. `.env` holds randomly generated local credentials. `pw.txt` retains the remote credentials and is not used for the default Docker connection.

MySQL listens on `127.0.0.1:3307` for local database clients and on `db:3306` inside Compose. The named `indiba_mysql-data` volume preserves data when containers are restarted, recreated, or removed with `make down`.

## Migrations

Run `make db-migrate`, or `php tools/db-migrate.php` with the desired database environment configured.

- `migrations/001_create_cms_tables.php` creates users, pages, media, audit, and login-attempt tables.
- `migrations/002_page_management.php` adds search metadata and groups to pages, then creates revisions and redirects.
- `migrations/003_separate_news.php` adds a persistent `content_type` and an index for separate admin lists, and classifies existing news routes without changing content or update dates.
- `migrations/004_master_pages_and_events.php` separates archive listing/filter screens into Master pages, individual news articles into News, and individual event entries into Events, using original HTML. Existing content, versions, and update dates are preserved.

The first two migrations reproduce the seven application tables read from the remote database using `SHOW CREATE TABLE`. Later migrations add portal features, including the `page`/`master`/`news`/`event` content types. No foreign keys have been added because the existing schema has none. `indiba_cms_migrations` is an additional migration-history table.

The runner uses a database lock, records each migration's name and checksum, and skips applied versions. It accepts existing CMS tables and adds only missing schema. MySQL DDL commits implicitly, so migrations tolerate a retry after partial failure. Create a new numbered migration to change the schema; do not edit an already applied migration. Windows and Unix line endings produce the same checksum.

## Seeding

Run `make db-seed`, or `php tools/db-seed.php` after migrating. `make cms-install` performs both steps; `make cms-upgrade` performs migrations only.

`seeders/001_cms_content.php` reads `storage/manifest.json`, indexes the saved HTML pages and asset metadata, and creates an `admin` account only when no administrators exist. The initial local password is saved in `storage/local-admin-bootstrap.txt`; it is never printed. Change it through **Your account** after signing in.

Seeding is transactional and serialized with a database lock. Existing routes and media paths are skipped, so reruns preserve page content, publication status, revisions, uploads, and passwords. New manifest entries are imported on later runs. Remote edited HTML and administrator accounts are not copied by this seeder.

Optional environment variables:

| Variable | Purpose |
| --- | --- |
| `CMS_IMPORT_MANIFEST` | Use a different import manifest, such as the small test fixture. |
| `CMS_BOOTSTRAP_FILE` | Choose the private initial-administrator credential file. |
| `CMS_INSTALL_FILE` | Choose the CMS installation marker; local Compose uses `storage/local-cms-installed.json`. |
| `CMS_ENABLED=1` | Enable managed pages without requiring an installation marker. |

`php tools/db-seed.php --no-activate` seeds without writing the installation marker. Compose waits for MySQL's authenticated health check and a successful migration/seeding job before starting the website. See [Docker's startup-order documentation](https://docs.docker.com/compose/how-tos/startup-order/) for these dependency conditions.

## Remote database

`docker compose -f compose.remote.yaml up -d --build --wait web` runs a separate instance on port 9001 using `pw.txt`. This does not create, seed, or alter the remote database. Standard CLI migration and seed commands act on the connection configured for the PHP process in which they are run.

## Verification

`make check` verifies downloaded files and public HTTP routes. `tools/check-database.php` checks fresh migration, rerun safety, adoption of an older schema, and seed rollback. It is restricted to the isolated `indiba_cms_test` database at `indiba-cms-test-db`; it must never run against the development or remote database. Existing browser suites use a separate web container on port 9002 with that small test fixture.

`node tools/check-news.cjs` checks News, Master pages, and Events classification, creation, URL changes, duplication, section changes, revisions, and bulk Trash/restore on the isolated portal at port 9002. `node tools/check-news.cjs --read-only` checks the existing local portal's lists, filters, and navigation without changing its content.
