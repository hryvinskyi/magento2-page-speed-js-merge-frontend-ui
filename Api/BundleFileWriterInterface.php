<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Api;

/**
 * Publishes a merged RequireJS bundle file that cached pages may already reference.
 *
 * @api
 */
interface BundleFileWriterInterface
{
    /**
     * Write the content to the path when no file exists there.
     *
     * An existing file is never deleted, truncated or rewritten. A new file appears whole: the content is written to
     * a temporary file in the same directory and renamed into place, so a reader sees either no file or all of it.
     * A filesystem failure is logged, never thrown; an exception of the content producer reaches the caller.
     *
     * @param string $path Absolute path of the bundle file
     * @param callable(): string $content Produces the bundle content; called only when no file exists at the path
     * @return void
     */
    public function writeIfAbsent(string $path, callable $content): void;
}
