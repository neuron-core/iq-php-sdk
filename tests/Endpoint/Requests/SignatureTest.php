<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Endpoint\Requests;

use Closure;
use NeuronAI\HttpClient\HttpResponse;
use NeuronCore\IQ\Tests\Support\Requests\RequestTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function array_unique;
use function array_values;
use function base64_decode;
use function bin2hex;
use function date;
use function dechex;
use function implode;
use function json_encode;
use function sprintf;
use function str_repeat;
use function strlen;
use function strtoupper;
use function strtr;
use function substr;
use function time;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * Step 2 of the endpoint: the Standard Webhooks signature of a request, and
 * the one answer to everything that does not verify. Each refused request is
 * a start the platform could have sent: accepted, it would be in the queue.
 */
class SignatureTest extends RequestTestCase
{
    protected const START_ID = 'inv_start';

    protected const STRANGER_KEY = 'a key the platform never held';

    public function test_the_signatures_of_this_suite_are_those_of_the_published_standard_webhooks_vector(): void
    {
        $signature = self::signature((string) base64_decode('MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', true), 'msg_p5jXN8AQM9LWM0D4loKWxJek', '1614265330', '{"test": 2432232314}');

        self::assertSame('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=', $signature);
    }

    #[DataProvider('backends')]
    public function test_a_start_the_platform_signed_is_queued(string $backend): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());

        $answer = $this->post($body, $this->signed($body, self::START_ID));

        self::assertSame([202, 1], [$answer->statusCode, $this->done()['messages queued']]);
    }

    #[DataProvider('requestsThatLackAHeader')]
    public function test_a_request_that_lacks_a_header_of_the_signature_is_answered_401_and_does_nothing(string $backend, Closure $strip): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());

        $answer = $this->post($body, $strip($this->signed($body, self::START_ID)));

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('signaturesThatDoNotCoverTheRequest')]
    public function test_a_signature_that_does_not_cover_what_was_received_is_answered_401_and_does_nothing(string $backend, Closure $forge): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());

        $answer = $this->post($body, $forge($this->signed($body, self::START_ID), $body, $this->platformKey()));

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('timestampsInsideTheWindow')]
    public function test_a_timestamp_within_300_seconds_of_the_clock_is_accepted(string $backend, int $secondsFromNow): void
    {
        $answer = $this->atOneInstant(function (int $now) use ($backend, $secondsFromNow): HttpResponse {
            $this->shop($backend);

            return $this->deliver($this->start(), $now + $secondsFromNow);
        });

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('timestampsOutsideTheWindow')]
    public function test_a_timestamp_more_than_300_seconds_from_the_clock_is_answered_401_and_does_nothing(string $backend, int $secondsFromNow): void
    {
        $answer = $this->atOneInstant(function (int $now) use ($backend, $secondsFromNow): HttpResponse {
            $this->shop($backend);

            return $this->deliver($this->start(), $now + $secondsFromNow);
        });

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('timestampsThatAreNoNumberOfSeconds')]
    public function test_a_timestamp_that_is_not_a_number_of_seconds_is_answered_401_and_does_nothing(string $backend, Closure $write): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());
        $timestamp = $write(time());

        $answer = $this->post($body, [
            'webhook-id' => self::START_ID,
            'webhook-timestamp' => $timestamp,
            // Signed over the header as it is sent: only the timestamp is wrong.
            'webhook-signature' => self::signature($this->platformKey(), self::START_ID, $timestamp, $body),
        ]);

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('timestampsThatAreNoNumberOfSeconds')]
    public function test_a_timestamp_that_is_not_a_number_of_seconds_is_not_read_as_the_instant_it_resembles(string $backend, Closure $write): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());
        $now = time();

        $answer = $this->post($body, [
            'webhook-id' => self::START_ID,
            'webhook-timestamp' => $write($now),
            // Signed over the instant a forgiving reader would take the header for.
            'webhook-signature' => self::signature($this->platformKey(), self::START_ID, (string) $now, $body),
        ]);

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_a_timestamp_written_with_leading_zeros_is_read_as_the_same_instant(string $backend): void
    {
        $this->shop($backend);
        $headers = $this->signed(self::PING);

        $answer = $this->post(self::PING, ['webhook-timestamp' => '00' . $headers['webhook-timestamp']] + $headers);

        self::assertSame(200, $answer->statusCode);
    }

    #[DataProvider('headersThatHoldNoSignatureOfVersion1')]
    public function test_a_signature_header_that_holds_no_v1_signature_is_answered_401_and_does_nothing(string $backend, Closure $rewrite): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());
        $headers = $this->signed($body, self::START_ID);

        $answer = $this->post($body, ['webhook-signature' => $rewrite(substr($headers['webhook-signature'], strlen('v1,')))] + $headers);

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('placesAmongSeveralSignatures')]
    public function test_one_valid_signature_among_several_in_one_header_value_is_enough(string $backend, int $place): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());
        $headers = $this->signed($body, self::START_ID);
        $signatures = $this->signaturesOfOtherKeys($body, $headers['webhook-timestamp']);
        $signatures[$place] = $headers['webhook-signature'];

        $answer = $this->post($body, ['webhook-signature' => implode(' ', $signatures)] + $headers);

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_several_signatures_in_one_header_value_none_of_them_valid_are_answered_401_and_do_nothing(string $backend): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());
        $headers = $this->signed($body, self::START_ID);

        $answer = $this->post($body, ['webhook-signature' => implode(' ', $this->signaturesOfOtherKeys($body, $headers['webhook-timestamp']))] + $headers);

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('placesAmongSeveralSignatures')]
    public function test_one_valid_signature_among_several_values_of_the_header_is_enough(string $backend, int $place): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());
        $headers = $this->signed($body, self::START_ID);
        $signatures = $this->signaturesOfOtherKeys($body, $headers['webhook-timestamp']);
        $signatures[$place] = $headers['webhook-signature'];

        $answer = $this->post($body, ['webhook-signature' => $signatures] + $headers);

        self::assertSame(202, $answer->statusCode);
    }

    #[DataProvider('backends')]
    public function test_several_values_of_the_header_none_of_them_valid_are_answered_401_and_do_nothing(string $backend): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());
        $headers = $this->signed($body, self::START_ID);

        $answer = $this->post($body, ['webhook-signature' => $this->signaturesOfOtherKeys($body, $headers['webhook-timestamp'])] + $headers);

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('bodiesAStrangerSends')]
    public function test_a_request_that_does_not_verify_is_answered_401_whatever_its_body_says(string $backend, string $body): void
    {
        $this->shop($backend);
        $timestamp = (string) time();

        $answer = $this->post($body, [
            'webhook-id' => self::START_ID,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => self::signature(self::STRANGER_KEY, self::START_ID, $timestamp, $body),
        ]);

        self::assertSame(self::INVALID_SIGNATURE, self::statusAndBody($answer));
        $this->assertNothingWasDone();
    }

    #[DataProvider('backends')]
    public function test_every_signature_problem_gets_the_same_answer(string $backend): void
    {
        $this->shop($backend);
        $body = self::encode($this->start());
        $signed = $this->signed($body, self::START_ID);
        $stale = $this->signed($body, self::START_ID, time() - 86400);
        $problems = [
            'no header' => $this->post($body, []),
            'no signature' => $this->post($body, ['webhook-signature' => ''] + $signed),
            'a signature of another key' => $this->post($body, ['webhook-signature' => self::signature(self::STRANGER_KEY, self::START_ID, $signed['webhook-timestamp'], $body)] + $signed),
            'an altered body' => $this->post($body . ' ', $signed),
            'a timestamp out of the window' => $this->post($body, $stale),
            'a timestamp that is not a number' => $this->post($body, ['webhook-timestamp' => 'now'] + $signed),
        ];
        $this->iq = $this->deploymentHolding(null);
        $problems['an endpoint that holds no key'] = $this->post($body, $signed);

        $answers = array_map(
            static fn (HttpResponse $answer): string => json_encode([$answer->statusCode, $answer->headers, $answer->body], JSON_THROW_ON_ERROR),
            $problems,
        );

        self::assertSame(['[401,{"Content-Type":"application\/json"},"{\"code\":\"invalid_signature\"}"]'], array_unique(array_values($answers)));
    }

    #[DataProvider('messagesAnsweredInTheRequest')]
    public function test_a_request_with_no_webhook_id_header_verifies_when_it_was_signed_over_an_empty_id(string $backend, string $body): void
    {
        $this->shop($backend);
        $timestamp = (string) time();

        $answer = $this->post($body, [
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => self::signature($this->platformKey(), '', $timestamp, $body),
        ]);

        self::assertSame(200, $answer->statusCode);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function messagesAnsweredInTheRequest(): array
    {
        return self::onEveryBackend([
            'a ping' => [self::PING],
            'an inspect' => [self::encode(['verb' => 'inspect', 'definition' => self::DEFINITION, 'workflowId' => self::WORKFLOW_ID])],
            'an abandon' => [self::encode(['verb' => 'abandon', 'definition' => self::DEFINITION, 'workflowId' => self::WORKFLOW_ID, 'runId' => self::RUN_ID])],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function requestsThatLackAHeader(): array
    {
        $without = static fn (string ...$names): Closure => static function (array $headers) use ($names): array {
            foreach ($names as $name) {
                unset($headers[$name]);
            }

            return $headers;
        };
        $emptied = static fn (string $name): Closure => static fn (array $headers): array => [$name => ''] + $headers;

        return self::onEveryBackend([
            'no header of the signature' => [$without('webhook-id', 'webhook-timestamp', 'webhook-signature')],
            'no webhook-id' => [$without('webhook-id')],
            'no webhook-timestamp' => [$without('webhook-timestamp')],
            'no webhook-signature' => [$without('webhook-signature')],
            'an empty webhook-id' => [$emptied('webhook-id')],
            'an empty webhook-timestamp' => [$emptied('webhook-timestamp')],
            'an empty webhook-signature' => [$emptied('webhook-signature')],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> a closure that takes the signed headers, the body and the bytes of the
     *                                          platform's key, and returns the headers of the request
     */
    public static function signaturesThatDoNotCoverTheRequest(): array
    {
        $signedAs = static fn (Closure $sign): Closure => static fn (array $headers, string $body, string $key): array => [
            'webhook-signature' => $sign($headers['webhook-id'], $headers['webhook-timestamp'], $body, $key),
        ] + $headers;

        return self::onEveryBackend([
            'a signature with its first character changed' => [static fn (array $headers): array => [
                'webhook-signature' => 'v1,' . ($headers['webhook-signature'][3] === 'A' ? 'B' : 'A') . substr($headers['webhook-signature'], 4),
            ] + $headers],
            'a signature cut short' => [static fn (array $headers): array => ['webhook-signature' => substr($headers['webhook-signature'], 0, 20)] + $headers],
            'a signature made with another key' => [$signedAs(
                static fn (string $id, string $timestamp, string $body): string => self::signature(self::STRANGER_KEY, $id, $timestamp, $body),
            )],
            'a signature made with an empty key' => [$signedAs(
                static fn (string $id, string $timestamp, string $body): string => self::signature('', $id, $timestamp, $body),
            )],
            'a signature made over another body' => [$signedAs(
                static fn (string $id, string $timestamp, string $body, string $key): string => self::signature($key, $id, $timestamp, $body . ' '),
            )],
            'a signature made over another webhook-id' => [$signedAs(
                static fn (string $id, string $timestamp, string $body, string $key): string => self::signature($key, 'inv_another', $timestamp, $body),
            )],
            'a signature made over another timestamp' => [$signedAs(
                static fn (string $id, string $timestamp, string $body, string $key): string => self::signature($key, $id, (string) ((int) $timestamp - 1), $body),
            )],
            'a signature made over the body alone' => [$signedAs(
                static fn (string $id, string $timestamp, string $body, string $key): string => self::signature($key, '', '', $body),
            )],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function timestampsInsideTheWindow(): array
    {
        return self::onEveryBackend([
            'signed 300 seconds ago' => [-300],
            'signed 299 seconds ago' => [-299],
            'signed now' => [0],
            'signed 299 seconds ahead' => [299],
            'signed 300 seconds ahead' => [300],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function timestampsOutsideTheWindow(): array
    {
        return self::onEveryBackend([
            'signed 301 seconds ago' => [-301],
            'signed 301 seconds ahead' => [301],
            'signed a day ago' => [-86400],
            'signed a day ahead' => [86400],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> a closure that writes the clock it is given
     */
    public static function timestampsThatAreNoNumberOfSeconds(): array
    {
        return self::onEveryBackend([
            'followed by a letter' => [static fn (int $now): string => "{$now}s"],
            'with a decimal part' => [static fn (int $now): string => "{$now}.0"],
            'in exponent notation' => [static fn (int $now): string => sprintf('%.9e', $now)],
            'in hexadecimal' => [static fn (int $now): string => '0x' . dechex($now)],
            'with a plus sign' => [static fn (int $now): string => "+{$now}"],
            'negative' => [static fn (int $now): string => "-{$now}"],
            'after a space' => [static fn (int $now): string => " {$now}"],
            'before a space' => [static fn (int $now): string => "{$now} "],
            'before a line feed' => [static fn (int $now): string => "{$now}\n"],
            'as a date' => [static fn (int $now): string => date(DATE_ATOM, $now)],
            'in milliseconds' => [static fn (int $now): string => "{$now}000"],
            'too large for an integer' => [static fn (): string => str_repeat('9', 30)],
            'in full-width digits' => [static fn (int $now): string => strtr((string) $now, ['0' => '０', '1' => '１', '2' => '２', '3' => '３', '4' => '４', '5' => '５', '6' => '６', '7' => '７', '8' => '８', '9' => '９'])],
            'a word' => [static fn (): string => 'now'],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>> a closure that takes the base64 digest of the valid signature
     */
    public static function headersThatHoldNoSignatureOfVersion1(): array
    {
        return self::onEveryBackend([
            'the version and nothing' => [static fn (): string => 'v1,'],
            'the digest without its version' => [static fn (string $digest): string => $digest],
            'the digest after a comma' => [static fn (string $digest): string => ",{$digest}"],
            'the digest of another version' => [static fn (string $digest): string => "v2,{$digest}"],
            'the digest of a version that begins like this one' => [static fn (string $digest): string => "v1a,{$digest}"],
            'the version in upper case' => [static fn (string $digest): string => "V1,{$digest}"],
            'the version of the digest given twice' => [static fn (string $digest): string => "v1,v1,{$digest}"],
            'the digest in hexadecimal' => [static fn (string $digest): string => 'v1,' . bin2hex((string) base64_decode($digest, true))],
            'the digest in upper case' => [static fn (string $digest): string => 'v1,' . strtoupper($digest)],
            'the version and the digest separated by a space' => [static fn (string $digest): string => "v1 {$digest}"],
            'the digest followed by a comma' => [static fn (string $digest): string => "v1,{$digest},"],
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function placesAmongSeveralSignatures(): array
    {
        return self::onEveryBackend(['the first' => [0], 'in the middle' => [1], 'the last' => [2]]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function bodiesAStrangerSends(): array
    {
        $start = ['verb' => 'start', 'invocationId' => self::START_ID, 'workflowId' => self::WORKFLOW_ID, 'runId' => self::RUN_ID, 'expiresAt' => 1];

        return self::onEveryBackend([
            'a start' => [self::encode($start + ['definition' => self::DEFINITION])],
            'a start of a definition this deployment lacks' => [self::encode($start + ['definition' => 'nightly-reconciliation'])],
            'an abandon' => [self::encode(['verb' => 'abandon', 'definition' => self::DEFINITION, 'workflowId' => self::WORKFLOW_ID, 'runId' => self::RUN_ID])],
            'an inspect' => [self::encode(['verb' => 'inspect', 'definition' => self::DEFINITION, 'workflowId' => self::WORKFLOW_ID])],
            'a ping' => [self::PING],
            'another protocol' => ['{"protocol":2,"verb":"ping"}'],
            'a verb the protocol does not have' => ['{"protocol":1,"verb":"retire"}'],
            'no JSON' => ['{"protocol": 1, "verb":'],
            'nothing' => [''],
            'five megabytes' => [str_repeat('a', 5 * 1024 * 1024)],
        ]);
    }

    /**
     * @return array<int, string> three signatures of this request, as the platform sends them while it rotates keys
     *                            the application does not hold
     */
    protected function signaturesOfOtherKeys(string $body, string $timestamp): array
    {
        return array_map(
            static fn (string $key): string => self::signature($key, self::START_ID, $timestamp, $body),
            ['a key retired last month', 'a key retired last week', 'a key not yet rolled out'],
        );
    }
}
