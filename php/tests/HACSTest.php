<?php

declare(strict_types=1);

use HACS\HACS;

require __DIR__ . '/../src/HACS.php';

$tests = [];

$tests['allows a permission from a matching prefix'] = static function (): void {
    assertSame(true, HACS::test(
        HACS::defineGrants(['project' => 'allow']),
        HACS::makePermission('project.update.owner'),
    ));
};

$tests['denies when the most specific matching grant is deny'] = static function (): void {
    $grants = HACS::defineGrants([
        'project' => 'allow',
        'project.update' => 'deny',
    ]);

    assertSame(false, HACS::test($grants, HACS::makePermission('project.update.owner')));
};

$tests['uses the most specific matching grant'] = static function (): void {
    $grants = HACS::defineGrants([
        'project' => 'allow',
        'project.update' => 'deny',
        'project.update.owner' => 'allow',
    ]);

    assertSame(
        ['key' => 'project.update.owner', 'value' => 'allow'],
        HACS::resolvePermission($grants, HACS::makePermission('project.update.owner')),
    );
};

$tests['does not treat partial segment matches as prefixes'] = static function (): void {
    $grants = HACS::defineGrants([
        'project' => 'allow',
        'projectile' => 'deny',
    ]);

    assertSame(
        ['key' => 'projectile', 'value' => 'deny'],
        HACS::resolvePermission($grants, HACS::makePermission('projectile.delete')),
    );
    assertSame(
        ['key' => 'project', 'value' => 'allow'],
        HACS::resolvePermission($grants, HACS::makePermission('project.delete')),
    );
};

$tests['lets later same-specificity grants override earlier grants across grant sets'] = static function (): void {
    $grants = [
        HACS::defineGrants([
            'project' => 'allow',
            'project.delete' => 'deny',
        ]),
        HACS::defineGrants([
            'project.delete' => 'allow',
        ]),
    ];

    assertSame(true, HACS::test($grants, HACS::makePermission('project.delete')));
};

$tests['ignores inherit grants so a broader grant can apply'] = static function (): void {
    $grants = HACS::defineGrants([
        'project' => 'allow',
        'project.update.owner' => 'inherit',
    ]);

    assertSame(true, HACS::test($grants, HACS::makePermission('project.update.owner')));
};

$tests['supports a root grant'] = static function (): void {
    assertSame(true, HACS::test(
        HACS::defineGrants(['*' => 'allow']),
        HACS::makePermission('anything.deep'),
    ));
};

$tests['supports boolean aliases for allow and deny'] = static function (): void {
    $grants = HACS::defineGrants([
        'project' => true,
        'project.delete' => false,
    ]);

    assertSame(true, HACS::test($grants, HACS::makePermission('project.read')));
    assertSame(false, HACS::test($grants, HACS::makePermission('project.delete')));
};

$tests['defaults to deny without a matching grant'] = static function (): void {
    $explanation = HACS::explainPermission(HACS::defineGrants([]), HACS::makePermission('project.delete'));

    assertSame(false, $explanation['allowed']);
    assertSame(null, $explanation['matched']);
};

$tests['returns a structured explanation with considered grants in specificity order'] = static function (): void {
    $explanation = HACS::explainPermission(
        HACS::defineGrants([
            '*' => 'allow',
            'project' => 'allow',
            'project.update' => 'deny',
            'project.update.owner' => 'inherit',
        ]),
        HACS::makePermission('project.update.owner'),
    );

    assertSame(false, $explanation['allowed']);
    assertSame('project.update.owner', $explanation['permission']);
    assertSame(['key' => 'project.update', 'value' => 'deny'], $explanation['matched']);
    assertSame([
        ['key' => '*', 'value' => 'allow'],
        ['key' => 'project', 'value' => 'allow'],
        ['key' => 'project.update', 'value' => 'deny'],
    ], $explanation['considered']);
};

$tests['returns a readable explanation'] = static function (): void {
    assertContains(
        "'project.read' is allowed.",
        HACS::explain(HACS::defineGrants(['project' => 'allow']), HACS::makePermission('project.read')),
    );
    assertContains(
        'No matching grant was found',
        HACS::explain(HACS::defineGrants([]), HACS::makePermission('project.read')),
    );
};

$tests['tags valid grant keys and checked permissions'] = static function (): void {
    assertSame('Project1.Module2.Action3', HACS::permissionKey('Project1.Module2.Action3'));
    assertSame('Project1.Module2.Action3', HACS::makePermission('Project1.Module2.Action3'));
};

$tests['rejects invalid permission syntax before tagging'] = static function (): void {
    foreach ([
        '',
        ' ',
        '.project',
        'project.',
        'project..delete',
        'project-delete',
        'project/delete',
        'project delete',
        'project:*',
        'project._delete',
    ] as $invalid) {
        assertThrows(static fn () => HACS::makePermission($invalid), 'Permission must match');
        assertThrows(static fn () => HACS::permissionKey($invalid), 'Permission must match');
    }
};

$tests['rejects invalid grant keys and values while defining grants'] = static function (): void {
    assertThrows(static fn () => HACS::defineGrants(['project-delete' => 'allow']), 'Permission grant key must match');
    assertThrows(static fn () => HACS::defineGrants(['project' => 'unknown']), 'Invalid permission grant value');
};

foreach ($tests as $name => $test) {
    try {
        $test();
        echo ".";
    } catch (Throwable $error) {
        echo PHP_EOL . "FAILED: {$name}" . PHP_EOL;
        echo $error->getMessage() . PHP_EOL;
        exit(1);
    }
}

echo PHP_EOL . count($tests) . ' tests passed.' . PHP_EOL;

function assertSame(mixed $expected, mixed $actual): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        );
    }
}

function assertContains(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(sprintf('Expected "%s" to contain "%s".', $haystack, $needle));
    }
}

function assertThrows(Closure $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $messagePart)) {
            return;
        }

        throw new RuntimeException(
            sprintf('Expected exception containing "%s", got "%s".', $messagePart, $error->getMessage()),
        );
    }

    throw new RuntimeException(sprintf('Expected exception containing "%s".', $messagePart));
}
