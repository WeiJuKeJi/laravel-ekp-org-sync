<?php

namespace WeiJuKeJi\LaravelEkpOrgSync\Services;

use DateTimeImmutable;
use WeiJuKeJi\LaravelEkpOrgSync\Exceptions\SyncFailure;

class Normalizer
{
    public const TYPES = ['org' => 'company', 'dept' => 'department', 'person' => 'person', 'post' => 'position', 'group' => 'group'];

    public function timestamp(mixed $value): string
    {
        if (! is_string($value) || strlen($value) !== 23) {
            throw new SyncFailure('invalid_timestamp');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value);
        if (! $date || $date->format('Y-m-d H:i:s.v') !== $value) {
            throw new SyncFailure('invalid_timestamp');
        }

        return $value;
    }

    public function entry(array $raw): array
    {
        $type = self::TYPES[$raw['type'] ?? ''] ?? null;
        if (! $type || ! isset($raw['isAvailable']) || ! is_bool($raw['isAvailable'])) {
            throw new SyncFailure('invalid_element_type_or_status');
        }
        $text = function (string $key, int $max, bool $required = false) use ($raw): ?string {
            $value = $raw[$key] ?? null;
            if ($value === null || $value === '') {
                if ($required) {
                    throw new SyncFailure('required_field_missing');
                }

                return null;
            }
            if (! is_string($value) || mb_strlen($value) > $max) {
                throw new SyncFailure('invalid_field_length');
            }

            return $value;
        };
        $links = [];
        if (array_key_exists('canLogin', $raw)) {
            if (! is_bool($raw['canLogin'])) {
                throw new SyncFailure('invalid_login_eligibility');
            }
            $links['can_login'] = $raw['canLogin'];
        }
        foreach (['posts', 'thisLeader', 'superLeader', 'members'] as $key) {
            $values = $raw[$key] ?? [];
            $values = is_string($values) ? [$values] : $values;
            if (! is_array($values)) {
                throw new SyncFailure('invalid_relationship');
            }
            $links[$key] = [];
            foreach ($values as $value) {
                if (! is_string($value) || strlen($value) > 128) {
                    throw new SyncFailure('invalid_relationship');
                }
                $links[$key][] = $value;
            }
            sort($links[$key]);
        }
        if (isset($raw['order']) && ! is_numeric($raw['order'])) {
            throw new SyncFailure('invalid_sort_order');
        }

        // Nothing outside this explicit map (especially password/customProps) is persisted.
        return [
            'external_id' => $text('id', 128, true), 'type' => $type, 'name' => $text('name', 500, true),
            'code' => $text('no', 200), 'login_name' => $text('loginName', 200),
            'contact_email' => $text('email', 500), 'phone' => $text('mobileNo', 100),
            'is_available' => $raw['isAvailable'], 'sort_order' => (int) ($raw['order'] ?? 0),
            'parent_external_id' => $text('parent', 128), 'source_modified_at' => $this->timestamp($raw['alterTime'] ?? null), 'links' => $links,
        ];
    }
}
