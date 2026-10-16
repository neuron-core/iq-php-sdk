# `InvocationSettled`: closing the stream of a queued segment

A segment that runs in a worker streams to the browsers through the agent's `channel()`. When it completes, suspends or fails, Neuron publishes the end of the stream itself. Everything else leaves the browsers waiting for an end that nothing will send: a job the queue gave up, a message that was refused, a start that never ran. This listener closes those streams. It is the listener of the example application the package's end-to-end tests run on a real queue, with a `kill -9` of the worker, a job that outlives its timeout and a queue that delivers twice.

It replaces what the `failed()` method of the guide's job did.

## The listener

```php
namespace App\Listeners;

use App\Neuron\Channels\ChannelFactory;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronCore\Cloud\Laravel\Events\InvocationSettled;
use NeuronCore\Cloud\Outcome;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Verb;
use RuntimeException;

class CloseBrowserStream
{
    /** The codes of a refused or rejected second copy of a message: the first copy is the one that streams. */
    protected const SECOND_COPY = ['stale_attempt', 'stale_run', 'already_claimed'];

    public function __construct(protected ChannelFactory $channels)
    {
    }

    public function handle(InvocationSettled $event): void
    {
        $report = $event->report;

        if (!$this->leavesTheStreamOpen($report)) {
            return;
        }

        $channel = $this->channels->make($report->workflowId);
        $ending = new RuntimeException("The {$report->verb->value} of the turn ended as '{$report->outcome->value}'.");

        foreach ((new AGUIAdapter(threadId: $report->workflowId))->error($ending) as $frame) {
            $channel->send($frame);
        }
        $channel->failed($ending, $report->workflowId);
    }

    /** Whether browsers may still be waiting for the end of a stream that nothing will send. */
    protected function leavesTheStreamOpen(Report $report): bool
    {
        // Only a chat has browsers that listen.
        if ($report->definition !== 'support-agent') {
            return false;
        }

        return match ($report->outcome) {
            // The segment ran to an end and told its own channel.
            Outcome::Completed, Outcome::Suspended, Outcome::Failed => false,
            Outcome::Refused, Outcome::Rejected => !in_array($report->code, self::SECOND_COPY, true),
            // Nothing ran. The platform sends its own message again; one of the application is not sent again
            // once its report exists.
            Outcome::Aborted => $report->origin === 'application',
            Outcome::Observed => $this->diedWithTheStreamOpen($report),
        };
    }

    /** The job of the segment died: the report shows what the store held afterwards, not how the segment ended. */
    protected function diedWithTheStreamOpen(Report $report): bool
    {
        $ofTheApplication = $report->origin === 'application';

        if ($report->run === null) {
            // No run holds the thread. A start of the application that no worker claimed never ran, and nothing
            // queues it again. One that was claimed either never ran or ended in a worker that died before its
            // report: only the channel knows whether that run told the browsers its end.
            return $report->verb === Verb::Start && $ofTheApplication && $report->snapshotRead
                && ($report->claimed === false || !$this->channels->deliveredAnEndOf($report->workflowId, $report->runId));
        }

        // Another run holds the thread by now: the report says nothing about the stream of that one.
        if ($report->run['runId'] !== $report->runId) {
            return false;
        }

        // A run still held or failed is one whose segment never told how it ended.
        if (in_array($report->run['status'], ['running', 'failed'], true)) {
            return true;
        }

        // The run waits. After an answer or a signal of the application, either the segment never ran or it ran
        // to a new wait: the report cannot tell, and the browsers load the thread again.
        return $ofTheApplication && in_array($report->verb, [Verb::Answer, Verb::Signal], true);
    }
}
```

Register it in a service provider's `boot()`: `Event::listen(InvocationSettled::class, CloseBrowserStream::class);`.

## The channel factory under the SDK

The channel is named from the thread alone, because that is all a worker has: the guide's `make($threadId, $runId)` loses its second argument. The listener asks one more thing of it, which only your delivery can answer:

```php
namespace App\Neuron\Channels;

use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;

interface ChannelFactory
{
    /** A fresh channel: one serves one segment. */
    public function make(string $threadId): StreamingChannelInterface;

    /** Whether a segment of that run told the listeners of the thread that it suspended or completed. */
    public function deliveredAnEndOf(string $threadId, string $runId): bool;
}
```

Record the end where the channel publishes it (the `completed` and `interrupted` callbacks of a `CallbackChannel`, or a key in the cache written beside the terminal frame), with the run ID, and read it back in `deliveredAnEndOf()`. Without that record, answer `false`: a browser then gets an error frame for a turn that had ended, and reloads the thread.

## What a report can and cannot tell

- **The event fires once for every segment a job ran**, whatever the outcome, and once more with `observed` when the queue gave the job up because it timed out, its worker was killed or it was kept aside as failed.
- **The `observed` report does not wait for the platform.** It fires with the first report that read the store, also while the platform is away. A report that could not read the store (`snapshotRead` false) tells nothing: the event waits for a try that reads it.
- **Its run can be another run.** `run` is the run that holds the workflow ID when the report is made, which can be a later one than `runId`. Compare the two before acting on its status.
- **It can come twice**: when the worker of the notice dies right after the event fired, and when a person replays a notice with `php artisan queue:retry`. A listener is idempotent.
- **It is the only word about a message of yours that was lost.** A start, an answer or a signal your code queued is not queued again when its worker is killed before it ran. For a start the `observed` report then shows `claimed` false and no run. For an answer or a signal it shows the run as the store holds it: still suspended means either that the segment never ran or that it ran to a new wait. Have the browser load the thread again and send the answer again if the wait is unchanged: it is safe, the message carries its fence.
- **`claimed` true and no run is ambiguous** for a start of yours: a worker killed between the claim and the first write, or a turn that ended in a worker that died before its report.
- **`$report->exception`** holds what a failed segment threw, in the worker process only. The package already hands it to Laravel's exception handler. `toArray()`, `json_encode()` and a queued listener never carry it.
- **The event does not fire** for a segment run in a request with `run()` or `events()`: the request has the result.
