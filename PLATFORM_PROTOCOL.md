# Neuron Cloud protocol 1: the platform's side

This document describes protocol 1 of Neuron Cloud for the engineer who builds the platform. It says what the protocol is for, what every message holds, what every answer of the application means, and what the platform does in each situation.

The platform is built from this document and from the files of `resources/protocol/1/`: one JSON Schema for each message, and the signature vectors. The reference simulator and the tests of the SDK's repository are there to compare behaviour with, and they need PHP to run. Nothing else needs to be read: this document explains the few facts about the application's side that the platform must know.

Three things are the sources of this document, in this order when they differ: the JSON Schemas, the behaviour of the SDK, and the SDK's own specification, `SDK_DESIGN.md`. When this document and a schema disagree, the schema is right and this document has a defect.

- **Protocol:** 1
- **SDK it was checked against:** `neuron-cloud-php/1.0.0`
- **Date:** 2026-10-10
- **Status:** the application's side exists and is tested. The platform does not exist yet. Section 11 lists what is not settled.

The repository of the SDK holds a **reference simulator** of the platform, in `tests/Support/Simulator`: a small implementation of the platform's side, which the SDK's tests run against a real application. Where the protocol leaves a choice to the platform, this document says so, and says what the simulator chose in a sentence that begins "Reference simulator:", or in a table column of that name. What stands there is never a rule. Section 12.3 describes the simulator.

Every JSON example in this document is a message that the real SDK produced, or one that it received and treated as this document says. The identifiers that the platform mints were chosen so that the examples tell one story, and in two examples the instants were too. Each example validates against the schema named in the comment that precedes it in the Markdown source. The examples are printed on several lines for reading. The SDK writes a body on one line, and no receiver may depend on the layout of a body.

## Contents

