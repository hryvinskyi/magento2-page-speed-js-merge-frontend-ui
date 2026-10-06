<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Unit\Model;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\CollectToken;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;

/**
 * A token signs its route key under the deployment's crypt key; only a token the server issued yields a route key.
 */
class CollectTokenTest extends TestCase
{
    private const CRYPT_KEY = 'a2f4c6e8b0d1f3a5c7e9b1d3f5a7c9e1';
    private const OTHER_CRYPT_KEY = 'f0e1d2c3b4a5968778695a4b3c2d1e0f';
    private const ROUTE_KEY = 'catalog_product_view____catalog_product_view_type_simple___0f1e2d3c___1';

    /**
     * An issued token yields the route key it was issued for, whatever characters the route key holds.
     *
     * @dataProvider routeKeyProvider
     * @param string $routeKey Route key to issue a token for
     * @return void
     */
    public function testAnIssuedTokenYieldsItsRouteKey(string $routeKey): void
    {
        $collectToken = $this->collectToken(self::CRYPT_KEY);

        self::assertSame($routeKey, $collectToken->routeKeyOf($collectToken->issue($routeKey)));
    }

    /**
     * Route keys of several shapes, dots included.
     *
     * @return array<string, array{string}>
     */
    public static function routeKeyProvider(): array
    {
        return [
            'default and a page handle' => [self::ROUTE_KEY],
            'first handle is not default' => [
                'catalog_category_view____catalog_category_view_type_brands_page___9a8b7c6d___3',
            ],
            'contains dots' => ['cms.page.view___a.b___1'],
            'single character' => ['k'],
        ];
    }

    /**
     * A key the server did not issue yields nothing.
     *
     * @dataProvider unissuedKeyProvider
     * @param callable(CollectToken): string $token Builds the key from a token service under the deployment key
     * @return void
     */
    public function testAKeyTheServerDidNotIssueYieldsNothing(callable $token): void
    {
        $collectToken = $this->collectToken(self::CRYPT_KEY);

        self::assertNull($collectToken->routeKeyOf($token($collectToken)));
    }

    /**
     * Keys that look like tokens but were not issued for the route key they carry.
     *
     * @return array<string, array{callable(CollectToken): string}>
     */
    public static function unissuedKeyProvider(): array
    {
        return [
            'plain route key' => [static fn (): string => self::ROUTE_KEY],
            'empty' => [static fn (): string => ''],
            'only a dot' => [static fn (): string => '.'],
            'missing signature' => [static fn (): string => self::ROUTE_KEY . '.'],
            'missing route key' => [
                static fn (CollectToken $token): string => substr(
                    $token->issue(self::ROUTE_KEY),
                    strlen(self::ROUTE_KEY)
                ),
            ],
            'tampered signature' => [
                static function (CollectToken $token): string {
                    $issued = $token->issue(self::ROUTE_KEY);
                    $last = substr($issued, -1);

                    return substr($issued, 0, -1) . ($last === '0' ? '1' : '0');
                },
            ],
            'signature of another route key' => [
                static function (CollectToken $token): string {
                    $issued = $token->issue('another_route_key___1');

                    return self::ROUTE_KEY . substr($issued, (int)strrpos($issued, '.'));
                },
            ],
            'keyed hash of the bare route key without the context' => [
                static fn (): string => self::ROUTE_KEY . '.'
                    . hash_hmac('sha256', self::ROUTE_KEY, self::CRYPT_KEY),
            ],
        ];
    }

    /**
     * A token issued under another crypt key yields nothing.
     *
     * @return void
     */
    public function testATokenIssuedUnderAnotherCryptKeyYieldsNothing(): void
    {
        $foreignToken = $this->collectToken(self::OTHER_CRYPT_KEY)->issue(self::ROUTE_KEY);

        self::assertNull($this->collectToken(self::CRYPT_KEY)->routeKeyOf($foreignToken));
    }

    /**
     * A token service on a real encryptor with the given crypt key.
     *
     * @param string $cryptKey Deployment crypt key
     * @return CollectToken
     */
    private function collectToken(string $cryptKey): CollectToken
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (?string $path = null): ?string => $path === Encryptor::PARAM_CRYPT_KEY ? $cryptKey : null
        );

        return new CollectToken(new Encryptor(new Random(), $deploymentConfig, new KeyValidator()));
    }
}
