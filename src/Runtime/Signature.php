<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Runtime;

use function abs;
use function base64_decode;
use function base64_encode;
use function ctype_digit;
use function explode;
use function hash_equals;
use function hash_hmac;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The Standard Webhooks rule the platform signs its messages with.
 *
 * @internal
 */
final class Signature
{
    protected const KEY_PREFIX = 'whsec_';

    /** Seconds a timestamp may differ from the receiver's clock, either way. */
    public const TOLERANCE = 300;

    /**
     * @param string|null $signingKey "whsec_" followed by the base64 of the key bytes
     */
    public function __construct(protected ?string $signingKey)
    {
    }

    /**
     * The signature of one message, or null when there is no key to sign with.
     */
    public function sign(string $id, int $timestamp, string $body): ?string
    {
        $key = $this->keyBytes();
        if ($key === null) {
            return null;
        }

        return 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    /**
     * @param string $timestamp the webhook-timestamp header, as received
     * @param string $signatures the webhook-signature header: one signature, or several separated by spaces
     * @param int $now the receiver's clock
     */
    public function verify(string $id, string $timestamp, string $signatures, string $body, int $now): bool
    {
        if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = $this->sign($id, (int) $timestamp, $body);
        if ($expected === null) {
            return false;
        }

        foreach (explode(' ', $signatures) as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A key that is missing, empty or does not decode is no key.
     */
    protected function keyBytes(): ?string
    {
        if ($this->signingKey === null || !str_starts_with($this->signingKey, self::KEY_PREFIX)) {
            return null;
        }

        $bytes = base64_decode(substr($this->signingKey, strlen(self::KEY_PREFIX)), true);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }
}