1. [What the protocol is for](#1-what-the-protocol-is-for)
2. [The model](#2-the-model)
3. [Transport and security](#3-transport-and-security)
4. [Identifiers and formats](#4-identifiers-and-formats)
5. [The messages, one by one](#5-the-messages-one-by-one)
6. [How to read what the application returns](#6-how-to-read-what-the-application-returns)
7. [What the platform does by itself](#7-what-the-platform-does-by-itself)
8. [Who acts](#8-who-acts)
9. [Situations](#9-situations)
10. [Durations and limits](#10-durations-and-limits)
11. [What is not settled](#11-what-is-not-settled)
12. [How to check an implementation](#12-how-to-check-an-implementation)
13. [Traces, evaluation results and datasets](#13-traces-evaluation-results-and-datasets)

[Glossary](#glossary)

---

## 1. What the protocol is for

### 1.1 The two parties

**The application** is a program that a developer wrote and deployed. It runs workflows: code that can stop in the middle, wait for hours or days, and continue. The application keeps the state of every workflow in its own database, which this document calls **the store**. It executes every step in its own processes. It has its own queue and its own workers. It exposes one signed URL that the platform calls: **the endpoint**. **The SDK** is the library inside the application that speaks this protocol. This document says "the SDK" only for what that library does by itself, and "the application" for everything else on that side. Under the SDK, the application uses a workflow framework, Neuron AI, which admits or refuses every request to execute a run and keeps the records of the run in the store. This document calls it **the engine**, and names it only where a refusal comes from it.

**The platform** is Neuron Cloud, the service this document specifies. It keeps a list of the application's runs and a clock. It tells the application when to continue a run: when a timer is due, when a failed run must be tried again, when a trigger says that a run must start. When the platform is unsure of what it holds, it asks the application.

| | The application | The platform |
|---|---|---|
| The workflow code and its execution | Owns it | Never sees it |
| The state of every run | Owns it: the store is the truth | Holds a copy of identifiers and statuses |
| The queue and the workers | Owns them | Knows nothing of them |
| The list of definitions | Publishes it with a sync | Holds the last one published: the manifest |
| The time a run must wake | Reports it | Owns the clock: sends the wake when it is due |
| Retries after a failure | Executes each part of a run once | Owns them: decides when to wake a failed run, and when to give up |
| What starts a run on a schedule | Nothing | Owns the triggers: the schedules and the API calls that start runs |
| The decision to discard a run | Can discard its own runs in its own code | Sends an abandon, and only when a person asks |

### 1.2 Five principles

Everything else in this document follows from five principles.

1. **The application's store is the truth.** The platform holds a copy of identifiers and statuses. Whenever the copy may be wrong, the platform reads the truth again with an `inspect`.
2. **The platform sends three orders and two questions.** The orders are `start` (start a run), `wake` (continue a run) and `abandon` (discard a run). The questions are `inspect` (which run holds this workflow ID?) and `ping` (does this endpoint verify my signature?). Nothing else goes from the platform to the application.
3. **Every execution says "starting", then "this is the run now".** The application executes a run in **segments**: a segment is one uninterrupted part of the execution of a run, in one process. Before a segment executes, the SDK sends the platform an announcement. After it, the SDK sends a report. Both are notices: the SDK never waits for the platform's permission.
4. **A start runs at most once.** The application refuses a second copy of a start, which is the same message delivered again, by itself, even after the run has finished. The platform may send a start again without checking anything first.
5. **The platform owns retries.** The application executes each segment once. When a segment fails, or the process that executed it dies, nothing runs it again until a wake arrives. The platform sends that wake, under its retry policy. The application's own code can issue one too (section 9.11), and the application lets only one of the two execute.

One consequence shapes the whole design. A run stays correct whatever the platform does with its clock, its memory and its timers: no late, repeated or missing message of the platform can make a workflow step execute twice or skip a step. Five rules about how the platform writes its own orders are the exception (section 7.1). Everything else the platform does decides two things only: whether a run is woken at the right time, and whether the platform knows how a run ended.

### 1.3 What crosses the wire and what never does

| The platform receives | The platform never receives |
|---|---|
| Definition names and versions, the endpoint URL | Workflow state, the results of steps |
| Workflow IDs, run IDs, attempt numbers | The input of a start, prompts, chat messages |
| A status or an outcome: running, suspended, failed, completed | The answer to a wait, an approval decision, the payload of an event |
| For a wait: its number, its type, the event name, the time to wake | The text and the tool inputs of an approval request |
| The lease deadline of a running run | The message and the trace of an exception |
| The class name of an exception | The result of a run |

Section 2 defines the terms of this table. The platform never sends a payload either: a `start` carries no input, and a `wake` carries nothing but identifiers.

Three kinds of data that do cross deserve care on the platform. A workflow ID is chosen by the application or by a trigger, and it can be personal data: a chat thread ID built from a user ID is one. An event name is chosen by the developer. The class name of an exception names the developer's code. The platform stores them, shows them to operators and must treat them as the customer's data.

One more value can cross: a label that code outside the SDK stored with a run reaches the platform as the `definition` of that run when it has the form of a definition name, and only then (section 2.5).

The platform keeps nothing else of what the application sends (section 7.2).

The table holds for the messages of protocol 1. An application can turn on two more, which carry what the right column lists: the trace of each segment and the results of an evaluation. It can also read a dataset the platform keeps, which sends nothing but the name of the dataset, and what an evaluation found, which sends nothing but the ID of its suite. Section 13 describes all four.

### 1.4 What protocol 1 covers

Protocol 1 covers eight messages, and one extension that either side may lack: the requested sync.

| Message | From | To | Kind |
|---|---|---|---|
| `sync` | Application | Platform | The list of definitions and the endpoint URL |
| announcement | Application | Platform | Notice: "a segment of this run is starting" |
| report | Application | Platform | Notice: "this is the run now" |
| `start` | Platform | Application | Order |
| `wake` | Platform | Application | Order |
| `abandon` | Platform | Application | Order, sent only when a person asks |
| `inspect` | Platform | Application | Question |
| `ping` | Platform | Application | Question |
| `sync`, requested | Platform | Application | Extension: the request for the list of definitions and the endpoint URL, answered with the body of a sync (section 5.11) |

Protocol 1 does not cover the following. The names in the right column are reserved: the platform must not use them for anything else.

| Not in protocol 1 | Reserved name |
|---|---|
| An input passed to a start through the platform | The field `input` of a start |
| Events routed or kept by the platform, answers delivered by the platform | The values `answer` and `signal` of the field `verb` in a message of the platform. The endpoint answers `400 invalid_request` to both today |
| A start that the platform issues by itself to replace one it believes lost | Nothing on the wire |
| A retry policy declared in the application's code | Nothing: the policy is a setting of the platform |
| A marker "do not retry this failure" | The report field `retryable` |
| A switch per definition "the platform may start this" | The manifest field `platformStart` |
| Listing runs, child workflows, a pull transport | Nothing |
| Traces, evaluation results and datasets | Not messages of protocol 1, but the SDK sends them when the application turns them on: section 13 |

In protocol 1 the application delivers events and answers to its own runs, in its own code. The platform never does.

### 1.5 Four kinds of behaviour

This document marks every behaviour of the platform with one of four words. Section 8 lists them all in one table.

| Mark | Meaning |
|---|---|
| **AUTOMATION** | The platform does it by itself: no person is involved in the single act. A setting may bound an automation, or leave it nothing to do: a platform with no retry policy retries nothing, and one with no trigger starts nothing |
| **OPERATOR ACTION** | A person asks for it through the platform's dashboard or API |
| **SETTING** | A preference that somebody configures. Most settings are configured on the platform. Some are set by the developer in the application's code, and the platform only reads them: the list of definitions and the endpoint URL, from a sync; the lease, from a sync and from every announcement; and the time of a timer, from a snapshot |
| **FIXED BY THE PROTOCOL** | A value or a format that neither side may change |

A value that only the reference simulator has is none of the four: it is one implementation's choice. Who may change a setting of the platform, and at which level it holds, is the platform's own design (section 11.3, P6).

Three sets of numbers recur in this document. The rules that bind the platform are R1 to R18: section 7 states them, and earlier sections cite them by number. The points that are not settled are Q1 to Q6 and U1 to U27, and the decisions that belong to the platform's product are P1 to P7: section 11 lists them all.

---

## 2. The model

This section defines the terms. Each term has one meaning in the whole document. The glossary at the end repeats them in one table.

### 2.1 Environment, definition, version, manifest, trigger, operator

An **environment** is one deployment of an application, as the platform knows it. The platform keeps one set of API keys, one set of signing keys, one endpoint URL, one manifest and one list of runs for each environment. No message names the environment: the API key of a request identifies it, and the platform knows which environment an endpoint belongs to because it stored that URL itself. How an environment and its keys are created is the platform's own design (P2).

A **definition** is a workflow class that the application registered under a name. A chat agent is a workflow too, and is registered the same way. The name is the `definition` field of every message.

A **version** is an integer from 1 to 999999. The application registers one or more versions of a definition. The highest registered version is the **current version**: every new run starts on it. A run stays on the version it started on for its whole life, because a deploy may change the code under it.

The **manifest** is the list of definitions and versions that the last `sync` published, with the endpoint URL. It is complete: a sync replaces the previous manifest.

A **trigger** is what makes the platform start a run: a schedule, or a call to the platform's API. Somebody sets a trigger up on the platform, and nothing in the application declares it. A **firing** is one occurrence of a trigger. The protocol says what a firing leads to, and leaves the design of triggers to the platform (P1).

An **operator** is a person who acts on the platform through its dashboard or its API. An **owner** is an operator who may confirm that the endpoint of an environment moves to another host.

### 2.2 Workflow ID, run, run ID

A **workflow ID** is the stable address of one workflow instance: `order:123`, `reconciliation:2026-10-07`, the ID of a chat thread. The application chooses it, or the trigger that starts the run does. At most one run holds a workflow ID at a time. All definitions of an application share one space of workflow IDs.

A **run** is one execution of a definition on a workflow ID, from its start to its end. A chat thread sees many runs, one per turn, each on the same workflow ID after the previous one ended.

A **run ID** identifies one run. Whoever starts the run mints it: the platform for a start it sends, the SDK for a run the application starts by itself. A minted run ID has the form `<version>-<unique>`, such as `2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12`. The number at the front is the version the run is pinned to. No message other than the sync carries a version field.

A run ID of another form belongs to a run that the application's code started without the SDK. Such a run counts as a run of the lowest registered version.

When a run completes, the application deletes its records. The store then shows no run for the workflow ID, and the workflow ID is free for the next run.

### 2.3 Segment, attempt, lease

A **segment** is one uninterrupted part of the execution of a run, in one process. It ends when the run suspends, completes or fails. There are four kinds of segment, named by a **verb**:

| Verb | What begins the segment | Who can issue it |
|---|---|---|
| `start` | The run is created | The platform, or the application's own code |
| `wake` | A continuation with no input: the time that a run sleeps until, or the deadline of its wait, has come, or a run that failed, or whose process died, is tried again | The platform, or the application's own code |
| `answer` | The answer to the wait the run is suspended on | The application's own code only |
| `signal` | An event delivered to a run that waits for it | The application's own code only |

In this document "verb" means the kind of a segment. A notice carries it in its field `verb`. A message of the platform has a field `verb` too, which names the message: `start`, `wake`, `inspect`, `abandon` or `ping`.

The **origin** of a segment says who issued it: `platform` when the order arrived through the endpoint, `application` when the application's own code created it.

An **invocation ID** identifies one `start` or one `wake` of the platform, or one segment that the application issued. Every copy of the order carries it, and so do the announcement and the report of the segment.

The **attempt** is a counter that the application keeps with the run. It starts at 1. The application raises it by one each time a process takes the run over to execute a segment. The first segment of a run executes under attempt 1. A wake that carries attempt 1 and is admitted executes under attempt 2. A message that carries an attempt is valid only while that attempt is still the current one. The run ID and the attempt that a `wake` or an `abandon` carries are its **fence**: the application refuses the message when they are no longer current. The platform never computes an attempt. It copies the one the application showed it.

A **lease** is the time for which one process holds a run. When a process takes a run to execute a segment, it writes a deadline with the run: now plus the lease length of the definition. While the run is running and the deadline has not passed, no other process may take it. When the deadline has passed and the run is still marked running, the run is **dead**: the process that held it was killed, or is too slow. A wake may then take the run over. The lease length is `leaseSeconds`. The application sets it for each version of a definition, publishes it in the sync, and repeats it in every announcement.

### 2.4 Wait

A **wait** is what a suspended run waits for. Protocol 1 knows two types.

| Type | The run waits for | What the platform does |
|---|---|---|
| `sleep_until` | A time: `wakeAt` | Sends a wake at `wakeAt` |
| `wait_for_event` | An event with the name `eventName`, which the application delivers itself. The wait can have a deadline, shown as `wakeAt` | Sends a wake at the deadline, when there is one. Otherwise sends nothing |

An approval is a `wait_for_event` whose `eventName` is `approval`: the run waits for a person's decision, which the application receives and delivers itself. An agent that waits for the results of tools executed elsewhere shows `tool_results`. The platform treats every event wait the same way, whatever its name.

Each wait of a run has a number, `interruptId`, which is unique within the run.

The **timer** of a wait is the wake that the platform plans for its `wakeAt`. The type of the wait makes no difference: `wakeAt` is the time of a `sleep_until`, or the deadline of a `wait_for_event`. A wait whose `wakeAt` is null has no timer.

### 2.5 The definition of a run

When the SDK starts a run, it stores the name of the definition with the run. The application's store calls this label the **tag** of the run. The platform sees it as the field `definition` of the `run` object. One rule follows: a run is executed, read or discarded only through the definition that started it. The SDK compares the tag of a run with the definition that a `wake`, an `inspect` or an `abandon` names, and refuses the message when the two differ.

A run that the application's code started without the SDK has the tag that this code gave it, or none. That makes four cases:

| The run was started | Its tag | `definition` in a snapshot | A `wake`, an `inspect` or an `abandon` is |
|---|---|---|---|
| By the SDK | The name of its definition | That name | Refused when it names another definition |
| Outside the SDK, with no tag | None | null | Never refused for its definition: nothing checks which one the message names |
| Outside the SDK, with a tag that has the form of a definition name (section 4) | That text | That text | Refused when it names anything but that text |
| Outside the SDK, with a tag of another form | That text | null: such a tag does not cross | Always refused: no message can name such a tag |

The SDK refuses in two ways: it reports a `wake` of the run as `rejected` with `wrong_definition`, and it answers `400 invalid_request` to an `inspect` and to an `abandon` of the workflow ID that the run holds. A null `definition` therefore does not prove that a run has no tag.

### 2.6 Snapshot

A **snapshot** is what the store showed for one workflow ID at one instant: the run that held the workflow ID, or nothing. A snapshot has three parts:

- `run`: an object that describes the run, or null when no run holds the workflow ID;
- `at`: the instant the store was read, on the clock of the application host that read it;
- `snapshotRead`: false when the store could not be read. The snapshot then shows nothing.

A snapshot **names** a run when its `run` has that run ID. The platform receives a snapshot in every report, in every answer to an `inspect`, and in every `200` and `409` answer to an `abandon`. Section 6 says how to read one.

### 2.7 The ledger and "claimed"

The **ledger** is the SDK's list, in the application's store, of the run IDs that a worker wrote there immediately before it executed a start, and of the run IDs that an abandon wrote there (section 5.7). A start that the endpoint accepted is not in it yet: a `202` writes nothing. The platform never reads the ledger directly, but two fields of the protocol speak of it, so the platform must know what it is.

A start reaches a worker through the application's queue, and a queue can deliver a message twice. Immediately before the SDK executes a start, it writes the run ID into the ledger with one atomic write. This is the **claim**. A second copy of the same start finds the run ID there and does nothing. The SDK never undoes a claim. A ledger entry outlives the run. Every start has a **use-by time**, the instant from which no worker may execute it any more (the field `expiresAt`, section 5.4), and the SDK keeps the entry at least until one hour after it.

A run ID is **claimed** when it is in the ledger. The field `claimed` of a report or of an answer says so. Section 6.7 says what the platform may conclude from it, and where it must not trust it.

### 2.8 What happens in the application between an order and its report

The platform sees only messages. This is what produces them.

```text
platform                     endpoint (a web request)             worker (a queue job)
   |  start or wake  -------->  verify the signature
   |                            check the fields
   |                            put the order on the queue
   |  <--------  202 {}  -----  answer
   |                                                              take the order from the queue
   |  <----------------------------------  announcement  -------  "a segment is starting"
   |                                                              start only: claim the run ID
   |                                                              execute the segment
   |                                                              read the store: the snapshot
   |  <----------------------------------  report  -------------  "this is the run now"
```

A `202` therefore promises nothing: the order is in a queue, nothing has run, and no lease exists. An `inspect`, an `abandon` and a `ping` do not go through the queue: the endpoint deals with them inside the request and answers with the result.

A segment that the application's own code issues produces the same two notices. When it runs in a worker, the worker posts them as above. When it runs inside a web request, as a chat turn does, the SDK does not call the platform from that request: it puts the announcement on the application's queue, executes the segment, and puts a **reminder** on the queue: a job that tells a worker "read the store and post the report of this segment". A worker posts the announcement later, and the worker that takes the reminder reads the store and posts the report. The two notices of such a segment can reach the platform seconds or minutes after the segment ended.

A segment that runs inside a web request can be **streamed**: the application sends its output to a client, such as a browser, while the segment executes. That client is the **reader** of the stream.

### 2.9 The life of a run as the platform sees it

The platform holds a record for every run it has started or heard of. A run that the platform holds is **open** or **closed**. The names of the states below are this document's own: they never cross the wire.

| State | Meaning |
|---|---|
| Open, issued | The platform sent a start for it. No message of the application has shown the run yet |
| Open, heard of | An announcement or a report named its run ID. No snapshot has named it yet |
| Open, `running` | The last snapshot that named it shows a process executing it, under a lease |
| Open, `suspended` | The last snapshot that named it shows it waiting |
| Open, `failed` | The last snapshot that named it shows that a segment threw |
| Closed, completed | A report said `completed`. Final |
| Closed, abandoned | The endpoint answered `200` to an abandon. Final, but for one case that is not settled (U12) |
| Closed, ended with an unknown outcome | The run left its workflow ID, and nothing said how |
| Closed, lost | A start that no copy claimed and that none can claim any more: it never ran |
| Closed, spent | A start that the application refused for good: it will never run under that run ID |

What moves a run from one state to the next. This table is rule R7; section 7.6 adds what closes nothing.

| From | Event | To |
|---|---|---|
| Nothing | A trigger fires and the platform issues a start | Open, issued |
| Nothing | An announcement or a report names a run ID the platform does not hold | Open, heard of |
| Nothing | A snapshot names a run ID the platform does not hold, and shows a definition that the manifest holds | Open, with the status shown |
| Any open state | A snapshot names the run, and is newer than what the platform holds (section 7.5) | Open, with the status shown |
| Any state but closed, abandoned | A report whose `runId` is the run says `completed` | Closed, completed |
| Closed, abandoned | A report whose `runId` is the run says `completed` | Not settled (U12) |
| Any open state | The endpoint answers `200` to an abandon of the run, whether or not a snapshot ever named it | Closed, abandoned |
| Open, after a snapshot named the run | A snapshot of the same workflow ID with a later `at` does not name it (section 7.5, step 4) | Closed, ended with an unknown outcome |
| Open, issued or heard of | The platform asks, and the answer shows that the run does not hold the workflow ID and may have run (sections 7.7 and 7.10) | Closed, ended with an unknown outcome |
| Open, issued or heard of | For a start that the platform sent: the platform asks 300 seconds after the use-by time of the start, and the answer shows no run under its run ID and `claimed` false (section 7.7). For a start of the application: Q6 | Closed, lost |
| Open, issued or heard of | A report says that the start will never run under its run ID, and no snapshot ever named that run ID (section 7.7) | Closed, spent |
| Closed as unknown, lost or spent | A snapshot with a later `at` than the message that closed the run names it | Open, with the status shown |
| Closed, ended with an unknown outcome, for a run that the application started and that no snapshot ever named | An announcement of the run with a later `at` than the message that closed it (section 7.3, step 5) | Open, heard of |
| Closed as completed or abandoned | A snapshot or an announcement, whatever it shows | No change |

The three closed states that are not final are conclusions of the platform. They give way to the store: when a later snapshot shows the run, the run is open again.

When a snapshot makes the platform hold a run that it had never heard of, which is the third row of the table above, the platform **adopts** the run: it holds the run under the definition that the snapshot shows (rule R3, section 7.1).

---

## 3. Transport and security

### 3.1 Two directions

Every message is an HTTP `POST` with a JSON object as its body. Every answer is an HTTP status with a JSON object as its body.

| Direction | URL | Authenticated by |
|---|---|---|
| Application to platform | `{platformUrl}/v1/sync`, `{platformUrl}/v1/announcements`, `{platformUrl}/v1/reports` | The API key, as a bearer token |
| Platform to application | The endpoint URL that the last sync published. One URL receives all five messages of the platform, and the requested sync of section 5.11 | A signature made with the signing key |

`{platformUrl}` is `https://cloud.neuron-ai.dev` unless the application is configured with another one. The SDK removes a trailing slash from it and appends the path.

The two directions are independent. The application never answers an order with its result: the result arrives later, as an announcement and a report on the other direction.

### 3.2 What the application sends the platform

| Part | Value |
|---|---|
| Method | `POST` |
| `Authorization` | `Bearer <API key>` |
| `User-Agent` | `neuron-cloud-php/<version>`, today `neuron-cloud-php/1.0.0`. The same string is the field `sdk` of a sync |
| `Content-Type` | `application/json`. The SDK's default HTTP client sends it. An application can plug in another HTTP client, so the platform must not depend on any header but `Authorization` |
| Body | One JSON object. The SDK's default client escapes `/` as `\/` and writes non-ASCII characters as `\u` escapes, so the platform must parse the body as JSON and never compare it as text |

Each request is one try, and the SDK waits for its answer for the time of section 3.8. An answer that arrives later does not count: the SDK has already treated the request as undelivered.

The platform answers with a JSON object. When it refuses a request, the body carries a `code` at its top level, such as `{"code": "unauthorized"}`. The SDK ignores every field of an answer that it does not know.

The platform checks the API key before it reads the body. **AUTOMATION.** A request with a missing, wrong or revoked key gets `401` with the code `unauthorized`. The SDK treats `401` and `403` alike, whatever the body says: both are final (section 5.2).

### 3.3 What the platform sends the endpoint

| Part | Value |
|---|---|
| Method | `POST`. The endpoint answers `405` to any other method, before it looks at the signature |
| URL | The endpoint URL of the manifest, exactly as the sync gave it |
| `webhook-id` | The ID of the message. For a `start` and a `wake` it is the `invocationId` of the body |
| `webhook-timestamp` | The Unix time, in seconds, at which the platform signed this request |
| `webhook-signature` | One signature for each active signing key, separated by one space |
| `Content-Type` | `application/json`. The endpoint does not look at it |
| Body | One JSON object of at most 65,536 bytes |

The endpoint reads header names in any letter case. The platform waits for its answer for the time of section 3.8.

The endpoint deals with a request in a fixed order, and that order explains which answer a faulty request gets:

1. The method is not `POST`: `405`.
2. The signature does not verify: `401`. The endpoint decodes nothing before this point.
3. The body is longer than 65,536 bytes: `400`.
4. The body is not a JSON object, or its `protocol` is not 1: `400`.
5. The value of `verb` is unknown, or a field of that message is missing or malformed: `400`.
6. The endpoint answers a `ping` here, and a requested sync (section 5.11).
7. This deployment does not have the definition, or for a `start` and a `wake` the version in the run ID: `404`.
8. A `start` or a `wake` goes to the application's queue: `202`, or `503` when the queue does not take it. An `inspect` or an `abandon` is dealt with in the request.

### 3.4 The signature, step by step

The platform signs every request with the rule of [Standard Webhooks](https://www.standardwebhooks.com/). **FIXED BY THE PROTOCOL.**

1. **Take the key bytes.** A signing key is the text `whsec_` followed by the base64 of the key bytes. Remove `whsec_` and decode the rest from base64. The result is the key.
2. **Choose the message ID.** For a `start` and a `wake` it is the `invocationId` of the order, which stays the same every time the platform sends that order again. For an `inspect`, an `abandon`, a `ping` and a requested sync it is any ID the platform mints for that request. Reference simulator: `msg_` followed by 24 hexadecimal digits.
3. **Take the timestamp.** The current Unix time in seconds, written in decimal digits with no leading zero. Take a new timestamp for every request, also when the platform sends the same order again.
4. **Serialize the body once.** The bytes that the platform signs are the bytes that it sends. Do not serialize the body a second time for the request.
5. **Build the signed text**: the message ID, a dot, the timestamp, a dot, the body. In bytes: `{id}.{timestamp}.{body}`.
6. **Compute** HMAC-SHA256 of the signed text under the key bytes. Encode the 32 bytes of the result in base64 and put `v1,` in front. That is one signature.
7. **Send three headers**: `webhook-id` with the message ID, `webhook-timestamp` with the timestamp, and `webhook-signature` with the signature. During a key rotation, compute one signature for each active key and write them all in one header value, separated by one space. Do not send two `webhook-signature` header lines: a web server may join them with a comma, and the endpoint splits the value on spaces only.

The endpoint verifies in this way. It refuses a timestamp that is not made of digits only, or that differs from its own clock by more than 300 seconds either way. It computes the signature of step 6 with the one key it holds, over the `webhook-id` it received, the timestamp read as a number, and the body as it received it. It accepts the request when one of the signatures in the header equals the one it computed. In every other case it answers `401` with the code `invalid_signature`. The answer does not say which check failed. An endpoint that is configured with no signing key, or with one that does not decode, answers `401` to every `POST`.

#### A worked example

This is the vector "a wake signed by the platform" of `resources/protocol/1/signature-vectors.json`.

```text
signing key      whsec_bmV1cm9uLWNsb3VkLXByb3RvY29sLTEtdmVjdG9ycyEh
key bytes        33 bytes, the ASCII text  neuron-cloud-protocol-1-vectors!!
                 in hexadecimal  6e6575726f6e2d636c6f75642d70726f746f636f6c2d312d766563746f72732121
message ID       inv_01JB7K4T9QW2
timestamp        1791453603
body, 180 bytes  {"protocol":1,"verb":"wake","invocationId":"inv_01JB7K4T9QW2","definition":"order-fulfilment","workflowId":"order:123","runId":"2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12","attempt":1}
```

The signed text is 208 bytes long, on one line, with no line break at its end:

```text
inv_01JB7K4T9QW2.1791453603.{"protocol":1,"verb":"wake","invocationId":"inv_01JB7K4T9QW2","definition":"order-fulfilment","workflowId":"order:123","runId":"2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12","attempt":1}
```

```text
HMAC-SHA256, in hexadecimal   e9e4d7780e9813c9a52cf3a4cade22f466036178fbda78823ae1551bd4ad7534
the same 32 bytes in base64   6eTXeA6YE8mlLPOkyt4i9GYDYXj72niCOuFVG9StdTQ=
```

The request therefore carries:

```text
webhook-id: inv_01JB7K4T9QW2
webhook-timestamp: 1791453603
webhook-signature: v1,6eTXeA6YE8mlLPOkyt4i9GYDYXj72niCOuFVG9StdTQ=
```

A shell with OpenSSL reproduces it:

```text
printf %s 'inv_01JB7K4T9QW2.1791453603.{"protocol":1,"verb":"wake","invocationId":"inv_01JB7K4T9QW2","definition":"order-fulfilment","workflowId":"order:123","runId":"2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12","attempt":1}' \
  | openssl dgst -sha256 -mac HMAC -macopt hexkey:6e6575726f6e2d636c6f75642d70726f746f636f6c2d312d766563746f72732121 -binary \
  | openssl base64
```

An endpoint whose clock shows any instant from 1791453303 to 1791453903 accepts this request. At 1791453904 it answers `401`.

### 3.5 The time window and replayed requests

The window of 300 seconds either way is **FIXED BY THE PROTOCOL** and enforced by the SDK. It must exceed the difference between the platform's clock and the clock of any host of the application.

Within the window, anyone who recorded a signed request can send it again. That is harmless by design: a replayed `start` is a second copy of a start, and a replayed `wake` carries a fence that the first copy already used (sections 9.4 and 9.5). The platform sees a second announcement and a second report under the same invocation ID.

### 3.6 Two keys

| Key | Who holds it | What its holder can do |
|---|---|---|
| API key | The application and the platform | Publish a sync, which replaces the manifest and the endpoint URL. Send announcements and reports |
| Signing key | The platform and the application's endpoint | Send the five messages of the platform: start any registered definition that can start without input, on any workflow ID; wake a run, which fires a timer that is due or forces a retry of a failed or dead run; abandon a run; inspect any workflow ID; read the list of definitions and the endpoint URL that a sync publishes (section 5.11) |

The signing key alone can start runs, and the platform cannot stop a start that somebody else signed. It can only notice it afterwards: the application announces and reports a segment whose origin is `platform` under an invocation ID that the platform never sent. The platform alerts on that (section 7.16). On a run that has a tag (section 2.5), the holder of the key can act only through the definition that the tag names. The platform must store signing keys as it stores its most sensitive secrets.

The protocol sets no length for the bytes of a signing key: the platform chooses it (P2). The endpoint uses any key whose text begins with `whsec_` and whose rest decodes from base64 to at least one byte. Five vectors of section 12.2 show the keys that it cannot use. The keys of the other vectors are 24 and 33 bytes long.

### 3.7 Key rotation

A person starts and ends a rotation. **OPERATOR ACTION.** The protocol requires two things of the platform while a rotation lasts. **AUTOMATION.**

- **Signing keys.** The platform signs every request with every active signing key, and sends all the signatures in one header (section 3.4, step 7). The application holds one signing key at a time, and its endpoint accepts a request when one of the signatures matches.
- **API keys.** The platform accepts the old and the new API key. The application holds one API key at a time.

### 3.8 Timeouts

| Who waits | For what | How long | Kind |
|---|---|---|---|
| The SDK | The answer to an announcement or a report | 3 seconds | FIXED BY THE PROTOCOL |
| The SDK | The answer to a sync | 15 seconds | FIXED BY THE PROTOCOL |
| The SDK | The answer to a trace (section 13) | 3 seconds | FIXED BY THE PROTOCOL |
| The SDK | The answer to the results of an evaluation (section 13) | 15 seconds | FIXED BY THE PROTOCOL |
| The SDK | The answer to the read of what an evaluation found (section 13.6) | 15 seconds | FIXED BY THE PROTOCOL |
| The SDK | The answer to the read of a dataset (section 13) | 15 seconds | FIXED BY THE PROTOCOL |
| The platform | The answer of the endpoint to any message, a `ping` included | 10 seconds | SETTING |

The platform answers a sync only after it has pinged the endpoint (section 5.1). The time that it allows that ping and the rest of its work must fit in the 15 seconds that the SDK waits: this bounds the setting.

### 3.9 Bodies

These rules hold for every body, in both directions. **FIXED BY THE PROTOCOL.** Each rule says what the endpoint answers to a body of the platform that breaks it. In the other direction the SDK builds every body from typed values, so it writes no integer with a fraction and repeats no field name. Whether the platform must detect a body that breaks one of these rules all the same is not settled (U2).

- **A body is one JSON object**, in UTF-8. The endpoint answers `400 invalid_request` to a list, a scalar, text that is not JSON, text that is not UTF-8, a string with an unpaired surrogate escape, and values nested 512 levels deep or more, the body counted as the first level.
- **No message to the endpoint is longer than 65,536 bytes.** The endpoint answers `400 invalid_request` to a longer one and does not decode it. The largest valid message of protocol 1 is under 4 KB.
- **Every request carries `"protocol": 1`.** The exceptions are the sync, which lists `protocols`, and the bodies of a `ping` answer and of the answer to a requested sync, which do too. No answer carries `protocol`, except the answer to a sync.
- **Instants are Unix seconds**, as whole numbers.
- **An integer is written without a fraction and without an exponent.** `1.0` and `1e0` are malformed. The endpoint answers `400 invalid_request` to such a number in any field, and `400 unsupported_protocol` when the field is `protocol`. In the answer to a sync such a number makes the answer unusable for the SDK. A JSON Schema cannot express this rule, so a validator does not catch it.
- **A receiver ignores the fields it does not know.** The platform must also not store them (section 7.2). The rule is about fields: what the platform does with a value that it does not know, such as a new outcome, is not settled (U3).
- **An optional field is left out, never sent as null.** The endpoint answers `400 invalid_request` to `"attempt": null` in an `abandon` and to `"runId": null` in an `inspect`. The fields that the schemas declare as "a value or null", such as `code` in a report, are required and are sent as null.
- **Of two fields with the same name, the endpoint reads the last one.** The platform must not send such a body.

### 3.10 HTTPS, redirects and reachable endpoints

Both directions use HTTPS. The SDK refuses to publish an endpoint URL that does not begin with `https://`, before it sends anything, and the schema of the sync refuses one too.

The platform accepts only an endpoint that is publicly routable. **AUTOMATION.** The protocol says no more than that: what counts as routable, and when the platform checks it, are not settled (U5). A developer who works on a local machine publishes the public `https` URL of a tunnel, which is a service that forwards a public address to a local port.

The platform never follows a redirect, neither for the ping of a sync nor for any later message. **AUTOMATION.** A `3xx` answer counts as no usable answer (section 5.9).

---

## 4. Identifiers and formats

**FIXED BY THE PROTOCOL.** The regular expressions are those of `resources/protocol/1/definitions.json`; the one of `errorClass` is in `report.json` and is given under the table.

| Identifier | Format | Who mints it | Example |
|---|---|---|---|
| `definition` | `^[a-z0-9][a-z0-9._-]{0,63}$` | The developer | `order-fulfilment` |
| `version` | An integer from 1 to 999999 | The developer | `2` |
| `workflowId` | 1 to 255 characters, no control character, no leading `__`: `^(?!__)[^\x00-\x1F\x7F]{1,255}$` | The application, or the trigger that starts the run | `order:123` |
| `runId`, minted | `<version>-<unique>`: `^[1-9][0-9]{0,5}-[A-Za-z0-9][A-Za-z0-9_-]{0,119}$` | The platform for a start it sends, the SDK for a run the application starts | `2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12` |
| `runId`, any | `^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$`. Every minted run ID also has this format | Code of the application outside the SDK | `legacy-run-7` |
| `invocationId` | `^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$` | The platform for a start and a wake it sends, the SDK for a segment the application issues | `inv_01JB7K4T9QW2` |
| `attempt` | An integer, 1 or more | The application | `3` |
| `interruptId` | An integer, 1 or more | The application | `2` |
| An instant: `at`, `expiresAt`, `wakeAt`, `leaseExpiresAt` | An integer, 0 or more: Unix seconds | Whoever writes the field | `1791453603` |
| `leaseSeconds` | An integer: 60 or more in a sync, 1 or more in an announcement | The developer | `600` |
| `errorClass` | The name of a PHP class: name parts separated by `\`, with no `\` in front. Each part holds letters, digits, `_` and non-ASCII characters, and does not begin with a digit. No maximum length | The application | `App\Billing\PaymentDeclined` |
| `eventName` | Any text: the schema sets no minimum and no maximum length | The developer | `payment.received` |

The regular expression of `errorClass`:

```text
^(?:[A-Za-z_]|[^\x00-\x7F])(?:[A-Za-z0-9_]|[^\x00-\x7F])*(?:\\(?:[A-Za-z_]|[^\x00-\x7F])(?:[A-Za-z0-9_]|[^\x00-\x7F])*)*$
```

The schemas set no maximum length for `errorClass`, for `eventName` and for the `sdk` of a sync. The limit of 65,536 bytes of section 3.9 is for a message to the endpoint only, so the protocol sets no largest notice (U2).

What the platform must respect when it mints an identifier:

- **A run ID is never used twice** in an environment, whoever minted the first one (section 7.1, rule R1). The platform takes the `<unique>` part from a source that cannot repeat and cannot collide with the run IDs that the SDK mints, which look like `2-01a1259e-743e-792a-9eb0-a441942d6464`. Reference simulator: a random UUID. The platform cannot hold the application to this rule: what it does with a run ID that reaches it under a second workflow ID is not settled (U8).
- **The version at the front of a run ID is the current version** of the definition in the manifest: the highest one.
- **An invocation ID belongs to one order** and stays the same every time the platform sends that order again. The SDK reads it from the `webhook-id` header and refuses a `start` or a `wake` whose body names another one.
- **A workflow ID that a trigger builds stays within 255 bytes**, not only 255 characters. Two of the stores an application can use refuse a longer name, and the endpoint then answers every `inspect` of that workflow ID with a snapshot that was not read, and every `abandon` with `503`.

The platform must not expect a prefix in an identifier that the application minted. The SDK's invocation IDs look like `inv_01a1259e-67ab-77a9-aeb3-103ea6966127` today, and nothing in the protocol promises that form.

---

## 5. The messages, one by one

Each message has its JSON Schema in `resources/protocol/1/`. In the field tables, "required" means that the schema requires the field. A field whose type is "X or null" is always present and is null when it has no value.

The answers of the application's endpoint follow one table of statuses, which section 5.9 gives in full. The sections on `start`, `wake`, `inspect`, `abandon` and `ping` list what each message adds to it.

### 5.1 Sync

**What it is for.** The application publishes the complete list of its definitions and versions, and the URL of its endpoint. The platform stores them as the manifest.

**Who sends it, and when.** The application, as the last step of a deploy. A person or a deploy script runs it. The SDK refuses to send a sync, before any request leaves, when no definition is registered, when the endpoint URL does not begin with `https://`, when a registered lease is under 60 seconds, and when the application's store is of a kind that several processes cannot share.

**Request:** `POST {platformUrl}/v1/sync`, schema `sync.json`.

| Field | Type | Required | Meaning |
|---|---|---|---|
| `protocols` | List of integers, 1 or more each; the list must contain 1 | Yes | The protocol versions the sender speaks. Today `[1]` |
| `sdk` | Text, not empty | Yes | The SDK and its version |
| `endpoint` | Text that begins with `https://` | Yes | The URL the platform calls |
| `definitions` | List of objects, of any length | Yes | Every registered version of every definition. The list is complete and replaces the previous one |
| `definitions[].definition` | Definition name | Yes | The name |
| `definitions[].version` | Integer, 1 to 999999 | Yes | The version |
| `definitions[].leaseSeconds` | Integer, 60 or more | Yes | The lease of a segment of this version |

The SDK lists the definitions grouped by name: the names in the order in which each was first registered, and under each name its versions in the order in which they were first registered. The platform finds the current version of a definition by its number, not by its place in the list: it is the highest.

The schema accepts an empty list, and a list that names one definition and version twice. The SDK sends neither. An empty list is complete like any other: it replaces the manifest with nothing. Every version that has open runs then becomes **stranded**, which means that it is not in the manifest and still has open runs (section 7.15). What the platform does with a pair that is listed twice is not settled (U4).

No rule of section 7 uses the `leaseSeconds` of a sync: the platform counts the silence of a segment from the `leaseSeconds` of its announcement (section 7.10).

<!-- example: sync | schema: sync.json -->
```json
{"protocols": [1], "sdk": "neuron-cloud-php/1.0.0", "endpoint": "https://shop.example.com/neuron-cloud",
 "definitions": [{"definition": "order-fulfilment", "version": 2, "leaseSeconds": 600},
                 {"definition": "order-fulfilment", "version": 1, "leaseSeconds": 600},
                 {"definition": "nightly-reconciliation", "version": 1, "leaseSeconds": 900}]}
```

**What the platform does with it** is the procedure of section 7.15: it checks the endpoint URL, sends the endpoint a signed `ping`, and only then replaces the manifest.

**Answer `200`,** schema `sync-answer.json`.

| Field | Type | Required | Meaning |
|---|---|---|---|
| `protocol` | `1` | Yes | |
| `modified` | Boolean | Yes | False when the sync changed nothing |
| `versions` | List of objects | Yes | One entry for each version that is in the manifest now |
| `stranded` | List of objects | Yes | One entry for each version that is not in the manifest and still has open runs |
| `versions[]`, `stranded[]` `.definition` | Definition name | Yes | |
| `versions[]`, `stranded[]` `.version` | Integer, 1 to 999999 | Yes | |
| `versions[]`, `stranded[]` `.openRuns` | Integer, 0 or more | Yes | How many runs the platform holds open on that version |

The protocol says no more about `modified` than "nothing changed": what counts as a change is not settled (U4). The SDK hands the value to the deploy script and does nothing else with it.

<!-- example: sync-answer | schema: sync-answer.json -->
```json
{"protocol": 1, "modified": true,
 "versions": [{"definition": "order-fulfilment", "version": 2, "openRuns": 14},
              {"definition": "order-fulfilment", "version": 1, "openRuns": 0},
              {"definition": "nightly-reconciliation", "version": 1, "openRuns": 1}],
 "stranded": [{"definition": "trial", "version": 3, "openRuns": 2}]}
```

The SDK reads the counts for the developer. A version with no open run that is lower than the highest version registered for its definition can leave the code: here version 1 of `order-fulfilment`. A stranded version is a warning, not a failure: the platform accepts the sync anyway.

A sync that changes nothing:

<!-- example: sync-answer-unchanged | schema: sync-answer.json -->
```json
{"protocol": 1, "modified": false,
 "versions": [{"definition": "order-fulfilment", "version": 2, "openRuns": 14},
              {"definition": "order-fulfilment", "version": 1, "openRuns": 0},
              {"definition": "nightly-reconciliation", "version": 1, "openRuns": 1}],
 "stranded": []}
```

**Every answer, and what the SDK does with it.** The SDK sends a sync once. It never sends it again by itself: whatever the answer, the deploy script succeeds or fails, and a person decides.

| Status | Body | Meaning | What the SDK does |
|---|---|---|---|
| `200` | A sync answer | The manifest is the list of this sync | Returns the result to the deploy script |
| `400` | `{"code": "invalid_request"}` | The body is malformed | Fails the deploy with `invalid_request`. Final |
| `400` | `{"code": "unsupported_protocol"}` | `protocols` does not contain 1 | Fails the deploy with `unsupported_protocol`. Final |
| `401` or `403` | `{"code": "unauthorized"}` | The API key is missing, wrong or revoked | Fails the deploy with `unauthorized`. Final |
| `422` | `{"code": "endpoint_unverified"}` | The platform could not ping the endpoint. The manifest is unchanged | Fails the deploy with `endpoint_unverified`. Final |
| Anything else | Anything | The platform did not confirm the sync | Fails the deploy with `report_undelivered`. The developer runs the sync again, which is safe because the list replaces the previous one |

Which of these answers a sync gets while it waits for an owner's consent (section 7.15, step 4) is Q3. Which one it gets for an endpoint that is not publicly routable is not settled either (U5).

Three details of how the SDK reads the answer decide what the platform must send.

- The SDK decides "final" from the code, not from the exact status: `401` and `403` are final whatever the body says, and any `4xx` whose `code` is `invalid_request`, `unsupported_protocol` or `endpoint_unverified` is final. Any other `4xx`, such as a `409` or a `429`, or one with a code that the SDK does not know, is "not confirmed".
- A `200` counts as the answer to a sync only when `modified` is a boolean and `versions` and `stranded` are lists whose entries each hold a definition name, a version of 1 or more and a number of open runs of 0 or more. The SDK accepts a list that is left out, and does not look at `protocol` nor at any other field. The schema requires all four fields, and the platform sends all four.
- A `200` of another shape, a `5xx` and no answer within 15 seconds are all "not confirmed". After a `2xx` that is not a sync answer the SDK tells the developer that the sync may have been applied.

The refusal of a sync whose endpoint could not be pinged:

<!-- example: platform-error-endpoint-unverified | schema: platform-error.json -->
```json
{"code": "endpoint_unverified"}
```

### 5.2 Announcement

**What it is for.** "A segment of this run is starting; its lease is `leaseSeconds`." The platform learns that a run exists, which segment is about to execute, and how long to wait for its report before it asks.

**Who sends it, and when.** The SDK, once for every segment, before the segment executes:

- a worker that is about to execute a segment posts the announcement directly, then executes the segment whatever the platform answered;
- a segment that runs inside a web request does not post it: the SDK puts the announcement on the application's queue before the segment executes, and a worker posts it later.

**Request:** `POST {platformUrl}/v1/announcements`, schema `announcement.json`.

| Field | Type | Required | Meaning |
|---|---|---|---|
| `protocol` | `1` | Yes | |
| `invocationId` | Invocation ID | Yes | The ID of the segment. When `origin` is `platform`, it is the `invocationId` of the `start` or the `wake` that the platform sent |
| `verb` | `start`, `wake`, `answer` or `signal` | Yes | The kind of segment (section 2.3) |
| `origin` | `platform` or `application` | Yes | Who issued the segment |
| `definition` | Definition name | Yes | The definition the segment runs on: the one the order named, or the one the application's code named |
| `workflowId` | Workflow ID | Yes | |
| `runId` | Run ID, of any form | Yes | The run the segment belongs to. For a start it is the run ID that the run will have if the start executes |
| `attempt` | Integer of 1 or more, or null | Yes | The fence of the segment. Null for a start, which has none. For a wake, an answer or a signal: the attempt that was current when the segment was issued. The segment then executes under the next attempt |
| `leaseSeconds` | Integer, 1 or more | Yes | The lease the segment runs under |
| `at` | Instant | Yes | The application's clock when the SDK built the announcement, before the segment started. It keeps that value when a queue posts the announcement later |

The announcement of a start that the platform sent:

<!-- example: announcement-start-platform | schema: announcement.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2M8Q3W7A", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",
 "attempt": null, "leaseSeconds": 600, "at": 1791634831}
```

The announcement of a signal that the application's own code issued for the same run. The run was at attempt 1, so the segment will execute under attempt 2:

<!-- example: announcement-signal-application | schema: announcement.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c1-f97e-7292-a087-ef14e6187925", "verb": "signal",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "order:123",
 "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": 1, "leaseSeconds": 600, "at": 1791634831}
```

**What an announcement does not say.** It does not say that the segment will execute. A worker posts the announcement of a second copy of a start, and the SDK rejects that copy afterwards. A worker posts the announcement of a wake whose fence was already used, and the engine refuses that wake afterwards. The report says what happened.

**Not every report follows an announcement.** The SDK decides some things before it announces. A report with one of these outcomes arrives with no announcement before it: `aborted` with `unknown_definition`, `unknown_version` or `not_executed` when the workflow could not be built, `rejected` with `invalid_start`, and `rejected` with `expired` when the worker found the use-by time passed as soon as it took the start. A report that says `aborted` with `announce_failed` follows an announcement that the platform did not record. The platform accepts a report with no announcement.

An announcement can also arrive after the report of its segment, or not at all. A worker that could not post an announcement puts it on the application's queue and executes the segment, so the report can reach the platform first; the announcement that follows keeps the `at` of the moment it was built, before the segment. When the queue does not take the announcement either, a segment that the platform ordered still executes, and its report arrives with no announcement.

**Answer `200`,** schema `recorded.json`:

<!-- example: recorded | schema: recorded.json -->
```json
{"recorded": true}
```

The platform answers `200` only after it has written the announcement durably (section 7.2). **AUTOMATION.**

**Every answer, and what the SDK does with it.** The table is the same for an announcement and for a report.

| Status | Body | Meaning | What the SDK concludes |
|---|---|---|---|
| `200` | `{"recorded": true}` | The platform holds the notice | Recorded. Nothing more happens |
| `401` or `403` | Anything | The API key is missing, wrong or revoked | Final: `unauthorized` |
| Any `4xx` | `{"code": "invalid_request"}` | The body is malformed | Final: `invalid_request` |
| Any `4xx` | `{"code": "unsupported_protocol"}` | `protocol` is not 1 | Final: `unsupported_protocol` |
| Anything else | Anything | The platform did not record the notice | Undelivered |

"Anything else" includes a `200` whose body is not a JSON object that holds `"recorded": true`, with the boolean `true` (the SDK ignores the other fields of that object), a `201` or a `202`, a `3xx` (the SDK's default HTTP client follows no redirect), a `4xx` with another code or none, every `5xx`, and no answer within 3 seconds.

- **Undelivered.** An announcement goes to the application's queue as it is, with its `at`, and a worker posts it again later. A report does not: the SDK puts a reminder on the queue, and the worker that takes it reads the store again and posts a new report, with a new `at` (section 5.3). The application's worker is expected to try again after 10, 30, 60 and 300 seconds, then every 900 seconds, without a limit. The queue and those delays belong to the application, so the platform must not count on exact values. When the platform comes back after an outage, the notices that piled up arrive together.
- **Final.** The job that posted the notice ends as a failed job in the application's queue, and nothing sends the notice again until a person fixes the cause and replays the job. A notice that the platform refuses for good can still reach it twice:
  1. A worker that executes a segment posts the two notices of the segment itself.
  2. When the platform does not record such a post, whether its answer is final or not, the SDK puts the notice on the application's queue once: the announcement as it was, and for a report a reminder.
  3. The job that takes it posts the notice a second time, the report with a fresh snapshot (section 5.3). The final answer to this second post is the one that ends the notice.
- **In both cases the segment is not affected.** A worker executes the segment whether or not the platform recorded its announcement.

**A notice that the platform cannot take.** **AUTOMATION.** The platform answers `invalid_request` only to a body that can never become valid. To a notice that it fails to store it gives an answer that the table above counts as undelivered, so that the application posts the notice again. The protocol names no status for that answer. Any `5xx` is one: the SDK counts every `5xx` as undelivered, whatever its body. Reference simulator: `503`. The SDK reads no `Retry-After` header in an answer of the platform.

The same announcement can reach the platform twice, for example when the platform recorded it and the answer was lost. A notice that arrives a second time with the same body changes nothing of what the platform holds for the run. An invocation ID, however, does not make a notice unique, and the platform must not drop a notice because it has already recorded one under that ID. Every copy of an order posts its own announcement and its own report under the invocation ID of the order (sections 9.4 and 9.5), and a report that the platform did not record is posted again with a fresh snapshot (section 5.3). The later one can be the only one that names the run (section 9.14). The platform takes every notice through section 7.3 or 7.4, and reads the snapshot of every report that carries one (section 7.5).

A refusal of the platform validates against `platform-error.json`. The three refusals of a notice differ only in the code:

<!-- example: platform-error-unauthorized | schema: platform-error.json -->
```json
{"code": "unauthorized"}
```

### 5.3 Report

**What it is for.** "This is the run now." A report says how one **call** ended, and carries a snapshot of the workflow ID that the application read from its store immediately before it sent the report. A call is one handling, by one process of the application, of one order of the platform or of one request of the application's own code to execute a segment. A call executes one segment or none. Two copies of one order are two calls, each with its own report.

**Who sends it, and when.** The SDK, after every segment and after every order that did not become a segment:

- a worker that executed a segment, or decided not to, reads the store and posts the report directly;
- for a segment that ran inside a web request, a worker reads the store and posts the report later;
- when the platform did not record a report, a worker reads the store again later and posts a new report for the same segment. That report carries the same `invocationId`, `verb`, `outcome`, `code`, `errorClass` and `executedAttempt`, and a fresh snapshot: its `run`, `claimed` and `at` can differ from those of the first.

**Request:** `POST {platformUrl}/v1/reports`, schema `report.json`. All fifteen fields are required.

| Field | Type | Meaning in one line |
|---|---|---|
| `protocol` | `1` | |
| `invocationId` | Invocation ID | The segment the report speaks for. The same as in its announcement |
| `verb` | `start`, `wake`, `answer` or `signal` | The kind of segment |
| `origin` | `platform` or `application` | Who issued the segment |
| `definition` | Definition name | The definition the order or the application's code named |
| `workflowId` | Workflow ID | |
| `runId` | Run ID | The run the segment was for |
| `outcome` | One of seven words | How the call ended |
| `code` | Text or null | Why, for the outcomes `refused`, `rejected` and `aborted` |
| `errorClass` | Class name or null | The class of the exception, for `failed` and sometimes for `aborted` |
| `executedAttempt` | Integer of 1 or more, or null | The attempt this call executed under. Null when it executed no segment |
| `run` | Object or null | The run that holds the workflow ID now. Null when none does |
| `claimed` | Boolean or null | Whether the run ID of a start is in the ledger |
| `snapshotRead` | Boolean | False when the store could not be read: `run` and `claimed` then mean nothing |
| `at` | Instant | The application's clock immediately after the store was read |

Section 6 explains every field, every outcome and every code. The report of the start announced above:

<!-- example: report-start-suspended | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2M8Q3W7A", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",
 "outcome": "suspended", "code": null, "errorClass": null, "executedAttempt": 1,
 "run": {"runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 1, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638431}},
 "claimed": true, "snapshotRead": true, "at": 1791634831}
```

**Answer `200`,** and every other answer: as for an announcement (section 5.2).

When the platform did record a report and only its answer was lost, the SDK posts a second report for the segment all the same. The platform then holds two reports with one `invocationId` and two snapshots. It reads both snapshots by the rules of section 7.5, which do not depend on the order of arrival.

### 5.4 Start

**What it is for.** The order to start a run.

**Who sends it, and when.** The platform, when a trigger fires: once for each firing. **AUTOMATION**; the trigger is a **SETTING**. The platform sends the same start again in the cases of section 7.12, and never after its use-by time.

**Request:** `POST {endpoint}`, schema `start.json`.

| Field | Type | Required | Meaning |
|---|---|---|---|
| `protocol` | `1` | Yes | |
| `verb` | `"start"` | Yes | |
| `invocationId` | Invocation ID | Yes | The ID of this order. It equals the `webhook-id` header and is the same every time the platform sends this start again |
| `definition` | Definition name | Yes | The definition to start. It is in the manifest |
| `workflowId` | Workflow ID | Yes | The workflow ID the run will hold. The trigger chooses it |
| `runId` | Minted run ID | Yes | `<current version>-<unique>`, minted by the platform and never used before |
| `expiresAt` | Instant | Yes | The use-by time: a worker that takes the start at or after this instant does not execute it. The platform sets it once, 600 to 3600 seconds after it first sends the start, and never changes it |

A start carries no input. A workflow that the platform starts loads what it needs from its workflow ID.

<!-- example: start | schema: start.json -->
```json
{"protocol": 1, "verb": "start", "invocationId": "inv_01JB2M8Q3W7A", "definition": "order-fulfilment",
 "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "expiresAt": 1791635731}
```

The headers of that request, signed with the key of section 3.4. The signature covers the body as it was sent: the object above on one line, with no space between its parts.

```text
webhook-id: inv_01JB2M8Q3W7A
webhook-timestamp: 1791634831
webhook-signature: v1,TgMEQR3zydmvanG9ThgDRo5b3xzdF3rC4fZ0FqVQhIs=
```

**What the endpoint checks.** The run ID has the minted form. The `invocationId` equals the `webhook-id` header. The `expiresAt` is no more than 3,900 seconds ahead of the endpoint's clock, which is 3,600 seconds plus the signature window. The endpoint does not check the lower bound of 600 seconds: the protocol sets it, and the application executes a start with a nearer use-by time like any other. It does not refuse a use-by time that has already passed either: it queues such a start, and the worker reports it as `rejected` with `expired`. The deployment has the definition and the version at the front of the run ID.

**Every answer.**

| Status | `code` | Meaning | What the platform does |
|---|---|---|---|
| `202` | None: the body is `{}` | The start is verified, valid and on the application's queue. Nothing has run and no lease exists | Assumes nothing. Waits for the announcement and the report. When the start stays silent, asks after its use-by time (section 7.7) |
| `400` | `invalid_request`, `unsupported_protocol` | The endpoint will never accept this start | Never sends it again. Alerts |
| `401` | `invalid_signature` | The endpoint does not verify the signature | Stops every delivery to the endpoint, keeps the start, alerts, pings (section 5.9) |
| `404` | `unknown_definition`, `unknown_version` | This deployment does not have that code: a deploy has not delivered it yet, or has removed it | Sends the start again with a back-off while the version is in the manifest and the use-by time has not passed |
| `405` | `invalid_request` | The request was not a `POST` | Never sends it again. Alerts |
| `503` | `dispatch_failed`, with `Retry-After` | The application's queue did not take the start | Sends the same start again once `Retry-After` has passed. Holds back every other message to this endpoint until then |
| `500`, a `3xx`, no answer | `internal_error` for a `500` | Nobody knows whether the start is on the queue | Sends the same start again up to 5 times, with a back-off of 5 seconds that doubles to 300. Then treats it as **accepted and silent**: as if the endpoint had answered `202` and nothing had followed (section 7.12) |

### 5.5 Wake

**What it is for.** The order to continue a run, with no input. The application decides what the wake does: it fires a timer that is due, or it executes again the segment of a failed or dead run. A wake that finds nothing to do executes nothing.

**Who sends it, and when.** The platform, in three cases and no other. **AUTOMATION.**

| Case | The platform holds | Section |
|---|---|---|
| A timer is due | A snapshot that shows the run `suspended` on a wait with a `wakeAt` that has passed | 7.8 |
| A failed run is retried | A snapshot that shows the run `failed`, and the retry policy allows a retry | 7.9 |
| A dead run is retried | A snapshot that shows the run `running` under a lease that has passed, and the retry policy allows a retry | 7.9 |

**Request:** `POST {endpoint}`, schema `wake.json`.

| Field | Type | Required | Meaning |
|---|---|---|---|
| `protocol` | `1` | Yes | |
| `verb` | `"wake"` | Yes | |
| `invocationId` | Invocation ID | Yes | The ID of this order. It equals the `webhook-id` header and is the same every time the platform sends this wake again because a delivery failed. For a wake that the platform decides again: Q5 |
| `definition` | Definition name | Yes | The definition of the run (section 7.1, rule R3) |
| `workflowId` | Workflow ID | Yes | |
| `runId` | Run ID, of any form | Yes | The run to wake, as a snapshot showed it |
| `attempt` | Integer, 1 or more | Yes | The attempt that the same snapshot showed. Never a guess, never an increment |

<!-- example: wake | schema: wake.json -->
```json
{"protocol": 1, "verb": "wake", "invocationId": "inv_01JB7M6P2ZC4", "definition": "order-fulfilment",
 "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "attempt": 2}
```

**What the application does with it.** A worker takes the wake, posts the announcement and reads the store. It executes nothing when no run with that run ID holds the workflow ID (`refused` with `stale_run`), when the run was started by another definition (`rejected` with `wrong_definition`), when the attempt is no longer the current one (`refused` with `stale_attempt`), or when a process is executing the run (`refused` with `executing`). It executes nothing either when the run is suspended and nothing is due on the application's own clock: the report then says `suspended` with no `executedAttempt`. Otherwise it raises the attempt, executes a segment and reports.

Because of the fence, the platform may send a wake again whenever it is unsure: a copy of a wake that already executed is refused. There is one exception: a wake reported as `rejected` with `wrong_definition` is never sent again.

**Every answer.** The table of a start (section 5.4) holds for a wake, with two differences. A wake has no use-by time: after a `404` the platform sends it again for as long as the version is in the manifest. And when a `202` is followed by no announcement within 900 seconds, the platform asks, and sends the wake again when the attempt is unchanged (section 7.10).

### 5.6 Inspect

**What it is for.** The question "which run holds this workflow ID?". With a run ID, also "is this run ID in the ledger?".

**Who sends it, and when.** The platform, whenever what it holds may be wrong. **AUTOMATION.** Section 7.10 lists the cases. An `inspect` reads the store and changes nothing in the application. The platform may send it at any time.

**Request:** `POST {endpoint}`, schema `inspect.json`.

| Field | Type | Required | Meaning |
|---|---|---|---|
| `protocol` | `1` | Yes | |
| `verb` | `"inspect"` | Yes | |
| `definition` | Definition name | Yes | The definition of the run the platform asks about (rule R3) |
| `workflowId` | Workflow ID | Yes | |
| `runId` | Run ID, of any form | No | The run ID the platform asks about. Leave the field out when there is none: never send null |

Every `inspect` that section 7 asks for is about a run that the platform holds, and names its run ID. No rule of protocol 1 needs the form with no `runId`. The endpoint accepts it, and the answer then says `claimed` null.

An `inspect` has no `invocationId`. Its `webhook-id` is any ID the platform mints for the request.

<!-- example: inspect | schema: inspect.json -->
```json
{"protocol": 1, "verb": "inspect", "definition": "order-fulfilment", "workflowId": "order:123",
 "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12"}
```

The answer always describes the run that holds the workflow ID now, whichever run ID the question named. The `runId` of the question decides one thing only: the `claimed` of the answer.

**Answer `200`,** schema `inspect-answer.json`.

| Field | Type | Required | Meaning |
|---|---|---|---|
| `run` | Object or null | Yes | The run that holds the workflow ID. Null when none does |
| `claimed` | Boolean or null | Yes | Whether the `runId` of the question is in the ledger. Null when the question named no run ID, and when the store could not be read |
| `snapshotRead` | Boolean | Yes | False when the store could not be read: `run` and `claimed` then mean nothing |
| `at` | Instant | Yes | The application's clock immediately after the store was read |

<!-- example: inspect-answer-suspended-event | schema: inspect-answer.json -->
```json
{"run": {"runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 1, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638431}},
 "claimed": true, "snapshotRead": true, "at": 1791634831}
```

The same question with no run ID:

<!-- example: inspect-without-run | schema: inspect.json -->
```json
{"protocol": 1, "verb": "inspect", "definition": "order-fulfilment", "workflowId": "order:123"}
```

<!-- example: inspect-answer-without-run | schema: inspect-answer.json -->
```json
{"run": {"runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 1, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638431}},
 "claimed": null, "snapshotRead": true, "at": 1791634831}
```

Section 6.11 says how to read each combination of `run`, `claimed` and `snapshotRead`.

**Every answer.**

| Status | `code` | Meaning | What the platform does |
|---|---|---|---|
| `200` | None | The answer above. When the store cannot be read, the endpoint still answers `200`, with `snapshotRead` false | Reads the snapshot (section 7.5) |
| `400` | `invalid_request` | One of two things, which the answer does not tell apart. The question is malformed. Or the run that holds the workflow ID, whichever run the question names, has a tag that is not the definition the question names (section 2.5). A platform that sends only well-formed questions meets the second case alone. The answer shows nothing of the run asked about, not even `claimed` | Never sends it again. Alerts. Whether it may ask about that run again later is not settled (U18) |
| `400` | `unsupported_protocol` | | Never sends it again. Alerts |
| `401` | `invalid_signature` | | Stops every delivery, alerts, pings (section 5.9) |
| `404` | `unknown_definition` | This deployment does not have the definition. An `inspect` is never answered `unknown_version`: the endpoint reads the store through the current version when it lacks the version of the run | Asks again with a back-off while the version of the run is in the manifest (section 7.11, step 4) |
| `405` | `invalid_request` | | Never sends it again. Alerts |
| `500`, a `3xx`, no answer | `internal_error` for a `500` | Unknown | Asks again up to 5 times, with a back-off of 5 seconds that doubles to 300. Then alerts, and keeps asking at a slow pace until an answer is read (section 7.12) |

The SDK never answers `503` to an `inspect`. A proxy in front of the application can: what the platform then does is not settled (U1).

### 5.7 Abandon

**What it is for.** The order to discard a run: to delete it from the store and free its workflow ID, whatever the run was waiting for, and to make sure that a start still waiting in a queue never executes.

**Who sends it, and when.** The platform, only when an operator asks for it. **OPERATOR ACTION.** The platform never abandons a run by itself, not even a run that failed for good.

**Request:** `POST {endpoint}`, schema `abandon.json`.

| Field | Type | Required | Meaning |
|---|---|---|---|
| `protocol` | `1` | Yes | |
| `verb` | `"abandon"` | Yes | |
| `definition` | Definition name | Yes | The definition of the run (rule R3) |
| `workflowId` | Workflow ID | Yes | |
| `runId` | Run ID, of any form | Yes | The run to discard |
| `attempt` | Integer, 1 or more | No | The attempt a snapshot showed for the run. Leave the field out for a start the platform sent and has heard nothing of: never send null |

An `abandon` has no `invocationId`. Its `webhook-id` is any ID the platform mints for the request.

The abandon of a run that a snapshot showed at attempt 2:

<!-- example: abandon | schema: abandon.json -->
```json
{"protocol": 1, "verb": "abandon", "definition": "order-fulfilment", "workflowId": "order:124",
 "runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40", "attempt": 2}
```

The abandon of a start that the platform sent and has heard nothing of:

<!-- example: abandon-without-attempt | schema: abandon.json -->
```json
{"protocol": 1, "verb": "abandon", "definition": "order-fulfilment", "workflowId": "order:125",
 "runId": "2-0192f3ab-52c7-7e19-a4d3-8b6c0f2e7d15"}
```

**What the endpoint does with it**, in this order. The order explains the answers.

1. It looks at the run that holds the workflow ID. When that run has a tag that is not the definition the message names (section 2.5), it answers `400 invalid_request` and writes nothing.
2. It writes the run ID of the message into the ledger when it is not there, with a use-by time one hour ahead. From then on the SDK rejects a start with that run ID that still waits in a queue.
3. When a run held the workflow ID at step 1, it discards that run, under the fence: the run ID must match, and the attempt too when the message carries one. A message with no attempt discards the run with that run ID at whatever attempt it is.
4. It reads the store again for the answer.

**Answer `200`,** schema `inspect-answer.json`: the same four fields as the answer to an `inspect`. `claimed` is always true.

<!-- example: abandon-answer-200 | schema: inspect-answer.json -->
```json
{"run": null, "claimed": true, "snapshotRead": true, "at": 1791634834}
```

A `200` says that nothing will run under that run ID any more. It does not say whether anything ran: the body is the same when the endpoint discarded a run and when it found no run and wrote the ledger entry.

**Answer `409`,** schema `error.json`: the same four fields, and a `code`.

The example is the answer to an abandon of run `2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40` that carried attempt 1, sent after the run had moved on to attempt 2. The platform then sends the abandon again with the attempt shown: that second abandon is the first example of this section.

<!-- example: abandon-answer-409-stale-attempt | schema: error.json -->
```json
{"code": "stale_attempt",
 "run": {"runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 2, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638434}},
 "claimed": true, "snapshotRead": true, "at": 1791634834}
```

**Every answer.** Section 6.8 reads the `200` and the `409` in full.

| Status | `code` | Meaning | What the platform does |
|---|---|---|---|
| `200` | None | Nothing will run under that run ID any more | Closes the run as abandoned |
| `409` | `stale_run`, `stale_attempt`, `executing`, `conflict`, `completed` or `abandon_refused` | The run stays, or the endpoint cannot tell what became of it. `run` is attached | Plans from `run`, never from the code (section 6.8) |
| `400` | `invalid_request` | The message is malformed, or the run that holds the workflow ID has a tag that is not the definition the message names, as for an `inspect` (section 5.6). Nothing was written | Never sends it again. Alerts. The operator's request ends as refused |
| `400` | `unsupported_protocol` | | The same |
| `401` | `invalid_signature` | | Stops every delivery, alerts, pings (section 5.9) |
| `404` | `unknown_definition` | This deployment does not have the definition. An `abandon` is never answered `unknown_version` | Sends it again with a back-off while the version of the run is in the manifest (section 7.11, step 4). What becomes of the operator's request when the version is not in the manifest is not settled (U18) |
| `405` | `invalid_request` | | Never sends it again. Alerts |
| `503` | `unavailable`, with `Retry-After` | The store failed, or the application could not build the workflow. The ledger entry may already be written | Sends the same abandon again once `Retry-After` has passed. Holds back every other message to this endpoint until then |
| `500`, a `3xx`, no answer | `internal_error` for a `500` | Unknown | Sends it again up to 5 times, with a back-off of 5 seconds that doubles to 300. Then alerts, and keeps sending it at a slow pace until an answer is read (section 7.12) |

### 5.8 Ping

**What it is for.** The question "does this endpoint verify my signature?".

**Who sends it, and when.** The platform, in two cases. **AUTOMATION.**

- Before it accepts a sync: one ping to the endpoint URL that the sync names.
- After an endpoint answered `401`: one ping every 60 seconds until one succeeds. The 60 seconds are a **SETTING**.

**Request:** `POST {endpoint}`, schema `ping.json`. The body has two fields and nothing else.

<!-- example: ping | schema: ping.json -->
```json
{"protocol": 1, "verb": "ping"}
```

**Answer `200`,** schema `ping-answer.json`. `protocols` lists the protocol versions the endpoint speaks and contains 1.

<!-- example: ping-answer | schema: ping-answer.json -->
```json
{"protocols": [1]}
```

A ping succeeds when the answer is `200` and its body validates against `ping-answer.json`. Every other answer is a failed ping, a `3xx` included.

A successful ping proves that the URL reaches an endpoint of the SDK, that the endpoint holds a signing key that verifies the platform's signature, that the two clocks are within the window, and that the endpoint speaks protocol 1. It proves nothing about the application's queue, its store or the definitions it has deployed: the endpoint answers a ping before it looks at any of them.

### 5.9 Every answer of the endpoint, by status

No answer of the endpoint carries `protocol`. Every answer is a JSON object sent as `application/json`. The body of every answer that is not `200` or `202` validates against `error.json` and has a `code` at its top level.

| Status | `code` | Answers | Meaning | What the platform does |
|---|---|---|---|---|
| `202` | None: the body is `{}` | `start`, `wake` | Verified, valid, queued. Nothing has run | Assumes nothing yet |
| `200` | None | `inspect`, `abandon`, `ping`, requested `sync` | The question was answered, or the abandon is done | Reads the body |
| `400` | `invalid_request` | All | The message is malformed, longer than 65,536 bytes, or has an unknown `verb`. For an `inspect` and an `abandon` also: the run that holds the workflow ID has a tag that is not the definition named (section 5.6). For a requested sync also: the SDK does not know the verb (section 5.11) | Never sends the message again. Alerts, except for a requested sync |
| `400` | `unsupported_protocol` | All | `protocol` is not 1 | Never sends the message again. Alerts |
| `401` | `invalid_signature` | All | One answer for every signature problem: a wrong key, an altered body, a timestamp outside the window, no key configured | Stops every delivery to this endpoint and keeps what it had planned. Alerts. Pings every 60 seconds. Resumes after a ping succeeds, the ping of a sync included (section 7.15) |
| `404` | `unknown_definition`, `unknown_version` | `start`, `wake`, `inspect`, `abandon` | This deployment lacks that code | Sends the message again with a back-off while the version is in the manifest. Never sends a `start` or a `wake` for a version that left the manifest |
| `405` | `invalid_request` | All | The request was not a `POST` | Never sends the message again. Alerts |
| `409` | A refusal of the application's engine, or `abandon_refused` | `abandon` | The abandon did not discard the run | Plans from `run` |
| `409` | `sync_refused` | requested `sync` | The application would not publish this sync either (section 5.11) | Ends the request |
| `503` | `dispatch_failed` for a `start` and a `wake`, `unavailable` for an `abandon` | `start`, `wake`, `abandon` | The application's queue or store failed. The answer carries `Retry-After`, in seconds: 5 today | Sends the same message again once `Retry-After` has passed, or after the back-off when the answer carries no `Retry-After`. Holds back every other message to this endpoint until then |
| `500` | `internal_error` | All | Something unexpected was thrown in the application. Nobody knows whether an order was queued | Sends the same message again up to 5 times, with a back-off of 5 seconds that doubles to 300. Then section 7.12 |
| A `3xx`, no answer within 10 seconds | None | All | Unknown | The same as after a `500` |

This document says that a message got **no usable answer** when the endpoint answered `500` or a `3xx`, or did not answer within the platform's timeout: the last two rows of the table.

Every refusal but the `409` to an abandon has the same body, with its own code. Three of them, as the endpoint sent them: a `401`, a `404` and a `500`.

<!-- example: error-401 | schema: error.json -->
```json
{"code": "invalid_signature"}
```

<!-- example: error-404-unknown-version | schema: error.json -->
```json
{"code": "unknown_version"}
```

<!-- example: error-500 | schema: error.json -->
```json
{"code": "internal_error"}
```

A `503` of the endpoint has the same kind of body, and carries the header `Retry-After: 5`:

<!-- example: error-503-dispatch-failed | schema: error.json -->
```json
{"code": "dispatch_failed"}
```

**Answers this table does not list.** When the web servers of the application are down, nothing answers with the bodies above: the platform sees a refused or reset connection, a TLS or DNS failure, or the `502`, `503` or `504` of a proxy with no `code`. What follows such an answer, a `403`, a `408`, a `429`, another `2xx`, or a `200`, `202` or `409` whose body is broken, is not settled (U1). What section 7.12 promises for an endpoint that is away depends on it.

### 5.10 Every code

| Where it appears | Codes |
|---|---|
| Answers of the platform to the application | `unauthorized`, `invalid_request`, `unsupported_protocol`, `endpoint_unverified` (to a sync only) |
| Answers of the endpoint | `invalid_signature`, `invalid_request`, `unsupported_protocol`, `unknown_definition`, `unknown_version`, `dispatch_failed`, `unavailable`, `abandon_refused`, `sync_refused` (to a requested sync only), `internal_error` |
| A `409` answer to an abandon | `stale_run`, `stale_attempt`, `executing`, `conflict`, `completed`, `abandon_refused`. The schema also allows `run_in_flight` and `not_awaited`, which an abandon cannot produce |
| A report whose outcome is `refused` | `run_in_flight`, `stale_run`, `stale_attempt`, `executing`, `not_awaited`, `conflict` |
| A report whose outcome is `rejected` | `expired`, `invalid_start`, `already_claimed` for a start; `wrong_definition` for a wake, and for a wake only |
| A report whose outcome is `aborted` | `not_executed`, `unknown_definition`, `unknown_version`, `announce_failed`, `unavailable` |

### 5.11 Requested sync: the platform asks for the manifest

**What it is for.** The platform asks the application for the sync it would publish, and takes the answer in as that sync. An owner can then publish the manifest from the dashboard without a deploy: after a deploy that did not run the sync, or to connect an environment for the first time at a URL that the owner types.

**Its place in protocol 1.** An extension, which changes neither the protocol version nor any other message. An endpoint that does not know the verb answers `400 invalid_request`, as it answers any verb it does not know. To this message, the platform reads that answer as "this SDK does not answer a requested sync", and as nothing more: it raises no alert.

**Who sends it, and when.** The platform, when an operator asks for it. **OPERATOR ACTION.** It goes to the endpoint URL of the manifest, or to a URL that an owner typed, and it is signed like every message of the platform (section 3.4). The platform sends it once and never again by itself, whatever the answer: a person asked for it, and a person asks again. Reference simulator: it never sends one.

**Request:** `POST {endpoint}`, schema `requested-sync.json`. The body has two fields and nothing else.

<!-- example: requested-sync | schema: requested-sync.json -->
```json
{"protocol": 1, "verb": "sync"}
```

**Answer `200`,** schema `sync.json`. The body is the one that a sync of the deployment would post (section 5.1), built by the same code: every registered version of every definition, in the same order, and the endpoint URL that the application is configured with.

<!-- example: requested-sync-answer | schema: sync.json -->
```json
{"protocols": [1], "sdk": "neuron-cloud-php/1.0.0", "endpoint": "https://shop.example.com/neuron-cloud",
 "definitions": [{"definition": "order-fulfilment", "version": 2, "leaseSeconds": 600},
                 {"definition": "order-fulfilment", "version": 1, "leaseSeconds": 600},
                 {"definition": "nightly-reconciliation", "version": 1, "leaseSeconds": 900}]}
```

The SDK cannot see the URL that a request arrived at, so the `endpoint` of the answer is the one the application was configured with, and the SDK does not compare the two. The platform compares them after it has decoded the body: the endpoint writes `/` as `\/`, as in every answer. When they are equal, the endpoint has just answered a signed request at that URL, which proves what a ping would (section 5.8). When they differ, the answer moves the endpoint to another URL, as a sync that names it would. Section 7.15 says how the platform takes the answer in.

The answer grows with the list, by about 70 bytes for each registered version. A platform that limits the size of an answer of the endpoint must allow the longest manifest that it accepts in a sync. It names the definitions, the versions, the leases and the endpoint URL: what a sync publishes, and nothing more.

**Every answer.** The rows of section 5.9 hold, with these differences. The endpoint answers a requested sync before it looks for any definition, and reads nothing in the application's store, so it never answers `404` or `503`.

| Status | Body | Meaning | What the platform does |
|---|---|---|---|
| `200` | A sync | The manifest of the deployment | Takes it in as a sync (section 7.15) |
| `400` | `{"code": "invalid_request"}` | The SDK does not know the verb, or the body is malformed | Ends the request as not supported. No alert |
| `409` | `{"code": "sync_refused"}` | The application would refuse to publish this sync itself: it is configured with no endpoint URL, or with one that does not begin with `https://`; it has no store, or one that several processes cannot share; it registered no definition, or a lease under 60 seconds. The answer does not say which | Ends the request. A person runs the sync from the deploy, which says why |
| Any other | As section 5.9 | As section 5.9 | Ends the request. A `401` and a `503` of the endpoint of the manifest also act on its deliveries, as section 5.9 says |

Unlike the `409` to an abandon, this `409` carries no snapshot:

<!-- example: error-409-sync-refused | schema: error.json -->
```json
{"code": "sync_refused"}
```

---

## 6. How to read what the application returns

The platform learns everything it knows about a run from three things: the `run` object of a snapshot, the outcome of a report, and the answer of the endpoint to an `inspect` or an `abandon`. This section says what each value means and what the platform does when it receives it.

One rule governs all of it: **the platform plans from a snapshot that was read, never from a word.** The outcome of a report says how one call ended. The snapshot says what the store holds. When the two disagree, the store is right (section 6.9).

### 6.1 Where a snapshot is

| Message | The snapshot is | Read at |
|---|---|---|
| A report | `run`, `snapshotRead`, `at` | After the segment ended, immediately before the report was built |
| A `200` answer to an `inspect` | `run`, `snapshotRead`, `at` | While the endpoint answered |
| A `200` or a `409` answer to an `abandon` | `run`, `snapshotRead`, `at` | After the endpoint dealt with the abandon |

An announcement carries no snapshot.

When `snapshotRead` is false, the application could not read its store, and `run` is null. The platform ignores `run` and `claimed`, whatever they hold, and asks again later (section 7.10). `claimed` is null then in the answer to an `inspect`, and in a report that a worker posts after a later read of the store. It is not always null: in the report of a start that the worker which handled the start posts itself, `claimed` holds what that worker's own claim gave, true or null (section 6.7, and the last example of section 6.6), and the platform ignores it all the same. An `abandon` answer is the one exception for `claimed`: it is true there even when `snapshotRead` is false.

### 6.2 The `run` object, field by field

`run` is null when no run holds the workflow ID. Otherwise:

| Field | Type | Meaning |
|---|---|---|
| `runId` | Run ID, of any form | The run that holds the workflow ID. It is not always the run the message was about: compare it with the `runId` of the report or of the question |
| `definition` | Definition name, or null | The definition that started the run (section 2.5). Null for a run with no tag, and for a run to which code outside the SDK gave a tag that does not have the form of a definition name |
| `status` | `running`, `suspended`, `failed` or `completed` | What the run is doing |
| `attempt` | Integer, 1 or more | The current attempt. It is the fence for the next `wake` or `abandon` |
| `leaseExpiresAt` | Instant, or null | The deadline of the lease. It matters only while the status is `running` |
| `wait` | Object | What the run waits for. Present when the status is `suspended`, absent otherwise |

**"You received this status: it means that; do this."**

| `status` | `leaseExpiresAt` | It means | The platform |
|---|---|---|---|
| `suspended` | Not looked at | The run waits. `wait` says for what | Plans one timer when `wait.wakeAt` is set (section 7.8). Sends nothing when it is null, and reads the run now and then (section 7.13) |
| `failed` | Not looked at | A segment threw under this attempt, or was cut off. Nothing will run until somebody wakes the run | Counts one failure for this run ID and attempt. Sends a wake with this attempt when the retry policy allows, otherwise leaves the run failed and alerts (section 7.9) |
| `running` | Later than the `at` of the same snapshot | A process took the run and is executing a segment, or was executing one when it died. Nobody can tell which before the deadline | Sends no wake. Asks again when the deadline has passed (section 7.10) |
| `running` | Not later than the `at` of the same snapshot | The run is dead: its lease has passed and it is still marked running | Counts one failure for this run ID and attempt. Sends a wake with this attempt when the retry policy allows (section 7.9) |
| `running` | null | The run was started outside the SDK and runs with no lease. A wake would take it over while its process may be alive | Never wakes it. Reads it now and then (section 7.13). Alerts |
| `completed` | Not looked at | A completion that code outside the SDK kept in the store. A run that the SDK started never shows this status: its records are deleted when it completes | Not settled (U11) |

**A lease has passed** when `leaseExpiresAt` is not later than the `at` of the same snapshot. Both instants are read on the application's clock. The platform's own clock does not decide: it can be seconds ahead or behind.

Two snapshots of one run show the difference. A worker took the run at attempt 3 and stands inside a step. In the first the lease has not passed. In the second it has:

<!-- example: inspect-answer-running | schema: inspect-answer.json -->
```json
{"run": {"runId": "2-01a125c2-07a2-78d7-abc3-27a8f7e26068", "definition": "order-fulfilment",
         "status": "running", "attempt": 3, "leaseExpiresAt": 1791634842},
 "claimed": true, "snapshotRead": true, "at": 1791634838}
```

<!-- example: inspect-answer-lease-passed | schema: inspect-answer.json -->
```json
{"run": {"runId": "2-01a125c2-07a2-78d7-abc3-27a8f7e26068", "definition": "order-fulfilment",
         "status": "running", "attempt": 3, "leaseExpiresAt": 1791634842},
 "claimed": true, "snapshotRead": true, "at": 1791634843}
```

A failed run:

<!-- example: inspect-answer-failed | schema: inspect-answer.json -->
```json
{"run": {"runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40", "definition": "order-fulfilment",
         "status": "failed", "attempt": 1, "leaseExpiresAt": null},
 "claimed": true, "snapshotRead": true, "at": 1791634834}
```

A run that the application's code started without the SDK: no definition, a run ID that is not minted, running with no lease:

<!-- example: inspect-answer-outside-sdk | schema: inspect-answer.json -->
```json
{"run": {"runId": "legacy-run-7", "definition": null, "status": "running", "attempt": 1, "leaseExpiresAt": null},
 "claimed": null, "snapshotRead": true, "at": 1791634843}
```

### 6.3 The `wait` object

| Field | Type | Meaning |
|---|---|---|
| `interruptId` | Integer, 1 or more | The number of the wait within the run. With the run ID and the attempt it identifies one timer |
| `type` | `sleep_until` or `wait_for_event` | A sleep until a time, or an event that the application delivers itself |
| `eventName` | Text, or null | The name of the event for a `wait_for_event`. Null for a `sleep_until`. An approval shows `approval` |
| `wakeAt` | Instant, or null | For a `sleep_until`: the time that the run sleeps until. For a `wait_for_event`: its deadline, or null when it has none |

All four fields are always present.

The platform looks at `wakeAt` only. **AUTOMATION.** When it is set, the platform sends one wake at that instant, whatever the type. When it is null, the platform has nothing to send: the application will deliver the event itself.

The answer to an `inspect` of a run that waits with no timer: a chat agent that stopped at the approval of a tool call. Its turn ran inside a web request, so its run ID was never claimed (section 6.7):

<!-- example: inspect-answer-approval | schema: inspect-answer.json -->
```json
{"run": {"runId": "1-01a125da-de86-7585-9f8b-7b185fd89455", "definition": "support-agent",
         "status": "suspended", "attempt": 1, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "approval", "wakeAt": null}},
 "claimed": false, "snapshotRead": true, "at": 1791636463}
```

The platform plans no timer for this run and reads it now and then (section 7.13).

Two details of how the SDK writes a wait:

- An event name that is not valid UTF-8 is shown with its invalid bytes replaced by U+FFFD, so two different names can look alike. The platform shows event names and never sends one back.
- A time or a deadline set before 1970 is shown as the instant 0. It is long due either way.

### 6.4 `at`, and what the platform uses it for

`at` is the clock of the application host that built the message, in whole seconds. In a snapshot it is the instant the store was read. In an announcement it is the instant the SDK built the announcement, before the segment executed.

The platform uses the `at` of a snapshot for three things.

1. **To order what it was told about one workflow ID**: a snapshot that names a run against one that does not (section 7.5), and a snapshot against the message that closed a run (section 7.6). The platform compares `at` with `at`, never with the time of arrival: the snapshots of one workflow ID can arrive in any order (section 9.7).
2. **To decide whether a lease has passed**: `leaseExpiresAt` against the `at` of the same snapshot.
3. **To notice a wrong clock**: the platform alerts when the `at` of a report is more than 300 seconds from its own clock (section 7.16). It still records the report.

It uses the `at` of an announcement for one thing: to decide whether the announcement opens a closed run again (section 7.3, step 5). Whether the silence of a segment counts from that `at` or from the arrival of the announcement is not settled (U19).

Two hosts of one application can differ by a few seconds, and so can the application and the platform. Q2 says what that costs.

### 6.5 The fields of a report

| Field | What it tells the platform |
|---|---|
| `invocationId` | Which segment the report speaks for. It pairs the report with its announcement and, when `origin` is `platform`, with the order the platform sent. A report whose `origin` is `platform` under an invocation ID that the platform never sent is a sign that somebody else holds the signing key: the platform alerts |
| `verb` | Which rules apply. The rules for a start (section 7.7) apply to `start` only. `wrong_definition` exists for `wake` only. `claimed` is null for every verb but `start` |
| `origin` | Whose segment it was. The platform may send again only what it sent itself, so it never sends anything for a segment whose origin is `application`. A start of the application that will never run has no operator to tell |
| `definition` | The definition that the order, or the application's code, named. It is not always the definition of the run: in the report of a wake that named the wrong definition, this field holds the wrong one and `run.definition` the right one, or null (section 2.5). When the report is the first message that names its `runId`, the platform holds the run under this definition (rule R3) |
| `workflowId` | The workflow ID the snapshot is about |
| `runId` | The run the call was for. When `run.runId` is the same, the snapshot names the run. When `run` is null or names another run, this run does not hold the workflow ID |
| `outcome`, `code` | How the call ended (section 6.6) |
| `errorClass` | The class of the exception that failed or aborted the call. The platform groups failures by it, shows it to operators, and can choose a retry policy by it. It is never a message. For an anonymous class it is the nearest named parent |
| `executedAttempt` | Whether this call executed a segment, and under which attempt. Null means that no segment executed in this call. With `failed` it names the attempt that failed. With `suspended` it separates a segment that ran from a wake that came too early |
| `run`, `snapshotRead`, `at` | The snapshot (sections 6.1 to 6.4) |
| `claimed` | Whether the run ID of a start is in the ledger (section 6.7) |

### 6.6 The outcomes of a report

There are seven outcomes. Three of them carry a code.

| Outcome | In one line | `code` | `errorClass` | `executedAttempt` |
|---|---|---|---|---|
| `completed` | The run finished | null | null | The attempt of the segment that completed the run. Null when this call executed no segment (see `completed` below) |
| `suspended` | The run waits | null | null | The attempt of the segment that ran, or null when none ran |
| `failed` | A segment ran and threw | null | The class | The attempt that failed |
| `refused` | The application's engine said no, or the SDK's check before a wake did | A refusal code | null | null, or the attempt of a segment that lost its run |
| `rejected` | The SDK said no, for good | A rejection code | null | null |
| `aborted` | Nothing ran, for a reason that may pass | An abort code | The class, or null | null |
| `observed` | Nothing is known about how the call ended: a worker only read the store | null | null | null |

The tables below say, for every outcome and code, what happened in the application and what the platform does. "Reads the snapshot" means the procedure of section 7.5, which the platform applies to every report whose `snapshotRead` is true, whatever the outcome.

#### `completed`

| What happened | What the platform does |
|---|---|
| The engine returned the state of the run as completed. The application has deleted the records of the run, so `run` is null, unless another run has taken the workflow ID since. The exception is a completion that code outside the SDK kept in the store (section 6.2): `run` then shows that same run with the status `completed` | Closes the run named by `runId` as completed. This is final, and the platform does it also when `snapshotRead` is false. Reads the snapshot for the other runs of the workflow ID |

`executedAttempt` is null on a `completed` report when the SDK saw no segment execute in this call. One case: a `wake` or an `answer` that carries the attempt of a run whose completion code outside the SDK kept in the store is reported `completed`, because the engine returns that completion as it is. The platform closes the run named by `runId` whatever `executedAttempt` holds.

<!-- example: report-wake-completed | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB7M6P2ZC4", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",
 "outcome": "completed", "code": null, "errorClass": null, "executedAttempt": 3, "run": null, "claimed": null,
 "snapshotRead": true, "at": 1791634834}
```

#### `suspended`

| `executedAttempt` | What happened | What the platform does |
|---|---|---|
| Set | A segment executed under that attempt, and the run stopped at a wait | Reads the snapshot and plans from it |
| null | No segment executed. The engine found the run suspended with nothing due on the application's clock, and returned the same wait and the same attempt | Reads the snapshot. When this is the report of a wake that the platform sent for a timer, and `run` shows the run ID, the attempt and the `interruptId` that the wake was sent for, the wake came **too early by the application's clock**: the platform sends the wake again after 1 to 60 seconds (section 7.8) |

A signal that the application issued, executed under attempt 2, left the run asleep on a timer:

<!-- example: report-signal-suspended-timer | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c1-f97e-7292-a087-ef14e6187925", "verb": "signal",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "order:123",
 "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "outcome": "suspended", "code": null, "errorClass": null,
 "executedAttempt": 2,
 "run": {"runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 2, "leaseExpiresAt": null,
         "wait": {"interruptId": 2, "type": "sleep_until", "eventName": null, "wakeAt": 1791634833}},
 "claimed": null, "snapshotRead": true, "at": 1791634831}
```

The platform's wake for that timer arrived before the timer was due on the application's clock. Nothing executed, and the report shows the same wait and the same attempt:

<!-- example: report-wake-too-early | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB7M6P2ZC4", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",
 "outcome": "suspended", "code": null, "errorClass": null, "executedAttempt": null,
 "run": {"runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 2, "leaseExpiresAt": null,
         "wait": {"interruptId": 2, "type": "sleep_until", "eventName": null, "wakeAt": 1791634833}},
 "claimed": null, "snapshotRead": true, "at": 1791634831}
```

An answer that the application issued. The platform treats the verbs `answer` and `signal` alike:

<!-- example: report-answer-suspended | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-06dc-722b-89c9-f41e78f6323c", "verb": "answer",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "thread:8f14e45f",
 "runId": "2-01a125c2-06b3-743a-bad3-20c03307aed8", "outcome": "suspended", "code": null, "errorClass": null,
 "executedAttempt": 2,
 "run": {"runId": "2-01a125c2-06b3-743a-bad3-20c03307aed8", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 2, "leaseExpiresAt": null,
         "wait": {"interruptId": 2, "type": "sleep_until", "eventName": null, "wakeAt": 1791634837}},
 "claimed": null, "snapshotRead": true, "at": 1791634835}
```

#### `failed`

| What happened | What the platform does |
|---|---|
| A segment executed under `executedAttempt` and threw an exception of the class `errorClass`. The application marked the run failed before the exception surfaced, so `run` normally shows the run `failed` at that attempt | Remembers `errorClass` for this run ID and attempt. Reads the snapshot. Counts a failure and plans a retry only when the snapshot shows the run `failed` (section 7.9). When the snapshot shows something else, it follows the snapshot (section 6.9) |

<!-- example: report-start-failed | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2N4C6Z1D", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:124", "runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40",
 "outcome": "failed", "code": null,
 "errorClass": "NeuronCore\\Cloud\\Tests\\Support\\ExampleApp\\PlannedFailure", "executedAttempt": 1,
 "run": {"runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40", "definition": "order-fulfilment",
         "status": "failed", "attempt": 1, "leaseExpiresAt": null},
 "claimed": true, "snapshotRead": true, "at": 1791634834}
```

#### `refused`

A refused call changed nothing in the run that holds the workflow ID. One row of the table is different: a segment that executed and then lost its run.

| `code` | Verbs | What happened | What the platform does |
|---|---|---|---|
| `run_in_flight` | `start` | A run already holds the workflow ID. `run` shows it. The start created nothing | Never sends the start again. Marks the start spent, unless a snapshot names its run ID (section 7.7). Reads the snapshot, which names the other run |
| `stale_run` | `wake`, `answer`, `signal` | No run with that run ID holds the workflow ID: the run completed, was abandoned, or another run has taken its place. `run` is null or names the other run | Reads the snapshot. It closes the run when a snapshot named it before (section 7.5) |
| `stale_attempt`, `executedAttempt` null | `wake`, `answer`, `signal` | The attempt of the message is no longer the current one: another copy of the message, or another segment, was admitted first. Nothing executed | Reads the snapshot and plans from the attempt it shows |
| `stale_attempt`, `executedAttempt` set | Any | This call did execute a segment under that attempt, and lost the run while it executed: its lease passed and another process took the run over. The step that was executing ran in both processes. The write of the slow process was refused and changed nothing | Reads the snapshot. Nothing else. It is a sign that the lease of the definition is shorter than its slowest step |
| `executing` | `wake`, `answer`, `signal` | A process is executing the run: it is `running` under a lease that has not passed, or it is `running` with no lease. Nothing executed | Reads the snapshot and plans from it (section 6.2): it waits for the lease deadline, or, for a run with no lease, sends no wake at all. A platform that follows section 7.9 sends no wake to such a run; it sees this code when two of its messages raced |
| `not_awaited` | `answer`, `signal` | The run is not waiting for the input that the application's code gave it | Reads the snapshot |
| `conflict` | Any | Another process changed the run while this call was being admitted. Nothing executed | Reads the snapshot. Never sends a start again. Whether it sends a wake of its own again when the snapshot still asks for that wake is not settled (U26) |

A start that met a run:

<!-- example: report-start-run-in-flight | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-06db-7c3d-8427-661091e34952", "verb": "start",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "thread:8f14e45f",
 "runId": "2-01a125c2-06db-730f-83a4-eea27083cd1d", "outcome": "refused", "code": "run_in_flight",
 "errorClass": null, "executedAttempt": null,
 "run": {"runId": "2-01a125c2-06b3-743a-bad3-20c03307aed8", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 1, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638435}},
 "claimed": false, "snapshotRead": true, "at": 1791634835}
```

A copy of a wake that arrived after the run completed:

<!-- example: report-wake-stale-run | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB7M6P2ZC4", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",
 "outcome": "refused", "code": "stale_run", "errorClass": null, "executedAttempt": null, "run": null,
 "claimed": null, "snapshotRead": true, "at": 1791634834}
```

A second copy of a wake, after the first one was admitted and raised the attempt to 2:

<!-- example: report-wake-stale-attempt | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2P7H5K9E", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:124", "runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40",
 "outcome": "refused", "code": "stale_attempt", "errorClass": null, "executedAttempt": null,
 "run": {"runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 2, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638434}},
 "claimed": null, "snapshotRead": true, "at": 1791634834}
```

A slow worker that lost its run. It executed under attempt 3, another process took the run over and completed it, and the slow worker's last write was refused:

<!-- example: report-wake-lost-its-run | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB30B6N2P5", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:133", "runId": "2-01a125c2-07a2-78d7-abc3-27a8f7e26068",
 "outcome": "refused", "code": "stale_attempt", "errorClass": null, "executedAttempt": 3, "run": null,
 "claimed": null, "snapshotRead": true, "at": 1791634843}
```

A wake that met a run under a lease that had not passed:

<!-- example: report-wake-executing | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB31F9Q7R8", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:133", "runId": "2-01a125c2-07a2-78d7-abc3-27a8f7e26068",
 "outcome": "refused", "code": "executing", "errorClass": null, "executedAttempt": null,
 "run": {"runId": "2-01a125c2-07a2-78d7-abc3-27a8f7e26068", "definition": "order-fulfilment",
         "status": "running", "attempt": 3, "leaseExpiresAt": 1791634842},
 "claimed": null, "snapshotRead": true, "at": 1791634838}
```

A wake that met a run which the application's code started without the SDK, and which runs with no lease. The SDK's own check refused it:

<!-- example: report-wake-executing-no-lease | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB34R2W9X7", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:134", "runId": "legacy-run-7", "outcome": "refused",
 "code": "executing", "errorClass": null, "executedAttempt": null,
 "run": {"runId": "legacy-run-7", "definition": null, "status": "running", "attempt": 1, "leaseExpiresAt": null},
 "claimed": null, "snapshotRead": true, "at": 1791634843}
```

#### `rejected`

A rejected call executed nothing and will never execute, however often the message is sent.

| `code` | Verb | What happened | What the platform does |
|---|---|---|---|
| `expired` | `start` | The use-by time had passed when a worker took this copy of the start, or when the copy reached the claim. This copy claimed nothing | Never sends the start again. Closes nothing: the report says only that this copy did not run. Asks 300 seconds after the use-by time (section 7.7) |
| `invalid_start` | `start` | The workflow of that definition cannot be started the way the platform starts it, with no input: for example, none of its steps takes such a start. The application's code is at fault, or the trigger points at a definition that needs an input. Nothing was claimed | Never sends the start again. Marks it spent, and shows the code to an operator. What becomes of a trigger that produces such starts is the product's decision (P1) |
| `already_claimed` | `start` | The run ID was already in the ledger: another copy of this start claimed it, or an abandon wrote it. This copy did nothing | Closes nothing and sends nothing. Reads the snapshot |
| `wrong_definition` | `wake` | The run with that run ID has a tag that is not the definition the wake names (section 2.5). Nothing executed, and the run is as it was | Never sends this wake again. Alerts: the platform named a definition that did not start the run, or somebody else holds the signing key, or code outside the SDK gave the run a tag of its own (section 7.16). Closes nothing. Reads the snapshot. What becomes of the run afterwards is not settled (U9) |

<!-- example: report-start-expired | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2R5M2T8G", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:126", "runId": "2-0192f3ac-8e41-7a05-b7f6-1c9d3e5a2b68",
 "outcome": "rejected", "code": "expired", "errorClass": null, "executedAttempt": null, "run": null,
 "claimed": null, "snapshotRead": true, "at": 1791634834}
```

<!-- example: report-start-invalid-start | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB33M8U5V4", "verb": "start", "origin": "platform",
 "definition": "catalog-import", "workflowId": "catalog:2026-10-10",
 "runId": "1-0192f3b0-2a6c-7f31-8d4e-9b0a1c3e5f72", "outcome": "rejected", "code": "invalid_start",
 "errorClass": null, "executedAttempt": null, "run": null, "claimed": null, "snapshotRead": true,
 "at": 1791634843}
```

The second copy of a start whose first copy ran:

<!-- example: report-start-already-claimed | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2M8Q3W7A", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",
 "outcome": "rejected", "code": "already_claimed", "errorClass": null, "executedAttempt": null,
 "run": {"runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 1, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638431}},
 "claimed": true, "snapshotRead": true, "at": 1791634831}
```

A wake that named `nightly-reconciliation` for a run that `order-fulfilment` started. The report's own `definition` is the one the wake named:

<!-- example: report-wake-wrong-definition | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2W8K4Y0L", "verb": "wake", "origin": "platform",
 "definition": "nightly-reconciliation", "workflowId": "order:128",
 "runId": "1-0192f3ae-6b2d-7c84-a1f9-3e5d7b9c0a46", "outcome": "rejected", "code": "wrong_definition",
 "errorClass": null, "executedAttempt": null,
 "run": {"runId": "1-0192f3ae-6b2d-7c84-a1f9-3e5d7b9c0a46", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 1, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638435}},
 "claimed": null, "snapshotRead": true, "at": 1791634835}
```

#### `aborted`

An aborted call executed no step of the workflow. The cause may pass, so the same message may succeed later.

| `code` | `errorClass` | What happened |
|---|---|---|
| `unknown_definition`, `unknown_version` | null | The worker that took the order does not have that definition, or that version of it. The usual cause is a worker that still runs old code during a deploy. A worker that lacks the definition has nothing to read the store through: its report has `snapshotRead` false, so the platform also asks (section 7.10). A worker that lacks only the version reads the store through the current version, and its report carries a snapshot like any other |
| `not_executed` | The class of what was thrown | The application could not build the workflow, or could not read the store before a wake, or something was thrown before the engine began the segment |
| `announce_failed` | The class of what the queue threw | A segment of the application's own: its announcement could be neither posted nor queued, so the SDK did not execute it. A segment that the platform ordered is never aborted for this reason: the platform already knows of it |
| `unavailable` | The class of what was thrown | A start only: the SDK could not read the ledger, or could not write the claim. `claimed` is null. Nothing was claimed, with one exception: when the store committed the write and lost its answer, the run ID is in the ledger. The copy that the platform then sends is reported `rejected` with `already_claimed` and no run, the start never runs, and the platform closes it as ended with an unknown outcome when it asks after the use-by time (rule R12, section 7.7) |

A report that another worker posts later for the same order, because the platform did not record the first one (section 5.3), keeps the code and carries what that worker could read.

What the platform does depends on who issued the order, not on the code:

| `origin` | `verb` | `claimed` | What the platform does |
|---|---|---|---|
| `platform` | `start` | null or false | Sends the same start again after a back-off, while its use-by time has not passed (section 7.12). The SDK rejects a copy that was in fact claimed as `already_claimed`, and that copy changes nothing |
| `platform` | `start` | true | Never sends the start again: it cannot run under that run ID. Marks it spent, unless a snapshot names its run ID |
| `platform` | `wake` | Always null | Reads the snapshot. Sends the same wake again after a back-off when the snapshot still asks for it. The protocol sets no limit to these re-sends (U17) |
| `application` | Any | Any | Sends nothing: whether the application tries the segment again is its own affair. For a start, section 7.7 says what the report closes |

The platform reads the snapshot of an aborted report like any other. It can show that something did change: a wake whose admission was written to the store and whose answer was lost is reported `aborted`, and its snapshot shows the run `running` at the next attempt. The platform then follows the snapshot.

A worker on old code, which lacks the version:

<!-- example: report-start-aborted-unknown-version | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2T9D7V3H", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:127", "runId": "2-0192f3ad-c3f8-7d52-9e0b-4a7c6d8f1e23",
 "outcome": "aborted", "code": "unknown_version", "errorClass": null, "executedAttempt": null, "run": null,
 "claimed": null, "snapshotRead": true, "at": 1791634834}
```

A worker that lacks the definition:

<!-- example: report-start-aborted-unknown-definition | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2T9D7V3H", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:127", "runId": "2-0192f3ad-c3f8-7d52-9e0b-4a7c6d8f1e23",
 "outcome": "aborted", "code": "unknown_definition", "errorClass": null, "executedAttempt": null, "run": null,
 "claimed": null, "snapshotRead": false, "at": 1791634834}
```

An application whose own code threw while it built the workflow. The same fault prevented the worker from reading the store, so `snapshotRead` is false. The platform both sends the start again and asks:

<!-- example: report-start-aborted-not-executed | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2T9D7V3H", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:127", "runId": "2-0192f3ad-c3f8-7d52-9e0b-4a7c6d8f1e23",
 "outcome": "aborted", "code": "not_executed", "errorClass": "DomainException", "executedAttempt": null,
 "run": null, "claimed": null, "snapshotRead": false, "at": 1791634834}
```

A store that failed at the claim:

<!-- example: report-start-aborted-unavailable | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2T9D7V3H", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:127", "runId": "2-0192f3ad-c3f8-7d52-9e0b-4a7c6d8f1e23",
 "outcome": "aborted", "code": "unavailable",
 "errorClass": "NeuronCore\\Cloud\\Tests\\Support\\ExampleApp\\PlannedFailure", "executedAttempt": null,
 "run": null, "claimed": null, "snapshotRead": true, "at": 1791634834}
```

A start of the application's own that it could not announce:

<!-- example: report-start-aborted-announce-failed | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-0764-7e24-9f9a-1d9b05145cfb", "verb": "start",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "order:132",
 "runId": "2-01a125c2-0764-70a7-9702-9280787fba84", "outcome": "aborted", "code": "announce_failed",
 "errorClass": "RuntimeException", "executedAttempt": null, "run": null, "claimed": null, "snapshotRead": true,
 "at": 1791634835}
```

#### `observed`

| What happened | What the platform does |
|---|---|
| The SDK does not know how the call ended. A worker read the store and reports what it shows. This is the report of a segment whose process died, when the application's queue notices it, and of a streamed segment whose reader stopped before the end | Reads the snapshot and plans from it: it usually shows the run `failed`. For a start that the platform sent, when no run with its run ID is shown: with `claimed` false the platform sends the start again (section 7.12); with `claimed` true it sends nothing and closes nothing, and asks after the use-by time (section 7.7). For a wake that the platform sent, when the snapshot still shows the wait, or the failed or dead attempt, that the wake was sent for: whether the platform sends the wake again is not settled (U26) |

A streamed segment whose reader left. The application failed the run it was executing:

<!-- example: report-start-observed | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-0722-7558-9912-411b59971de3", "verb": "start",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "thread:c9f0f895",
 "runId": "2-01a125c2-0722-7059-a22b-f1cd9710d3ce", "outcome": "observed", "code": null, "errorClass": null,
 "executedAttempt": null,
 "run": {"runId": "2-01a125c2-0722-7059-a22b-f1cd9710d3ce", "definition": "order-fulfilment",
         "status": "failed", "attempt": 1, "leaseExpiresAt": null},
 "claimed": false, "snapshotRead": true, "at": 1791634835}
```

#### A report whose snapshot was not read

Any outcome can come with `snapshotRead` false. The outcome still counts for what it decides by itself: `completed` closes the run, and the rules for a start apply with `claimed` taken as unknown. The platform asks 30 seconds later (section 7.10). When that answer names a run which the report had closed as spent, the run is open again (section 7.6).

<!-- example: report-start-suspended-unread | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2T9D7V3H", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:127", "runId": "2-0192f3ad-c3f8-7d52-9e0b-4a7c6d8f1e23",
 "outcome": "suspended", "code": null, "errorClass": null, "executedAttempt": 1, "run": null, "claimed": true,
 "snapshotRead": false, "at": 1791634835}
```

### 6.7 `claimed`

`claimed` says whether the run ID of a start is in the ledger (section 2.7).

| Where | Value | It means |
|---|---|---|
| The report of a start, posted by the worker that handled the start | true | This call wrote the ledger entry, or found it there |
| | null | The call ended before the claim, or the claim failed: the ledger was not read |
| The report of a start, posted by a worker that read the store later: a segment that ran in a web request, a report after one that the platform did not record, an `observed` report | true or false | What the ledger held when that worker read it |
| | null | The store could not be read |
| The report of a `wake`, an `answer` or a `signal` | null | Always |
| The answer to an `inspect` that names a run ID | true or false | What the ledger holds for that run ID |
| | null | The store could not be read |
| The answer to an `inspect` that names no run ID | null | Always |
| The answer to an `abandon`, `200` or `409` | true | Always: the abandon writes the entry when it is not there |

How the platform uses it:

- **true**: a copy of the start claimed the run ID, or an abandon wrote it into the ledger. The start will not execute again. It ran, or it was stopped between the claim and the first write of the run, or it was abandoned: the ledger cannot say which.
- **false**: no copy had claimed the run ID when the ledger was read. A copy may still be in a queue. For a start that the platform sent, false with no run under its run ID means that the start has not run yet.
- **null**: unknown. In an `aborted` report the platform treats null like false and sends the start again: a copy of a start that was in fact claimed is harmless.

Two limits:

- `claimed` false proves "never ran" only for a start that travelled through a queue, which every start of the platform does. A start that the application's code executed in the calling process is never claimed: its report says false although it ran.
- The SDK may drop a ledger entry from one hour after the use-by time of its start. An `inspect` made later than that can answer false for a start that ran.

A start that the application's code executed inside a web request:

<!-- example: report-start-in-request | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-06b3-7941-84ee-931b600e33fe", "verb": "start",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "thread:8f14e45f",
 "runId": "2-01a125c2-06b3-743a-bad3-20c03307aed8", "outcome": "suspended", "code": null, "errorClass": null,
 "executedAttempt": 1,
 "run": {"runId": "2-01a125c2-06b3-743a-bad3-20c03307aed8", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 1, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638435}},
 "claimed": false, "snapshotRead": true, "at": 1791634835}
```

### 6.8 Reading the answer to an `abandon`

The platform reads the status first, then `run`. It never plans from the `code` of a `409`: the endpoint reads `run` after the engine refused, so the two can describe different moments. Each row below pairs a `run` with the code that normally comes with it. When an answer pairs them otherwise, the platform takes the row that describes its `run`. The last row says so for an answer that does not show the run of the abandon.

| Status | `code` | `run` | It means | What the platform does |
|---|---|---|---|---|
| `200` | None | null, or another run | Nothing will run under that run ID any more: the endpoint discarded the run, or found no run and wrote the ledger entry, so that a start still in a queue is rejected | Closes the run as abandoned. Reads the snapshot for the other runs of the workflow ID |
| `409` | `stale_attempt` | The run, at another attempt | The run moved on since the snapshot the platform used | Sends the abandon again with the attempt shown. **AUTOMATION**: the operator asked once. How many times is not settled (U24) |
| `409` | `stale_run` | The run named in the abandon | A worker claimed and started the run after the endpoint had looked for it | Sends the abandon again with the attempt shown |
| `409` | `stale_run` | Another run | Another run holds the workflow ID, and the abandon discarded nothing. The run ID of the abandon is in the ledger: this abandon wrote it, or found it there, and the answer does not say which | Reads the snapshot. It adopts the run shown when the platform has never heard of it (section 7.5), and it closes the run of the abandon when an earlier snapshot named that run. Otherwise the platform inspects, as in the next row |
| `409` | `stale_run` | null | The run ID was already in the ledger and no run holds the workflow ID. The endpoint cannot tell why: a worker has claimed the start and its run is about to appear, the run has already ended, the start stopped between its claim and its first write, or an earlier copy of this abandon did the work | Reads the snapshot, which closes the run as ended with an unknown outcome when an earlier snapshot named it (section 7.5, step 4): the operator's request ends there. Otherwise inspects with the run ID. When the answer shows the run, sends the abandon again with the attempt shown. What the platform does when the answer shows no run is Q1. Reference simulator: it closes the run as ended with an unknown outcome, and the operator's request ends there |
| `409` | `executing` | The run, `running` under a lease that has not passed | A process is executing the run, and the engine does not discard a run under a live lease | Leaves the run open. When to try again is not settled (U24) |
| `409` | `conflict` | The run named in the abandon | Another process changed the run while the endpoint discarded it | Sends the abandon again with the attempt shown (section 7.14, step 5) |
| `409` | `completed` | The run, `completed` | A completion that code outside the SDK kept. It cannot occur for a run the SDK started | Reads the snapshot. Whether it closes the run is not settled (U11) |
| `409` | `abandon_refused` | The run named in the abandon, at any attempt | The run is a chat agent whose conversation ends on a tool call that nobody answered. The agent keeps its run. This check comes before the fence, so the code is the same when the attempt of the abandon is stale, and when the abandon names another run ID (the last row) | Leaves the run open. What becomes of the operator's request is not settled (U25) |
| `409` | Any code but `stale_run` | null, or another run | The code and `run` describe different moments: the run of the abandon ended, or left its workflow ID, after the engine refused and before the endpoint read `run`. With `abandon_refused`, `run` also shows another run when the abandon named a run ID that does not hold the workflow ID | Plans from `run`: as in the fourth row when it shows another run, and as in the fifth row when it is null (section 7.14, step 6) |

In every `200` and `409` answer `claimed` is true. `snapshotRead` is false when the endpoint could not read the store for the answer after it had dealt with the abandon: the status still counts, and the platform asks for the snapshot later.

**An abandon of a start that never ran, while another run holds the workflow ID.** This is the fourth row of the table, the one whose `run` shows another run. It is what the platform meets when the application started a run of its own on the workflow ID of a start that was lost. The answer is `409 stale_run` with that other run in `run` and `claimed` true, also when it is this very abandon that wrote the run ID into the ledger. The platform learns two things from it: the start can never run, and which run holds the workflow ID. It does not learn whether the start had run before. The platform reads the snapshot of this answer like any other (section 7.5). When it has never heard of the run shown, it adopts that run under the definition shown, if the manifest holds that definition (R3), and from then on wakes it like any other run. For the run of the abandon it inspects with the run ID, as in the fifth row: what it does when the answer shows no run under that run ID is Q1. Reference simulator: it inspects at once, and closes the run of the abandon as ended with an unknown outcome.

The endpoint discards a run that is `running` with no lease like any other, and the process that executes it loses its next write.

The `200` for a start that was still in the application's queue. No run existed, and the endpoint wrote the ledger entry:

<!-- example: abandon-answer-200-queued-start | schema: inspect-answer.json -->
```json
{"run": null, "claimed": true, "snapshotRead": true, "at": 1791634834}
```

When a worker takes that start afterwards, it reports `rejected` with `already_claimed`, and no run appears:

<!-- example: report-start-already-claimed-abandoned | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2Q1X8R4F", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:125", "runId": "2-0192f3ab-52c7-7e19-a4d3-8b6c0f2e7d15",
 "outcome": "rejected", "code": "already_claimed", "errorClass": null, "executedAttempt": null, "run": null,
 "claimed": true, "snapshotRead": true, "at": 1791634834}
```

A `409` that shows another run. The abandon named a run ID that never held the workflow ID:

<!-- example: abandon-answer-409-another-run | schema: error.json -->
```json
{"code": "stale_run",
 "run": {"runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 2, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638434}},
 "claimed": true, "snapshotRead": true, "at": 1791634834}
```

A `409` with no run: a second copy of an abandon that had already discarded the run:

<!-- example: abandon-answer-409-no-run | schema: error.json -->
```json
{"code": "stale_run", "run": null, "claimed": true, "snapshotRead": true, "at": 1791634834}
```

A `409` for a run that a worker is executing:

<!-- example: abandon-answer-409-executing | schema: error.json -->
```json
{"code": "executing",
 "run": {"runId": "2-01a125c2-07a2-78d7-abc3-27a8f7e26068", "definition": "order-fulfilment",
         "status": "running", "attempt": 3, "leaseExpiresAt": 1791634842},
 "claimed": true, "snapshotRead": true, "at": 1791634838}
```

### 6.9 When the outcome and the snapshot disagree

They can disagree, and each case below is correct behaviour of the application.

| Outcome | Snapshot | Why | What the platform does |
|---|---|---|---|
| `failed` | `suspended`, `running`, or no run | The store committed a write of the segment and the answer to that write was lost. The worker saw a failure; the store holds the result | Follows the snapshot. Counts no failure. When the snapshot shows no run, the platform closes the run as ended with an unknown outcome if an earlier snapshot named it (section 7.5, step 4). A run that no snapshot ever named stays open, and the platform asks about it later (sections 7.7 and 7.10) |
| `aborted` | `running` at the next attempt | The store committed the admission of a wake and lost the answer | Follows the snapshot: waits for the lease deadline, then wakes with the new attempt |
| `suspended` or `failed` | A later state of the same run, or no run | The report was posted late, by a worker that read the store after the run had moved on | Follows the snapshot |
| `completed` | Another run | The next run on the workflow ID started before the report was built | Closes the completed run. Holds the other one |
| Any | `snapshotRead` false | The store could not be read | Asks |

### 6.10 Combinations that cannot occur

The schema of a report refuses the following, and the SDK never sends them. A platform that receives one is not talking to the SDK.

- `refused`, `rejected` or `aborted` with a null `code`, or with a code of another outcome.
- `completed`, `suspended`, `failed` or `observed` with a `code`.
- `failed` with a null `errorClass`, or with a null `executedAttempt`.
- `completed`, `suspended`, `refused`, `rejected` or `observed` with an `errorClass`.
- `rejected`, `aborted` or `observed` with an `executedAttempt`.
- A `wake`, an `answer` or a `signal` with a `claimed` that is not null.
- An `answer` or a `signal` with the outcome `rejected`.
- A rejected `wake` with a code other than `wrong_definition`, and `wrong_definition` anywhere else.
- A `run` whose status is `suspended` with no `wait`, or a `run` of another status with a `wait`.

In an announcement, a `start` with an `attempt` and any other verb without one cannot occur.

### 6.11 Reading the answer to an `inspect`

| `snapshotRead` | `run` | `claimed` | It means | What the platform does |
|---|---|---|---|---|
| false | null | null | The application could not read its store, or could not build the workflow to read it | Changes nothing of what it holds. Asks again after 30 seconds, then 60, doubling to 600 |
| true | The run asked about | true, false, or null | The run holds the workflow ID | Reads the snapshot and plans from it |
| true | null | true | No run holds the workflow ID, and the run ID was claimed: the run ended, or it was abandoned, or its start stopped between the claim and the first write | Reads the snapshot, which closes a run that an earlier snapshot named. A start of the platform that no snapshot named stays open until 300 seconds after its use-by time; the platform then closes it as ended with an unknown outcome (section 7.7). An `inspect` that follows a `409` to an abandon is read by section 6.8 instead (Q1). A run of the application that no snapshot named is closed by this answer (section 7.10) |
| true | null | false | No run holds the workflow ID, and nothing claimed the run ID | Reads the snapshot, which closes a run that an earlier snapshot named. For a start of the platform that no snapshot named: sends it again while its use-by time allows (section 7.12), and closes it as lost when asked 300 seconds after its use-by time (section 7.7). For a run of the application that no snapshot named: section 7.10, and Q6 |
| true | Another run | true or false | Another run holds the workflow ID | Reads the snapshot, which may show a run the platform has never heard of. For the run asked about: as in the two rows above |
| true | Anything | null | The question named no run ID | Reads the snapshot |

No run and a claimed run ID:

<!-- example: inspect-answer-gone-claimed | schema: inspect-answer.json -->
```json
{"run": null, "claimed": true, "snapshotRead": true, "at": 1791634834}
```

No run and a run ID that nothing claimed: a start that still waits in the application's queue:

<!-- example: inspect-answer-unclaimed | schema: inspect-answer.json -->
```json
{"run": null, "claimed": false, "snapshotRead": true, "at": 1791634834}
```

A store that could not be read:

<!-- example: inspect-answer-unread | schema: inspect-answer.json -->
```json
{"run": null, "claimed": null, "snapshotRead": false, "at": 1791634835}
```

---

## 7. What the platform does by itself

This section gives the platform's behaviour as rules and procedures. The rules are numbered R1 to R18. The comments in the code of the reference simulator use the same numbers.

| Rule | In one line | Sections |
|---|---|---|
| R1 | Never use a run ID twice; one `expiresAt` for the life of a start | 7.1, 7.7 |
| R2 | A wake and an abandon carry a run ID and an attempt that the application showed | 7.1, 7.8, 7.9, 7.14 |
| R3 | Send a run its own definition | 7.1, 7.11 |
| R4 | One start for each firing of a trigger | 7.1, 7.7 |
| R5 | An abandon only when an operator asks | 7.1, 7.14 |
| R6 | Plan from a snapshot that was read | 7.5, 7.10 |
| R7 | Know when a run has ended | 2.9, 7.6 |
| R8 | One timer per wait | 7.8 |
| R9 | Retries are wakes | 7.9 |
| R10 | Silence means ask | 7.10 |
| R11 | A dead run is retried like a failed one. A run under a lease that has not passed, or with no lease, is never woken | 7.9, 7.10, 7.13 |
| R12 | A start that stays open is asked about, not replaced | 7.7 |
| R13 | Re-send only what can be re-sent | 7.12 |
| R14 | Read a wait that has no timer now and then | 7.13 |
| R15 | Keep only coordination data, and answer `recorded` only after a durable write | 7.2 |
| R16 | Sync: ping the endpoint first, accept only a public `https` endpoint, follow no redirect, ask an owner before the endpoint moves to another host, never send a `start` or a `wake` for a stranded version | 3.10, 7.11, 7.15 |
| R17 | Do not flood | 7.11 |
| R18 | Alert | 7.16 |

Where a rule stops short, the text says "not settled" and gives the number of the entry of section 11 that says what is missing.

### 7.1 Five rules that correctness depends on

A run stays correct whatever the platform does, with five exceptions. The application cannot check these five rules, except R3 for a run that has a tag (section 2.5). A platform that breaks one of them can make a workflow run twice, or destroy a run. All five are **AUTOMATION**: the platform follows them in every message it writes, and no setting turns one off.

| Rule | The platform must | Why |
|---|---|---|
| **R1** | Never use a run ID twice. Give a start one `expiresAt` for the life of its run ID, never more than 3600 seconds after it first sends the start | The application tells a new start from a copy by the run ID alone. The ledger entry that an abandon writes outlives the start only within that bound |
| **R2** | Put in a wake a run ID and an attempt that the application itself showed: in a report, or in the answer to an `inspect` or to an `abandon`. Put the same in an abandon, or, for a start that the platform sent and has heard nothing of, that start's run ID with no attempt. Never guess an attempt, and never add one to it | The fence makes a copy harmless only when it describes a state the application really showed |
| **R3** | Send a run its own definition (below) | A run executed through another definition would continue on the steps of the wrong workflow |
| **R4** | Issue one start for each firing of a trigger | Two starts with two run IDs are two runs |
| **R5** | Send an abandon only when an operator asks | An abandon destroys a run and its state |

**R3 in full.** The definition of a run is, in this order of preference:

1. the definition of the start, for a run the platform started;
2. the definition of the first announcement or report whose `runId` is that run, for a run the application started;
3. the `definition` that a snapshot shows, for a run that the platform has seen only in a snapshot, when the manifest holds that definition. The platform then holds the run and wakes it like any other run. This is called **adopting** the run.

The platform never takes the definition of a run from a message about another run. When a snapshot shows a run with a null `definition`, or with a definition that the manifest does not hold, the run has no definition yet: the platform sends it nothing until an announcement or a report of its own arrives.

Before the platform sends a `wake`, an `inspect` or an `abandon`, it compares the definition it is about to name with the `definition` that the last snapshot showed for that run. When they differ, it sends nothing and alerts. A snapshot that shows null leaves nothing to compare, and the platform sends the message.

For a run that has a tag, the SDK enforces R3 itself, and for a run with no tag nothing checks it: the table of section 2.5 says what the SDK refuses, and how. What the platform does with a run that it holds under a definition which did not start it is not settled (U9).

### 7.2 What the platform holds, and what it never holds

**R15.** The platform keeps only coordination data from what the application sends. **AUTOMATION.**

For each environment it holds the active API keys and signing keys, the manifest, the endpoint URL, whether deliveries to the endpoint are stopped (after a `401`), and until when they are held back (after a `Retry-After`).

For each run:

| It holds | From |
|---|---|
| The run ID, the workflow ID, the definition (R3), and whether the platform or the application started it | The start it issued, or the first notice of the run |
| The firing that started it | The trigger |
| The newest snapshot that named the run: `definition`, `status`, `attempt`, `leaseExpiresAt`, and the four fields of `wait` | Section 7.5 |
| The greatest `at` of a snapshot that named the run, and the greatest `at` of a snapshot of its workflow ID that did not name it | Section 7.5 |
| For a run held `running` under a lease: the instant, on the platform's clock, at which it asks about that lease (the arrival of the last snapshot that showed it, plus `leaseExpiresAt` minus the `at` of that snapshot), or the fact that a snapshot showed the lease passed | Sections 7.5 and 7.10 |
| Whether the run is closed, as what, and the `at` of the message that closed it | Section 7.6 |
| Each failure it counted: the attempt, whether the run had failed or was dead, and the `errorClass` | Section 7.9 |

For each order it sent, a `start` or a `wake`: the invocation ID, the run ID, the attempt, what the order is for (the start, the timer of one wait, the retry of one attempt), the `expiresAt` of a start, how many times it was sent, the last answer, and when the endpoint accepted it.

For each segment it heard of, by invocation ID: the run ID, the verb, the origin, the attempt, the `leaseSeconds`, the `at` of the announcement and of the report, when each arrived, the outcome, the code, and `claimed`.

These lists are about what the platform keeps of what the application sends. The platform also keeps facts of its own, which no message carries and which R15 does not limit. For each run: whether an operator asked for an abandon and what became of the request (pending, waiting for an `inspect`, refused); how many abandons of that request were answered `409`; the last answer to an `inspect` and to an `abandon` after which the message must be sent again (a `404`, or no usable answer), how many such answers came in a row, and when each may be sent again; when it last read a snapshot of the workflow ID and when it last asked; and the instants at which it must ask again after a snapshot that was not read and after two snapshots that it cannot order.

How long the platform keeps a closed run, an order, a segment and a firing is the product's decision: P4 says what the rules need of it.

Of what the application sends, the platform never holds anything else. It drops a field it does not know. It does not keep a body as it arrived, and that covers its logs, its traces and its error reports. An application that follows the protocol sends no customer data beyond section 1.3, but a future SDK may add fields, and the platform must not start to store them by accident.

The platform answers `{"recorded": true}` only after a durable write: the application stops trying as soon as it reads that answer. When the write fails, the platform gives an answer that counts as undelivered, such as a `5xx` (section 5.2).

The platform accepts announcements and reports for runs it did not start. The application wins: a notice is never refused because the platform does not know the run, or holds it closed.

### 7.3 Taking in an announcement

**AUTOMATION.**

1. Check the API key, then the body against `announcement.json`. Refuse with the answers of section 5.2. Whether a body that fails only for a value which the schema does not list is refused is not settled (U3).
2. When the platform holds no run with the `runId` of the announcement, create it: with the workflow ID and the definition of the announcement, as a run the application started. What the platform does when it holds that run ID under another workflow ID is not settled (U8).
3. Record the segment under its `invocationId`: the verb, the origin, the attempt, `leaseSeconds`, the `at` of the announcement, and the platform's own time of arrival. The invocation ID can already be recorded: the platform sent that order again, or the application posted the announcement again. How the platform keeps the notices of one invocation ID apart depends on Q5. Reference simulator: one record for each invocation ID, which keeps the greatest `at`.
4. When `origin` is `platform` and the platform never sent an order with that invocation ID, alert (section 7.16). Hold the run all the same.
5. When the run is closed as ended with an unknown outcome, the application started it, no snapshot ever named it, and the `at` of the announcement is later than the `at` that closed it: open it again. An announcement opens no other closed run. In particular it never opens a run closed as completed: a second copy of a start or of a wake posts its announcement before it is refused.
6. Write durably, then answer `200 {"recorded": true}`. When the write fails, give an answer that counts as undelivered, such as a `5xx` (section 5.2).

The platform then waits for the report of the segment. When none follows within `leaseSeconds`, it asks (section 7.10). From which instant the `leaseSeconds` count is not settled (U19). The announcement can be the later of the two to arrive (section 5.2), so the platform first looks at the reports that it already holds under that invocation ID.

### 7.4 Taking in a report

**AUTOMATION.** The order of the steps matters: the snapshot is read before the rules for a start look at the run.

1. Check the API key, then the body against `report.json`. Refuse with the answers of section 5.2. Whether a body that fails only for a value which the schema does not list is refused is not settled (U3).
2. When the platform holds no run with the `runId` of the report, create it, as in step 2 of section 7.3.
3. Record the outcome of the segment under its `invocationId`: the outcome, the code, the `at`, the time of arrival, and `claimed` when `snapshotRead` is true. When a report is already recorded under that invocation ID, because a second copy of the order ran or because the application posted the report again, the platform still takes the new report through every step that follows: nothing in this procedure is done once per invocation ID. What the record of the segment keeps of two reports depends on Q5, and which outcome it keeps when they differ is not settled (U10). Reference simulator: the greatest `at`, as for an announcement (section 7.3, step 3).
4. Alert when `origin` is `platform` and the platform never sent an order with that invocation ID, and when `at` is more than 300 seconds from the platform's clock. Go on all the same.
5. When `snapshotRead` is true, read the snapshot (section 7.5). When it is false, ignore `run` and `claimed`, and plan an `inspect` for 30 seconds later (section 7.10).
6. When the outcome is `completed`, close the run named by `runId` as completed.
7. When the outcome is `failed`, remember `errorClass` for the run ID and `executedAttempt`.
8. When the verb is `start`, apply the rules for a start (section 7.7), whatever the origin.
9. When the verb is `wake` and the outcome is `rejected`, end that wake for good and alert.
10. Write durably, then answer `200 {"recorded": true}`. When the write fails, give an answer that counts as undelivered, such as a `5xx` (section 5.2).

What the platform sends next follows from what it now holds: a timer (7.8), a retry (7.9), the same order again (7.12), or nothing.

### 7.5 Reading a snapshot

**R6: plan from a snapshot that was read.** **AUTOMATION.** This procedure applies to every snapshot whose `snapshotRead` is true: the one of a report, of a `200` answer to an `inspect`, and of a `200` or `409` answer to an `abandon`. Its input is a workflow ID, a `run` that may be null, and an `at`.

**Step 1: find the run it names.** When `run` is not null, look for a run with `run.runId` among the runs the platform holds. When there is none, adopt the run if R3 allows: `run.definition` is not null and the manifest holds a definition of that name. Otherwise hold nothing for it.

**Step 2: note the runs it does not name.** For every other run that the platform holds on that workflow ID, remember this `at` as an instant at which the run did not hold the workflow ID, when it is greater than the one remembered.

**Step 3: update the run it names.**

1. A run closed as completed or abandoned stays as it is. Stop here for that run.
2. A run closed as ended with an unknown outcome, lost or spent is open again when this `at` is later than the `at` of the message that closed it. When the two are equal, the platform cannot tell which came first: the run stays closed, and the platform inspects one second later. When this `at` is earlier, the snapshot is old: ignore it.
3. Remember this `at` as an instant at which the run held the workflow ID, when it is greater than the one remembered. Remember `run.definition` as the definition last shown.
4. Replace the status, the attempt, the lease deadline and the wait that the platform holds with those of the snapshot when one of these is true:
   - the platform holds none yet;
   - the attempt of the snapshot is higher;
   - the attempt is the same, the platform holds the run as `running`, and the snapshot shows a status other than `running` or a later lease deadline.

   In every other case keep what the platform holds: the snapshot is an older one that arrived late, or it shows what the platform already holds at a later `at`.

Then judge the run on this snapshot, whatever item 4 did. Compare the snapshot with what the platform now holds for the run. When the attempt or the status differs, the snapshot counts no failure, and nothing more is done with it. When both are the same, one row of this table applies, with this snapshot's own `at`:

| The snapshot shows | The platform |
|---|---|
| `failed` | Counts the failure of that attempt (section 7.9, step 1) |
| `running` under the lease deadline that the platform holds, and that deadline is later than this `at` | The lease has not passed. Plans the `inspect` of the lease deadline for `leaseExpiresAt` minus this `at` after the arrival of this snapshot (section 7.10) |
| `running` under the lease deadline that the platform holds, and that deadline is not later than this `at` | The lease has passed and the run is dead (section 6.2). Counts the failure of that attempt (section 7.9, step 1), and plans no `inspect` for that lease |
| `running` with a null `leaseExpiresAt` | Alerts (section 7.16) |

The answer to the `inspect` that the platform sends at a lease deadline goes through the third row: it shows the attempt, the status and the lease deadline that the platform already holds, so item 4 replaces nothing, and the lease is found passed.

**Step 4: close the runs that left.** For every open run of that workflow ID that some snapshot has named:

- when the greatest "did not hold" instant is later than the greatest "held" instant, close the run as ended with an unknown outcome;
- when the two instants are equal, the platform cannot order them. It neither closes the run nor plans a wake from what it holds for it, and it inspects once that second has passed;
- otherwise the run is open.

This step compares `at` to the second and has no margin. Q2 says what that costs when two hosts of the application differ by a few seconds.

Two different orderings are at work, and they must not be mixed. Two snapshots that name the same run are ordered by the attempt, which only the application raises. A snapshot that names a run and one that does not are ordered by `at`. Neither ordering uses the time of arrival, so the platform reaches the same result whatever the order in which messages arrive.

A run that no snapshot ever named is not closed by step 4. Sections 7.7 and 7.10 say when the platform asks about such a run.

**Then plan** from what the platform holds for each open run of the workflow ID, by the table of section 6.2. The platform keeps no `at` with the status it holds. Whether a lease has passed was decided in step 3, on the snapshot that showed it, and the plan reads the result: a failure that was counted, or the instant of the next `inspect`.

### 7.6 When a run is closed, and when it is open again

**R7: know when a run has ended.** **AUTOMATION.** The two tables of section 2.9 give the five closed states and what moves a run into each. Completed and abandoned are final, and the three others are not.

These close nothing:

- an announcement;
- a report that says `rejected` with `already_claimed`, or with `wrong_definition`;
- a report that says `rejected` with `expired`: it says only that one copy did not run, and the platform asks before it closes the start;
- a report that says `aborted` with a `claimed` that is not true;
- a snapshot that does not name a run which no snapshot ever named;
- a snapshot with the same `at` as the newest snapshot that named the run.

A report that says `completed` closes its run as completed also when the platform had closed that run as ended with an unknown outcome, as lost or as spent. For a run closed as abandoned this is not settled (U12).

**What the platform concluded gives way to the store.** A run closed as ended with an unknown outcome, as lost or as spent is open again when a snapshot with a later `at` than the message that closed it names that run. The platform then plans from that snapshot. A run closed as completed or as abandoned stays closed whatever arrives.

When the platform issued the start of the run, it shows three of these states to an operator, who looks and decides. **OPERATOR ACTION.**

| State | What the platform tells the operator |
|---|---|
| Spent | The start will never run under this run ID, with the code of the report |
| Lost | The start never ran, and no copy of it can run any more |
| Ended with an unknown outcome | The workflow may have run. Nothing says whether it finished |

The platform never issues a replacement by itself. An operator who wants the workflow to run fires the trigger again, which is a new start with a new run ID. A start that the application's own code issued has no operator on the platform: the platform marks it and asks nobody to act.

### 7.7 The life of a start

**R1, R4, R12.** This section follows a start that the platform issued. Its last paragraph says what holds for a start that the application issued.

**Issuing.** **AUTOMATION**, caused by a firing. The trigger is a **SETTING**.

1. A trigger fires for a definition and a workflow ID. The platform remembers the firing. When the same firing reaches it again, it issues nothing (R4). What identifies a firing is the product's decision (P1).
2. The definition is in the manifest: the run ID needs its current version. What the platform does with a firing for a definition that is not there is the product's decision too (P1).
3. The platform mints the run ID: the current version of the definition, a dash, and a unique part (R1). It mints one invocation ID for the start.
4. When the platform first sends the start, it fixes `expiresAt`: the instant of that first send plus the lifetime of a start. The lifetime is a **SETTING** from 600 to 3600 seconds. The platform fixes `expiresAt` at the first send and not when the trigger fires, so that a start which had to wait for the endpoint still has its whole lifetime. How long a start may wait for that first send is not settled (U14).
5. Every later copy of the start is the same message: the same run ID, the same invocation ID, the same `expiresAt`.

**What comes back.** The table reads the answer to the start and then its reports.

| The platform receives | It means | The platform |
|---|---|---|
| `202`, then an announcement, then a report with a snapshot that names the run | The run exists | Holds the run open and plans from the snapshot |
| A report `completed` | The run started and finished in one segment | Closes the run as completed |
| A report `rejected` with `already_claimed` | Another copy of this start claimed the run ID before this one, or an abandon wrote it into the ledger | Closes nothing. Reads the snapshot |
| A report `refused`, a report `rejected` with `invalid_start`, or a report `aborted` with `claimed` true | The start will not run again under this run ID. It never ran, with one exception: a report `refused` with `stale_attempt` and an `executedAttempt` says that this call executed the first segment and lost its run to another process (section 6.6) | Never sends the start again. When a snapshot that was read names its run ID, in this report or in an earlier one, or the run is already closed, the report closes nothing. Otherwise the platform closes the run as spent. Whether that holds for the one exception is not settled (U13) |
| A report `rejected` with `expired` | This copy did not run. Another copy may have | Never sends the start again. Leaves the run open and asks at `expiresAt` plus 300 seconds |
| A report `aborted` with `claimed` null or false | Nothing ran. With `claimed` null the run ID can still be in the ledger (section 6.6, the code `unavailable`) | Sends the same start again after a back-off, while `expiresAt` has not passed (section 7.12) |
| An `observed` report, or an `inspect` answer, that shows `claimed` false and no run under the run ID | No copy has claimed the start | The same |
| An `observed` report that shows `claimed` true and no run under the run ID | A copy claimed the start. Nothing says whether it ran | Sends nothing and closes nothing. Asks at `expiresAt` plus 300 seconds |
| Nothing at all after the `202` | The start is in a queue, or the job was lost | Waits until `expiresAt` plus 300 seconds, then asks |

**R12: a start that stays open is asked about, not replaced.** When a start is still open at its `expiresAt` plus 300 seconds, because it stayed silent or because its reports closed nothing, the platform sends an `inspect` with the run ID. **AUTOMATION.** The 300 seconds are a **SETTING**; they must not be shorter than the signature window.

| The answer shows | The platform |
|---|---|
| The run holds the workflow ID | Plans from the snapshot |
| No run under that run ID, `claimed` false, asked no later than `expiresAt` plus 3600 seconds | Closes the run as lost: no copy claimed it, and none can any more, because the use-by time has passed |
| No run under that run ID, `claimed` true | Closes the run as ended with an unknown outcome: the run completed without a report, or it was abandoned, or the start stopped between its claim and its first write. The answer cannot tell which |
| No run under that run ID, `claimed` false, asked later than `expiresAt` plus 3600 seconds | Closes the run as ended with an unknown outcome: the ledger entry may have been dropped |
| `snapshotRead` false | Asks again (section 7.10) |
| `400` | The answer shows nothing of the start: the run that holds the workflow ID has a tag that is not the definition of the start (section 5.6). The platform alerts and leaves the start open. Whether it asks again is not settled (U18) |

The platform never sends a start after its `expiresAt`. It never issues a replacement on its own.

**A start that the application issued.** Its reports have the origin `application`. The platform sent nothing, so the rows that send a start again do not apply: the platform never sends a segment that the application issued (R13). The rows that close a run apply as they are, and nobody is told, because a start of the application has no operator. The platform therefore closes such a start as spent on a report `refused`, `rejected` with `invalid_start`, or `aborted` with `claimed` true, when no snapshot has named its run ID. The platform is never told the use-by time of such a start, so it cannot apply R12 to it as written: Q6.

### 7.8 Timers

**R8: one timer per wait.** **AUTOMATION.**

1. A snapshot that the platform holds shows a run `suspended` with a `wait.wakeAt`. The platform plans one wake for that run ID, attempt and `interruptId`.
2. When its own clock reaches `wakeAt`, the platform sends a `wake` with the run ID and the attempt of that snapshot (R2). It mints one invocation ID for the timer, and keeps it on every copy that it sends because a delivery failed (section 7.12). Whether a wake that the platform decides again, after "too early" or after the `inspect` that follows a silence, keeps that invocation ID is Q5.
3. The report of that wake decides what follows.

| The report of the wake says | It means | The platform |
|---|---|---|
| `suspended` or `completed` or `failed` with an `executedAttempt` | The timer fired and a segment ran | Plans from the snapshot. A new wait gets a timer of its own |
| `suspended` with no `executedAttempt`, and a snapshot that shows the same run, attempt and `interruptId` | Too early by the application's clock | Sends the wake again after 1 to 60 seconds: a **SETTING** |
| `refused` with `stale_attempt` or `stale_run` | The run moved on before the wake arrived: the application answered the wait, or the run ended | Plans from the snapshot. The platform drops the timer of a wait that is gone |
| `rejected` with `wrong_definition` | The run has a tag that is not the definition the wake named | Never sends that wake again. Alerts (section 6.6) |
| `aborted` | Nothing ran, for a reason that may pass | Plans from the snapshot, and sends the wake again after the back-off when the snapshot still shows that wait at that attempt (section 7.12) |
| Anything else, such as `refused` with `conflict`, or `observed` | No segment executed in this call | Plans from the snapshot. When the snapshot still shows that wait at that attempt, whether the platform sends the wake again is not settled (U26) |

"Too early" is what the report of the wake itself says. What the platform still holds does not count: a wake whose own report shows no run, or anything but that wait and that attempt, was not too early.

The protocol sets no limit to the re-sends of a wake that comes too early, and asks for no alert (U17). One thing bounds the cause: a platform whose clock is more than 300 seconds ahead of the endpoint's gets `401` to every request (section 3.5).

The deadline of an event wait is a timer like any other: the platform does not look at the type of the wait.

When the platform holds a newer snapshot for the run before `wakeAt`, the timer of the old wait no longer exists: the platform plans only from what it holds now.

Timers that come due together, for example after an outage of the platform, are spread out (section 7.11).

### 7.9 Retries

**R9: retries are wakes. R11: a dead run is retried like a failed one.** **AUTOMATION**, under the retry policy, which is a **SETTING**.

1. A snapshot shows a run `failed`, or `running` under a lease that has passed (section 6.2). The platform counts one failure for that run ID and attempt. It counts a failure once, however many snapshots show it.
2. When the retry policy allows another try, the platform waits the delay of the policy and sends a `wake` with the run ID and the attempt of that snapshot. It mints one invocation ID for the retry of that attempt; Q5 applies to it as to the wake of a timer.
3. The wake executes under the next attempt. When that attempt fails too, the next snapshot shows a new attempt, and the platform counts again.
4. When the policy gives up, the platform leaves the run failed, sends nothing more to it, and alerts. A person decides: **OPERATOR ACTION**. The protocol gives the operator one order, the abandon (section 7.14), and the application's own code can finish the run with a wake of its own (section 9.11). Whether an operator may ask the platform for one more retry is not settled (U22).

The platform counts a failure from a snapshot, never from the outcome of a report (section 6.9).

The report of a retry wake is read like the report of the wake of a timer (the table of section 7.8), with two differences. It is never "too early": that row is for a timer. And where that table speaks of the wait, read the failed or dead attempt that the retry was sent for. A report whose call executed no segment counts no new failure, because a failure is counted once for a run ID and attempt.

Three limits protect a run that is `running`:

- the platform never sends a wake to a run shown `running` before its lease deadline;
- when a snapshot shows `running` under a lease that has not passed, the platform waits until the deadline and sends an `inspect`. Only a snapshot that shows the lease passed is followed by a wake;
- the platform never sends a wake to a run shown `running` with no lease.

**The retry policy** says how many times, and how far apart, the platform wakes a failed or dead run before it gives up. The protocol carries none of it and fixes none of its values: they are the product's decision (P3). The application has no retry of its own for a segment, so a platform with no policy leaves every failed run failed. A platform can choose a policy by definition or by `errorClass`. When the count starts again is Q4.

### 7.10 Silence means ask

**R10, R11, R6.** **AUTOMATION.** The platform never concludes anything from silence. It sends an `inspect`, and reads the answer as a snapshot.

| The platform waits for | Counted from | How long | Then |
|---|---|---|---|
| The report of an announced segment | The announcement | `leaseSeconds` of the announcement | `inspect`. What follows when the answer shows the same wait and attempt as before is not settled (U19) |
| The announcement of a wake that was answered `202` | The `202`, or the last re-send of a wake that counts as accepted and silent (section 7.12) | 900 seconds | `inspect`. When the attempt is unchanged, the platform sends the wake again |
| Anything about a start it sent | The first send | Until `expiresAt` plus 300 seconds | `inspect` with the run ID (section 7.7) |
| The end of the lease of a run shown `running` | The arrival of the snapshot | `leaseExpiresAt` minus the `at` of that snapshot | `inspect`, when the platform still holds that snapshot |
| A snapshot, after a report or an answer whose `snapshotRead` is false | That report or answer | 30 seconds, then 60, doubling to 600 | `inspect`, again and again until an answer is read |
| A second, after two snapshots that it cannot order | The later arrival | 1 second | `inspect` |
| A change in a run that waits with no timer | The last read | One day at first, then longer, 7 days at most (section 7.13) | `inspect` |
| A change in a run that runs with no lease | The last read | No value in the protocol (U20) | `inspect` |
| A snapshot that names a run which the application started and which was announced, when no snapshot has named that run yet, whatever its reports said | The announcement | `leaseSeconds` of the announcement | `inspect` with the run ID. When the answer shows no run, or another run, the platform closes the run as ended with an unknown outcome. An announcement or a snapshot of that run with a later `at` opens it again |
| A snapshot that names a run which the application started and which was never announced, when the platform knows that run only from reports that show no run | Not settled. Q6 recommends the last report | Q6 recommends 300 seconds | `inspect` with the run ID. As what the platform then closes the run is part of Q6 too: it recommends lost when nothing claimed the run ID, and ended with an unknown outcome otherwise |

The 900 seconds, the 30 seconds and the 1 second are **SETTINGS**. The 900 seconds must exceed the delay that the application's queue can add.

Two rows count the `leaseSeconds` of an announcement from "the announcement". Whether that is the `at` of the announcement or its arrival is not settled, and neither is whether the first row applies to a run that the platform holds closed (U19).

The first two rows need to know which report follows which announcement, and whether a wake was announced. Several announcements and several reports can stand under one invocation ID, because every copy of an order posts its own. How the platform tells them apart depends on Q5.

### 7.11 Before a message leaves

**AUTOMATION.** The platform checks these in order before it sends anything to an endpoint.

1. **The endpoint is known.** A sync has published a URL.
2. **Deliveries are not stopped.** After a `401` the platform sends only pings, until one succeeds (section 5.9).
3. **Deliveries are not held back.** After a `503` with `Retry-After`, the platform sends nothing to that endpoint until that time has passed.
4. **For a `start` and a `wake`: the version is in the manifest.** The version of a run is the number at the front of its run ID; a run ID that is not minted counts as the lowest version of its definition in the manifest. Which version it counts for when the manifest holds no version of that definition is not settled (U27). The platform never sends a `start` or a `wake` for a version that left the manifest (R16). What becomes of an order that is due for such a version is not settled (U7). An `inspect` and an `abandon` are sent whatever the manifest holds, until the endpoint answers `404` to one: the platform then sends it again only while the version is in the manifest.
5. **For a `wake`, an `inspect` and an `abandon`: the definition matches** the last snapshot of the run (R3). When it does not, the platform sends nothing and alerts.
6. **For a `start`: the use-by time has not passed.**
7. **For a `wake`: the limits of section 7.9 hold**, and the attempt is the one of the snapshot the platform holds now.

**R17: do not flood.** The platform limits the requests in flight to one endpoint, honours `Retry-After` for every message to that endpoint, and spreads out timers that come due together after an outage. The limits are **SETTINGS** with no value in the protocol (P5).

### 7.12 Sending a message again

**R13: re-send only what can be re-sent.** **AUTOMATION.** The platform sends again only a message that it sent itself. It never sends a segment that the application issued.

A message sent again is the same message: the same body, the same invocation ID, the same `expiresAt` for a start. Only the timestamp and the signature are new. One point is open, for a wake only. The last two rows of the table below are wakes that the platform decides again, and whether such a wake keeps its invocation ID is Q5. A start is not concerned: every copy of a start carries the same invocation ID, run ID and `expiresAt` (section 7.7).

| After | The platform sends the same message again | How often |
|---|---|---|
| A `503` | Once `Retry-After` has passed. After the back-off when the answer carries no `Retry-After` | Until it gets another answer |
| A `500`, a `3xx`, or no answer within 10 seconds | After a back-off of 5 seconds that doubles with every copy, up to 300 seconds: 5, 10, 20, 40, 80 | 5 times, 155 seconds in all. Then see below |
| A `404` | After the same back-off | While the version is in the manifest. For a start: while its use-by time has not passed |
| A `401` | After a ping succeeds | Once, then by the answer it gets |
| A report `aborted` of an order of the platform, with a `claimed` that is not true | After the back-off | By the reports that follow |
| For a wake: another report whose call executed no segment, such as `refused` with `conflict`, or `observed`, and a snapshot that still shows that wait, or that failed or dead attempt, at the attempt of the wake | Not settled (U26) | |
| For a start: an `observed` report or an `inspect` answer that shows `claimed` false and no run under its run ID | After the back-off, while its use-by time has not passed | By the reports that follow |
| For a wake: `202`, then no announcement within 900 seconds, then an `inspect` that shows the attempt unchanged | At once | By what follows |
| For a wake: a report that says "too early" (section 7.8) | After 1 to 60 seconds | Until the timer is due on the application's clock |

The back-off and the number of copies are **SETTINGS** with the values above. Two things about them are not settled: whether the count of copies and the back-off start again when another answer comes in between (U16), and what limits a message that is sent again without end, such as a wake that every worker reports `aborted` (U17).

The platform **never** sends again: a message answered `400` or `405`; a start after its `expiresAt`; a start reported `refused`, `rejected`, or `aborted` with `claimed` true; a wake reported `rejected` with `wrong_definition`.

An announcement or a report that carries the invocation ID of an order shows that the order was queued, whatever the endpoint answered. After a `500`, a `3xx` or a timeout the platform then need not send the order again, and a copy that it does send is harmless.

**After the last of the 5 copies** the platform does not give the message up.

- **A `start` or a `wake`:** the platform alerts, and treats the order as accepted and silent. For a wake, section 7.10 then applies: after 900 seconds the platform asks, and sends the wake again when the attempt is unchanged. For a start, section 7.7 applies: the platform asks 300 seconds after the use-by time.
- **An `inspect` or an `abandon`:** the platform alerts, and keeps sending the message at a slow pace until an answer is read. The pace is a **SETTING** with no value in the protocol (U18).

An endpoint that cannot be reached for a while, as during a deploy or a restart of the application's web servers, therefore never loses a timer: the wake leaves once the endpoint answers again. That holds when what the platform gets in that time is no usable answer (section 5.9). When it gets an answer that section 5.9 does not list, such as a refused connection or the `502` of a proxy, it depends on U1. A start is less protected, because it has a use-by time (U15).

### 7.13 Reading a wait that has no timer

**R14.** **AUTOMATION**, on a schedule that is a **SETTING**.

A run that is suspended with a null `wakeAt` gives the platform nothing to send, and the application's own code may answer it or discard it without a report ever reaching the platform. The platform therefore sends an `inspect` for such a run from time to time: daily at first, then less often, and at least every 7 days. The shape of that schedule is not settled (U20).

For a run that is `running` with no lease the protocol says only that the platform reads it now and then (R11): the period is not settled (U20).

A read that finds the run gone closes it (section 7.5). No rule asks the platform to read any other open run, such as one that the retry policy gave up on (U21).

### 7.14 Abandoning a run

**R5, R2.** An operator asks: **OPERATOR ACTION**. Everything after that is **AUTOMATION**.

1. The operator names a run that the platform holds open.
2. The platform chooses the form of the abandon (R2): with the attempt of the snapshot it holds for the run, or with no attempt for a start that the platform sent and has heard nothing of. For a run that the platform has heard of and that no snapshot has named, R2 allows neither form: not settled (U23).
3. The platform checks the definition (section 7.11, step 5) and sends the abandon at once, whatever it asked about the run before.
4. It reads the answer by section 6.8.
5. When the answer is a `409` that shows the run, at another attempt or as started in the meantime, the platform sends the abandon again with the attempt shown. The operator asked once. How many times, and how soon, is not settled (U24).
6. When the answer is a `409` that shows no run with that run ID, the platform reads the snapshot. When that closes the run, the operator's request ends there. Otherwise the platform sends an `inspect` with the run ID, and then follows section 6.8 (Q1).
7. When the answer is a `400`, the platform stops, and the operator's request ends as refused. For a `409` with `abandon_refused`, what becomes of the request is not settled (U25).
8. When the answer is a `404`, the platform sends the abandon again with a back-off while the version of the run is in the manifest (section 7.11, step 4). When the version is not in the manifest, it does not send the abandon again, and what becomes of the request is not settled (U18).

The platform never sends an abandon for another reason: not for a run that the retry policy gave up on, not for a stranded version, not to clean up.

### 7.15 Taking in a sync, and the manifest

**R16.** **AUTOMATION**, except the consent of step 4.

1. Check the API key: `401`.
2. Check the body against `sync.json`: `400` with `invalid_request`, or with `unsupported_protocol` when `protocols` does not contain 1. An endpoint URL that does not begin with `https://` fails here.
3. Check that the endpoint is publicly routable (section 3.10). Which answer a sync gets when it is not is not settled (U5).
4. When the sync names an endpoint on another host than the one the environment is on now, ask an owner of the environment to confirm the move, and do not apply the sync before that. **OPERATOR ACTION.** A new path on the same host needs no consent, and the first sync of an environment has no host to compare with. The platform cannot make the SDK wait for the owner: it answers the sync with a refusal, the deploy fails, and the SDK never sends a sync again by itself (section 5.1). Which refusal is Q3. What "another host" is, and how the owner's consent reaches a later sync, are not settled (U6).
5. Send a signed `ping` to the URL of the sync, and do not follow a redirect. When the ping fails, answer `422 endpoint_unverified` and leave the manifest as it was. A wrong URL or a wrong signing key therefore fails the deploy.
6. In one durable write: replace the manifest with the list of the sync, and store the endpoint URL. Deliveries that a `401` had stopped resume, because the ping of step 5 succeeded (section 5.9).
7. Answer `200` with `modified`, `versions` and `stranded` (section 5.1).

What the platform does with two syncs that arrive at the same time is not settled (U4).

A **requested sync** (section 5.11) is taken in by the same procedure from step 2, on the body of the endpoint's `200`. Step 1 has no API key to check, because the platform made the request itself, over HTTPS, to a URL that it chose. When the `endpoint` of the answer is the URL that the platform called, step 5 sends no ping: the endpoint has just answered a signed request there. When it is another URL, steps 3 to 5 apply to that URL, as for any sync that moves the endpoint. Step 7 answers nobody: the platform shows the result to the operator who asked.

How the platform counts the open runs of a version: a run counts for the definition the platform holds it under and the version of its run ID (section 7.11, step 4). It counts while it is open. An entry of the manifest gets its count in `versions`, also when the count is 0. A definition and version that is not in the manifest and has open runs goes to `stranded`. For a run whose run ID is not minted, when the manifest holds no version of its definition, the version to count it for is not settled (U27).

What the manifest decides afterwards:

| The manifest | The platform |
|---|---|
| Holds a definition | Can start runs of it, on its highest version. Can adopt a run that a snapshot shows with that definition |
| Holds the version of a run | Sends that run its wakes |
| Does not hold the version of a run: the version is **stranded** | Sends that run no `start` and no `wake`. What becomes of an order that is due is not settled (U7). Still sends `inspect` and `abandon` |
| Shows an older version with no open run | Nothing. The SDK tells the developer that the version can leave the code. The highest version is never in that list |

### 7.16 Alerts

**R18.** The platform raises an alert by itself: **AUTOMATION**. A person looks at it and decides: **OPERATOR ACTION**. No alert stops the platform from recording a notice.

| The platform alerts when | What it suggests |
|---|---|
| An announcement or a report has the origin `platform` and an invocation ID that the platform never sent | Somebody else holds the signing key. An operator rotates it |
| A wake is reported `rejected` with `wrong_definition` | The platform named a definition that did not start the run, or somebody else holds the signing key, or the run carries a tag that code outside the SDK gave it (section 2.5). The snapshot of the report shows such a run with a null `definition` when the tag does not have the form of a definition name |
| The endpoint answers `400` or `405` to a message | The platform sent a malformed message, which is a defect of the platform. For an `inspect` or an `abandon` there is a second cause, the one of the row above: the run that holds the workflow ID has a tag that is not the definition named |
| The platform holds back a `wake`, an `inspect` or an `abandon` because the last snapshot of the run shows another definition (R3) | The platform's record of the run is wrong |
| The `at` of a report is more than 300 seconds from the platform's clock | A clock is wrong: the application's, or the platform's |
| The endpoint answers `401` | The signing key of the application is wrong, or its clock is. Deliveries are stopped |
| The retry policy gave up on a run | The run is left failed |
| A run is `running` with no lease | The application starts runs outside the SDK. The platform cannot wake them |
| An order or a question got no usable answer after the last copy (section 7.12) | The endpoint is away |
| A sync names an endpoint on another host | An owner must confirm the move |
| An answer of the endpoint breaks its schema | The endpoint is not the SDK, or a proxy rewrites its answers |

The 300 seconds of the clock alert are a **SETTING**. How an alert reaches a person is the product's decision (P7).

---

## 8. Who acts

Every behaviour of this document in one table, with the kind it is. The four words are those of section 1.5. A SETTING says where it is set: on the platform, or in the application's code. The third column holds the value that the protocol gives, with the bound that the value must respect. The fourth holds a value that only the reference simulator has, or the one that it picks inside a range: it is never a rule. The table has no row for what the platform's product decides, such as how an environment and its first keys are created, or how long closed runs are kept: section 11.3 lists those.

| Behaviour | Kind | Value in the protocol | Reference simulator | Section |
|---|---|---|---|---|
| **Keys and transport** | | | | |
| Check the API key of every request of the application | AUTOMATION | | | 3.2 |
| Sign every request with every active signing key; accept the old and the new API key during a rotation | AUTOMATION | | | 3.4, 3.7 |
| Start and end the rotation of a key; revoke a key | OPERATOR ACTION | | | 3.7 |
| The signature rule and its window | FIXED BY THE PROTOCOL | 300 seconds either way | | 3.4, 3.5 |
| The longest body of a message to the endpoint | FIXED BY THE PROTOCOL | 65,536 bytes | | 3.9 |
| The formats of the identifiers; an integer written without a fraction or an exponent | FIXED BY THE PROTOCOL | | | 3.9, 4 |
| The statuses, the codes and their meanings | FIXED BY THE PROTOCOL | | | 5, 6 |
| How long the SDK waits for an answer of the platform | FIXED BY THE PROTOCOL | 3 seconds; 15 for a sync | | 3.8 |
| How long the platform allows the endpoint to answer | SETTING, on the platform | 10 seconds. With the platform's own work it must fit in the 15 seconds of a sync | | 3.8 |
| Accept only an `https`, publicly routable endpoint; never follow a redirect | AUTOMATION | | | 3.10 |
| Validate against its schema everything it receives: a sync, an announcement, a report, and the body of a `200` and of a `409` of the endpoint. Answer `400` to a sync or a notice that does not validate: with `unsupported_protocol` when its `protocol` is not 1 or its `protocols` do not contain 1, with `invalid_request` otherwise. For a body that fails only for a value which the schema does not list, such as a new outcome: not settled (U3) | AUTOMATION | | | 12.1 |
| **The manifest** | | | | |
| Take in a sync: check it, ping the endpoint, replace the manifest, count the open runs of each version | AUTOMATION | | | 7.15 |
| Confirm that the endpoint of an environment moves to another host | OPERATOR ACTION | | | 7.15 |
| Which definitions and versions exist; the endpoint URL; the lease of each version | SETTING, in the application's code. The platform reads them from the sync. The lease that it counts with is the one that each announcement repeats | | | 5.1 |
| The shortest lease a sync may publish | FIXED BY THE PROTOCOL | 60 seconds | | 5.1 |
| Never send a `start` or a `wake` for a stranded version | AUTOMATION | | | 7.11, 7.15 |
| **Notices and snapshots** | | | | |
| Record an announcement or a report; answer `recorded` only after a durable write, and with an answer that counts as undelivered when the write fails | AUTOMATION | No status is named. Any `5xx` counts as undelivered | `503` | 5.2, 7.2 to 7.4 |
| Hold a run that the application started, from its first notice | AUTOMATION | | | 7.3, 7.4 |
| Keep only coordination data; drop every field it does not know | AUTOMATION | | | 7.2 |
| Read every snapshot: replace what it holds, adopt a run it has never heard of, close a run that left | AUTOMATION | | | 7.5 |
| Close a run as completed, abandoned, ended with an unknown outcome, lost or spent; open it again when a later snapshot names it | AUTOMATION | | | 2.9, 7.6 |
| **Starts** | | | | |
| A trigger: its schedule, its definition, and how it builds the workflow ID | SETTING, on the platform | | | 7.7 |
| Fire a trigger by hand | OPERATOR ACTION | | | 7.7 |
| Issue one start for each firing, whoever caused it: a schedule, a program that calls the platform's API, or an operator. The start is for a definition of the manifest, with a run ID never used and a use-by time fixed at the first send | AUTOMATION | | | 7.1, 7.7 |
| The lifetime of a start | SETTING, on the platform, inside a range that is FIXED BY THE PROTOCOL | 600 to 3600 seconds. The endpoint enforces the upper bound only | 900 seconds | 5.4, 7.7 |
| The longest lifetime the endpoint accepts for a start | FIXED BY THE PROTOCOL | 3600 seconds, plus the signature window | | 5.4 |
| Ask about a start that is still open after its use-by time | AUTOMATION | | | 7.7 |
| How long after the use-by time the platform asks | SETTING, on the platform | 300 seconds. Not shorter than the signature window | | 7.7 |
| How long the SDK keeps a ledger entry | FIXED BY THE PROTOCOL | At least one hour after the use-by time | | 2.7 |
| Look at a start that is spent, lost or ended with an unknown outcome; decide whether to fire the trigger again. The platform never replaces a start by itself | OPERATOR ACTION | | | 7.6, 7.7 |
| **Wakes** | | | | |
| Send one wake when the timer of a wait is due | AUTOMATION | | | 7.8 |
| Copy the run ID and the attempt of a wake or of an abandon from what the application showed; never guess an attempt and never add to one | AUTOMATION | | | 7.1 |
| The instant of a timer: the time that a run sleeps until, or the deadline of an event wait | SETTING, in the application's code. The platform reads `wait.wakeAt` | | | 6.3 |
| Send a wake again when it came too early | AUTOMATION | | | 7.8 |
| How long before a wake that came too early is sent again | SETTING, on the platform | 1 to 60 seconds | 30 seconds | 7.8 |
| Count failures; send a retry wake; stop when the policy gives up | AUTOMATION | | | 7.9 |
| The retry policy: how many retries, how far apart, for which definitions or error classes | SETTING, on the platform | None | 3 retries, 30 seconds apart, for every run | 7.9 |
| Abandon a run that the retry policy gave up on, or leave it failed | OPERATOR ACTION | | | 7.9 |
| Never wake a run that is running before its lease deadline, nor one that runs with no lease | AUTOMATION | | | 7.9 |
| **Questions** | | | | |
| Ask after every silence: a segment, a wake, a lease deadline, a snapshot that was not read, two snapshots it cannot order, a run of the application that no snapshot has named | AUTOMATION | | | 7.10 |
| How long the platform waits for the report of an announced segment | SETTING, in the application's code. The platform reads `leaseSeconds` from the announcement | The `leaseSeconds` of the announcement. From which instant they count is not settled (U19) | | 7.10 |
| How long the platform waits for the announcement of a wake | SETTING, on the platform | 900 seconds. More than the delay that the application's queue can add | | 7.10 |
| How long before it asks for a snapshot that was not read | SETTING, on the platform | 30 seconds, doubling to 600 | | 7.10 |
| How long before it asks after two snapshots that it cannot order | SETTING, on the platform | 1 second. One whole second at least | | 7.10 |
| Read now and then a run that waits with no timer, and a run that runs with no lease | AUTOMATION | | | 7.13 |
| How often it reads such a run | SETTING, on the platform | With no timer: daily, then at least every 7 days. With no lease: none | After 1, 2 and 4 days, then every 7 days, for both | 7.13 |
| **Delivery** | | | | |
| Check the definition, the manifest and the state of the endpoint before a message leaves | AUTOMATION | | | 7.11 |
| Send a message again with a back-off; treat an order as accepted and silent after the last copy | AUTOMATION | | | 7.12 |
| The back-off, and how many copies follow when a message gets no usable answer | SETTING, on the platform | 5 seconds doubling to 300; 5 copies | | 7.12 |
| The slow pace of an `inspect` or an `abandon` after its last copy | SETTING, on the platform | None | Every 300 seconds | 7.12 |
| Stop deliveries after a `401`, ping, resume after a ping succeeds, the ping of an accepted sync included | AUTOMATION | | | 5.9, 7.15 |
| How often it pings after a `401` | SETTING, on the platform | 60 seconds | | 5.8 |
| Honour `Retry-After`; limit what it sends one endpoint; spread out timers that come due together | AUTOMATION | | | 7.11 |
| That a `503` of the endpoint carries `Retry-After`, in seconds | FIXED BY THE PROTOCOL | The SDK sends 5 today. The platform reads the header and does not assume the value | | 5.9 |
| How many requests it sends one endpoint at a time | SETTING, on the platform | None | One | 7.11 |
| **Abandon** | | | | |
| Abandon a run. The platform never does it by itself | OPERATOR ACTION | | | 5.7, 7.1, 7.14 |
| After an operator asked: send the abandon, send it again with the attempt shown, inspect after a `409` with no run | AUTOMATION | | | 7.14 |
| **Alerts** | | | | |
| Raise an alert | AUTOMATION | | | 7.16 |
| Look at an alert and decide what to do | OPERATOR ACTION | | | 7.16 |
| How far the `at` of a report may be from the platform's clock before it alerts | SETTING, on the platform | 300 seconds | | 7.16 |

Three numbers of the reference simulator have no kind yet, because the point they belong to is not settled: the 300 seconds after which it asks about a run of the application that it knows only from reports (Q6), the 900 seconds after which it asks about a start whose copies ran out (U15), and the 5 abandons that it sends for one request when each is answered `409` (U24).

---

## 9. Situations

Each situation says what the platform sees, what it must conclude, and what it does. The first three are ordinary, and the others are failures. The end-to-end tests of section 12.4 play them against the real SDK, except section 9.25, which only the `Simulator/` classes play, with no application. The table of section 12.4 names the test of each.

In the tables, "platform → endpoint" is a signed request of the platform, and "application → platform" a notice.

### 9.1 A run that the platform starts: an event wait, then a sleep

An order workflow. A trigger starts it for `order:123`. It waits up to one hour for the event `payment.received`, then sleeps until the order ships, then completes. The application registered `order-fulfilment` in versions 2 and 1 with a lease of 600 seconds.

| # | Direction | Message | What it says | Answer |
|---|---|---|---|---|
| 1 | platform → endpoint | `start` | Run `2-0192f3a8…`, use-by time 900 seconds ahead (the example of section 5.4) | `202 {}` |
| 2 | application → platform | announcement | `start`, origin `platform`, attempt null, lease 600 (section 5.2) | `200` recorded |
| 3 | application → platform | report | `suspended`, executed under attempt 1, `claimed` true. The run waits for `payment.received`, `wakeAt` one hour ahead (section 5.3) | `200` recorded |
| 4 | application → platform | announcement | `signal`, origin `application`, attempt 1 (section 5.2) | `200` recorded |
| 5 | application → platform | report | `suspended`, executed under attempt 2. The run sleeps, `wakeAt` two seconds ahead (section 6.6) | `200` recorded |
| 6 | platform → endpoint | `wake` | Attempt 2 (section 5.5) | `202 {}` |
| 7 | application → platform | announcement | `wake`, origin `platform`, attempt 2 | `200` recorded |
| 8 | application → platform | report | `completed`, executed under attempt 3, `run` null (section 6.6) | `200` recorded |

What the platform concludes and does at each step:

1. It holds the run open, as issued. A `202` promises nothing.
2. A worker took the start. The platform expects a report within 600 seconds.
3. The run exists and waits. The platform plans one timer, for attempt 1 and wait 1, at `wakeAt`.
4. The customer paid, and the application's own code delivered the event. The platform sent nothing and does nothing: it waits for the report.
5. The snapshot shows attempt 2, which is higher than what the platform holds, so it replaces it. The timer of wait 1 no longer exists. The platform plans one timer for attempt 2 and wait 2.
6. The platform's clock reaches `wakeAt`. The wake carries the run ID and the attempt of the snapshot of step 5.
7. A worker took the wake.
8. The platform closes the run as completed, and plans nothing more for it.

The announcement of step 7:

<!-- example: announcement-wake-platform | schema: announcement.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB7M6P2ZC4", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",
 "attempt": 2, "leaseSeconds": 600, "at": 1791634833}
```

The platform sent two messages: one start and one wake. It asked no question.

Had the payment not arrived, the platform's clock would have reached the `wakeAt` of step 3. The platform would have sent a wake with attempt 1. The application then continues the run with the fact that the deadline passed, and the workflow decides what to do.

### 9.2 A run that the application starts by itself

The application's checkout starts the same workflow for `order:129` in its own code, on its own queue. The platform sends no start. It learns of the run from two notices.

| # | Direction | Message | What it says |
|---|---|---|---|
| 1 | application → platform | announcement | `start`, origin `application`, attempt null, a run ID that the SDK minted |
| 2 | application → platform | report | `suspended`, executed under attempt 1, `claimed` true, the run waits |

<!-- example: announcement-start-application | schema: announcement.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-067e-7f9f-8cbd-15c0d9bedb83", "verb": "start",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "order:129",
 "runId": "2-01a125c2-067d-75be-a73c-08d3158354d1", "attempt": null, "leaseSeconds": 600, "at": 1791634835}
```

<!-- example: report-start-application-queued | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-067e-7f9f-8cbd-15c0d9bedb83", "verb": "start",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "order:129",
 "runId": "2-01a125c2-067d-75be-a73c-08d3158354d1", "outcome": "suspended", "code": null, "errorClass": null,
 "executedAttempt": 1,
 "run": {"runId": "2-01a125c2-067d-75be-a73c-08d3158354d1", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 1, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638435}},
 "claimed": true, "snapshotRead": true, "at": 1791634835}
```

1. The platform holds no run with that run ID. It creates one: started by the application, under the definition of the announcement.
2. The run waits. The platform plans its timer.

From here the run is like any other: the platform wakes it when a timer is due and retries it when it fails. The platform never sends a start for it, and never sends again a segment that the application issued.

When the report of step 2 does not arrive within the `leaseSeconds` of the announcement, the platform asks (section 7.10). When neither notice arrives, the platform does not know that the run exists: section 9.20.

### 9.3 A chat turn served inside a web request

A chat agent. The workflow ID is the ID of the conversation. Each message of the user is one turn, and each turn is one run on that workflow ID. The application executes the turn inside the web request that received the message, and streams the answer to the browser. It does not call the platform from that request (section 2.8).

The platform sees two notices, which a worker of the application posts after the fact. The two below come from the order workflow of the test application, run inside a web request on a workflow ID that stands for a conversation. A chat agent produces the same two messages; its wait would be named `approval`.

<!-- example: announcement-start-in-request | schema: announcement.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-06b3-7941-84ee-931b600e33fe", "verb": "start",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "thread:8f14e45f",
 "runId": "2-01a125c2-06b3-743a-bad3-20c03307aed8", "attempt": null, "leaseSeconds": 600, "at": 1791634835}
```

The report of that turn is the last example of section 6.7.

What is particular to such a turn:

- **`claimed` is false although the turn ran.** A start that runs in the calling process is never entered in the ledger.
- **The `at` of the announcement is earlier than the turn; the `at` of the report is later than its end,** by as long as the two notices waited in the application's queue. Both can arrive within the same second.
- **The outcome says how the turn ended.**

| The report says | It means | The platform |
|---|---|---|
| `completed`, `run` null | The turn ended. The conversation is free for the next message | Closes the run. The next turn is a new run with a new run ID on the same workflow ID |
| `suspended`, a wait named `approval` with a null `wakeAt` | The agent wants to call a tool and waits for the user's decision | Holds the run. Sends nothing. Reads it now and then (section 7.13) |
| `failed`, or `observed` with a snapshot that shows `failed` | A step threw, or the browser left while the turn was executing | Retries by the policy. The wake runs the turn again in a worker of the application, with no browser attached |
| `refused` with `run_in_flight` | The user sent a message while an earlier turn still holds the conversation. The run ID of this report never ran. `run` shows the turn that holds the conversation | Marks this start spent. Reads the snapshot |

The user's decision on an approval arrives as an `answer` segment that the application issues. Its announcement carries the attempt the run was at:

<!-- example: announcement-answer-application | schema: announcement.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-06dc-722b-89c9-f41e78f6323c", "verb": "answer",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "thread:8f14e45f",
 "runId": "2-01a125c2-06b3-743a-bad3-20c03307aed8", "attempt": 1, "leaseSeconds": 600, "at": 1791634835}
```

The report of that answer is the `answer` example of section 6.6.

### 9.4 A start delivered twice

The application's queue delivers the start to two workers, or the platform sent it twice because the first answer was lost, or somebody replayed the signed request within the signature window.

**The platform sees** two announcements with one invocation ID, then two reports: one says `suspended`, `completed` or `failed` with `claimed` true, the other says `rejected` with `already_claimed`, `claimed` true, and a snapshot of the run as it is.

**It concludes** that the start ran once. The second copy found its run ID in the ledger and did nothing.

**It does** nothing for the rejected copy: it closes nothing and reads the snapshot like any other.

The same holds when a copy arrives after the run completed and its records are gone. The ledger outlives the run. The report then shows no run:

<!-- example: report-start-already-claimed-after-completion | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2M8Q3W7A", "verb": "start", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:123", "runId": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12",
 "outcome": "rejected", "code": "already_claimed", "errorClass": null, "executedAttempt": null, "run": null,
 "claimed": true, "snapshotRead": true, "at": 1791634834}
```

The run stays closed as completed: the announcement of that copy does not open it again.

### 9.5 A wake, or an `answer` segment of the application, delivered twice

**The platform sees** two announcements with one invocation ID and the same attempt, then two reports. The first copy was admitted and raised the attempt. The second says `refused`: with `stale_attempt` and a snapshot of the run at the new attempt, or with `stale_run` and no run when the first copy completed the run (both examples are in section 6.6).

**It concludes** that the segment executed once.

**It does** nothing for the refused copy but read its snapshot.

A replayed `wake` request is the same case. After the signature window the endpoint answers `401` to it.

### 9.6 A report that is lost

The platform did not record a report: it was down, it answered too late, or the request never arrived.

**The platform sees**, later, a report with the same invocation ID and the same outcome, and a snapshot that was read when the application's worker posted it. That snapshot can show a later state than the outcome: the run may have moved on meanwhile.

**It concludes** how the segment ended from the outcome, and what the run is now from the snapshot.

**It does** what section 7.4 says, with no special case. When the platform had in fact recorded the first report, it now holds two reports for one segment, and the rules of section 7.5 give the same result in either order.

When the application's queue loses that second report too, the platform hears nothing: it asks when the lease of the announcement has passed (section 7.10).

### 9.7 The platform is down for a while

**The application** keeps executing. Nothing on its side waits for the platform. Its announcements and reports pile up in its own queue. No timer fires and no retry happens while the platform is down.

**The platform sees**, when it is back, the notices of that time arriving together, in any order, each with the `at` of the moment it was built or read.

**It concludes** the state of each run by the rules of section 7.5, which do not depend on the order of arrival. It learns of the runs that the application started meanwhile.

**It does this:** it fires the timers that came due, late, and spread out (section 7.11). The clock alert of section 7.16 is for a report only, whose `at` is read immediately before the report is posted. An announcement that waited in the application's queue keeps the `at` of the moment it was built, so an old `at` in an announcement says nothing about a clock.

### 9.8 The application's endpoint is away for a while

A deploy restarts the web servers of the application. For some minutes no request of the platform gets an answer within 10 seconds. A refused connection, or the `502` of a proxy, is another matter: what follows it is not settled (U1). Reference simulator: it treats both like a `500`, so what follows holds for them too.

**The platform sees** no usable answer to a wake and to its 5 copies, 155 seconds in all.

**It concludes** nothing about the run. Nobody knows whether a copy was queued.

**It does this:** it alerts, and treats the wake as accepted and silent. After 900 seconds it sends an `inspect`. When that gets no answer either, it keeps asking: 5 copies with the back-off, then at the slow pace of section 7.12, which has no value in the protocol (U18). Reference simulator: every 300 seconds. When the endpoint answers again, the answer shows the run suspended at the same attempt, and the platform sends the wake once more. The timer fires once, late. No timer is lost.

A start that the platform sent while the endpoint was away is less protected, because it has a use-by time: the platform asks about it only after that time, when it can no longer send it. Whether it should ask sooner is not settled (U15).

### 9.9 A worker is killed in the middle of a segment

**The platform sees** an announcement and no report.

**It concludes** nothing until the `leaseSeconds` of the announcement have passed. Then it sends an `inspect`. The answer shows the run `running`, at the attempt the dead worker raised it to. When the lease deadline in that answer has not passed by the answer's own `at`, the platform waits for the deadline and asks again. When it has passed, the run is dead.

**It does this:** it counts one failure for that attempt, and sends a wake with the attempt shown when the retry policy allows:

<!-- example: wake-dead | schema: wake.json -->
```json
{"protocol": 1, "verb": "wake", "invocationId": "inv_01JB32J4S0T1", "definition": "order-fulfilment",
 "workflowId": "order:133", "runId": "2-01a125c2-07a2-78d7-abc3-27a8f7e26068", "attempt": 3}
```

The application takes the run over. The steps that had been committed are not executed again; only the step that was executing when the worker died runs again.

<!-- example: report-wake-dead-completed | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB32J4S0T1", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:133", "runId": "2-01a125c2-07a2-78d7-abc3-27a8f7e26068",
 "outcome": "completed", "code": null, "errorClass": null, "executedAttempt": 4, "run": null, "claimed": null,
 "snapshotRead": true, "at": 1791634843}
```

### 9.10 A slow worker is taken for dead

A step takes longer than the lease. The platform sees the same as in section 9.9 and does the same: nothing tells a slow worker from a dead one.

**The platform then sees**, after its wake completed or suspended the run, one more report from the slow worker: `refused` with `stale_attempt` and an `executedAttempt` (the example "a slow worker that lost its run" of section 6.6).

**It concludes** that the step ran in two processes, and that the write of the slow one was refused.

**It does** nothing but read the snapshot. The cause is a lease shorter than the slowest step, which only the developer can fix.

### 9.11 A step fails, and the platform retries

**The platform sees** a report that says `failed` with the class of the exception, and a snapshot that shows the run `failed` at the attempt that failed (the example of section 6.6).

**It concludes** one failure for that run ID and attempt.

**It does this:** it waits the delay of the retry policy, then sends a wake with that attempt:

<!-- example: wake-retry | schema: wake.json -->
```json
{"protocol": 1, "verb": "wake", "invocationId": "inv_01JB2P7H5K9E", "definition": "order-fulfilment",
 "workflowId": "order:124", "runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40", "attempt": 1}
```

The wake executes under the next attempt:

<!-- example: report-wake-retry-suspended | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01JB2P7H5K9E", "verb": "wake", "origin": "platform",
 "definition": "order-fulfilment", "workflowId": "order:124", "runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40",
 "outcome": "suspended", "code": null, "errorClass": null, "executedAttempt": 2,
 "run": {"runId": "2-0192f3a9-11d4-7b6e-8c2a-5d7f9e1b3a40", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 2, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638434}},
 "claimed": null, "snapshotRead": true, "at": 1791634834}
```

When the retry fails too, the platform counts the new attempt. When the policy gives up, it leaves the run failed and alerts.

The application's own code can also finish a failed run: it then issues a wake itself. The platform sees an announcement and a report with the verb `wake` and the origin `application`:

<!-- example: announcement-wake-application | schema: announcement.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-073b-7304-a5ff-75eb8508877c", "verb": "wake",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "thread:c9f0f895",
 "runId": "2-01a125c2-0722-7059-a22b-f1cd9710d3ce", "attempt": 1, "leaseSeconds": 600, "at": 1791634835}
