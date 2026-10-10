<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Simulator;

use function array_filter;
use function array_map;
use function base64_decode;
use function base64_encode;
use function hash_hmac;
use function implode;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * How the platform signs what it sends the endpoint: Standard Webhooks, as
 * appendix A of the design writes it, with one signature per active key.
 */
final class Signer
{
    protected const KEY_PREFIX = 'whsec_';

    /**
     * @param string[] $signingKeys each "whsec_" followed by the base64 of the key bytes
     */
    public function __construct(protected array $signingKeys)
    {
    }

    /**
     * @return array<string, string> the three headers of a signed message
     */
    public function headers(string $id, int $timestamp, string $body): array
    {
        return [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => implode(' ', array_map(
                static fn (string $key): string => 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true)),
                $this->keys(),
            )),
        ];
    }

    /**
     * @return string[] the bytes of every key that decodes
     */
    protected function keys(): array
    {
        return array_filter(array_map(
            static fn (string $key): string => str_starts_with($key, self::KEY_PREFIX)
                ? (string) base64_decode(substr($key, strlen(self::KEY_PREFIX)), true)
                : '',
            $this->signingKeys,
        ));
    }
}
