<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndToEnd;

use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use Throwable;

use function fwrite;

use const STDERR;

/**
 * A test over real HTTP: the platform simulator as a process of its own,
 * with the shop of example (a) or with a test in the place of the
 * application. Every process a test starts is stopped when the test ends, or
 * after the last test of its class for a stage the class shares, and a test
 * that fails prints what each process wrote.
 */
abstract class EndToEndTestCase extends TestCase
{
    /** @var Stage[] started for every test of the class */
    protected static array $sharedByTheClass = [];

    /** Whether a failed test of the class already printed the logs of the stages the class shares. */
    protected static bool $printedForTheClass = false;

    /** @var Stage[] started for the test that runs */
    protected array $ofThisTest = [];

    /** What the processes of this test wrote, kept from before they were stopped. */
    protected string $logsOfThisTest = '';

    protected function setUp(): void
    {
        $missing = Deployment::unavailable();

        if ($missing !== null) {
            self::markTestSkipped("The processes of this test cannot be run here: {$missing}.");
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->ofThisTest as $stage) {
            $this->logsOfThisTest .= $stage->logs();
            $stage->tearDown();
        }

        $this->ofThisTest = [];
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$sharedByTheClass as $stage) {
            $stage->tearDown();
        }

        self::$sharedByTheClass = [];
        self::$printedForTheClass = false;
    }

    /**
     * Take charge of a stage this test started: it is torn down when the test ends.
     *
     * @template TStage of Stage
     * @param TStage $stage
     * @return TStage
     */
    protected function forThisTest(Stage $stage): Stage
    {
        return $this->ofThisTest[] = $stage;
    }

    /**
     * Take charge of a stage every test of the class looks at, for a class that plays one flow and checks
     * one thing per test: it is torn down after the last test.
     *
     * @template TStage of Stage
     * @param TStage $stage
     * @return TStage
     */
    protected static function forTheClass(Stage $stage): Stage
    {
        return self::$sharedByTheClass[] = $stage;
    }

    protected function onNotSuccessfulTest(Throwable $t): never
    {
        if (!$t instanceof SkippedTest && !$t instanceof IncompleteTest) {
            fwrite(STDERR, "\n{$this->name()} failed. What its processes wrote:\n{$this->logsOfThisTest}" . self::logsSharedByTheClass() . "\n");
        }

        parent::onNotSuccessfulTest($t);
    }

    /**
     * @return string what the processes of the stages the class shares wrote; empty once a failed test has printed it
     */
    protected static function logsSharedByTheClass(): string
    {
        $logs = '';
        foreach (self::$sharedByTheClass as $stage) {
            $logs .= $stage->logs();
        }

        $alreadyPrinted = self::$printedForTheClass;
        self::$printedForTheClass = true;

        return $alreadyPrinted ? '' : $logs;
    }
}
