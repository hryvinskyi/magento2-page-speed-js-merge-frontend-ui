<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Api;

/**
 * Tells where the merged RequireJS bundle of a route key lives on disk.
 *
 * @api
 */
interface RequireJsBundleLocatorInterface
{
    /**
     * Absolute path of the bundle file for the URL list currently stored under the route key.
     *
     * The file name is derived from the stored list, so a different list has a different file.
     *
     * @param string $key Route key
     * @return string Absolute file path
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    public function getRequireJsResultFilePath(string $key): string;
}
