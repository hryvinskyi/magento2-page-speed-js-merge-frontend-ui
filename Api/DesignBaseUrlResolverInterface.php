<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Api;

/**
 * Tells which RequireJS base URL a page with a given route key was rendered with.
 *
 * A route key ends in the hash of its design theme's code and the id of its store; the base URL is the static URL
 * of that theme in the store's locale, the same base the merged bundle derives its module keys from.
 *
 * @api
 */
interface DesignBaseUrlResolverInterface
{
    /**
     * The insecure and secure static base URLs of the route key's design.
     *
     * @param string $routeKey Route key recovered from a valid token
     * @return list<string> The base URLs, each ending in "/"; empty when the route key belongs to another store than
     *                      the current one, or names no known frontend theme
     */
    public function forRouteKey(string $routeKey): array;
}
