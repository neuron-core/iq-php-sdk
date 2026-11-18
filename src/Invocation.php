<?php

declare(strict_types=1);

namespace NeuronCore\IQ;

use NeuronCore\IQ\Runtime\Notices;

use function base64_decode;
use function base64_encode;
use function serialize;
use function unserialize;

/**
 * What travels in your queue: an order to execute one segment, or an
 * announcement or an observe to post.
 */
final class Invocation
{
    /**
     * The last four fields exist only in your queue and are never read from the wire.
     *
     * @internal the SDK builds its own invocations; a worker rebuilds one with fromArray()
     *
     * @param string $origin 'platform' or 'application'; set by the SDK, never read from the wire
     * @param int|null $attempt the fence of a wake, an answer or a signal
     * @param int|null $expiresAt the use-by time
     * @param string|null $signal the event name of a signal
     * @param array<array-key, mixed>|null $payload an answer or a signal payload; PHP-serialized and base64-encoded in toArray()
     * @param string|null $event the start event, PHP-serialized and base64-encoded
     * @param array<string, mixed>|null $body a prepared announcement; for an observe, the segment's invocationId and verb
     *                                        and, when the job got that far, its outcome, code, errorClass and executedAttempt
     */
    public function __construct(
        public readonly string $invocationId,
        public readonly Verb $verb,
        public readonly string $origin,
        public readonly string $definition,
        public readonly string $workflowId,
        public readonly string $runId,
        public readonly ?int $attempt = null,
        public readonly ?int $expiresAt = null,
        public readonly ?string $signal = null,
        public readonly ?array $payload = null,
        public readonly ?string $event = null,
        public readonly ?array $body = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'invocationId' => $this->invocationId,
            'verb' => $this->verb->value,
            'origin' => $this->origin,
            'definition' => $this->definition,
            'workflowId' => $this->workflowId,
            'runId' => $this->runId,
            'attempt' => $this->attempt,
            'expiresAt' => $this->expiresAt,
            'signal' => $this->signal,
            // Carried as the event is: JSON holds no binary string and no object, and it turns 10.0 into 10.
            'payload' => $this->payload === null ? null : base64_encode(serialize($this->payload)),
            'event' => $this->event,
            'body' => $this->body,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['invocationId'],
            Verb::from($data['verb']),
            $data['origin'],
            $data['definition'],
            $data['workflowId'],
            $data['runId'],
            $data['attempt'] ?? null,
            $data['expiresAt'] ?? null,
            $data['signal'] ?? null,
            isset($data['payload']) ? self::carriedPayload($data['payload']) : null,
            $data['event'] ?? null,
            $data['body'] ?? null,
        );
    }

    /**
     * The observe of a segment whose job the queue gave up. Nobody knows how
     * such a segment ended: a worker that is given the observe reads the store
     * and tells the platform what it shows.
     *
     * @param self $segment a start, a wake, an answer or a signal
     * @throws IQException invalid_request, when $segment is itself an announcement or an observe
     */
    public static function observeOf(self $segment): self
    {
        if ($segment->verb === Verb::Announce || $segment->verb === Verb::Observe) {
            throw new IQException(
                "The {$segment->verb->value} '{$segment->invocationId}' is a notice of the SDK and not a segment: nothing observes it.",
                Code::InvalidRequest,
            );
        }

        return Notices::observe($segment, ['invocationId' => $segment->invocationId, 'verb' => $segment->verb->value]);
    }

    /**
     * A carried value that is not an array must fail here: taken for no
     * payload, it would resume the wait with an empty answer.
     *
     * @return array<array-key, mixed>
     */
    protected static function carriedPayload(string $carried): array
    {
        return unserialize((string) base64_decode($carried, true));
    }
}
