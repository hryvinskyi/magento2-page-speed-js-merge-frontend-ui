<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Api;

/**
 * Decides which module URLs may become part of a route key's merged RequireJS bundle.
 *
 * Every accepted URL is keyed by its module key: the path segments that follow as many segments as the base URL
 * has, which is how the bundle names the module it inlines. A list holds one URL per module key, so no URL can
 * stand in for a module another URL already provides.
 *
 * @api
 */
interface CollectedUrlFilterInterface
{
    /**
     * Whether the base URL a page reported is one of the base URLs of its route key's design.
     *
     * The static version segment and the scheme are ignored in the comparison: a page cached before a deployment
     * still reports under the version it was rendered with.
     *
     * @param list<string> $designBaseUrls Base URLs of the route key's design
     * @param string $base RequireJS base URL the page reported
     * @return bool
     */
    public function acceptsBase(array $designBaseUrls, string $base): bool;

    /**
     * Keep the reported URLs that start with the base and name an existing script or template of its design.
     *
     * A URL with a query, a fragment, an empty, "." or ".." segment, a suffix other than ".js" or ".html", or no
     * file behind it is dropped. The first URL for a module key wins.
     *
     * @param string $base Accepted RequireJS base URL
     * @param list<string> $urls Module URLs the page reported
     * @return array<string, string> The kept URLs, keyed by module key
     */
    public function filterReported(string $base, array $urls): array;

    /**
     * Keep the stored URLs that still name an existing script or template under the base's design.
     *
     * A stored URL qualifies when its leading segments equal the base apart from the static version and the scheme.
     * The first URL for a module key wins.
     *
     * @param string $base Accepted RequireJS base URL
     * @param list<string> $urls URLs of a stored list
     * @return array<string, string> The kept URLs, keyed by module key
     */
    public function filterStored(string $base, array $urls): array;

    /**
     * Total size in bytes of the files the module keys name under the base's design.
     *
     * @param string $base Accepted RequireJS base URL
     * @param list<string> $moduleKeys Module keys
     * @return int
     */
    public function totalBytes(string $base, array $moduleKeys): int;
}
