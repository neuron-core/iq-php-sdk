<?php

declare(strict_types=1);

namespace NeuronCore\IQ;

/**
 * The codes of the SDK and of the platform. A refusal by Neuron carries a
 * RefusalReason value instead, so the code of a report is a string.
 */
enum Code: string
{
    // Answers of the endpoint
    case InvalidSignature = 'invalid_signature';
    case InvalidRequest = 'invalid_request';
    case UnsupportedProtocol = 'unsupported_protocol';
    case UnknownDefinition = 'unknown_definition';
    case UnknownVersion = 'unknown_version';
    case DispatchFailed = 'dispatch_failed';
    case Unavailable = 'unavailable';
    case AbandonRefused = 'abandon_refused';
    case SyncRefused = 'sync_refused';
    case InternalError = 'internal_error';

    // Reports: rejected. A wake carries wrong_definition only and a start never does; submitInputs(), signal()
    // and recover() throw it too
    case Expired = 'expired';
    case InvalidStart = 'invalid_start';
    case AlreadyClaimed = 'already_claimed';
    case WrongDefinition = 'wrong_definition';

    // Reports: aborted, with unknown_definition, unknown_version and unavailable above
    case NotExecuted = 'not_executed';
    case AnnounceFailed = 'announce_failed';

    // Answers of the platform, with invalid_request and unsupported_protocol above
    case EndpointUnverified = 'endpoint_unverified';
    case Unauthorized = 'unauthorized';

    // Thrown locally only, with dispatch_failed above
    case ReportUndelivered = 'report_undelivered';
    case UnsuitablePersistence = 'unsuitable_persistence';
    case NotConfigured = 'not_configured';
    case SegmentUsed = 'segment_used';
}
