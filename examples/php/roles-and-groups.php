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
$hacs = HACSDatabaseGrants::forUser($userId);

echo $hacs->explain('project.read') . PHP_EOL;
echo $hacs->explain('project.delete') . PHP_EOL;
echo $hacs->explain('project.delete.owner') . PHP_EOL;

final class HACSDatabaseGrants
{
    /**
     * Loads a user's grants from the database.
     *
     * The order matters. HACS evaluates all matching grants and uses the most
     * specific match. When specificity ties, later same-specificity grant sets
     * win. This example sorts lower priority first and higher priority later.
     *
     * Group grants are loaded first, then direct user grants. Because HACS
     * preprocesses exact grant keys, direct user grants override group grants
     * with the same permission key.
     */
    public static function forUser(int $userId): HACS
    {
        $hacs = new HACS();

        self::loadGroupGrants($hacs, $userId);
        self::loadDirectGrants($hacs, $userId);

        return $hacs;
    }

    private static function loadGroupGrants(HACS $hacs, int $userId): void
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

        self::addRows($hacs, $rows);
    }

    private static function loadDirectGrants(HACS $hacs, int $userId): void
    {
        $rows = self::selectRows(
            'permission_grants',
            ['permission_key', 'grant_value'],
            ['user_id' => (string) $userId],
            '`priority` ASC, `permission_key` ASC',
        );

        self::addRows($hacs, $rows);
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
     */
    private static function addRows(HACS $hacs, array $rows): void
    {
        foreach ($rows as $row) {
            $hacs->addGrants([
                $row['permission_key'] => $row['grant_value'],
            ]);
        }
    }
}
