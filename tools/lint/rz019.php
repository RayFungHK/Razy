<?php

/**
 * RZ-019 detector, shared by tools/lint-module-discipline.php (module trees)
 * and tools/lint-style.php (framework src): a value concatenated or
 * interpolated into a query-builder syntax string.
 *
 * Simple Syntax strings (where / having / order / select / from / update /
 * delete) are parsed by the engine; a value glued into the text becomes
 * syntax. Values go in as `:name` + assign() (docs/STYLE.md §3). An
 * identifier spliced from the caller's own allow-list or config is the one
 * legitimate case, and it carries `lint-allow: RZ-019 <reason>`.
 *
 * Returns per-line regexes; a match is one finding.
 */
declare(strict_types=1);

// Builder methods whose first argument is Simple Syntax text
$methods = '(?:where|orWhere|having|order|orderBy|group|groupBy|select|from)';

return [
    // "...$x..." or "...{$x}..." as the syntax argument
    '#->' . $methods . '\s*\(\s*"[^"]*\$#',
    // '...' . $x  /  $x . '...'  as the syntax argument
    '#->' . $methods . '\s*\(\s*(?:\'[^\']*\'|\$[A-Za-z_][\w>\-]*)\s*\.#',
    // update('t', [... '...' . $x ...]) / update('t', ["...$x"])
    '#->update\s*\([^;]*\[[^;\]]*(?:\'[^\']*\'\s*\.\s*\$|\$[A-Za-z_][\w>\-]*\s*\.\s*\'|"[^"]*\$)#',
    // delete('t', [...], '...' . $x) / delete('t', [...], "...$x")
    '#->delete\s*\([^;]*,\s*(?:\'[^\']*\'\s*\.|"[^"]*\$)#',
];
