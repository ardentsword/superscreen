<?php

namespace Deployer;

require_once 'recipe/common.php';
require_once 'deploy/git.php';

// [Optional] Allocate tty for git clone. Default value is false.
set('git_tty', true);

// Shared across releases: logs, the live layout state (JSON store), and the
// uncommitted local env. var/state.json persists the layout over deploys.
set('shared_dirs', ['var/log']);
set('shared_files', ['.env.local', 'var/state.json']);

set('allow_anonymous_stats', false);

set('console_options', fn () => '--no-interaction');
set('bin/console', fn () => parse('{{release_path}}/bin/console'));

set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader --no-scripts');

desc('Clear cache');
task('cache:clear', fn () => run('{{bin/php}} {{bin/console}} cache:clear {{console_options}} --no-warmup'));

desc('Warm up cache');
task('cache:warmup', fn () => run('{{bin/php}} {{bin/console}} cache:warmup {{console_options}}'));

desc('Shows current deployed version');
task('deploy:current', function () {
    $current = run('readlink {{deploy_path}}/current');
    writeln("Current deployed version: $current");
});

// No database (JSON file storage) and no asset pipeline (vanilla assets shipped
// in public/), so this is leaner than the usual Symfony recipe.
desc('Deploy project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deployment:log',
    'cache:clear',
    'cache:warmup',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:current',
]);

after('deploy', 'deploy:success');

after('deploy:failed', 'deploy:unlock');
