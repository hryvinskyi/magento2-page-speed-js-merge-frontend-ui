<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Api;

/**
 * Signs a route key for the storefront collector and recovers it from what the collector sends back.
 *
 * A token is printed into every cached page, so it is public: it proves that the server issued the route key,
 * and nothing about the module list sent with it.
 *
 * @api
 */
interface CollectTokenInterface
{
    /**
     * Issue the token the collector of a page with this route key reports under.
     *
     * @param string $routeKey Route key of the rendered page
     * @return string The route key followed by a signature of it
     */
    public function issue(string $routeKey): string;

    /**
     * Recover the route key a token was issued for.
     *
     * @param string $token Token as the collector sent it
     * @return string|null The route key, or null when the token is malformed or its signature does not match
     */
    public function routeKeyOf(string $token): ?string;
}
