<?php

declare(strict_types=1);

use HACS\HACS;

require __DIR__ . '/../src/HACS.php';

$tests = [];

$tests['allows a permission from a matching prefix'] = static function (): void {
    $hacs = new HACS(['project' => 'allow']);

    assertSame(true, $hacs->can('project.update.owner'));
};

$tests['denies when the most specific matching grant is deny'] = static function (): void {
    $hacs = new HACS([
        'project' => 'allow',
        'project.update' => 'deny',
    ]);

    assertSame(false, $hacs->can('project.update.owner'));
};

$tests['uses the most specific matching grant'] = static function (): void {
    $hacs = new HACS([
        'project' => 'allow',
        'project.update' => 'deny',
        'project.update.owner' => 'allow',
    ]);

    assertSame(
        ['key' => 'project.update.owner', 'value' => 'allow'],
        $hacs->resolvePermission('project.update.owner'),
    );
};

$tests['does not treat partial segment matches as prefixes'] = static function (): void {
    $hacs = new HACS([
        'project' => 'allow',
        'projectile' => 'deny',
    ]);

    assertSame(
        ['key' => 'projectile', 'value' => 'deny'],
        $hacs->resolvePermission('projectile.delete'),
    );
    assertSame(
        ['key' => 'project', 'value' => 'allow'],
        $hacs->resolvePermission('project.delete'),
    );
};

$tests['lets later exact grants override earlier exact grants'] = static function (): void {
    $hacs = new HACS([
        'project' => 'allow',
        'project.delete' => 'deny',
    ]);
    $hacs->addGrants([
        'project.delete' => 'allow',
    ]);

    assertSame(true, $hacs->can('project.delete'));
    assertSame(
        ['project' => 'allow', 'project.delete' => 'allow'],
        $hacs->grants(),
    );
};

$tests['does not let inherit override an earlier exact grant'] = static function (): void {
    $hacs = new HACS([
        'project' => 'allow',
        'project.update.owner' => 'deny',
    ]);
    $hacs->addGrants([
        'project.update.owner' => 'inherit',
    ]);

    assertSame(false, $hacs->can('project.update.owner'));
    assertSame(
        ['key' => 'project.update.owner', 'value' => 'deny'],
        $hacs->resolvePermission('project.update.owner'),
    );
};

$tests['ignores inherit grants when no earlier exact grant exists'] = static function (): void {
    $hacs = new HACS([
        'project' => 'allow',
        'project.update.owner' => 'inherit',
    ]);

    assertSame(true, $hacs->can('project.update.owner'));
    assertSame(
        ['key' => 'project', 'value' => 'allow'],
        $hacs->resolvePermission('project.update.owner'),
    );
};

$tests['supports a root grant'] = static function (): void {
    $hacs = new HACS(['*' => 'allow']);

    assertSame(true, $hacs->can('anything.deep'));
};

$tests['lets a specific grant override a root grant'] = static function (): void {
    $hacs = new HACS([
        '*' => 'allow',
        'admin.delete' => 'deny',
    ]);

    assertSame(true, $hacs->can('admin.read'));
    assertSame(false, $hacs->can('admin.delete'));
};

$tests['supports boolean aliases for allow and deny'] = static function (): void {
    $hacs = new HACS([
        'project' => true,
        'project.delete' => false,
    ]);

    assertSame(true, $hacs->can('project.read'));
    assertSame(false, $hacs->can('project.delete'));
};

$tests['defaults to deny without a matching grant'] = static function (): void {
    $hacs = new HACS();
    $explanation = $hacs->explainPermission('project.delete');

    assertSame(false, $hacs->can('project.delete'));
    assertSame(false, $explanation['allowed']);
    assertSame(null, $explanation['matched']);
};

$tests['keeps static test as a single grant set convenience'] = static function (): void {
    assertSame(true, HACS::test(['project' => 'allow'], 'project.read'));
    assertSame(false, HACS::test([], 'project.read'));
};

$tests['returns a structured explanation with considered grants in specificity order'] = static function (): void {
    $hacs = new HACS([
        '*' => 'allow',
        'project' => 'allow',
        'project.update' => 'deny',
        'project.update.owner' => 'inherit',
    ]);
    $explanation = $hacs->explainPermission('project.update.owner');

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
        (new HACS(['project' => 'allow']))->explain('project.read'),
    );
    assertContains(
        'No matching grant was found',
        (new HACS())->explain('project.read'),
    );
};

$tests['rejects invalid permission syntax'] = static function (): void {
    $instance = new HACS([]);

    foreach ([
        '',
        ' ',
        '*',
        '.project',
        'project.',
        'project..delete',
        'project-delete',
        'project/delete',
        'project delete',
        'project:*',
        'project._delete',
    ] as $invalid) {
        assertThrows(static fn () => $instance->can($invalid), 'Permission must match');
    }
};

$tests['rejects invalid grant keys and values while defining grants'] = static function (): void {
    assertThrows(static fn () => (new HACS())->addGrants(['project-delete' => 'allow']), 'Permission grant key must match');
    assertThrows(static fn () => new HACS(['project' => 'unknown']), 'Invalid permission grant value');
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
