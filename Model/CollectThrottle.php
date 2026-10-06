<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model;

use Hryvinskyi\PageSpeedJsMerge\Model\Cache\JsList;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectThrottleInterface;

/**
 * Keeps the hold as an expiring entry next to the URL lists.
 *
 * The file cache has no atomic "add": two collects in the same instant can both take the hold. The update one of them
 * may lose is reported again by later page views, so that race is accepted.
 */
class CollectThrottle implements CollectThrottleInterface
{
    /**
     * Cache ids accept only letters, digits and underscores; the route key is hashed into that alphabet.
     */
    private const HOLD_ID_PREFIX = 'collect_hold_';

    /**
     * @param JsList $jsList Storage of the URL lists, which also keeps the holds
     * @param int $holdSeconds How long a claimed hold lasts
     */
    public function __construct(
        private readonly JsList $jsList,
        private readonly int $holdSeconds = 60
    ) {
    }

    /**
     * @inheritDoc
     * @throws \Zend_Cache_Exception When the storage cannot be opened
     */
    public function claim(string $routeKey): bool
    {
        $cache = $this->jsList->getCache();
        $holdId = self::HOLD_ID_PREFIX . md5($routeKey);
        if ($cache->test($holdId) !== false) {
            return false;
        }

        return $cache->save('1', $holdId, [], $this->holdSeconds);
    }
}
