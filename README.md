# Magento 2 Page Speed JS Merge Frontend UI

[![Latest Stable Version](https://poser.pugx.org/hryvinskyi/magento2-page-speed-js-merge-frontend-ui/v/stable)](https://packagist.org/packages/hryvinskyi/magento2-page-speed-js-merge-frontend-ui)
[![Total Downloads](https://poser.pugx.org/hryvinskyi/magento2-page-speed-js-merge-frontend-ui/downloads)](https://packagist.org/packages/hryvinskyi/magento2-page-speed-js-merge-frontend-ui)
[![PayPal donate button](https://img.shields.io/badge/paypal-donate-yellow.svg)](https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=volodymyr%40hryvinskyi%2ecom&lc=UA&item_name=Magento%202%20Page%20Speed%20JS%20Merge%20Frontend%20UI&currency_code=USD&bn=PP%2dDonationsBF%3abtn_donateCC_LG%2egif%3aNonHosted "Donate once-off to this project using Paypal")
[![Latest Unstable Version](https://poser.pugx.org/hryvinskyi/magento2-page-speed-js-merge-frontend-ui/v/unstable)](https://packagist.org/packages/hryvinskyi/magento2-page-speed-js-merge-frontend-ui)
[![License](https://poser.pugx.org/hryvinskyi/magento2-page-speed-js-merge-frontend-ui/license)](https://packagist.org/packages/hryvinskyi/magento2-page-speed-js-merge-frontend-ui)

## Description

Frontend implementation of JavaScript merging and minification. Executes JS merge logic on storefront pages.

## Features

- **MinifyMergeFiles Observer** - Triggers file merging/minification
- **RequireJS Management** - Frontend RequireJS handling
- **Cache Management** - Frontend cache operations
- **API Interfaces** - Frontend JS merge operations
- **UI Components** - Display components for merged JS

## Security and cache

The storefront collector (`view/frontend/web/js/require-js-data-collector.js`) reports to
`POST pagespeedjsmerge/js/collect` the RequireJS modules a page loaded that its merged bundle did not contain.
The endpoint:

- **Never purges a page cache.** The `tags` parameter is ignored; the collector now sends it empty, and pages
  rendered by earlier releases may still send the page's tags. A merged bundle file is named after the URL list it
  holds, so a cached page keeps loading the file it references and fetches a missing module on its own; pages pick up
  a richer bundle as they are rendered again.
- **Never deletes or rewrites a bundle file.** A list that gains URLs gets a file of its own. A file is written only
  when absent, to a temporary file that is then renamed into place, so no visitor can receive a missing or half-written
  bundle; page rendering writes through the same writer. The endpoint no longer changes the `last_update` value.
- **Only accepts keys the server issued.** The page carries a collect token: the route key and a keyed hash of it
  under the deployment's crypt key. Any other key, a route key of another store, and any malformed request is answered
  `{"result": false}` and writes nothing. The token is public, so it proves only that the key was issued; rotating
  the crypt key makes the reports of pages still in a cache be refused until those pages expire.
- **Only stores the route key's own static scripts and templates.** The page's RequireJS base URL must be the static
  URL of the theme the route key was rendered with, in its store's locale (the static version and the scheme may
  differ). Each entry must start with that base, end in `.js` or `.html`, have no query, fragment or `.`/`..`
  segment, and exist as a file of that theme under `pub/static`.
- **Adds modules, never replaces them.** Entries are identified by the module name the bundle derives from the URL;
  the first entry for a module wins, and stored entries come before reported ones.
- **Limits its work.** Only a report that brings new modules takes the route key's hold (60 seconds, `holdSeconds`
  of `Model\CollectThrottle`); reports that bring nothing new never block one that does. A report may carry at most
  3000 URLs and adds at most 200 new modules; a stored list holds at most 3000 URLs, and the files it names at most
  20 MB together. An addition past either limit is refused and leaves the list unchanged. The limits are the
  `maxUrlCount`, `maxNewUrlsPerReport` and `maxTotalBytes` arguments of `Model\RecordCollectedUrls` in `etc/di.xml`.

## Upgrading from 1.0.16 or earlier

Lists stored by 1.0.16 and earlier may name files outside the theme's static directory (web root, media, paths with
`..`), and pages keep inlining them until the list is rebuilt. Flush the JS merge lists and bundles once after
deploying this release:

- in the admin, **System > Cache Management > Flush Js Bundling Cache**, which clears the stored lists, the bundle
  directory and the full page cache; or
- on the command line: `rm -rf var/pagespeed_cache pub/static/pagespeed_cache && bin/magento cache:clean full_page`.

Until the first report for a page type arrives after the flush, its pages are sent with no-cache headers; the first
visitor of each page type stores a new list within seconds.

## Installation

```bash
composer require hryvinskyi/magento2-page-speed-js-merge-frontend-ui
bin/magento module:enable Hryvinskyi_PageSpeedJsMergeFrontendUi
bin/magento setup:upgrade
bin/magento cache:flush
```

## Dependencies

- `php: >=8.1`
- `magento/framework: *`
- `magento/module-store: *`
- `magento/module-theme: *`
- `hryvinskyi/magento2-page-speed-api: 1.0.*`
- `hryvinskyi/magento2-page-speed: 1.0.*`
- `hryvinskyi/magento2-page-speed-js-merge: 1.0.*`

## Compatibility

- Magento 2.3.x, 2.4.x
- PHP 8.1+

## License

[MIT License](LICENSE)

## Author

**Volodymyr Hryvinskyi** - volodymyr@hryvinskyi.com
