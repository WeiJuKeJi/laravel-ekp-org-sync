<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Services;

use Illuminate\Support\Facades\Http;
use WeiJuKeJi\LaravelEkpOrgSync\Exceptions\SyncFailure;
use WeiJuKeJi\LaravelEkpOrgSync\Models\Source;

class EkpClient
{
    public function call(Source $source, string $method, array $body): array
    {
        $this->validateUrl($source->url);
        if (! in_array($method, ['getElementsBaseInfo', 'getUpdatedElements', 'getUpdatedElementsByToken'], true)) {
            throw new SyncFailure('unsupported_method');
        }
        try {
            $response = Http::withBasicAuth($source->username, $source->password)
                ->acceptJson()->asJson()->timeout(config('ekp-org-sync.timeout', 45))
                ->connectTimeout(10)->withOptions(['allow_redirects' => false, 'verify' => true])
                ->post(rtrim($source->url, '/').'/api/sys-organization/sysSynchroGetOrg/'.$method, $body);
            if (! $response->successful()) {
                throw new SyncFailure('http_'.$response->status());
            }
            if (strlen($response->body()) > 32 * 1024 * 1024) {
                throw new SyncFailure('response_too_large');
            }
            $envelope = $response->json();
            if (! is_array($envelope) || ($envelope['returnState'] ?? null) !== 2) {
                throw new SyncFailure('business_failure');
            }
            $records = $envelope['message'] ?? [];
            if (is_string($records)) {
                $records = json_decode($records, true, 512, JSON_THROW_ON_ERROR);
            }
            if (! is_array($records) || ! array_is_list($records) || ! isset($envelope['count']) || (int) $envelope['count'] !== count($records)) {
                throw new SyncFailure('invalid_envelope');
            }
            foreach ($records as $record) {
                if (! is_array($record)) {
                    throw new SyncFailure('invalid_element');
                }
            }

            return ['records' => $records, 'timestamp' => $envelope['timeStamp'] ?? null, 'token' => $envelope['token'] ?? null];
        } catch (SyncFailure $error) {
            throw $error;
        } catch (\Throwable) {
            throw new SyncFailure('transport_or_decode_failure');
        }
    }

    public function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new SyncFailure('invalid_https_origin');
        }
    }
}