```

<!-- example: report-wake-application | schema: report.json -->
```json
{"protocol": 1, "invocationId": "inv_01a125c2-073b-7304-a5ff-75eb8508877c", "verb": "wake",
 "origin": "application", "definition": "order-fulfilment", "workflowId": "thread:c9f0f895",
 "runId": "2-01a125c2-0722-7059-a22b-f1cd9710d3ce", "outcome": "suspended", "code": null, "errorClass": null,
 "executedAttempt": 2,
 "run": {"runId": "2-01a125c2-0722-7059-a22b-f1cd9710d3ce", "definition": "order-fulfilment",
         "status": "suspended", "attempt": 2, "leaseExpiresAt": null,
         "wait": {"interruptId": 1, "type": "wait_for_event", "eventName": "payment.received",
                  "wakeAt": 1791638435}},
 "claimed": null, "snapshotRead": true, "at": 1791634835}
```

When the platform's retry wake for the old attempt arrives afterwards, the application refuses it with `stale_attempt`. The fence lets only one of the two execute.

### 9.12 The application's store fails

The store can fail at several moments. Each looks different, and the rule "plan from a snapshot that was read" covers them all.

| What failed | The platform sees | It does |
|---|---|---|
| The read for the report | A report with its real outcome and `snapshotRead` false (the last example of section 6.6) | Ignores `run` and `claimed`. Asks 30 seconds later, then 60, doubling to 600, until an answer is read |
| A write of the segment was committed, and its answer was lost | A report that says `failed`, with a snapshot that shows the run `suspended` | Follows the snapshot: the run waits. Counts no failure and sends no retry |
| The write that completes the run was committed, and its answer was lost | A report that says `failed`, with `run` null | Closes the run as ended with an unknown outcome when an earlier snapshot named it: the snapshot shows that it left, and no report said `completed`. When no snapshot ever named the run, as after the first segment of a start, the run stays open and the platform asks later (sections 7.7 and 7.10) |
| The admission of a wake was committed, and its answer was lost | A report that says `aborted` with `not_executed`, with a snapshot that shows the run `running` at the next attempt | Follows the snapshot: waits for the lease deadline, sees the lease passed, and wakes with the new attempt |
| The ledger, when a worker claims a start | A report that says `aborted` with `unavailable` (section 6.6) | Sends the same start again after a back-off. Section 6.6 says what follows when the store had in fact committed the claim |
| The store, while the endpoint answers an `inspect` | `200` with `snapshotRead` false | Asks again later |
| The store, while the endpoint deals with an `abandon` | `503` with `unavailable` | Sends the same abandon again after `Retry-After` |

### 9.13 An order is accepted and never runs

The endpoint answered `202`, and then the application's queue lost the job, or a worker died with it before it announced anything.

**For a wake, the platform sees** nothing. After 900 seconds it sends an `inspect`. The answer shows the run as before, at the same attempt. The platform sends the wake again.

**For a start, the platform sees** nothing. It waits until 300 seconds after the use-by time and sends an `inspect` with the run ID. The answer shows no run and `claimed` false (the example of section 6.11). The platform closes the run as lost and shows it to an operator. It is too late to send the start again, and the platform never replaces it by itself.

A `202` promised nothing.

### 9.14 A deploy has not delivered a version yet, or has removed one

**Not delivered yet.** The sync of a release published version 2 before the web servers and the workers of the application run the new code. The platform mints a start with version 2.

- A web server on the old code answers `404` with `unknown_version`. The platform sends the same start again with a back-off.
- When the web servers run the new code and an old worker takes the start, the worker reports `aborted` with `unknown_version` (section 6.6). The platform sends the same start again.
- When a new worker takes it, the start runs. Every copy was the same message with the same use-by time.

**Removed.** A release drops version 1 while a run of version 1 sleeps.

- Before the sync of that release, the platform's wake is answered `404` with `unknown_version`, and the platform sends it again with a back-off: version 1 is still in the manifest.
- The sync arrives without version 1. The platform accepts it, and lists version 1 under `stranded` with its open runs. From then on it sends that run no wake.
- What becomes of the timer is not settled (U7).

### 9.15 A timer fires early

The platform's clock is ahead of the application's.

**The platform sees** the report of its wake: `suspended`, no `executedAttempt`, and a snapshot with the same wait and the same attempt (the example of section 6.6).

**It concludes** that the wake came too early by the application's clock.

**It does this:** it sends the wake again after 1 to 60 seconds, and again, until the report shows that a segment ran.

### 9.16 An event before its wait, and an approval sent twice

These happen inside the application. The platform sees one of them only.

- **An approval submitted twice before the first was admitted.** The platform sees two announcements with the verb `answer` and the same attempt, and two reports: one executed, the other is `refused` with `stale_attempt`, or with `stale_run` when the first one ended the run. The second answer did not reach a later wait.
- **An event that arrives before its wait, and an approval submitted after the run moved on.** The application refuses both in its own code, before anything is queued. The platform sees nothing, and nothing keeps the event for later.

### 9.17 A browser closes a streamed turn

| What happened in the application | The platform sees | It does |
|---|---|---|
| The reader of the stream stopped while a step was executing. The application failed the run | A report that says `observed`, with a snapshot that shows the run `failed` (section 6.6) | Counts a failure and retries by the policy |
| The reader stopped at the very end of the stream, after the run had suspended or completed | A report that says `suspended` or `completed` | Plans from the snapshot. Nothing failed |
| The application's runtime ended the request by itself when the connection closed. Nothing was queued after the announcement | An announcement and no report | Asks when the lease of the announcement has passed. The answer shows the run `failed`, or `suspended` when it had already stopped. The platform plans from it |

### 9.18 A start meets a run on the same workflow ID

**A start of the application's own code** that meets a run is refused in the application. When the application meant to put the start on its queue, the SDK refuses it before it queues anything, and the platform sees nothing. When the application ran the start in the calling process, the platform sees an announcement and a report that says `refused` with `run_in_flight` for a run ID that never ran (section 6.6). The platform marks that start spent and shows it to nobody: a start of the application has no operator.

**A start of the platform** that meets a run gives the same report, with the origin `platform` and `claimed` true. The platform marks the start spent and shows the code to an operator.

In both cases the snapshot names the run that holds the workflow ID. When the platform has never heard of that run, it adopts it (section 7.5).

A start never replaces a run, also when that run has failed.

### 9.19 An abandon, case by case

An operator asks the platform to abandon a run.

| The run is | The platform sends | The endpoint answers | The platform |
|---|---|---|---|
| Suspended or failed, and the platform holds its current attempt | `abandon` with that attempt | `200`, `run` null | Closes the run as abandoned |
| Suspended, and it moved on since the platform's snapshot | `abandon` with the old attempt | `409 stale_attempt`, with the run at the new attempt | Sends the abandon again with the attempt shown |
| A start that still waits in the application's queue | `abandon` with no attempt | `200`, `run` null | Closes the run as abandoned. The worker that takes the start later reports `rejected` with `already_claimed`, and the run stays closed |
| A start that a worker has claimed and not yet written | `abandon` with no attempt | `409 stale_run`, `run` null | Inspects. What it does when the answer shows no run yet is Q1 |
| Already gone, and its run ID is in the ledger: a run started through a queue that completed, or a run that an earlier copy of the abandon dealt with | `abandon` | `409 stale_run`, `run` null | Reads the snapshot, which closes the run as ended with an unknown outcome when an earlier snapshot named it. Otherwise the platform cannot tell this case from the one above: it inspects, and what it does when the answer shows no run is Q1. Reference simulator: it closes the run as ended with an unknown outcome |
| Already gone, and its run ID is not in the ledger: a run that the application started in the calling process and that completed, or a run whose ledger entry was dropped (section 6.7) | `abandon` | `200`, `run` null, `claimed` true | Closes the run as abandoned. The `200` does not say that a run was discarded (section 6.8) |
| Not the run that holds the workflow ID, such as a start that never ran while the application started a run of its own | `abandon` | `409 stale_run`, with the other run | Reads the snapshot. Adopts the other run when it has never heard of it. For the run of the abandon: section 6.8 |
| Being executed by a worker | `abandon` with the attempt shown | `409 executing`, with the run | Leaves the run open. When to try again is not settled (U24) |
| A chat agent whose conversation ends on a tool call nobody answered | `abandon` | `409 abandon_refused` | Leaves the run open. What becomes of the request is not settled (U25) |
| Started by another definition than the platform names | `abandon` | `400 invalid_request` | Never sends it again. Alerts |

### 9.20 Every notice of a run is lost

The application started a run in a web request, and its queue lost both the announcement and the report. The platform has never heard of the run. The run sleeps on a timer that nobody will fire.

**The platform sees** nothing, until a snapshot of that workflow ID reaches it for another reason: the report of another segment on that workflow ID, such as a start that was refused with `run_in_flight`, or the answer to an `inspect` or an `abandon` of another run of that workflow ID.

**It concludes**, from `run.definition`, which definition started the run.

**It does this:** it adopts the run under that definition when the manifest holds it (rule R3), and from then on wakes it like any other run. Its timer fires.

A run whose snapshot shows a null `definition` cannot be adopted. Its timer fires only after an announcement or a report of its own has arrived.

### 9.21 A start expires

A start waited in the application's queue past its use-by time.

**The platform sees** a report that says `rejected` with `expired`, `claimed` null and no announcement before it (section 6.6). Or it sees an announcement first, when the worker took the start in time and the use-by time passed before the claim.

**It concludes** that this copy did not run. It does not conclude that the start never ran: another copy may have.

**It does this:** it never sends the start again, and asks 300 seconds after the use-by time. The answer shows no run and `claimed` false, and the platform closes the run as lost.

### 9.22 The endpoint holds another signing key, or the request was altered

**The platform sees** `401` with `invalid_signature`.

**It concludes** that the endpoint does not verify its signature. It cannot tell why.

**It does this:** it stops every delivery to that endpoint, keeps everything it had planned, alerts, and pings every 60 seconds. When a ping succeeds, the ping of an accepted sync included, it resumes and sends what was kept. While the endpoint holds the wrong key, a sync fails with `422 endpoint_unverified`, which fails the application's deploy.

A request that somebody altered on the way gets the same `401`, and nothing of it reaches the application's queue.

### 9.23 The application's API key is wrong or revoked

**The platform sees** requests with a key it does not accept, and answers each `401` with `unauthorized`.

**The application** keeps executing. Every announcement and every report ends as a failed job in its queue, because `401` is final for the SDK.

**The platform** holds nothing for the runs that were started meanwhile, so it fires no timer and no retry for them. When a person fixes the key and replays the failed jobs, the notices arrive, and the platform catches up as in section 9.7.

### 9.24 A worker dies between the claim and the first write of the run

A worker claimed the start in the ledger and died before the run was written.

**The platform sees** an announcement and no report. When the lease of the announcement has passed, its `inspect` shows no run and `claimed` true (the example of section 6.11).

**It concludes** that the start will not run again. It cannot conclude that it never ran: a run that completed in a worker which died before reporting leaves exactly the same answer.

**It does this:** it sends nothing. At 300 seconds after the use-by time it asks once more and closes the run as ended with an unknown outcome. It shows the run to an operator with the warning that the workflow may have run.

### 9.25 A message names another definition

The platform holds a run under one definition and the store says that another one started it.

| The platform sends | The application answers | The platform |
|---|---|---|
| `wake` | `202`, then a report that says `rejected` with `wrong_definition` (section 6.6) | Never sends that wake again. Alerts |
| `inspect` | `400 invalid_request` | Never sends it again. Alerts |
| `abandon` | `400 invalid_request`. Nothing was written | Never sends it again. Alerts |

One detail: the endpoint looks up the definition that the message names before anything else. When that definition has no version equal to the one at the front of the run ID, a `wake` is answered `404 unknown_version` and never reaches a worker.

The platform avoids all three by comparing the definition before it sends (section 7.11, step 5). What it then does with the run is not settled (U9).

---

## 10. Durations and limits

One table for every number of the protocol. The third column says who can change the value: nobody (fixed by the protocol, with what enforces it), the platform (a setting), or the application. A value that follows from another one says so.

| Duration or limit | Value | Who sets it | It must exceed |
|---|---|---|---|
| Signature window | 300 seconds either way | Fixed. The SDK's endpoint enforces it | The clock difference between the platform and any host of the application |
| Longest body of a message to the endpoint | 65,536 bytes | Fixed. The SDK's endpoint enforces it | |
| Deepest nesting of a body | Fewer than 512 levels, the body counted as the first | Fixed. The SDK's endpoint enforces it | |
| The SDK waits for the answer to an announcement or a report | 3 seconds | Fixed. Built into the SDK | The time the platform needs for a durable write |
| The SDK waits for the answer to a sync | 15 seconds | Fixed. Built into the SDK | The ping plus the platform's own work |
| The platform waits for an answer of the endpoint | 10 seconds. The ping of a sync and the platform's own work must end within the 15 seconds above | Setting of the platform | |
| Use-by time of a start of the platform | 600 to 3600 seconds after the first send | Setting of the platform inside that range, which is fixed. The SDK's endpoint refuses more than 3600 seconds plus the signature window ahead of its clock. The application does not enforce the lower bound | The delay the application's queue can add |
| A ledger entry | Kept at least until one hour after the use-by time of its start | Fixed. Built into the SDK | |
| The ledger entry that an abandon writes | A use-by time one hour ahead | Fixed. Built into the SDK | |
| Lease, `leaseSeconds` | 600 seconds unless the developer sets another. 60 at least in a sync | The application. The SDK refuses to publish less than 60 | The slowest step of the workflow, plus the clock difference between the application's own hosts |
| Segment silence | The `leaseSeconds` of the announcement | Follows from the lease | |
| Lease of a run shown `running` | `leaseExpiresAt` minus the `at` of the snapshot, counted from the arrival of the snapshot | Follows from the snapshot | |
| Wake silence | 900 seconds after the `202`, or after the last copy of a wake that got no usable answer | Setting of the platform | The delay the application's queue can add |
| Start silence | Until `expiresAt` plus 300 seconds | Setting of the platform | It must not be shorter than the signature window |
| Wait before a wake that came too early is sent again | 1 to 60 seconds | Setting of the platform | |
| Back-off before a message is sent again | 5 seconds, doubling to 300 | Setting of the platform | |
| Copies after a `500`, a `3xx` or no answer | 5, which takes 155 seconds | Setting of the platform | |
| Pace of an `inspect` or an `abandon` after its last copy | No value in the protocol (U18) | Setting of the platform | |
| Ping after a `401` | Every 60 seconds, until one succeeds | Setting of the platform | |
| `Retry-After` of a `503` of the endpoint | 5 seconds today | Fixed. Built into the SDK. The platform reads the header and does not assume the value | |
| Wait before asking for a snapshot that was not read | 30 seconds, doubling to 600 | Setting of the platform | |
| Wait before asking after two snapshots with the same `at` | 1 second | Setting of the platform | One whole second |
| Reading a wait with no timer | Daily at first, then less often, at least every 7 days (U20) | Setting of the platform | |
| Reading a run that runs with no lease | No value in the protocol (U20) | Setting of the platform | |
| Report clock alert | `at` more than 300 seconds from the platform's clock | Setting of the platform | |
| An `inspect` can trust `claimed` false | Until one hour after the use-by time of the start | Follows from the ledger | |
| Retry policy | No value in the protocol (P3) | Setting of the platform | |
| Requests in flight to one endpoint | No value in the protocol (P5) | Setting of the platform | |
| How often the application's queue tries a notice again | After 10, 30, 60 and 300 seconds, then every 900 seconds, without a limit | The application | |
| `version` | 1 to 999999 | Fixed | |
| `definition` | 1 to 64 characters | Fixed | |
| `workflowId` | 1 to 255 characters | Fixed | |
| `runId` of any form, `invocationId` | 1 to 128 characters | Fixed | |
| `runId` that the platform or the SDK mints | 3 to 127 characters: a version of 1 to 6 digits, a dash, and a unique part of 1 to 120 characters | Fixed. The SDK's endpoint refuses a `start` whose run ID has another form | |
| `errorClass`, `eventName`, the `sdk` of a sync | No maximum length | Fixed: the schemas set none | |

---

## 11. What is not settled

The application's side is finished. The platform's side has three kinds of open point, and this section numbers them so that each can be answered by its number.

- **Q1 to Q6** are questions for whoever decides the protocol, each with a recommendation. An answer can change a rule of this document.
- **U1 to U27** are points on which no source says what the platform does, although it must do something.
- **P1 to P7** are decisions of the platform's product. The protocol does not make them: it only bounds them.

For each point of the first two lists the text says what the reference simulator does today. That is one implementation's choice, never a rule.

### 11.1 Six open questions

**Q1. An abandon that meets a start which a worker claimed a moment before** (question 15 of `SDK_DESIGN.md`).
The endpoint answers `409 stale_run` with no run, because the run is not written yet (section 6.8).

| | |
|---|---|
| Recommended | The platform inspects at once, closes nothing, and keeps the abandon pending until the `expiresAt` of the start plus 300 seconds. As soon as a snapshot shows the run, it abandons it with the attempt shown |
| Its cost | An operator can wait up to 65 minutes for the final word |
| The alternative | The operator's request ends at the first `inspect`. When that shows no run, the platform closes the run as ended with an unknown outcome. A run that appears afterwards is open again, and the abandon is forgotten |
| Reference simulator | The alternative |

**Q2. The order of two snapshots** (question 17).
`at` is in whole seconds, and two hosts of one application can differ by a few seconds.

| | |
|---|---|
| Recommended for protocol 1 | Keep whole seconds. On a tie the platform closes nothing and inspects one second later (section 7.5) |
| Its cost | One more request for each tie, and no protection against clocks that differ: a snapshot that shows no run, taken by a host a few seconds ahead, can close a run that another host started a moment later. When that run sleeps on a timer, nothing opens it again |
| The real fix | A counter kept with the run in the application's store and raised at every write, which needs a change in the application's engine |
| A stopgap | A margin in the rule that closes a run by absence, or an `inspect` before the platform closes a run on a difference of a few seconds |
| Reference simulator | The recommendation: it compares `at` exactly, and inspects after a tie |

**Q3. A sync that waits for an owner to confirm an endpoint move** (question 18).
The platform must not apply the sync before the owner confirms (section 7.15), and it has to answer something.

| | |
|---|---|
| Recommended | A new final code for a sync, `endpoint_unconfirmed`, answered `409`, so that the deploy fails with a message that says what a person must do. It needs one more code in the SDK: today the SDK reads a `409` with that code as "not confirmed", like any answer it does not know |
| The alternative | `422 endpoint_unverified`, which blames a ping that never happened |
| Reference simulator | The alternative, and an alert |

**Q4. When the count of the retry policy starts again** (question 19).

| | |
|---|---|
| Recommended | When a segment of the run ends without failing. The policy then counts failures in a row |
| The alternative | Failures count over the whole life of a run. A long run that fails once at each of four steps then exhausts a policy of 3 retries although every retry worked |
| Reference simulator | The alternative |

**Q5. The invocation ID of a wake that the platform decides again** (question 21).
The invocation ID of an order is the same on every copy, so two copies of one wake can each post an announcement and a report under one ID. The platform then has to tell which report answers which announcement.

| | |
|---|---|
| Recommended | A new invocation ID for every wake that the platform decides again: after "too early", and after the `inspect` that follows a silence. The same invocation ID only when the platform sends the same request again because its delivery failed. This changes nothing on the wire. The recommendation does not name a wake that is sent again after a report `aborted`, nor one that is sent again after another report whose call executed no segment (U26) |
| The alternative | The platform pairs announcements and reports of one invocation ID by `at`. It can then take the report of a first copy for the report of a second copy whose worker died, so that nobody asks about that segment |
| Reference simulator | The alternative. It keeps one invocation ID for the timer of a wait, or for the retry of an attempt, through every copy. Section 12.3 says how it pairs |

**Q6. A start that the application put on its own queue, and that expires there** (question 22).
The use-by time of such a start is one hour after the application queued it, and the platform is never told it. The platform therefore cannot apply R12 to it as written.

| | |
|---|---|
| Recommended | No new field. The platform asks about a run of the application that it knows only from reports which show no run, 300 seconds after its last report. It closes the run as lost when nothing claimed it, and as ended with an unknown outcome otherwise (section 7.10) |
| The alternative | `expiresAt` travels in the announcement and in the report of a start: one field in two messages |
| Reference simulator | The recommendation, with one precision: it closes the run as lost only when the answer says `claimed` false and the outcome that it holds for a start of that run is `rejected` with `expired`. A false `claimed` alone proves nothing for a run of the application: a start that ran in the calling process is never claimed. It applies the same test to a run of the application that was announced, which the rule closes as ended with an unknown outcome whatever `claimed` says (section 7.10) |

### 11.2 What the protocol does not say

| # | Point | What is not said | Reference simulator |
|---|---|---|---|
| U1 | Answers of the endpoint that the status table of section 5.9 does not list | Whether the status or the code decides for a refused or reset connection, a TLS or DNS failure, a `403`, `408`, `429`, `502` or `504`, another `2xx`, a `404` or `503` with no `code`, and a `200`, `202` or `409` with a broken body | It reads the body of a `200` and of a `409` only, so the status decides for every other answer. It counts a `202` to a `start` or a `wake` as accepted whatever its body. After a `404` or a `503` with no `code` it does what the table says for that status. It counts a `200` or a `409` whose body does not validate against its schema as no answer, and alerts. It treats every other answer, a failed connection included, like a `500` |
| U2 | A body of the application that the schemas cannot bound or check | The largest sync or notice that the platform must accept: `sdk`, `errorClass` and `eventName` have no maximum length. Whether the platform must refuse a body in which a number is written `1.0`, or a field name is repeated | It sets no limit of size. It answers `400` to a number written with a fraction or an exponent: with `unsupported_protocol` when the number is `protocol` or an entry of `protocols`, with `invalid_request` otherwise. It answers `400 invalid_request` to a body that its JSON parser refuses. Of two fields with the same name it reads the last |
| U3 | A value that the platform does not know | What the platform does with a notice that holds a value outside the lists of its schema: an outcome, a code, a verb, a status or a wait type that a later SDK adds. The rule "a receiver ignores the fields it does not know" is about fields | It validates against the schema, which refuses such a value, and answers `400 invalid_request`. That answer is final for the SDK: the notice ends as a failed job in the application's queue |
| U4 | A sync | What counts as a change for `modified`. What the platform does with a definition and version that the list names twice, and with two syncs that arrive at the same time | For `modified` it compares the list entry by entry and in order, `leaseSeconds` included, and the endpoint URL as text; it does not keep `sdk`. Of a pair that is named twice it keeps the last entry. It handles one request at a time |
| U5 | A routable endpoint | What "publicly routable" means. Whether the platform checks it at the sync only, or at every delivery. Which answer a sync gets when the endpoint is not routable | It refuses `localhost`, an address in a private or reserved range, and a name that leads nowhere. It checks at the sync only. It answers `422 endpoint_unverified`, and sends no ping |
| U6 | Moving an endpoint | What "another host" is: the host name, or the scheme, the host and the port. How the owner's consent reaches a later sync | The host name. It asks for no consent at the first sync of an environment. When the owner confirms, it records the host that was confirmed and applies nothing: the developer runs the sync again, and that sync passes |
| U7 | Stranded versions | What becomes of a `start` or a `wake` that is due for a version which left the manifest | It keeps the order planned, and sends it when a later sync publishes the version again |
| U8 | A run ID that the platform holds under another workflow ID | What the platform does with an announcement, a report or a snapshot that carries such a run ID. A minted run ID cannot repeat, but one of another form is chosen by code of the application | It keys a run by its run ID alone, and keeps the workflow ID and the definition of the first message that named it. It records the notice. It reads the snapshot as one that names no run of its workflow ID. A `completed` report closes the run that it holds under that run ID |
| U9 | A run that the platform holds under a definition which did not start it | Whether the platform ever moves the run to the definition that a snapshot shows, and what an operator can do with such a run: every `wake`, `inspect` and `abandon` of it is held back or refused. The same for a run whose tag does not have the form of a definition name (section 2.5) | It never changes the definition of a run. When the last snapshot shows another definition, it sends the run nothing and alerts. When the snapshot shows none, it sends, and the application refuses: after a wake reported `rejected`, it plans no other wake for that wait, or that retry, at that attempt |
| U10 | Two reports under one invocation ID that differ in outcome | Which outcome the platform keeps for the segment. Two copies of one start give such reports: one `suspended`, the other `rejected` with `already_claimed` | It keeps the outcome, the code and `claimed` of the report that arrived last. What it does for Q6 reads that record |
| U11 | A snapshot that shows a run `completed` | Whether it closes the run. It can only be a completion that code outside the SDK kept | It closes the run as completed. After a `409` with the code `completed` to an abandon, the operator's request ends there |
| U12 | A `completed` report for a run closed as abandoned | Whether the report changes the state. One sentence of the rule says that a `completed` report closes its run, another that a run closed as abandoned stays closed | It closes the run as completed |
| U13 | A start reported `refused` with `stale_attempt` and an `executedAttempt` | Whether it is spent. The call executed a segment (section 6.6), and the rule for a refused start makes no exception for it | It closes the run as spent, with the code `stale_attempt`, when no snapshot ever named the run and the run is open. A later snapshot that names the run opens it again |
| U14 | A start that waits for its first send | How long it may wait while deliveries are stopped or held back. Whether the version at the front of its run ID follows a sync that raises the current version in the meantime | It waits without a limit. It mints the run ID when the trigger fires and never changes it, so the start runs on the version that was current then, while the manifest holds that version |
| U15 | A start whose copies ran out | R13 treats it as accepted and silent, and R12 asks about it only after its use-by time, when it can no longer be sent. An endpoint that is away for more than 155 seconds therefore loses a start sent in that time, although its use-by time has not passed. Whether the platform should ask sooner is not said | It asks about such a start 900 seconds after the last copy too, while its use-by time has not passed, and sends it again when the answer shows it unclaimed with no run. With the default values that helps only a start whose use-by time is more than about 20 minutes ahead |
| U16 | Counting copies | Whether the count of copies and the back-off start again when another answer comes in between | Never, for a `start` or a `wake`: it counts for the life of the order. For an `inspect` or an `abandon` it counts in a row the times it got no usable answer (section 12.3) |
| U17 | A message that is sent again without end | A limit, or an alert, for a wake that is reported "too early" or `aborted` again and again, and for a message that is answered `404` again and again while its version is in the manifest | No limit and no alert. The wait between two copies grows to 300 seconds; for "too early" it stays at the setting |
| U18 | An `inspect` or an `abandon` that fails | The pace after its last copy. Whether "never sends it again" after a `400` or a `405` means that the platform never asks about the run again. What becomes of an operator's request when the abandon is answered `404` and the version is not in the manifest | It sends the message every 300 seconds. After a `400` to an `inspect` it alerts, holds back every other question about that run for one day, and never asks about a silent start again: such a start stays open, and its version never shows 0 open runs. It keeps an abandon that was answered `404` pending, and sends it when a sync publishes the version again |
| U19 | Silence after an announcement | Whether `leaseSeconds` count from the `at` of the announcement or from its arrival. Whether the platform asks about a silent announcement of a run it holds closed. What follows the `inspect` when it shows the same wait and attempt | It counts from `at`. It does not ask about a closed run. It sends a wake of its own again, and does nothing for a segment of the application |
| U20 | Periods with no value | "Now and then" for a run that runs with no lease. The shape of "daily at first, then less often" for a wait with no timer | After 1 day, then 2, then 4, then every 7 days, for both. It starts again from 1 day when a read shows that the run changed |
| U21 | Open runs that nothing reads | Whether the platform reads from time to time a run that the retry policy gave up on, or a run whose wake is held back for a stranded version. The application can discard either without a notice | It reads neither. Such a run stays open until a notice or a snapshot of its workflow ID arrives |
| U22 | A run that the retry policy gave up on | Whether the platform sends one more wake at an operator's request | It has no such request. A change of the retry policy that allows one more retry makes it plan the wake again |
| U23 | An abandon of a run that the platform has heard of and holds no attempt for | R2 allows neither form for a run that was only announced, or reported with no run | For a start that the platform sent itself: an abandon with no attempt. For a run that the application started: it refuses the operator's request |
| U24 | An abandon answered `409` that shows the run | How many times the platform sends the abandon again, and how soon, when the answer shows another attempt, `executing` or `conflict` | 5 abandons in all, each at once, then it ends the request as refused. It treats every `409` that shows the run alike, except `abandon_refused` |
| U25 | An abandon answered `409` with `abandon_refused` | What becomes of the operator's request. The rule is to plan from `run` and never from the code, and no rule says what follows when a chat agent keeps its run | It ends the request as refused on that code, and sends the abandon no more |
| U26 | A wake whose report shows that nothing ran | Whether the platform sends a wake again, and how soon, when three things are true of the report of that wake: its call executed no segment, it is neither "too early", `aborted` nor `rejected`, and its snapshot still shows the wait, or the failed or dead attempt, that the wake was sent for. Two reports are the case: an `observed` report of a wake whose worker died before the wake was admitted, and a report `refused` with `conflict`. The rules name three wakes that are sent again: after "too early", after a silence, and after `aborted`. The fence makes any other copy harmless (section 5.5), so the platform may send one: no rule says that it does, nor how soon | It sends the wake again after the back-off, for the wake of a timer and for a retry alike, and counts no new failure |
| U27 | A run ID that is not minted, under a definition of which the manifest holds no version | Which version such a run counts for. It counts as the lowest version of its definition in the manifest, and no rule says what that is when the manifest holds none. The answer to a sync needs one: an entry of `stranded` requires a `version` | It counts such a run for version 1 |

### 11.3 What the platform's product decides

The protocol does not design what follows. Each row says what the protocol requires of the decision, so that the reader stops looking for the rest in this document.

| # | The product decides | What the protocol requires of it |
|---|---|---|
| P1 | Triggers: how one is configured, the form of a schedule, how it builds the workflow ID, what identifies a firing, and what becomes of a firing that was missed while the platform was down. Whether a firing is refused or kept when its definition is not in the manifest, when the platform holds an open run on the workflow ID, or when deliveries are stopped. Whether a trigger that keeps producing spent starts is stopped | A firing issues one start, and a firing that reaches the platform twice issues nothing the second time (R4). A start is issued only for a definition that is in the manifest, on its current version (section 7.7). The workflow ID has the format of section 4 and stays within 255 bytes. A start on a workflow ID that a run already holds is safe: the application refuses it, and the platform marks it spent (section 9.18). The platform never fires again by itself for a start that is spent, lost or ended with an unknown outcome (section 7.6). Reference simulator: a call of its operator API fires a trigger and may name the firing. It refuses a firing for a definition that no sync published, and checks nothing else |
| P2 | Environments and keys: how an environment is created, how its API keys and its signing keys are issued, and how long a key is | An API key identifies one environment (section 2.1). A signing key is `whsec_` followed by the base64 of its bytes, and the endpoint uses any key of one byte or more (section 3.6). During a rotation the platform signs with every active signing key and accepts the old and the new API key (section 3.7) |
| P3 | The retry policy: its default, whether it varies by definition or by `errorClass`, and the instant from which its delay counts | A retry is a wake with the attempt that the snapshot shows (R2, R9). One failure is counted for each run ID and attempt. With no policy the platform retries nothing, and every failed run stays failed. Q4 is about when the count starts again. Reference simulator: 3 retries, 30 seconds apart, one policy for every run. The delay counts from the moment it counted the failure |
| P4 | Retention: how long the platform keeps a closed run, an order, a segment and a firing | An open run is kept: it counts in every sync answer, and the platform may have to wake it. A start is kept at least until the platform has asked about it, 300 seconds after its use-by time (R12). A firing is kept for as long as the trigger can deliver it again (R4). Beyond that the protocol names no period, and two rules look back without a limit, because the application posts an undelivered notice again without end (section 5.2): the alert on an invocation ID that the platform never sent (R18), and the rule that a run closed as completed or abandoned stays closed (R7). A platform that has forgotten an order alerts on a late notice of it. A platform that has forgotten a run holds it again, as a run it has heard of. Reference simulator: it deletes nothing |
| P5 | Flow control: how many requests are in flight to one endpoint, and how timers that are due together are spread | The platform limits both, and honours `Retry-After` for every message to the endpoint (R17). Reference simulator: one request at a time, the longest due first (section 12.3) |
| P6 | Who may change a setting, and at which level it holds: the whole platform, one environment or one definition | The bounds of section 10. Two of them depend on the application: the lifetime of a start and the wait for the announcement of a wake must exceed the delay that the application's queue can add. Reference simulator: one set of settings for its one environment |
| P7 | What an operator sees and can ask for: the dashboard, how an alert reaches a person, how an owner confirms an endpoint move, what is shown when an abandon cannot be delivered | The platform shows a start that is spent, lost or ended with an unknown outcome, with what section 7.6 says of each. It raises the alerts of section 7.16. It sends an abandon only when an operator asks (R5), and moves an endpoint to another host only after an owner confirmed (R16) |

---

## 12. How to check an implementation

Four things in the repository of the SDK let the platform's engineer check an implementation without reading the SDK's code. The first two are files that any language can read. The last two need PHP.

### 12.1 The JSON Schemas

`resources/protocol/1/` holds one JSON Schema for each message and each answer, in draft 2020-12. They refer to each other by relative file name and carry no `$id`, so a validator needs the directory as its base.

| What | Schema |
|---|---|
| The sync, and its `200` answer | `sync.json`, `sync-answer.json` |
| An announcement, a report | `announcement.json`, `report.json` |
| The `200` answer to an announcement or a report | `recorded.json` |
| A refusal of the platform | `platform-error.json` |
| The five messages of the platform | `start.json`, `wake.json`, `inspect.json`, `abandon.json`, `ping.json` |
| The requested sync of section 5.11, and its `200` answer | `requested-sync.json`, `sync.json` |
| The `200` answer to an `inspect` or an `abandon` | `inspect-answer.json` |
| The `200` answer to a `ping` | `ping-answer.json` |
| Every answer of the endpoint that is not `200` or `202`, the `409` to an abandon included | `error.json` |
| The formats, the codes, the `run` and the `wait` objects | `definitions.json` |

How the platform uses them:

- **On everything it sends**: its five messages, the requested sync and its three kinds of answer validate, in its own test suite.
- **On everything it receives**: it validates a sync, an announcement and a report before it uses them, and answers `400` to one that does not validate, with the code that section 5.1 or 5.2 gives: `unsupported_protocol` for the protocol version, `invalid_request` otherwise. Whether it refuses a body that fails only for a value which the schema does not list is not settled (U3). It validates the body of a `200` and of a `409` of the endpoint. What follows one that does not validate is not settled (U1).

What the schemas do not check in a message of the platform, and the platform must check itself before it sends: that no number is written with a fraction or an exponent; that the body is no longer than 65,536 bytes; that the `invocationId` of a `start` or a `wake` equals the `webhook-id` header; the bounds of `expiresAt`. For a body that the platform receives, whether it must make the first check, and which size it must accept, are not settled (U2). The schemas accept fields they do not know, as the protocol requires.

### 12.2 The signature vectors

`resources/protocol/1/signature-vectors.json` holds 19 vectors and `toleranceSeconds`, which is 300. Each vector has:

| Field | Meaning |
|---|---|
| `key`, `id`, `timestamp`, `body` | The signing key, the `webhook-id`, the `webhook-timestamp` and the body of a request |
| `signature` | What a signer that holds `key` produces for them. Null when it cannot sign: no usable key, or a timestamp that is not a number |
| `header` | A `webhook-signature` header that a request arrives with |
| `now` | The clock of the receiver |
| `valid` | Whether a receiver that holds `key` accepts the request, with that header, at that clock |

The platform is the signer. For each of the 13 vectors whose `signature` is not null, its signing code must produce exactly that value from `key`, `id`, `timestamp` and `body`. The first vector is the published vector of Standard Webhooks.

The `valid` field shows what the application's endpoint does, which the platform does not implement but must not contradict: two signatures separated by a space are accepted when one matches; a timestamp exactly 300 seconds off is accepted and one of 301 seconds is not; a signature without `v1,` is refused.

### 12.3 The reference simulator

`tests/Support/Simulator/` is a small platform: the platform's side of protocol 1, in about 3,700 lines of PHP, with SQLite as its memory. It shares no code with the SDK. It is the one existing implementation of the rules of section 7, and the end-to-end tests run the real SDK against it.

It is a test instrument and not a specification. It serves one environment, handles one request at a time, deletes nothing, and sends nothing until a test moves its clock. It follows the rules of section 7, with the choices that section 11 names. Two differences from those rules are known. After a report whose snapshot was not read, it plans no `inspect` for the run of that report when it holds that run closed: because that same report closed it, as a `refused` report of a start does (section 6.6), or because the run was closed before. And it plans none after a `200` answer to an abandon whose snapshot was not read (section 6.8). Where it and this document differ in another way and section 11 does not say so, this document describes the intent, and the difference is worth reporting.

Three things about how it works explain what a test shows.

- **It stores no timer.** It keeps the platform's own facts of section 7.2 as columns of its table of runs. Each time a test moves its clock, it computes what is due from what it holds, and sends one message at a time: at most 20 in one pass, the longest due first, and at most one for a run.
- **It counts copies in two ways (U16).** For a `start` or a `wake` it keeps two numbers for the life of the order, and never sets either back: how many times the order was sent, and how many of those sends got no usable answer (section 5.9). The back-off after no usable answer doubles with the second number, and the sixth send of that kind makes the order count as accepted and silent, whatever was answered in between. The back-off after a `404`, after a `503` with no `Retry-After` and after a report doubles with the first number. For an `inspect` or an `abandon` it counts in a row the times it got no usable answer, and the `404` apart from them: the count starts again after an answer that it could read, and a `503` or a `401` in between leaves it as it is.
- **It pairs reports with announcements by `at` (Q5).** It holds one record for each invocation ID, with one `at` for its announcements and one for its reports. It takes a segment as reported when the `at` of the report is later than the `at` of the announcement, or when the two are equal and the report did not arrive before the announcement. It therefore asks about an announcement that arrives after a report of the same second: that announcement may be the one of a second copy of the order, whose worker has not reported. It takes a wake as announced when an announcement or a report with the invocation ID of the wake arrived, by its own clock, no earlier than the `202`, or than the last copy that counts as accepted and silent.

| File | What it shows |
|---|---|
| `Settings.php` | Every duration and count the simulator uses, with its default |
| `Database.php` | What a platform holds: the tables and their columns (section 7.2) |
| `Intake.php` | Taking in a sync, an announcement and a report (sections 7.3, 7.4, 7.15) |
| `Runs.php` | Reading a snapshot, closing and reopening a run, counting failures (sections 7.5, 7.6, 7.9) |
| `Plan.php` | What the platform will do next about a run, and when: every timer and every reason to ask (sections 7.7 to 7.13) |
| `Clockwork.php` | Sending what is due, and what follows each answer of the endpoint (sections 5.9, 7.12, 7.14) |
| `Endpoint.php`, `Signer.php` | Signing, sending, reading an answer, stopping and resuming deliveries |
| `Manifest.php`, `Orders.php`, `Segments.php`, `Alerts.php` | The manifest, the orders the platform sent, the segments it heard of, the alerts |
| `Operator.php` | What a test asks of it: fire a start, ask for an abandon, read what it holds, change a setting, move its clock |

It runs on PHP's built-in web server:

```text
SIMULATOR_DATABASE=<file> SIMULATOR_WIRE_TAP=<file> \
SIMULATOR_API_KEYS=<key> SIMULATOR_SIGNING_KEYS=<whsec_...> \
php -S 127.0.0.1:<port> tests/Support/Simulator/server.php
```

Besides the three paths of the protocol it answers an operator API under `/_operator/`, which is how a test drives it: `POST starts` fires a start, and may name the firing with a text such as `nightly-2026-10-09`, so that a second call with that text issues nothing and returns the run of the first; `POST abandons` asks for an abandon, `POST tick` sends whatever is due, `GET runs/<run ID>` shows everything it holds for a run with what it plans next, `GET alerts`, `GET manifest`, `GET wire` returns every message it received or sent with its answer, `PUT settings` changes a duration, `PUT tunnels` tells it which local address stands behind a public `https` origin, which is what makes a name routable for it, and `POST endpoint-moves` confirms a move. The log of the wire exists for the tests only: a real platform must not keep bodies (section 7.2).

To compare an implementation with it, send both the same notices and the same endpoint answers, and compare what each sends next.

### 12.4 The end-to-end tests

`tests/EndToEnd/` holds the scenarios. Each one starts the simulator and a real application with its web server, its queue and its worker processes, makes something go wrong, and checks both sides. `vendor/bin/phpunit tests/EndToEnd` runs them all in about a minute and a half.

| Test | It plays | Section |
|---|---|---|
| `ExampleATest` | Four orders from deploy to completion: started by the platform, by the application on its queue, by the application in a web request, and one whose step fails once | 9.1, 9.2, 9.3, 9.11 |
| `FailureTable/Row01…`, `Row02…` | A start delivered twice; a copy after the run completed | 9.4 |
| `FailureTable/Row03…` | A start past its use-by time | 9.21 |
| `FailureTable/Row04…` | A wake or an `answer` segment delivered twice | 9.5 |
| `FailureTable/Row05…` | A report that is lost | 9.6 |
| `FailureTable/Row06…` | The platform down | 9.7 |
| `FailureTable/Row07…` | A worker killed in the middle of a segment | 9.9 |
| `FailureTable/Row08…` | A slow worker taken for dead | 9.10 |
| `FailureTable/Row09…` | A step that throws; a policy that gives up | 9.11 |
| `FailureTable/Row10…` | The store failing at four moments | 9.12 |
| `FailureTable/Row11…` | A job accepted and never run | 9.13 |
| `FailureTable/Row12…` | A deploy and its versions | 9.14 |
| `FailureTable/Row13…` | A timer that fires early | 9.15 |
| `FailureTable/Row14…` | An early event; an approval sent twice | 9.16 |
| `FailureTable/Row15…` | A browser that closes a streamed segment | 9.17 |
| `FailureTable/Row16…` | A start that meets a failed run | 9.18 |
| `FailureTable/Row17…` | A wrong signing key, an altered body, a replayed request | 9.22, 3.5 |
| `FailureTable/Row18…` | A worker that dies after the claim | 9.24 |
| `FailureTable/Row19…` | A wrong API key | 9.23 |
| `AbandonTest` | An abandon of a waiting run, of a queued start, and of a start that a worker claimed a moment before | 9.19 |
| `LostNoticesTest` | Every notice of a run lost, and the run adopted from a snapshot: of a report, and of the `409` answer to an abandon | 6.8, 9.20 |
| `EndpointAwayTest` | The endpoint away for longer than the copies last | 9.8 |
| `WorkerLoopTest` | What an application's worker does with a notice the platform did not take | 5.2 |
| `Simulator/CorrectnessRulesTest` | R1 to R5, one case at a time | 7.1, 9.25 |
| `Simulator/SnapshotRulesTest` | R6 and R7 | 7.5, 7.6 |
| `Simulator/TimerRulesTest` | R8 to R12 and R14 | 7.7 to 7.10, 7.13 |
| `Simulator/DeliveryRulesTest` | R13, R17 and every status of the endpoint | 5.9, 7.11, 7.12 |
| `Simulator/IntakeRulesTest` | R15, R16 and R18 | 7.2, 7.15, 7.16, 9.25 |

The five `Simulator/` classes use no application: the test plays the application and scripts the answers of the endpoint. Their test names read as the rules of section 7, and they are the fastest way to see one rule in one case.

### 12.5 What most often goes wrong

**In what the platform sends**

1. It signs one serialization of the body and sends another. Sign the bytes, send the same bytes.
2. It sends an order again with a new invocation ID, a new run ID or a new `expiresAt`. A copy is the same message. Only the timestamp and the signature are new. One case is open: the invocation ID of a wake that the platform decides again (Q5).
3. It sends an order again with the old timestamp. After 300 seconds the endpoint answers `401`.
4. It sends a `start` or a `wake` whose `webhook-id` is not the `invocationId` of the body.
5. It writes `"attempt": null` or `"runId": null` where the field must be left out.
6. It writes an integer as `1.0`. Check the JSON encoder with every numeric type the platform stores.
7. It sends two `webhook-signature` headers during a rotation. Send one, with the signatures separated by a space.
8. It adds one to an attempt, or sends a wake with an attempt of an older snapshot. Copy the attempt of the newest snapshot of the run.
9. It names the definition of the message it is answering to. Name the definition of the run (R3).
10. It follows a redirect, or accepts an endpoint on a private address.

**In what the platform concludes**

11. It reads `202` as "started". Nothing has run.
12. It counts a failure from the outcome `failed`. Count it from a snapshot that shows `failed`.
13. It orders snapshots by arrival. Order two snapshots of one run by the attempt, and presence against absence by `at`.
14. It closes a run because a snapshot shows no run, although no snapshot ever named that run. Ask instead.
15. It treats `rejected` with `already_claimed` as a failure of the run. It is the sign that another copy ran.
16. It treats `rejected` with `expired` as "never ran". Another copy may have run.
17. It trusts `claimed` false for a start of the application, or more than an hour after the use-by time.
18. It compares `leaseExpiresAt` with its own clock. Compare it with the `at` of the same snapshot.
19. It replaces a start by itself, or abandons a run by itself. Both need a person.
20. It gives up a timer because the endpoint was away. A wake is not given up for that reason (section 7.12).

**In what the platform answers and keeps**

21. It answers `recorded` before the write is durable.
22. It answers `400 invalid_request` to a notice it failed to store. That answer is final for the SDK: give one that counts as undelivered, such as a `5xx` (section 5.2).
23. It refuses a notice for a run it does not know. Hold the run.
24. It keeps the body of a request in a log.
25. It answers a sync before it has pinged the endpoint, or more than 15 seconds after it received it.

---

## 13. Traces, evaluation results and datasets

Four more requests can reach the platform. None is a message of protocol 1, and no rule of section 7 depends on any of them: a run is coordinated exactly as before whether the platform receives them, refuses them or does not serve their paths. Two hand the platform what the application sends: a trace and the results of an evaluation. The third reads a dataset the platform keeps (13.5), and the fourth what an evaluation found (13.6).

### 13.1 What a trace and the results of an evaluation have in common

| | |
|---|---|
| Who turns them on | The developer, in the application's code: tracing with one call on the SDK, evaluation results by listing the SDK's output in the configuration of the evaluations. Both are off otherwise. **SETTING**, set in the application |
| Transport | As section 3.2: `POST`, `Authorization: Bearer <API key>`, the same `User-Agent`, one JSON object |
| `protocol` field | None. The bodies are not versioned by protocol 1 |
| Answer | Any `2xx` takes the request in, whatever its body. The SDK reads nothing of the answer. **FIXED BY THE PROTOCOL** |
| A refusal | Any other answer, and no answer within the timeout of section 3.8. The SDK writes one line in the application's own log and drops the request: it is never sent again, by the SDK or by a queue. A `401` stops nothing else |
| What they carry | The customer's data, which protocol 1 keeps out (section 1.3): see 13.4 |

The platform must not learn the state of a run from a trace. A trace may be missing, late or sent twice by a process that was run twice, and it is sent by the process that executed the segment before that process reports: only the snapshot of a report or of an `inspect` says what a run is (section 6).

### 13.2 A trace: `POST /v1/traces`

The body is an OTLP `ExportTraceServiceRequest` in its JSON encoding, the body an OpenTelemetry collector takes at the same path: `resourceSpans`, then `scopeSpans`, then `spans`. IDs are lowercase hexadecimal, times are Unix nanoseconds written as decimal strings, and attributes are typed key and value pairs.

```json
{"resourceSpans": [{
  "resource": {"attributes": [
    {"key": "service.name", "value": {"stringValue": "checkout"}},
    {"key": "telemetry.sdk.name", "value": {"stringValue": "neuron-cloud-php"}},
    {"key": "telemetry.sdk.language", "value": {"stringValue": "php"}},
    {"key": "telemetry.sdk.version", "value": {"stringValue": "1.0.0"}}]},
  "scopeSpans": [{
    "scope": {"name": "neuron-core/cloud-sdk", "version": "1.0.0"},
    "spans": [
      {"traceId": "8c1f0e6a4b7d2c935a1e0f4d6b8c2a17", "spanId": "3f9a1c2b4d5e6f70", "parentSpanId": "a1b2c3d4e5f60718",
       "flags": 257, "name": "ChargeNode", "kind": 1,
       "startTimeUnixNano": "1791453603100000000", "endTimeUnixNano": "1791453603900000000",
       "attributes": [
         {"key": "neuron.span.kind", "value": {"stringValue": "node"}},
         {"key": "neuron.run.id", "value": {"stringValue": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12"}},
         {"key": "neuron.workflow.id", "value": {"stringValue": "order:123"}}]},
      {"traceId": "8c1f0e6a4b7d2c935a1e0f4d6b8c2a17", "spanId": "a1b2c3d4e5f60718",
       "flags": 257, "name": "invoke_workflow order-fulfilment", "kind": 1,
       "startTimeUnixNano": "1791453603000000000", "endTimeUnixNano": "1791453604250000000",
       "attributes": [
         {"key": "gen_ai.operation.name", "value": {"stringValue": "invoke_workflow"}},
         {"key": "gen_ai.workflow.name", "value": {"stringValue": "order-fulfilment"}},
         {"key": "neuron.span.kind", "value": {"stringValue": "workflow"}},
         {"key": "neuron.run.id", "value": {"stringValue": "2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12"}},
         {"key": "neuron.workflow.id", "value": {"stringValue": "order:123"}},
         {"key": "neuron.run.status", "value": {"stringValue": "suspended"}}]}]}]}]}
```

One request is one segment. What the platform can rely on:

| Fact | Detail |
|---|---|
| The trace is the run | `traceId` is the first 32 hexadecimal characters of the SHA-256 of the run ID. Every segment of a run sends its spans under that trace ID, so the platform puts a run together by trace ID, or by `neuron.run.id` |
| A segment never sends a span twice | Each request holds only the spans of its segment, under span IDs made for it. There is no span for the whole run |
| Every segment has one parentless span | It is named `invoke_workflow <definition>` or, for an agent, `invoke_agent <definition>`, and it carries `neuron.run.status`: `completed`, `suspended` or `failed`, which is how that segment ended. The other spans of the segment are under it |
| Every span names its run | `neuron.run.id` and `neuron.workflow.id` are on every span, with the attributes the developer gave for all traces, such as `deployment.environment` |
| A segment that never started | One that failed while it was built sends its parentless span alone, with `neuron.run.status` `failed` and the error. The SDK sends no request with no span |
| A trace with no run ID | A listener of the application can keep from the SDK both the start of a segment and the error the framework reports when that listener fails. The spans of that segment then have a random trace ID and no `neuron.run.id`. The platform keeps or drops them; it cannot attach them to a run |

The names and attributes follow the OpenTelemetry semantic conventions for generative AI where they have a word, and `neuron.*` where they do not.

| `neuron.span.kind` | Span name | `kind` | What it measures |
|---|---|---|---|
| `workflow` | `invoke_workflow <definition>` | 1, internal | The segment of a workflow |
| `agent` | `invoke_agent <definition>` | 1 | The segment of an agent. It carries `gen_ai.agent.name` and `gen_ai.conversation.id`, the workflow ID of the agent, which is its thread |
| `node` | The short class name of the node | 1 | One execution of a node |
| `branch` | `branch.<id>` | 1 | One parallel branch. Spans of a branch carry `neuron.branch.id` |
| `middleware` | `<class>.<phase>` | 1 | One phase of a middleware |
| `generation` | `chat <model>` | 3, client | One call to a model: `gen_ai.provider.name`, `gen_ai.request.model`, `gen_ai.response.finish_reasons`, `gen_ai.usage.input_tokens`, `gen_ai.usage.output_tokens` and, when the provider reports them, `gen_ai.usage.cache_read.input_tokens`, `gen_ai.usage.cache_creation.input_tokens` and `gen_ai.usage.reasoning.output_tokens` |
| `tool` | `execute_tool <tool>` | 1 | One tool call: `gen_ai.tool.name`, `gen_ai.tool.call.id`, `gen_ai.tool.type`, `gen_ai.tool.description` |
| `retrieval` | `retrieval` | 3 | One retrieval: `neuron.rag.documents.count` |
| `processor` | `rag.preprocess.<class>`, `rag.postprocess.<class>` | 1 | One step before or after a retrieval |
| `structured` | `structured.schema`, `structured.extract`, `structured.deserialize`, `structured.validate` | 1 | One step of a structured output. A failed validation carries an error status and `neuron.structured.violations` |

An error is an `exception` event on the span that was open when it happened, with `exception.type`, `exception.message` and `exception.stacktrace`. The application reports an error of its own monitoring the same way while the run goes on, so an `exception` event does not mean that the run failed. A segment failed when `neuron.run.status` of its parentless span says `failed`; one span of that segment then carries the status code `2` (error), with the message of the error and `error.type`, its class.

With content capture, which is on unless the developer turns it off, these attributes are present too, each a string cut at 4,000 characters, the structured ones as JSON:

| Attribute | On | Holds |
|---|---|---|
| `gen_ai.input.messages`, `gen_ai.output.messages` | `generation` | The messages given to the model and its answer, as `{role, parts}` |
| `gen_ai.tool.call.arguments`, `gen_ai.tool.call.result` | `tool` | What the tool was given and what it returned |
| `gen_ai.retrieval.query.text`, `gen_ai.retrieval.documents` | `retrieval` | The question, and the ID and the score of each document |

The platform should answer `200` with `{}`, as a collector does, and may answer any other `2xx`.

### 13.3 The results of an evaluation: `POST /v1/evaluations`

An evaluation is a run of the developer's evaluators on their datasets, on a developer's machine or in CI. It has nothing to do with a workflow run: no ID in it is a run ID or a workflow ID. The SDK sends one request per evaluator, when all evaluators of the run are done.

```json
{"run_id": "eval_6acb600d897908.92076975",
 "name": "App\\Evaluators\\RefundEvaluator", "namespace": "support", "environment": "ci",
 "suite_id": "github-7812-1", "suite_size": 1, "branch": "fix-refunds", "base_branch": "main", "commit": "9f2c41e0",
 "ci_url": "https://github.com/acme/shop/actions/runs/7812", "error": null,
 "started_at": "2026-10-11T10:00:00.123456+00:00", "finished_at": "2026-10-11T10:00:02.500000+00:00",
 "summary": {"total": 2, "passed": 1, "failed": 1, "success_rate": 0.5,
             "total_execution_time": 2.376544, "average_execution_time": 0.92,
             "total_assertions": 2, "assertions_passed": 1, "assertions_failed": 1, "assertion_success_rate": 0.5,
             "cached_runs": 0,
             "score_statistics": {"average_score": 0.5, "min_score": 0.0, "max_score": 1.0},
             "score_statistics_by_label": {"StringContains": {"average": 0.5, "min": 0.0, "max": 1.0, "count": 2}},
             "has_failures": true, "warnings": []},
 "results": [
   {"evaluator_class": "App\\Evaluators\\RefundEvaluator", "index": 0, "passed": true,
    "input": {"question": "Refund order 123."}, "output": "Order 123 was refunded.",
    "execution_time": 0.84, "run_time": 0.8, "evaluation_time": 0.04,
    "run_usage": [{"provider": "anthropic", "model": "claude-opus-5-5", "calls": 1, "input_tokens": 1200, "output_tokens": 80,
                   "cached_input_tokens": 900, "cache_write_tokens": 0, "reasoning_tokens": 0}],
    "judge_usage": [], "cached_run": false, "error": null,
    "assertions_passed": 1, "assertions_failed": 0, "assertion_scores": [1.0],
    "scores": [{"label": "StringContains", "value": 1.0, "passed": true, "assertion": "StringContains", "message": "", "line": 37}],
    "failures": [], "reruns": []},
   {"evaluator_class": "App\\Evaluators\\RefundEvaluator", "index": 1, "passed": false,
    "input": {"question": "Where is my order?"}, "output": "I cannot help with that.",
    "execution_time": 1.0, "run_time": 0.96, "evaluation_time": 0.04, "run_usage": [], "judge_usage": [],
    "cached_run": false, "error": null,
    "assertions_passed": 0, "assertions_failed": 1, "assertion_scores": [0.0],
    "scores": [{"label": "StringContains", "value": 0.0, "passed": false, "assertion": "StringContains",
                "message": "Expected 'I cannot help with that.' to contain 'tracking'", "line": 37}],
    "failures": [{"evaluator_class": "App\\Evaluators\\RefundEvaluator", "assertion": "StringContains",
                  "message": "Expected 'I cannot help with that.' to contain 'tracking'", "line": 37, "context": []}],
    "reruns": [{"passed": true, "error": null, "assertions_passed": 1, "assertions_failed": 0,
                "run_time": 0.9, "evaluation_time": 0.04, "run_usage": [], "judge_usage": []}]}]}
```

| Field | Meaning |
|---|---|
| `run_id` | Made for this request, `eval_` and a unique suffix. Two evaluators of one evaluation have different ones, and `suite_id` ties them together |
| `name` | The class of the evaluator |
| `namespace` | The group the evaluator declares, or null |
| `environment` | What the developer named where the evaluation ran, or null |
| `suite_id` | Ties together the evaluators of one evaluation. In a CI build the SDK knows it names the attempt of the build, so that a later step can read the suite (13.6); otherwise it is made for the evaluation |
| `suite_size` | How many evaluators the evaluation has, so that the platform can tell a suite that did not all arrive |
| `branch`, `base_branch`, `commit`, `ci_url` | The code the evaluation ran on, the branch a pull request targets and the page of the build, each null when the SDK cannot tell. The platform keeps a `ci_url` only when it is an `http` or `https` URL |
| `error` | Why the evaluator could not run, or null. `results` is then empty |
| `started_at`, `finished_at` | When the evaluator ran, with microseconds and the offset from UTC |
| `summary` | Counts and rates over the items and over their assertions, times in seconds, and the statistics of the scores, overall and by label. `score_statistics` is null when no assertion gave a score. `warnings` lists what the reader should know about the run, such as cached outputs with no declared dependency |
| `results` | One entry per dataset item, in order. `input` is the item as the dataset holds it, with its `dataset_item_id` when it comes from a dataset of 13.5. `output` is what the evaluated code returned: a scalar as it is, anything else as a string of JSON, and the trajectory of a conversation as an object with `type` `trajectory`, `transcript`, `turns`, `tool_calls` and `usage`. `cached_run` says that the output came from the evaluation cache. `execution_time` is the whole item, `run_time` the evaluated code and `evaluation_time` the assertions and judges, each null when that step did not execute. `run_usage` and `judge_usage` hold one entry per provider and model with the tokens of that step: `provider` is the value of `gen_ai.provider.name`, and an empty list means nothing was measured, never that the item was free. Each entry of `scores` keeps the `assertion`, its `message`, which holds the reasoning of a judge whether it passed or not, and the `line`. `failures` lists each failed assertion. `reruns` lists the attempts made again at an item that failed: the verdict stays the one of the first run, and they count for what the item spent. An attempt that made no assertion judged nothing, and does not count as passing |

Receivers ignore fields they do not know: the summary and the results follow what the framework's evaluations give, and may gain fields.

### 13.4 What the platform does with them

Both carry the customer's data. A trace holds the message and the stack trace of every error and, with content capture, prompts, model answers and what tools were given and returned. An evaluation holds the inputs and the outputs of the evaluated code. R15 is about the messages of protocol 1: it does not forbid the platform to keep what the application chose to send here, and it does not allow the platform to mix it with the coordination data of a run.

- **AUTOMATION.** The platform authenticates both paths as it does a notice, and answers `401` with `unauthorized` to a key it does not accept.
- **AUTOMATION.** It attaches a trace to the run its `neuron.run.id` names, in the environment of the API key, and shows it with that run. A trace whose run the platform does not know is kept on its own or dropped: it never creates a run, never opens a closed one and never changes a status.
- **SETTING.** How long traces and evaluation results are kept, who may read them, and how large a request may be are the platform's own. A request the platform finds too large is answered with a `4xx` and is lost: the SDK does not split or retry it.
- **AUTOMATION.** The platform compares a run with the last earlier run of the same evaluator in the same environment. A run with a `base_branch` compares with that branch, and with its own when that one has no run. Any other run compares with its own branch. Runs that name neither a branch nor a commit stand in until the evaluator has a run that names a branch. A run never compares with its own suite, and a run with a `ci_url` never with its own commit, so re-running a build does not turn a failure into a known one.

### 13.5 A dataset: `GET /v1/datasets/{slug}`

An evaluator of the application can run on a dataset that the platform keeps, instead of a file of the application. The SDK reads the dataset once per evaluator, when the evaluation runner loads it, on a developer's machine or in CI. Like the results of an evaluation, it has nothing to do with a workflow run.

| | |
|---|---|
| Who turns it on | The developer, by returning the SDK's dataset from an evaluator. **SETTING**, set in the application |
| Request | `GET {platformUrl}/v1/datasets/{slug}`, with `Authorization: Bearer <API key>`, the `User-Agent` of section 3.2 and no body. `{slug}` is the name the platform gives the dataset, percent-encoded as one segment of the path |
| Answer | A `2xx` whose body is a JSON object with `items`: a list of JSON objects, one per item. The SDK hands each item to the evaluator as it is, in the order of the list, and ignores every other field. An empty list is a dataset with no item. **FIXED BY THE PROTOCOL** |
| A refusal | Any other answer, and no answer within the timeout of section 3.8. The SDK sends the request once: the evaluator does not run, and the developer sees why. `401` and `403` are `unauthorized`, a `4xx` that carries `invalid_request` or `unsupported_protocol` is that code, and anything else is `report_undelivered`, a `404` included |

```json
{"slug": "refund-requests", "name": "Refund requests", "description": null,
 "items": [
   {"dataset_item_id": 41, "input": {"question": "Refund order 123."}, "expected_output": {"answer": "refunded"}},
   {"dataset_item_id": 42, "input": "Where is my order?", "expected_output": null}]}
```

- **AUTOMATION.** The platform authenticates the path as it does a notice, and looks the slug up among the datasets of the environment of the API key. It answers `404` to a slug it does not know there.
- **What an item holds is the platform's.** The evaluator reads the item and the SDK reads nothing of it. The platform sends the `dataset_item_id` it gave the item, an integer that stays the same when an operator edits the item, with the `input` and the `expected_output` an operator stored. It sends only the items that are active, the oldest first.
- **What crosses.** The request carries nothing but the slug. The answer brings data the customer stored on the platform into the application. It goes back to the platform only when the developer also lists the output of 13.3, as the `input` of each result.
- **AUTOMATION.** A result whose `input` carries the `dataset_item_id` of an item of the environment of the API key is tied to that item, so the platform can compare it from one evaluation to the next. The platform leaves that key out of the `input` it shows for the result. An ID that is not an integer, or that names an item of another environment, ties the result to nothing and is left out the same way. A result with no ID is compared by its `input`.

### 13.6 What an evaluation found: `GET /v1/evaluations/suites/{suite_id}`

The exit code of `neuron evaluation` fails on any failed item, because nothing on the machine remembers an earlier run. The platform does, so a later step of a CI build asks it whether the evaluators of the build broke something new. It has nothing to do with a workflow run.

| | |
|---|---|
| Who turns it on | The developer, by running `vendor/bin/neuron-cloud-gate` in the build. **SETTING**, set in the application |
| Request | `GET {platformUrl}/v1/evaluations/suites/{suite_id}`, with `Authorization: Bearer <API key>`, the `User-Agent` of section 3.2 and no body. `{suite_id}` is percent-encoded |
| Answer | A `2xx` whose body is a JSON object with `verdict`: `passed`, `failed` or `incomplete` |
| A refusal | Any other answer, and no answer within the timeout of section 3.8. `404` means the platform holds no run of the suite. The SDK sends the request once and the step ends with exit code 2, which is neither a pass nor a failure of the evaluators, as it does for `incomplete` |

```json
{"suite_id": "github-7812-1", "branch": "fix-refunds", "base_branch": "main", "commit": "9f2c41e0",
 "ci_url": "https://github.com/acme/shop/actions/runs/7812", "verdict": "failed", "evaluators_expected": 1,
 "evaluators": [
   {"name": "App\\Evaluators\\RefundEvaluator", "namespace": "support", "run_id": "eval_6acb600d897908.92076975",
    "url": "https://cloud.neuron-ai.dev/acme/apps/shop/evaluations/4/runs/91", "status": "regressed",
    "total": 2, "passed": 1, "failed": 1, "errored": 0, "new_failures": 1, "confirmed_failures": 1, "fixed": 0,
    "compared": 2, "checks_changed": 0, "edited": 0, "reused_output": 0, "added": 0, "removed": 0, "gap": null,
    "baseline": {"run_id": "eval_6acb5f0c11b2a4.10293847", "branch": "main", "commit": "71ab09c2", "started_at": "2026-10-11T09:00:00.000000Z"}}],
 "failures": [
   {"evaluator": "App\\Evaluators\\RefundEvaluator", "index": 1, "change": "new_failure", "confirmed": true,
    "reruns": 2, "reruns_passed": 0, "reruns_errored": 0, "input": {"question": "Where is my order?"},
    "error": null, "reasons": ["Expected 'I cannot help with that.' to contain 'tracking'"]}],
 "failures_total": 1}
```

| Field | Meaning |
|---|---|
| `verdict` | `failed` when a run of the suite is `regressed` or `not_run`, `incomplete` when fewer evaluators arrived than `suite_size` says, and `passed` otherwise. A suite that compares nothing passes, and its evaluators say why |
| `evaluators` | Every run of the suite, as the jobs of a matrix share one. `status` is `regressed`, `not_run` when the evaluator could not run, every item threw, or it has no items or no assertions where its baseline had verdicts, `not_compared` when `gap` says why nothing compares, or `clean`. The counts say how much of it compared |
| `failures` | Items that passed in the baseline and fail or throw now, at most 50 of `failures_total`, the confirmed ones first. `confirmed` is false for an item that passed one of its `reruns`: it is reported and it does not fail the verdict. An item nobody re-ran counts as confirmed |

- **AUTOMATION.** The platform authenticates the path as it does a notice, reads only the suites of the app of the API key, and compares the runs when it is asked, so a run that arrived late changes the answer.

---

## Glossary

| Term | Meaning |
|---|---|
| Abandon | The order to discard a run. Sent only when an operator asks |
| Accepted and silent | What a `start` or a `wake` counts as after its last copy got no usable answer: the platform treats it as if the endpoint had answered `202` and nothing had followed |
| Adopt | To start holding a run that the platform has seen only in a snapshot, under the definition the snapshot shows |
| Announcement | The notice "a segment of this run is starting; its lease is `leaseSeconds`" |
| API key | The secret with which the application authenticates its requests to the platform |
| Application | The developer's deployed program, which keeps the state of its workflows and executes them |
| Attempt | The counter that the application raises each time a process takes a run over. Part of the fence |
| Call | In a report: one handling, by one process of the application, of an order of the platform or of a request of the application's own code to execute a segment. It executes one segment or none |
| Claim, claimed | The SDK's write of the run ID of a start into the ledger, before the start executes. A run ID is claimed when it is in the ledger, whether a start or an abandon wrote it |
| Closed | A run for which the platform plans nothing: completed, abandoned, ended with an unknown outcome, lost or spent |
| Copy | The same message sent or delivered again: the same body and the same invocation ID. Whether a wake that the platform decides again is a copy is Q5 |
| Current version | The highest version of a definition in the manifest. New runs start on it |
| Dead | A run that is `running` under a lease that has passed |
| Definition | A workflow or agent class that the application registered under a name |
| Endpoint | The one signed URL of the application that the platform calls |
| Engine | The workflow framework under the SDK, Neuron AI. It admits or refuses every request to execute a run, and keeps the records of the run in the store |
| Environment | What the platform keeps one set of keys, one endpoint, one manifest and one list of runs for |
| Fence | The run ID and the attempt that a wake or an abandon carries. The application refuses the message when they are no longer current |
| Firing | One occurrence of a trigger |
| Inspect | The question "which run holds this workflow ID?" |
| Invocation ID | The ID of one `start` or one `wake` of the platform, or of one segment that the application issued. The same on every copy |
| Lease | The time for which one process holds a running run. When it has passed, another process may take the run over |
| Ledger | The SDK's list, in the application's store, of the run IDs that a worker claimed for a start or that an abandon wrote |
| Manifest | The list of definitions and versions that the last sync published, with the endpoint URL |
| No usable answer | A `500`, a `3xx`, or no answer of the endpoint within the platform's timeout. For the answers that section 5.9 does not list: U1 |
| Notice | An announcement or a report |
| Open | A run that the platform holds and has not closed |
| Operator | A person who acts on the platform through its dashboard or its API |
| Order | A `start`, a `wake` or an `abandon` |
| Origin | Who issued a segment: `platform` or `application` |
| Outcome | The word of a report that says how one call ended |
| Owner | An operator who may confirm that the endpoint of an environment moves to another host |
| Ping | The question "does this endpoint verify my signature?" |
| Platform | Neuron Cloud: the service that keeps a list of runs and a clock |
| Question | An `inspect` or a `ping` |
| Reader | The client, such as a browser, that receives the output of a streamed segment |
| Reminder | A job on the application's queue that tells a worker "read the store and post the report of this segment" |
| Report | The notice "this is the run now": the outcome of one call, and a snapshot |
| Requested sync | The extension of section 5.11: the platform asks the endpoint for the body of a sync, and takes the answer in as one |
| Retry policy | How many times, and how far apart, the platform wakes a failed or dead run before it gives up. A setting of the platform |
| Run | One execution of a definition on a workflow ID |
| Run ID | The identifier of one run. Minted by whoever starts it |
| SDK | The library inside the application that speaks this protocol |
| Segment | One uninterrupted part of the execution of a run, in one process |
| Signing key | The secret with which the platform signs its requests to the endpoint |
| Snapshot | What the store showed for one workflow ID at one instant: `run`, `at`, `snapshotRead` |
| Start | The order to start a run |
| Store | The application's database of workflow state. It is the truth |
| Stranded | A version that is not in the manifest while runs of it are open |
| Streamed | A segment that runs inside a web request and sends its output to a reader while it executes |
| Sync | The application's message that publishes its definitions and its endpoint URL |
| Tag | The label that the application's store keeps with a run. The SDK sets it to the name of the definition that started the run |
| Timer | The wake that the platform plans for the `wakeAt` of a wait, whatever the type of the wait |
| Trigger | What makes the platform start a run: a schedule, or a call to the platform's API. A setting of the platform |
| Tunnel | A service that forwards a public `https` address to a port of a local machine |
| Use-by time | The `expiresAt` of a start: a worker that takes the start at or after it does not execute it |
| Verb | The kind of a segment: `start`, `wake`, `answer` or `signal`. The field `verb` of a message of the platform is another thing: it names the message |
| Version | An integer that the developer gives each registration of a definition. A run is pinned to the version at the front of its run ID |
| Wait | What a suspended run waits for: a time, or an event that the application delivers |
| Wake | The order to continue a run, with no input |
| Workflow ID | The stable address of one workflow instance. At most one run holds it at a time |
