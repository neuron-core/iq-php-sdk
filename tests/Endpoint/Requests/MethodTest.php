<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Endpoint\Requests;

use NeuronCore\Cloud\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Step 1 of the endpoint: a method other than POST is answered 405, before
 * the signature is looked at.
 */
class MethodTest extends RequestTestCase
{
    protected const NOT_A_POST = [405, '{"code":"invalid_request"}'];

    #[DataProvider('spellingsOfPost')]
    public function test_post_is_read_whatever_its_case(string $backend, string $method): void
    {
        $this->shop($backend);

        $answer = $this->post(self::PING, method: $method);

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('methodsOtherThanPost')]
    public function test_a_start_the_platform_signed_is_answered_405_and_does_nothing_under_another_method(string $backend, string $method): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());

        $answer = $this->post($body, $this->signed($body, 'inv_start'), $method);

        self::assertSame(self::NOT_A_POST, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_an_unsigned_get_is_answered_405_not_401(string $backend): void
    {
        $this->shop($backend);

        $answer = $this->post('', [], 'GET');

        self::assertSame(self::NOT_A_POST, self::statusAndBody($answer));
    }

    #[DataProvider('backends')]
    public function test_a_get_is_answered_405_by_an_endpoint_that_holds_no_signing_key(string $backend): void
    {
        $this->shop($backend);
        $this->cloud = $this->deploymentHolding(null);

        $answer = $this->post('', [], 'GET');

        self::assertSame(self::NOT_A_POST, self::statusAndBody($answer));
    }

    #[DataProvider('backends')]
    public function test_a_post_is_answered_401_by_an_endpoint_that_holds_no_signing_key(string $backend): void
    {
        $this->shop($backend);
        $this->cloud = $this->deploymentHolding(null);

        $answer = $this->post('', []);

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function spellingsOfPost(): array
    {
        return self::onEveryBackend(['POST' => ['POST'], 'post' => ['post'], 'Post' => ['Post'], 'pOsT' => ['pOsT']]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function methodsOtherThanPost(): array
    {
        return self::onEveryBackend([
            'GET' => ['GET'],
            'get' => ['get'],
            'HEAD' => ['HEAD'],
            'PUT' => ['PUT'],
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
            'OPTIONS' => ['OPTIONS'],
            'no method' => [''],
            'POST followed by a space' => ['POST '],
            'POST after a space' => [' POST'],
            'a method that begins with POST' => ['POSTS'],
            'two methods' => ['GET, POST'],
        ]);
    }
}
