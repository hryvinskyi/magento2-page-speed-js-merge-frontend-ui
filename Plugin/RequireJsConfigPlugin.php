<?php
/**
 * Copyright (c) 2022-2024. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Plugin;

use Magento\Framework\Code\Minifier\AdapterInterface;
use Magento\Framework\RequireJs\Config;

/**
 * Plugin to add a global RequireJS ready marker after RequireJS initialization.
 *
 * This ensures that any code waiting for RequireJS to be fully initialized
 * (including the min resolver which adds .min.js suffix when minification is enabled)
 * can safely check window.requireJsReady or listen for the 'requirejs:ready' event.
 */
class RequireJsConfigPlugin
{
    /**
     * @var AdapterInterface
     */
    private $minifyAdapter;

    /**
     * Constructor
     *
     * @param AdapterInterface $minifyAdapter
     */
    public function __construct(
        AdapterInterface $minifyAdapter
    ) {
        $this->minifyAdapter = $minifyAdapter;
    }

    /**
     * Add RequireJS ready marker after the config code when minification is disabled.
     *
     * @param Config $subject
     * @param string $result
     * @return string
     */
    public function afterGetConfig(Config $subject, string $result): string
    {
        return $result . $this->minifyAdapter->minify($this->getReadyCode());
    }

    /**
     * Get the JavaScript code that sets the ready marker and dispatches events.
     *
     * @return string
     */
    private function getReadyCode(): string
    {
        return <<<JS
    ;(function() {
        window.requireJsReady = true;

        var readyEvent = new CustomEvent('requirejs:ready', {
            bubbles: true,
            cancelable: false,
            detail: { timestamp: Date.now() }
        });
        document.dispatchEvent(readyEvent);

        // Backward compatibility: dispatch requirejs:loaded on window
        window.dispatchEvent(new Event('requirejs:loaded'));
    })();
JS;
    }
}
