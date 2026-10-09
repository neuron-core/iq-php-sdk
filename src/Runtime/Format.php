<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use function is_string;
use function preg_match;

/**
 * The formats of the identifiers the protocol carries.
 *
 * @internal
 */
final class Format
{
    protected const DEFINITION = '/^[a-z0-9][a-z0-9._-]{0,63}$/D';

    /** Neuron's own rule: 1 to 255 characters, no control character, no leading "__". */
    protected const WORKFLOW_ID = '/^(?!__)[^\x00-\x1F\x7F]{1,255}$/uD';

    /** Neuron's own rule. */
    protected const RUN_ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D';

    /** A run ID minted by the SDK or the platform carries its version at the front. */
    protected const MINTED_RUN_ID = '/^([1-9][0-9]{0,5})-[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/D';

    protected const INVOCATION_ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/D';

    public static function isDefinition(mixed $value): bool
    {
        return self::matches(self::DEFINITION, $value);
    }

    public static function isWorkflowId(mixed $value): bool
    {
        return self::matches(self::WORKFLOW_ID, $value);
    }

    public static function isRunId(mixed $value): bool
    {
        return self::matches(self::RUN_ID, $value);
    }

    public static function isMintedRunId(mixed $value): bool
    {
        return self::matches(self::MINTED_RUN_ID, $value);
    }

    public static function isInvocationId(mixed $value): bool
    {
        return self::matches(self::INVOCATION_ID, $value);
    }

    /**
     * The version a run is pinned to: the number at the front of its run ID.
     *
     * @return int|null null for a run ID that was not minted: it belongs to a run started outside the SDK
     */
    public static function mintedVersion(string $runId): ?int
    {
        return preg_match(self::MINTED_RUN_ID, $runId, $matches) === 1 ? (int) $matches[1] : null;
    }

    protected static function matches(string $format, mixed $value): bool
    {
        return is_string($value) && preg_match($format, $value) === 1;
    }
}
