<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration;

/**
 * Reads the scripts a merged RequireJS bundle inlines, keyed by the module key RequireJS asks for.
 */
class BundleModules
{
    /**
     * The inlined scripts of a bundle file's content.
     *
     * @param string $bundle Bundle file content
     * @return array<string, string> Script content by module key; empty when the content is no bundle
     */
    public static function scripts(string $bundle): array
    {
        if (preg_match('/^require\.config\(\{config:(.*)\}\);require\.config\(\{bundles:/s', $bundle, $match) !== 1) {
            return [];
        }

        $config = json_decode($match[1], true);
        $scripts = is_array($config) && is_array($config['jsbuild'] ?? null) ? $config['jsbuild'] : [];
        $result = [];
        foreach ($scripts as $moduleKey => $content) {
            if (is_string($content)) {
                $result[(string)$moduleKey] = $content;
            }
        }

        return $result;
    }
}
