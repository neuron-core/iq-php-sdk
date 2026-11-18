<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function base64_decode;
use function substr;
use function time;

/**
 * "A key that is missing, empty or does not decode is no key: every request
 * is answered 401", also a request signed with whatever such a key holds.
 */
class SigningKeyTest extends RequestTestCase
{
    /** Twenty-four bytes in base64, as the platform issues them after "whsec_". */
    protected const KEY_BYTES = 'bmV1cm9uLWNsb3VkLXByb3RvY29sLTEtdmVjdG9ycyEh';

    #[DataProvider('keysThatAreNoKey')]
    public function test_an_endpoint_that_holds_no_usable_key_answers_401_to_a_start_the_platform_signed(string $backend, ?string $signingKey): void
    {
        $this->shop($backend);
        $this->iq = $this->deploymentHolding($signingKey);

        $answer = $this->deliver($this->start());

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('keysThatAreNoKey')]
    public function test_an_endpoint_that_holds_no_usable_key_does_not_answer_a_ping(string $backend, ?string $signingKey): void
    {
        $this->shop($backend);
        $this->iq = $this->deploymentHolding($signingKey);

        $answer = $this->post(self::PING);

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
    }

    #[DataProvider('whatAKeyThatIsNoKeyCouldBeReadAs')]
    public function test_a_start_signed_with_what_an_unusable_key_holds_is_answered_401_and_does_nothing(string $backend, ?string $signingKey, string $bytes): void
    {
        $this->shop($backend);
        $this->iq = $this->deploymentHolding($signingKey);
        $body = self::encode($this->start());
        $timestamp = (string) time();

        $answer = $this->post($body, [
            'webhook-id' => 'inv_start',
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => self::signature($bytes, 'inv_start', $timestamp, $body),
        ]);

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_a_key_of_the_platform_is_read_as_the_bytes_its_base64_holds(string $backend): void
    {
        $this->shop($backend);
        $this->iq = $this->deploymentHolding('whsec_' . self::KEY_BYTES);
        $timestamp = (string) time();

        $answer = $this->post(self::PING, [
            'webhook-id' => self::WEBHOOK_ID,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => self::signature((string) base64_decode(self::KEY_BYTES, true), self::WEBHOOK_ID, $timestamp, self::PING),
        ]);

        self::assertSame(200, $answer->statusCode);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function keysThatAreNoKey(): array
    {
        return self::onEveryBackend([
            'no key' => [null],
            'an empty key' => [''],
            'a key that is only its prefix' => ['whsec_'],
            'a key without the whsec_ prefix' => [self::KEY_BYTES],
            'a key whose prefix is in upper case' => ['WHSEC_' . self::KEY_BYTES],
            'a key that does not decode' => ['whsec_not*base64*at*all'],
            'a key that is not text after its prefix' => ["whsec_\xFF\xFE"],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> the key the endpoint holds, then the bytes a signer could take it for
     */
    public static function whatAKeyThatIsNoKeyCouldBeReadAs(): array
    {
        return self::onEveryBackend([
            'no key, read as no bytes' => [null, ''],
            'an empty key, read as no bytes' => ['', ''],
            'a key that is only its prefix, read as no bytes' => ['whsec_', ''],
            'a key that is only its prefix, read as it is written' => ['whsec_', 'whsec_'],
            'a key without the whsec_ prefix, read as its base64' => [self::KEY_BYTES, (string) base64_decode(self::KEY_BYTES, true)],
            'a key without the whsec_ prefix, read as it is written' => [self::KEY_BYTES, self::KEY_BYTES],
            'a key without the whsec_ prefix, read as the base64 after its first six characters' => [
                self::KEY_BYTES,
                (string) base64_decode(substr(self::KEY_BYTES, 6), true),
            ],
            'a key whose prefix is in upper case, read as the base64 after its prefix' => [
                'WHSEC_' . self::KEY_BYTES,
                (string) base64_decode(self::KEY_BYTES, true),
            ],
            'a key that does not decode, read as the base64 it holds between its other characters' => [
                'whsec_not*base64*at*all',
                base64_decode('not*base64*at*all'),
            ],
            'a key that does not decode, read as it is written' => ['whsec_not*base64*at*all', 'not*base64*at*all'],
        ]);
    }
}
