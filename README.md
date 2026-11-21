# Typesense Search for Maho

Typesense Search adds product suggestions and a paged results view to Maho 27.1. Each enabled store view has its own index. Maho loads the live products, links and prices before rendering.

## Install on the Maho 27.1 preview

The package targets Maho 27.1 and PHP 8.5.1 or later. The Composer requirement excludes Maho 26.11 and earlier stable releases.

From a Maho 27.1 source checkout, add the module repository and install its preview branch.

```sh
composer config repositories.typesense-search vcs https://github.com/ugoparsi/module-typesense-search
COMPOSER_ROOT_VERSION=27.1.x-dev composer require ugoparsi/module-typesense-search:dev-main
```

Composer installs the SDK and compiles the routes, observers and queue handler. Run `composer dump-autoload` after changing the module's PHP attributes.

For a local copy, use `composer config repositories.typesense-search path ../typesense-module` for the repository step.

## Set up a store view

1. In **System → Configuration → Catalog → Typesense Search**, choose the store view and enter the server URL. Use the base URL, for example `http://127.0.0.1:8108` for a local server or the HTTPS URL for a hosted server. Include the base path if a proxy mounts the server there.
2. Set a collection prefix unique to the Maho installation. Use lowercase letters, digits, underscores or hyphens, starting with a letter or digit. The module adds the store view ID to each collection alias.
3. Enter two server-side keys. The search key needs document search access to the store view alias. The index key needs to create and remove the generated collections, import, update and remove documents, and read and update aliases for the prefix. Both settings use encrypted config fields.
4. Enable the view and run `./maho index:reindex typesensesearch` or reindex **Typesense Catalog Search** in Index Management. This populates the index from the current catalog. Keep the native Catalog Search index populated for fallback.
5. Run Maho cron as normal so the queue processes changes to products.

The storefront uses native Maho search until the first successful rebuild. If Typesense is unavailable, requests return to Maho search. Disable the setting to restore the native form URLs in that view.

A rebuild prepares a new collection and switches the store view alias after every batch succeeds. Incremental changes use Maho's queue and read the current product state before updating or removing a document. Workers that share a catalog across hosts use Maho's shared lock backend.

The module changes the two public search URL methods on the catalog search helper. Check those methods when combining this module with another extension that replaces the helper.

## Tests

Install the package into an isolated Maho 27.1 checkout with its development dependencies. Select a synthetic SQLite database and run the package integration suite from that checkout.

```sh
vendor/bin/pest vendor/ugoparsi/module-typesense-search/tests/IntegrationTest.php
```

The tests use the real Typesense PHP client with a simulated PSR-18 transport. A local Typesense 30.2 smoke check used Maho `74cf3ce08`, PHP 8.5 and SQLite. It covered first indexing, searches in an enabled view, the native form in a disabled view and fallback after stopping the engine.

## Licenses

PHP is under OSL-3.0. Layouts and templates are under AFL-3.0. The Typesense PHP client is a separate Apache-2.0 dependency.
