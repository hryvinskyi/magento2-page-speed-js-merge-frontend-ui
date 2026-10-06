<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Api;

/**
 * Adds the RequireJS modules a storefront page reported to the URL list of its route key.
 *
 * It never purges a page cache, never deletes or rewrites a bundle file a cached page may reference, and accepts only
 * route keys the server issued a token for.
 *
 * @api
 */
interface RecordCollectedUrlsInterface
{
    /**
     * Record the reported module URLs.
     *
     * @param string $token Collect token the page was rendered with
     * @param string $base RequireJS base URL of the page
     * @param list<string> $urls Module URLs the page loaded that its bundle did not contain
     * @return bool False when the report is refused; true when it is accepted, including when it adds nothing or
     *              arrives while another report for the same route key holds the key
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     * @throws \Zend_Cache_Exception When the URL list storage cannot be opened
     */
    public function execute(string $token, string $base, array $urls): bool;
}
