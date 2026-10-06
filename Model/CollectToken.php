<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectTokenInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Token format: the route key, a dot, and the keyed hash of a fixed context string followed by the route key.
 *
 * The context string keeps a signature that another feature computes with the same deployment key from passing as a
 * token. The hash is hexadecimal, so splitting on the last dot recovers the route key whatever characters it holds.
 */
class CollectToken implements CollectTokenInterface
{
    private const CONTEXT = 'hryvinskyi_pagespeed_js_merge_collect:';
    private const SEPARATOR = '.';

    /**
     * @param EncryptorInterface $encryptor Computes the keyed hash under the deployment's crypt key
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * @inheritDoc
     */
    public function issue(string $routeKey): string
    {
        return $routeKey . self::SEPARATOR . $this->sign($routeKey);
    }

    /**
     * @inheritDoc
     */
    public function routeKeyOf(string $token): ?string
    {
        $separator = strrpos($token, self::SEPARATOR);
        if ($separator === false || $separator === 0) {
            return null;
        }

        $routeKey = substr($token, 0, $separator);
        $signature = substr($token, $separator + 1);
        if ($signature === '' || !hash_equals($this->sign($routeKey), $signature)) {
            return null;
        }

        return $routeKey;
    }

    /**
     * Keyed hash of the context string followed by the route key.
     *
     * @param string $routeKey Route key to sign
     * @return string Hexadecimal signature
     */
    private function sign(string $routeKey): string
    {
        return (string)$this->encryptor->hash(self::CONTEXT . $routeKey);
    }
}
