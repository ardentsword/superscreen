<?php

namespace Deployer;

desc('Transfers information about current git commit to server');
task('deployment:log', function () { // https://stackoverflow.com/questions/59686270/how-to-log-deployments-in-deployer
    $branch = parse('{{branch}}');
    $date = date('Y-m-d H:i:s');
    $commitHashShort = runLocally('git rev-parse --short HEAD');
    $commit = explode(PHP_EOL, runLocally('git log -1 --pretty="%H%n%ci"'));
    $commitHash = $commit[0];
    $commitDate = $commit[1];

    $array = [
        'branch' => $branch,
        'date' => $date,
        'commitHashShort' => $commitHashShort,
        'commitHashLong' => $commitHash,
        'commitDate' => $commitDate,
    ];
    $json = json_encode($array, JSON_PRETTY_PRINT);

    runLocally("echo '$json' > release.json");
    upload('release.json', '{{release_path}}/release.json');
});
