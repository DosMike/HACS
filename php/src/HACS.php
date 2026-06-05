<?php
/**
 * github.com/DosMike/HACS
 * v1.1.0
 */

declare(strict_types=1);

namespace HACS;

use InvalidArgumentException;

final class HACS
{
    private const PERMISSION_PATTERN = '/^[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*$/';
    private const PERMISSION_GRANT_PATTERN = '/^(?:\*|[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*)$/';

    /** @var array<string, string> */
    private array $grants = [];

    /**
     * @param array<string, string|bool|null> $grants
     */
    public function __construct(array $grants = [])
    {
        $this->addGrants($grants);
    }

    /**
     * @param array<string, string|bool|null> $grants
     */
    public static function test(array $grants, string $permission): bool
    {
        return (new self($grants))->can($permission);
    }

    /**
     * Adds a grant set to the loaded in-memory grants.
     *
     * Exact-key grants from this set override earlier exact-key grants. `inherit`
     * is intentionally ignored during load, so it cannot erase a previously
     * loaded explicit allow or deny.
     *
     * @param array<string, string|bool|null> $grants
     */
    public function addGrants(array $grants): self
    {
        foreach ($grants as $key => $value) {
            $normalizedKey = self::normalizeGrantKey((string) $key);
            $normalizedValue = self::normalizeGrantValue($value);

            if ($normalizedValue === 'inherit') {
                continue;
            }

            $this->grants[$normalizedKey] = $normalizedValue;
        }

        return $this;
    }

    public function can(string $permission): bool
    {
        $match = $this->resolvePermission($permission);

        return $match !== null && $match['value'] === 'allow';
    }

    public function explain(string $permission): string
    {
        $explanation = $this->explainPermission($permission);

        if ($explanation['matched'] !== null) {
            $state = $explanation['allowed'] ? 'allowed' : 'denied';
            $matched = $explanation['matched'];

            return sprintf(
                "'%s' is %s. Matched '%s' with value '%s'. %s",
                $explanation['permission'],
                $state,
                $matched['key'],
                $matched['value'],
                $explanation['reason'],
            );
        }

        return sprintf(
            "'%s' is denied. No matching grant was found, so the default is deny.",
            $explanation['permission'],
        );
    }

    /**
     * @return array{
     *   allowed: bool,
     *   permission: string,
     *   matched: array{key: string, value: string}|null,
     *   considered: list<array{key: string, value: string}>,
     *   reason: string
     * }
     */
    public function explainPermission(string $permission): array
    {
        $normalizedPermission = self::normalizePermission($permission);
        $considered = $this->getMatchingGrants($normalizedPermission);
        $matched = empty($considered) ? null : $considered[array_key_last($considered)];

        return [
            'allowed' => $matched !== null && $matched['value'] === 'allow',
            'permission' => $normalizedPermission,
            'matched' => $matched,
            'considered' => $considered,
            'reason' => $matched !== null
                ? 'The most specific matching grant wins.'
                : 'Permissions without an explicit grant are denied.',
        ];
    }

    /**
     * @return array{key: string, value: string}|null
     */
    public function resolvePermission(string $permission): ?array
    {
        $normalizedPermission = self::normalizePermission($permission);
        $considered = $this->getMatchingGrants($normalizedPermission);

        return empty($considered) ? null : $considered[array_key_last($considered)];
    }

    /**
     * @return array<string, string>
     */
    public function grants(): array
    {
        return $this->grants;
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function getMatchingGrants(string $permission): array
    {
        $matches = [];

        foreach ($this->candidateKeys($permission) as $key) {
            if (array_key_exists($key, $this->grants)) {
                $matches[] = [
                    'key' => $key,
                    'value' => $this->grants[$key],
                ];
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function candidateKeys(string $permission): array
    {
        $keys = ['*'];

        if ($permission === '*') {
            return $keys;
        }

        $segments = explode('.', $permission);
        $current = [];

        foreach ($segments as $segment) {
            $current[] = $segment;
            $keys[] = implode('.', $current);
        }

        return $keys;
    }

    private static function normalizePermission(string $permission): string
    {
        if (preg_match(self::PERMISSION_PATTERN, $permission) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Permission must match %s: %s.', self::PERMISSION_PATTERN, json_encode($permission)),
            );
        }

        return $permission;
    }

    private static function normalizeGrantKey(string $key): string
    {
        if (preg_match(self::PERMISSION_GRANT_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Permission grant key must match %s: %s.', self::PERMISSION_GRANT_PATTERN, json_encode($key)),
            );
        }

        return $key;
    }

    private static function normalizeGrantValue(mixed $value): string
    {
        if ($value === null) {
            return 'inherit';
        } elseif ($value === true) {
            return 'allow';
        } elseif ($value === false) {
            return 'deny';
        } elseif ($value !== 'allow' && $value !== 'deny' && $value !== 'inherit') {
            throw new InvalidArgumentException(sprintf('Invalid permission grant value: %s.', (string) $value));
        }
        return $value;
    }
}
