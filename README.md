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

## Installation

```bash
composer require hryvinskyi/magento2-page-speed-js-merge-frontend-ui
bin/magento module:enable Hryvinskyi_PageSpeedJsMergeFrontendUi
bin/magento setup:upgrade
bin/magento cache:flush
```

## Dependencies

- `magento/framework: *`
- `hryvinskyi/magento2-page-speed-js-merge: 1.0.*`

## Compatibility

- Magento 2.3.x, 2.4.x
- PHP 7.4+, 8.0+, 8.1+

## License

[MIT License](LICENSE)

## Author

**Volodymyr Hryvinskyi** - volodymyr@hryvinskyi.com
