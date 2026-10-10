<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Simulator;

use InvalidArgumentException;

use function array_map;
use function bin2hex;
use function is_string;
use function random_bytes;
use function rawurldecode;
use function str_starts_with;
use function strlen;
use function substr;
use function vsprintf;
use function str_split;

/**
 * What a real platform would offer an operator or a trigger, and what a
 * test needs: fire a start, ask for an abandon, read what the platform holds,
 * set its durations and its retry policy, and move its clock on by one tick.
 */
final class Operator
{
    protected const RUNS = '/_operator/runs/';

    public function __construct(
        protected Settings $settings,
        protected Manifest $manifest,
        protected Endpoint $endpoint,
        protected Runs $runs,
        protected Orders $orders,
        protected Segments $segments,
        protected Alerts $alerts,
        protected Wire $wire,
        protected Plan $plan,
        protected Clockwork $clockwork,
    ) {
    }

    /**
     * @param array<string, string> $query
     * @param array<array-key, mixed> $request the body, decoded
     * @return array{int, mixed} the status and the body of the answer
     */
    public function handle(string $method, string $path, array $query, array $request, float $now): array
    {
        if (str_starts_with($path, self::RUNS)) {
            $run = $this->runs->find(rawurldecode(substr($path, strlen(self::RUNS))));

            return $run === null ? [404, ['error' => 'The platform holds no run with that run ID.']] : [200, $this->describe($run)];
        }

        return match ("{$method} {$path}") {
            'GET /_operator/health' => [200, ['ready' => true]],
            'GET /_operator/settings' => [200, $this->settings->all()],
            'PUT /_operator/settings' => $this->changeSettings($request),
            'PUT /_operator/tunnels' => $this->tunnel($request),
            'POST /_operator/endpoint-moves' => $this->confirmEndpointMove($request),
            'POST /_operator/starts' => $this->fire($request, $now),
            'POST /_operator/abandons' => $this->abandon($request),
            'POST /_operator/tick' => [200, ['sent' => $this->clockwork->tick($now)]],
            'GET /_operator/runs' => [200, array_map($this->describe(...), $this->runs->all($query['workflowId'] ?? null))],
            'GET /_operator/alerts' => [200, $this->alerts->all()],
            'GET /_operator/wire' => [200, $this->wire->all()],
            'GET /_operator/manifest' => [200, [
                'endpoint' => $this->endpoint->url(),
                'definitions' => $this->manifest->all(),
                'deliveriesStopped' => $this->endpoint->isStopped(),
            ]],
            default => [404, ['error' => "The operator API has no {$method} {$path}."]],
        };
    }

    /**
     * @param array<array-key, mixed> $settings
     * @return array{int, mixed}
     */
    protected function changeSettings(array $settings): array
    {
        try {
            $this->settings->change($settings);
        } catch (InvalidArgumentException $refused) {
            return [422, ['error' => $refused->getMessage()]];
        }

        return [200, $this->settings->all()];
    }

    /**
     * @param array<array-key, mixed> $tunnels public origin => local origin
     * @return array{int, mixed}
     */
    protected function tunnel(array $tunnels): array
    {
        foreach ($tunnels as $public => $local) {
            if (!is_string($public) || !is_string($local)) {
                return [422, ['error' => 'A tunnel leads from a public origin to a local one.']];
            }
        }

        $this->endpoint->tunnel($tunnels);

        return [200, $this->endpoint->tunnels()];
    }

    /**
     * Rule 16: the owner's consent to move the endpoint of the environment to another host.
     *
     * @param array<array-key, mixed> $request
     * @return array{int, mixed}
     */
    protected function confirmEndpointMove(array $request): array
    {
        if (!is_string($request['host'] ?? null)) {
            return [422, ['error' => 'Name the host the endpoint may move to.']];
        }

        $this->endpoint->approveHost($request['host']);

        return [200, ['host' => $request['host']]];
    }

