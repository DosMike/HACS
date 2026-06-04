<?php

declare(strict_types=1);

namespace HACS;

use InvalidArgumentException;

final class HACS
{
    private const PERMISSION_PATTERN = '/^(?:\*|[a-zA-Z0-9]+(?:\.[a-zA-Z0-9]+)*)$/';

    private function __construct()
    {
    }

    public static function permissionKey(string $value): string
    {
        return self::normalizePermission($value);
    }

    public static function makePermission(string $value): string
    {
        return self::normalizePermission($value);
    }

    /**
     * @param array<string, string|bool|null> $grants
     * @return array<string, string>
     */
    public static function permissionGrant(array $grants): array
    {
        $normalizedGrants = [];

        foreach ($grants as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalizedGrants[self::normalizeGrantKey((string) $key)] = self::normalizeGrantValue($value);
        }

        return $normalizedGrants;
    }

    /**
     * @param array<string, string|bool|null> $grants
     * @return array<string, string>
     */
    public static function defineGrants(array $grants): array
    {
        return self::permissionGrant($grants);
    }

    /**
     * @param array<string, string|bool|null>|list<array<string, string|bool|null>> $grants
     */
    public static function test(array $grants, string $permission): bool
    {
        $match = self::resolvePermission($grants, $permission);

        return $match !== null && $match['value'] === 'allow';
    }

    /**
     * @param array<string, string|bool|null>|list<array<string, string|bool|null>> $grants
     */
    public static function can(array $grants, string $permission): bool
    {
        return self::test($grants, $permission);
    }

    /**
     * @param array<string, string|bool|null>|list<array<string, string|bool|null>> $grants
     */
    public static function explain(array $grants, string $permission): string
    {
        $explanation = self::explainPermission($grants, $permission);

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
     * @param array<string, string|bool|null>|list<array<string, string|bool|null>> $grants
     * @return array{
     *   allowed: bool,
     *   permission: string,
     *   matched: array{key: string, value: string}|null,
     *   considered: list<array{key: string, value: string}>,
     *   reason: string
     * }
     */
    public static function explainPermission(array $grants, string $permission): array
    {
        $normalizedPermission = self::normalizePermission($permission);
        $considered = self::getMatchingGrants($grants, $normalizedPermission);
        $matched = $considered === [] ? null : $considered[array_key_last($considered)];

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
     * @param array<string, string|bool|null>|list<array<string, string|bool|null>> $grants
     * @return array{key: string, value: string}|null
     */
    public static function resolvePermission(array $grants, string $permission): ?array
    {
        $normalizedPermission = self::normalizePermission($permission);
        $considered = self::getMatchingGrants($grants, $normalizedPermission);

        return $considered === [] ? null : $considered[array_key_last($considered)];
    }

    /**
     * @param array<string, string|bool|null>|list<array<string, string|bool|null>> $grants
     * @return list<array{key: string, value: string}>
     */
    private static function getMatchingGrants(array $grants, string $permission): array
    {
        $matches = array_values(array_filter(
            self::flattenGrants($grants),
            static function (array $grant) use ($permission): bool {
                if ($grant['value'] === 'inherit') {
                    return false;
                }

                return $grant['key'] === '*'
                    || $permission === $grant['key']
                    || str_starts_with($permission, $grant['key'] . '.');
            },
        ));

        usort(
            $matches,
            static fn (array $left, array $right): int => self::specificity($left['key']) <=> self::specificity($right['key']),
        );

        return $matches;
    }

    /**
     * @param array<string, string|bool|null>|list<array<string, string|bool|null>> $grants
     * @return list<array{key: string, value: string}>
     */
    private static function flattenGrants(array $grants): array
    {
        $grantList = self::isGrantList($grants) ? $grants : [$grants];
        $matches = [];

        foreach ($grantList as $grantSet) {
            foreach ($grantSet as $key => $value) {
                if ($value === null) {
                    continue;
                }

                $matches[] = [
                    'key' => self::normalizeGrantKey((string) $key),
                    'value' => self::normalizeGrantValue($value),
                ];
            }
        }

        return $matches;
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
        if (preg_match(self::PERMISSION_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Permission grant key must match %s: %s.', self::PERMISSION_PATTERN, json_encode($key)),
            );
        }

        return $key;
    }

    private static function normalizeGrantValue(mixed $value): string
    {
        if ($value === true) {
            return 'allow';
        }

        if ($value === false) {
            return 'deny';
        }

        if ($value !== 'allow' && $value !== 'deny' && $value !== 'inherit') {
            throw new InvalidArgumentException(sprintf('Invalid permission grant value: %s.', (string) $value));
        }

        return $value;
    }

    private static function specificity(string $key): int
    {
        if ($key === '*') {
            return -1;
        }

        return substr_count($key, '.') + 1;
    }

    /**
     * @param array<mixed> $grants
     */
    private static function isGrantList(array $grants): bool
    {
        if ($grants === []) {
            return false;
        }

        return array_is_list($grants) && is_array($grants[0]);
    }
}
