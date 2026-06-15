<?php

namespace Deployer;

require_once 'deploy/symfony.php';

set('application', 'superscreen');
set('repository', 'git@git.loken.nl:ardent/superscreen.git');

host('oxybelis.loken.nl')
    ->setRemoteUser('www-data')
    ->set('branch', function () {
        return input()->getOption('branch') ?: 'master';
    })
    ->set('deploy_path', '/var/www/superscreen.oxybelis.loken.nl')
;

set('keep_releases', 2);
