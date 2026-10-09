<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Verb;

use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function ltrim;

/**
 * A message the platform sent the endpoint, decoded after its signature was
 * verified: an order (start, wake, abandon) or a question (inspect, ping),
 * with every field of its verb checked against its format.
 *
 * @internal
 */
final class PlatformMessage
{
    public const START = 'start';

    public const WAKE = 'wake';

    public const INSPECT = 'inspect';

    public const ABANDON = 'abandon';

    public const PING = 'ping';

    /** Seconds the platform may give a start at most, from the moment it first sends it to its use-by time. */
    public const LONGEST_START_LIFETIME = 3600;

    /** What each verb carries. "answer" and "signal" are reserved: the platform sends neither in protocol 1. */
    protected const FIELDS = [
        self::START => ['invocationId', 'definition', 'workflowId', 'runId', 'expiresAt'],
        self::WAKE => ['invocationId', 'definition', 'workflowId', 'runId', 'attempt'],
        self::INSPECT => ['definition', 'workflowId', 'runId'],
        self::ABANDON => ['definition', 'workflowId', 'runId', 'attempt'],
        self::PING => [],
    ];

    /**
     * @param string|null $definition null for a ping only, like the workflow ID
     * @param string|null $runId null for a ping, and for an inspect that names no run
     * @param int|null $attempt the fence of a wake, and of an abandon when the run exists
     * @param int|null $expiresAt the use-by time of a start
     * @param string|null $invocationId of a start and a wake: the webhook-id header, the same on every re-send
     */
    protected function __construct(
        public readonly string $verb,
        public readonly ?string $definition = null,
        public readonly ?string $workflowId = null,
        public readonly ?string $runId = null,
        public readonly ?int $attempt = null,
        public readonly ?int $expiresAt = null,
        public readonly ?string $invocationId = null,
    ) {
    }

    /**
     * @param string $webhookId the webhook-id header the signature was verified with
     * @param int $now the receiver's clock
     * @return self|Code the message, or what its body is refused with: invalid_request, unsupported_protocol
     */
    public static function decode(string $body, string $webhookId, int $now): self|Code
    {
        // Decoded as an array: PHP refuses to decode into an object a field whose name begins with U+0000,
        // and a receiver ignores the fields it does not know.
        $fields = json_decode($body, true);

        if (!is_array($fields) || ltrim($body, " \t\n\r")[0] !== '{') {
            // A list, a scalar or no JSON at all: only an object can be a message.
            return Code::InvalidRequest;
        }

        if (($fields['protocol'] ?? null) !== Protocol::VERSION) {
            return Code::UnsupportedProtocol;
        }

        $verb = $fields['verb'] ?? null;
        if (!is_string($verb) || !isset(self::FIELDS[$verb])) {
            return Code::InvalidRequest;
        }

        // Only the fields of the verb are read: what exists only in the application's queue (the origin,
        // a payload, a signal, an event, a body) is never taken from the wire.
        $carried = array_intersect_key($fields, array_flip(self::FIELDS[$verb]));

        return self::isWellFormed($verb, $carried, $webhookId, $now) ? new self($verb, ...$carried) : Code::InvalidRequest;
    }

    /**
     * Whether a worker executes the message: a start and a wake are queued, the rest is answered in the request.
     */
    public function runsInQueue(): bool
    {
        return $this->verb === self::START || $this->verb === self::WAKE;
    }

    /**
     * A start or a wake as it waits in the application's queue.
     */
    public function invocation(): Invocation
    {
        return new Invocation(
            $this->invocationId,
            Verb::from($this->verb),
            Origin::PLATFORM,
            $this->definition,
            $this->workflowId,
            $this->runId,
            $this->attempt,
            $this->expiresAt,
        );
    }

    /**
     * @param array<string, mixed> $carried the fields of the verb that the body holds
     */
    protected static function isWellFormed(string $verb, array $carried, string $webhookId, int $now): bool
    {
        $runId = $carried['runId'] ?? null;
        $attempt = $carried['attempt'] ?? null;

        $isAddressed = Format::isDefinition($carried['definition'] ?? null) && Format::isWorkflowId($carried['workflowId'] ?? null);
        // The invocation ID of a platform message is its webhook-id header, which is the one the signature covers.
        $isIdentified = Format::isInvocationId($webhookId) && ($carried['invocationId'] ?? null) === $webhookId;

        return match ($verb) {
            self::START => $isAddressed && $isIdentified && Format::isMintedRunId($runId)
                && self::isUseByTime($carried['expiresAt'] ?? null, $now),
            self::WAKE => $isAddressed && $isIdentified && Format::isRunId($runId) && self::isAttempt($attempt),
            self::INSPECT => $isAddressed && (!array_key_exists('runId', $carried) || Format::isRunId($runId)),
            self::ABANDON => $isAddressed && Format::isRunId($runId)
                && (!array_key_exists('attempt', $carried) || self::isAttempt($attempt)),
            // A ping carries nothing.
            self::PING => true,
            default => false,
        };
    }

    protected static function isAttempt(mixed $value): bool
    {
        return is_int($value) && $value >= 1;
    }

    /**
     * The upper bound of a use-by time is checked here, where the start enters the application. The two clocks
     * may differ by the signature window. A time that has passed is the runner's to refuse, with a report.
     */
    protected static function isUseByTime(mixed $value, int $now): bool
    {
        return is_int($value) && $value >= 0 && $value <= $now + self::LONGEST_START_LIFETIME + Signature::TOLERANCE;
    }
}
