<?php

declare(strict_types=1);

use framework\SQL;
use HACS\HACS;

require __DIR__ . '/../../php/src/HACS.php';
require __DIR__ . '/framework/SQL.php';

/*
 * Example database shape:
 *
 * users
 *   id
 *
 * permission_grants
 *   id
 *   user_id
 *   permission_key
 *   grant_value enum('allow', 'deny', 'inherit')
 *   priority int
 *
 * permission_groups
 *   id
 *   name
 *
 * permission_group_grants
 *   id
 *   group_id
 *   permission_key
 *   grant_value enum('allow', 'deny', 'inherit')
 *   priority int
 *
 * user_permission_groups
 *   user_id
 *   group_id
 *   priority int
 *
 * SQL.php reads examples/php/framework/server.config.php and opens a mysqli
 * connection when it is loaded. Create that config before running this example.
 */

$userId = 42;
$grants = HACSDatabaseGrants::grantsForUser($userId);

echo HACS::explain($grants, HACS::makePermission('project.read')) . PHP_EOL;
echo HACS::explain($grants, HACS::makePermission('project.delete')) . PHP_EOL;
echo HACS::explain($grants, HACS::makePermission('project.delete.owner')) . PHP_EOL;

final class HACSDatabaseGrants
{
    /**
     * Loads a user's ordered grant sets from the database.
     *
     * The order matters. HACS evaluates all matching grants and uses the most
     * specific match. When specificity ties, later same-specificity grant sets
     * win. This example sorts lower priority first and higher priority later.
     *
     * Group grants are loaded first, then direct user grants. That means direct
     * user grants override group grants when permission specificity ties.
     *
     * @return list<array<string, string>>
     */
    public static function grantsForUser(int $userId): array
    {
        return [
            ...self::groupGrantSetsForUser($userId),
            ...self::directGrantSetsForUser($userId),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function groupGrantSetsForUser(int $userId): array
    {
        $rows = self::selectRows(
            'user_permission_groups AS upg',
            [
                'group_name' => 'pg.name',
                'permission_key' => 'pgg.permission_key',
                'grant_value' => 'pgg.grant_value',
                'assignment_priority' => 'upg.priority',
                'grant_priority' => 'pgg.priority',
            ],
            ['upg.user_id' => (string) $userId],
            '`assignment_priority` ASC, `grant_priority` ASC, `permission_key` ASC',
            [
                'permission_groups AS pg' => ['upg.group_id', 'pg.id'],
                'permission_group_grants AS pgg' => ['pg.id', 'pgg.group_id'],
            ],
        );

        return self::grantSetsFromRows($rows);
    }

    /**
     * @return list<array<string, string>>
     */
    private static function directGrantSetsForUser(int $userId): array
    {
        $rows = self::selectRows(
            'permission_grants',
            ['permission_key', 'grant_value'],
            ['user_id' => (string) $userId],
            '`priority` ASC, `permission_key` ASC',
        );

        return self::grantSetsFromRows($rows);
    }

    /**
     * @param string|array<string>|array<string, string> $columns
     * @param array<string, int|string|null>|string|null $condition
     * @param array<string, array{0: string, 1: string}|string>|null $join
     * @return list<array<string, string>>
     */
    private static function selectRows(
        string $table,
        string|array $columns,
        null|string|array $condition = null,
        ?string $order = null,
        ?array $join = null,
    ): array {
        $result = SQL::select($table, $columns, $condition, $order, Join: $join);

        if ($result->failed()) {
            throw new RuntimeException("Failed to load grants from {$table}.");
        }

        return $result->getAll(static fn (array $row): array => array_map('strval', $row));
    }

    /**
     * @param list<array<string, string>> $rows
     * @return list<array<string, string>>
     */
    private static function grantSetsFromRows(array $rows): array
    {
        $grantSets = [];

        foreach ($rows as $row) {
            $grantSets[] = HACS::defineGrants([
                $row['permission_key'] => $row['grant_value'],
            ]);
        }

        return $grantSets;
    }
}
