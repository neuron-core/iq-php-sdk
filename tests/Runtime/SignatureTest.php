<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runtime;

use NeuronCore\IQ\Runtime\Signature;
use NeuronCore\IQ\Tests\Support\ProtocolSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function ctype_digit;
use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class SignatureTest extends TestCase
{
    /**
     * @param array{key: ?string, id: string, timestamp: string, body: string, signature: ?string, header: string, now: int, valid: bool} $vector
     */
    #[DataProvider('signable')]
    public function test_a_signer_produces_the_signature_of_the_vector(array $vector): void
    {
        $signature = (new Signature($vector['key']))->sign($vector['id'], (int) $vector['timestamp'], $vector['body']);

        self::assertSame($vector['signature'], $signature);
    }

    /**
     * @param array{key: ?string, id: string, timestamp: string, body: string, signature: ?string, header: string, now: int, valid: bool} $vector
     */
    #[DataProvider('vectors')]
    public function test_a_receiver_answers_what_the_vector_says(array $vector): void
    {
        $verified = (new Signature($vector['key']))->verify(
            $vector['id'],
            $vector['timestamp'],
            $vector['header'],
            $vector['body'],
            $vector['now'],
        );

        self::assertSame($vector['valid'], $verified);
    }

    public function test_the_vectors_include_the_one_standard_webhooks_publishes(): void
    {
        $published = self::vectors()['the published Standard Webhooks vector'][0];

        self::assertSame('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', $published['key']);
        self::assertSame('msg_p5jXN8AQM9LWM0D4loKWxJek', $published['id']);
        self::assertSame('1614265330', $published['timestamp']);
        self::assertSame('{"test": 2432232314}', $published['body']);
        self::assertSame('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', $published['signature']);
        self::assertTrue($published['valid']);
    }

    /**
     * @return array<string, array{array<string, mixed>}> the vectors a signer can be given: it takes the timestamp as a number
     */
    public static function signable(): array
    {
        return array_filter(self::vectors(), static fn (array $row): bool => ctype_digit($row[0]['timestamp']));
    }

    /**
     * @return array<string, array{array<string, mixed>}> the vectors of resources/protocol/1, by name
     */
    public static function vectors(): array
    {
        $document = json_decode(
            (string) file_get_contents(ProtocolSchema::directory() . '/signature-vectors.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $rows = [];
        foreach ($document['vectors'] as $vector) {
            $rows[$vector['name']] = [$vector];
        }

        return $rows;
    }
}
