<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Unit\Controller\Js;

use ArrayObject;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\RecordCollectedUrlsInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Controller\Js\Collect;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint hands a well-formed report to the use case and answers anything else with a refusal, never an error.
 */
class CollectTest extends TestCase
{
    /** @var ArrayObject<int, array{string, string, list<string>}> Reports the use case received */
    private ArrayObject $reports;

    /** @var ArrayObject<int, mixed> Data the JSON answer was given */
    private ArrayObject $answers;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->reports = new ArrayObject();
        $this->answers = new ArrayObject();
    }

    /**
     * A well-formed report reaches the use case unchanged and its verdict is the answer; tags are ignored.
     *
     * @return void
     */
    public function testAWellFormedReportIsDelegated(): void
    {
        $answer = $this->dispatch([
            'key' => 'route.token',
            'tags' => 'store,cms_b',
            'base' => 'https://shop.test/static/',
            'list' => ['https://shop.test/static/a.js'],
        ], true);

        self::assertSame(['result' => true], $answer);
        self::assertSame(
            [['route.token', 'https://shop.test/static/', ['https://shop.test/static/a.js']]],
            $this->reports->getArrayCopy()
        );
    }

    /**
     * The use case's refusal is the answer.
     *
     * @return void
     */
    public function testARefusedReportIsAnsweredWithFalse(): void
    {
        $answer = $this->dispatch(['key' => 'k', 'base' => 'b', 'list' => ['u']], false);

        self::assertSame(['result' => false], $answer);
    }

    /**
     * A malformed request is answered with false and never reaches the use case.
     *
     * @dataProvider malformedRequestProvider
     * @param array<string, mixed> $params Request parameters
     * @return void
     */
    public function testAMalformedRequestIsRefusedWithoutWork(array $params): void
    {
        $answer = $this->dispatch($params, true);

        self::assertSame(['result' => false], $answer);
        self::assertSame([], $this->reports->getArrayCopy());
    }

    /**
     * Requests whose key, base or list has the wrong shape.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedRequestProvider(): array
    {
        $list = ['https://shop.test/static/a.js'];

        return [
            'no parameters' => [[]],
            'key missing' => [['base' => 'b', 'list' => $list]],
            'key is an array' => [['key' => ['k'], 'base' => 'b', 'list' => $list]],
            'base missing' => [['key' => 'k', 'list' => $list]],
            'base is an array' => [['key' => 'k', 'base' => ['b'], 'list' => $list]],
            'list missing' => [['key' => 'k', 'base' => 'b']],
            'list is a string' => [['key' => 'k', 'base' => 'b', 'list' => 'https://shop.test/static/a.js']],
            'list has keys' => [['key' => 'k', 'base' => 'b', 'list' => ['x' => 'https://shop.test/static/a.js']]],
            'list holds an array' => [['key' => 'k', 'base' => 'b', 'list' => [['nested']]]],
            'list holds a number' => [['key' => 'k', 'base' => 'b', 'list' => [1]]],
        ];
    }

    /**
     * Run the action on the parameters with a use case that answers the given verdict.
     *
     * @param array<string, mixed> $params Request parameters
     * @param bool $verdict What the use case answers
     * @return mixed The data of the JSON answer
     */
    private function dispatch(array $params, bool $verdict): mixed
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name): mixed => $params[$name] ?? null
        );

        $answers = $this->answers;
        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(
            static function (mixed $data) use ($answers, $json): Json {
                $answers[] = $data;

                return $json;
            }
        );
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $reports = $this->reports;
        $useCase = new class ($reports, $verdict) implements RecordCollectedUrlsInterface {
            /**
             * @param ArrayObject<int, array{string, string, list<string>}> $reports
             * @param bool $verdict
             */
            public function __construct(private readonly ArrayObject $reports, private readonly bool $verdict)
            {
            }

            /**
             * @inheritDoc
             */
            public function execute(string $token, string $base, array $urls): bool
            {
                $this->reports[] = [$token, $base, $urls];

                return $this->verdict;
            }
        };

        (new Collect($jsonFactory, $request, $useCase))->execute();
        self::assertCount(1, $this->answers);

        return $this->answers[0];
    }
}
