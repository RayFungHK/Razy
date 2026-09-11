<?php
/**
 * golden/provider — public API command 'findUser'.
 *
 * API closure shape verified against
 * demo_modules/io/api_provider/default/controller/api/greet.php on disk:
 * the file RETURNS a Closure; the framework binds it to the controller scope
 * (verified src/library/Razy/Module/ClosureLoader.php:140) and whatever the
 * closure returns becomes the caller's result — data flows via the RETURN
 * VALUE, never via superglobals or echo (RZ-003).
 *
 * All inputs are typed parameters supplied by the caller — this module reads
 * no input superglobals anywhere (RZ-003; route args would come from
 * getRoutedInfo(), never directly from the request arrays). Static demo data on
 * purpose; the DB-backed golden path is the statement-builder + assign()
 * pattern (RZ-003) shown in demos/anti-patterns.md.
 */

return function (int $id = 0): array {
    $users = [
        1 => ['id' => 1, 'name' => 'Ada Lovelace', 'role' => 'engineer'],
        2 => ['id' => 2, 'name' => 'Grace Hopper', 'role' => 'rear admiral'],
        3 => ['id' => 3, 'name' => 'Alan Turing', 'role' => 'mathematician'],
    ];

    return [
        'found'  => isset($users[$id]),
        'user'   => $users[$id] ?? null,
        'source' => 'golden/provider::findUser',
    ];
};
