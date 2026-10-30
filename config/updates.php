<?php

use App\Core\Env;

// Admin → Updates: where new versions of this app come from.
return [
    // owner/name of the GitHub repository holding this app's code.
    'repository' => (string) Env::get('UPDATE_REPOSITORY', 'narendersinghh/nica'),
    // Branch followed by "Update to latest commit".
    'branch' => (string) Env::get('UPDATE_BRANCH', 'main'),
    // Only needed for a private repository (fine-grained token, read-only "Contents").
    'token' => (string) Env::get('UPDATE_GITHUB_TOKEN', ''),
    // Old code backups kept in storage/updates/ for rollback.
    'keep_backups' => 3,
];
