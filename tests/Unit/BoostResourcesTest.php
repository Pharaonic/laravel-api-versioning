<?php

declare(strict_types=1);

const BOOST_ROOT = __DIR__.'/../../resources/boost';

test('the guideline exists and names the skill', function () {
    $guideline = (string) file_get_contents(BOOST_ROOT.'/guidelines/core.blade.php');

    expect($guideline)->toContain('pharaonic/laravel-api-versioning')
        ->and($guideline)->toContain('api-versioning-development')
        ->and(substr_count($guideline, '@verbatim'))->toBe(substr_count($guideline, '@endverbatim'), 'Every code snippet is wrapped in @verbatim.');
});

test('every skill has frontmatter matching its folder', function () {
    $skills = glob(BOOST_ROOT.'/skills/*/SKILL.md') ?: [];
    expect($skills)->not->toBe([]);

    foreach ($skills as $path) {
        $contents = (string) file_get_contents($path);
        expect(preg_match('/\A---\n(.*?)\n---\n/s', $contents, $match))->toBe(1, $path.' starts with YAML frontmatter.');

        $fields = [];

        foreach (explode("\n", $match[1]) as $line) {
            if (preg_match('/^([a-z]+):\s*(.+)$/', $line, $field) === 1) {
                $fields[$field[1]] = $field[2];
            }
        }

        expect($fields['name'] ?? null)->toBe(basename(dirname($path)), 'The skill name is its folder name.')
            ->and(trim($fields['description'] ?? ''))->not->toBe('', 'The skill has a description.');
    }
});
