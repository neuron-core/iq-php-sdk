<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Sync;

use ErrorException;
use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\SyncResult;
use NeuronCore\IQ\Tests\Support\EndpointFlows\SyncTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use function array_filter;
use function array_is_list;
use function array_keys;
use function is_int;
use function is_string;
use function json_encode;
use function restore_error_handler;
use function set_error_handler;

use const JSON_THROW_ON_ERROR;

/**
 * A sync the platform accepted (a 200 whose "modified" is a boolean) with
 * lists the SDK cannot use. The platform has replaced its manifest by then,
 * so the deploy must hear either a result of the shape the design gives a
 * SyncResult, or an IQException that says the answer was unusable: never
 * an error of PHP, and never a code that belongs to another call.
 */
class UnusableSyncAnswerTest extends SyncTestCase
{
    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('listsThatAreMalformed')]
    public function test_sync_ends_with_a_result_or_a_cloud_exception_whatever_the_lists_of_an_accepted_answer_hold(array $answer): void
    {
        $ending = self::endingOfTheSync($answer);

        self::assertTrue(
            $ending instanceof SyncResult || $ending instanceof IQException,
            'sync() ended with ' . $ending::class . ($ending instanceof Throwable ? ': ' . $ending->getMessage() : ''),
        );
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('listsOfTheWrongTypes')]
    public function test_a_result_holds_its_versions_and_their_open_runs_as_integers_whatever_an_accepted_answer_sent(array $answer): void
    {
        $ending = self::endingOfTheSync($answer);

        self::assertTrue(
            $ending instanceof IQException || ($ending instanceof SyncResult && self::holdsOnlyIntegers($ending)),
            'sync() ended with ' . ($ending instanceof SyncResult ? self::described($ending) : $ending::class . ': ' . $ending->getMessage()),
        );
    }

    public function test_a_version_of_a_definition_this_process_lacks_does_not_end_an_accepted_sync_as_unknown_definition(): void
    {
        $ending = self::endingOfTheSync([
            'protocol' => 1,
            'modified' => true,
            'versions' => [self::openRuns('order-fulfilment', 2, 0), self::openRuns('archived-import', 1, 0)],
            'stranded' => [],
        ]);

        self::assertFalse(
            $ending instanceof IQException && $ending->reason === Code::UnknownDefinition,
            'The platform accepted the sync: "unknown_definition" is what workflow() throws for a name of the application.',
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function listsThatAreMalformed(): array
    {
        return [
            '"versions" is not a list' => [self::answer(versions: 'none')],
            'an entry of "versions" is not an object' => [self::answer(versions: ['order-fulfilment'])],
            'an entry of "versions" names no definition' => [self::answer(versions: [['version' => 1, 'openRuns' => 0]])],
            'an entry of "versions" names a definition that is not a string' => [self::answer(versions: [['definition' => ['order-fulfilment'], 'version' => 1, 'openRuns' => 0]])],
            'an entry of "versions" has no version' => [self::answer(versions: [['definition' => 'order-fulfilment', 'openRuns' => 0]])],
            'an entry of "versions" has no open runs' => [self::answer(versions: [['definition' => 'order-fulfilment', 'version' => 1]])],
            '"stranded" is not a list' => [self::answer(stranded: 'none')],
            'an entry of "stranded" is not an object' => [self::answer(stranded: ['trial'])],
            'an entry of "stranded" names no definition' => [self::answer(stranded: [['version' => 3, 'openRuns' => 2]])],
            'an entry of "stranded" has no version' => [self::answer(stranded: [['definition' => 'trial', 'openRuns' => 2]])],
            'an entry of "stranded" has no open runs' => [self::answer(stranded: [['definition' => 'trial', 'version' => 3]])],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function listsOfTheWrongTypes(): array
    {
        return [
            'a version of "versions" as a string' => [self::answer(versions: [['definition' => 'order-fulfilment', 'version' => '1', 'openRuns' => 0]])],
            'a version of "stranded" as a string' => [self::answer(stranded: [['definition' => 'trial', 'version' => 'three', 'openRuns' => 2]])],
            'the open runs of "stranded" as a string' => [self::answer(stranded: [['definition' => 'trial', 'version' => 3, 'openRuns' => 'two']])],
        ];
    }

    /**
     * @return array<string, mixed> an answer the SDK accepts as the answer to a sync
     */
    protected static function answer(mixed $versions = [], mixed $stranded = []): array
    {
        return ['protocol' => 1, 'modified' => true, 'versions' => $versions, 'stranded' => $stranded];
    }

    /**
     * How a sync of the shop ends when the platform answers 200 with this body. A warning or a notice of PHP
     * ends it too: the SDK then read a list it did not understand.
     *
     * @param array<string, mixed> $answer
     */
    protected static function endingOfTheSync(array $answer): SyncResult|Throwable
    {
        $iq = self::shop(self::platformAnswering(200, $answer));

        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $level, $file, $line);
        });

        try {
            return $iq->sync(self::ENDPOINT);
        } catch (Throwable $thrown) {
            return $thrown;
        } finally {
            restore_error_handler();
        }
    }

    protected static function described(SyncResult $result): string
    {
        return 'the result ' . json_encode(['retirable' => $result->retirable, 'stranded' => $result->stranded], JSON_THROW_ON_ERROR);
    }

    /**
     * The shape the design gives a SyncResult: name => int[], and name => [version => open runs].
     */
    protected static function holdsOnlyIntegers(SyncResult $result): bool
    {
        foreach ($result->retirable as $name => $versions) {
            if (!is_string($name) || !array_is_list($versions) || $versions !== array_filter($versions, is_int(...))) {
                return false;
            }
        }

        foreach ($result->stranded as $name => $versions) {
            if (!is_string($name) || $versions !== array_filter($versions, is_int(...)) || array_keys($versions) !== array_filter(array_keys($versions), is_int(...))) {
                return false;
            }
        }

        return true;
    }
}
