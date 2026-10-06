<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Api;

/**
 * Lets one collect per route key do work within a hold period; the others in that period are answered without work.
 *
 * @api
 */
interface CollectThrottleInterface
{
    /**
     * Take the hold for a route key.
     *
     * Only a report that would add modules takes the hold, before anything is written, so a burst of such reports
     * for one key does the work once and a report that adds nothing never blocks one that does.
     *
     * @param string $routeKey Route key recovered from a valid token
     * @return bool True when no hold was active and one is now set; false while a hold is active
     */
    public function claim(string $routeKey): bool;
}
