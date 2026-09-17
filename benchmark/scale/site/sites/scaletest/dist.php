<?php

/*
 * scaletest dist — the assembly-scale measurement distributor.
 * greedy: every scaffolded module loads on boot (that IS the measurement);
 * compiled_boot: the opt-in — the compiled arm bakes the artifact at build,
 * the legacy control ships the flag with NO artifact (absent = full boot),
 * so both arms run the identical dist definition.
 */

return [
    'dist' => 'scaletest',
    'global_module' => false,
    'autoload_shared' => false,
    'greedy' => true,
    'strict' => false,
    'compiled_boot' => true,
];