    /**
     * A trigger fires. Rule 4: one start per firing. Rule 1: a run ID that was never used, minted with the
     * current version. The start leaves with the next tick, which fixes its use-by time.
     *
     * @param array<array-key, mixed> $request
     * @return array{int, mixed}
     */
    protected function fire(array $request, float $now): array
    {
        ['definition' => $definition, 'workflowId' => $workflowId, 'firing' => $firing] = $request + ['definition' => null, 'workflowId' => null, 'firing' => null];

        if (!is_string($definition) || !is_string($workflowId) || ($firing !== null && !is_string($firing))) {
            return [422, ['error' => 'A start names a definition and a workflow ID, and may name its firing.']];
        }

        $fired = $firing === null ? null : $this->runs->ofFiring($firing);
        if ($fired !== null) {
            return [200, $this->describe($fired)];
        }

        $version = $this->manifest->currentVersion($definition);
        if ($version === null) {
            return [422, ['error' => "No sync published a definition named '{$definition}'."]];
        }

        $run = $this->runs->issue("{$version}-" . self::uuid(), $workflowId, $definition, $firing, $now);
        $this->orders->issue(Orders::START, $run['run_id'], null, 'start', $now);

        return [201, $this->describe($run)];
    }

    /**
     * Rule 5: an abandon is sent only when an operator asks. It leaves with the next tick, whatever the
     * platform asked about the run before. Rule 2: it carries an attempt the application reported, and none
     * only for a start the platform sent. An operator who asks for any other run no snapshot named is refused.
     *
     * @param array<array-key, mixed> $request
     * @return array{int, mixed}
     */
    protected function abandon(array $request): array
    {
        $run = is_string($request['runId'] ?? null) ? $this->runs->find($request['runId']) : null;

        if ($run === null || $run['closed_as'] !== null) {
            return [422, ['error' => 'An abandon names a run that the platform holds open.']];
        }

        if ($run['seen_at'] === null && $this->orders->startOf($run['run_id']) === null) {
            return [422, ['error' => 'The platform holds no attempt for this run and did not send its start: it has nothing to fence an abandon with.']];
        }

        $this->runs->update($run['run_id'], [
            'abandon' => 'requested',
            'abandon_tries' => 0,
            'abandon_after' => null,
            'abandon_status' => null,
            'abandon_failures' => 0,
        ]);

        return [202, $this->describe((array) $this->runs->find($run['run_id']))];
    }

    /**
     * Everything the platform holds for a run. "run" has the shape of the run object of the protocol, so that
     * a test can compare it with the store; it is null while no snapshot has named the run, and once it is closed.
     *
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    protected function describe(array $run): array
    {
        return [
            'runId' => $run['run_id'],
            'workflowId' => $run['workflow_id'],
            'definition' => $run['definition'],
            'origin' => $run['origin'],
            'state' => $run['closed_as'] ?? 'open',
            'code' => $run['code'],
            'run' => $run['closed_as'] === null && $run['seen_at'] !== null ? $this->held($run) : null,
            'seenAt' => $run['seen_at'],
            'readAt' => $run['read_at'],
            'closedAt' => $run['closed_at'],
            'abandon' => $run['abandon'],
            'failures' => $this->runs->failures($run['run_id']),
            'orders' => array_map(static fn (array $order): array => [
                'invocationId' => $order['invocation_id'],
                'verb' => $order['verb'],
                'attempt' => $order['attempt'],
                'purpose' => $order['purpose'],
                'expiresAt' => $order['expires_at'],
                'dueAt' => $order['due_at'],
                'sends' => $order['sends'],
                'status' => $order['status'],
                'acceptedAt' => $order['accepted_at'],
                'ended' => $order['ended'],
            ], $this->orders->of($run['run_id'])),
            'segments' => array_map(static fn (array $segment): array => [
                'invocationId' => $segment['invocation_id'],
                'verb' => $segment['verb'],
                'origin' => $segment['origin'],
                'attempt' => $segment['attempt'],
                'leaseSeconds' => $segment['lease_seconds'],
                'announcedAt' => $segment['announced_at'],
                'reportedAt' => $segment['reported_at'],
                'outcome' => $segment['outcome'],
                'code' => $segment['code'],
            ], $this->segments->of($run['run_id'])),
            'timers' => $this->plan->of($run),
        ];
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed> the newest snapshot that named the run
     */
    protected function held(array $run): array
    {
        $held = [
            'runId' => $run['run_id'],
            'definition' => $run['shown_definition'],
            'status' => $run['status'],
            'attempt' => $run['attempt'],
            'leaseExpiresAt' => $run['lease_expires_at'],
        ];

        if ($run['status'] === 'suspended') {
            $held['wait'] = [
                'interruptId' => $run['wait_id'],
                'type' => $run['wait_type'],
                'eventName' => $run['wait_event'],
                'wakeAt' => $run['wake_at'],
            ];
        }

        return $held;
    }

    protected static function uuid(): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
    }
}
