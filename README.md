MarvelsDB
=======

# Quick start with Docker

If you have Docker installed, this is the fastest way to get a local copy running —
no PHP, MySQL, or Composer needed on your machine:

```
docker compose up
```

The first run takes several minutes: it builds the PHP 7.4 image, installs composer
dependencies, creates the database schema, and clones + imports the card data from
[marvelsdb-json-data](https://github.com/zzorba/marvelsdb-json-data). Subsequent
starts skip all of that and boot in seconds.

The site is served at http://localhost:8000. Database data persists in a named
Docker volume (`docker compose down -v` resets it). Config lives in
`app/config/parameters.yml`, generated on first run — delete it to regenerate.

To run console commands inside the container:

```
docker compose exec app php bin/console <command>
```

To re-import card data after pulling new json-data (or to work on your own card
data changes, edit the cloned `marvelsdb-json-data/` directory first):

```
docker compose exec app php bin/console app:import:std marvelsdb-json-data/
```

# Very quick guide on how to install a local copy

This guide assumes you know how to use the command-line and that your machine has php 7.4 and mysql 8 installed.

- install composer: https://getcomposer.org/download/
- git clone the repo and `cd` to it
- run `composer install` (at the end it will ask for the database configuration parameters)
- if `composer install` fails with version issues, you may need to run `composer self-update --1` to downgrade to composer version 1
- run `php bin/console doctrine:database:create` to create the database.
- run `php bin/console doctrine:schema:create` to create the database schema.
- git clone the card data from https://github.com/zzorba/marvelsdb-json-data
- run `php bin/console app:import:std path-to-marvelsdb-json-data/` pointing to where you cloned the json data (can be a relative path)
- run `php bin/console server:run`

Additional useful commands.
- run `composer install` to rebuild minified JS files after making changes to the raw files.
- run `php bin/console doctrine:schema:update --dump-sql` to view database schema changes.
- run `php bin/console doctrine:schema:update --force` to execute database schema changes.
