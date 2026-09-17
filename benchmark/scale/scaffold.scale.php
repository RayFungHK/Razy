<?php

/**
 * SCALE site scaffold — the multi-module dist the fpm pair-A/B measures.
 *
 * Why this exists (EPOCH-2026-09, compiled column): the fpm baseline app is a
 * STANDALONE single module — 6 routes sit beneath the measured ±2x host noise
 * floor, so M2 replay there is unmeasurable by construction. The compiled-boot
 * claim is about ASSEMBLY size (N modules × manifests × __onInit × route
 * rows), so the honest instrument is an N-module multi-site dist on plain
 * fpm, compiled and legacy arms from the same phar, run PAIRED.
 *
 * Module anatomy is the gate scaffold's (proven live): module.php at the code
 * dir, package.php + controller + action under the version dir; the handler
 * echoes 'ok' byte-identically across arms. Registration is declaration-pure
 * (RZ-009), which is exactly what a compilable dist looks like.
 *
 *   php scaffold.scale.php --scale=60
 *
 * Generated into ./site/sites/scaletest/scale/ — NOT committed; the image
 * builds them (same discipline as Dockerfile.gate).
 */

$root = __DIR__ . '/site/sites/scaletest/scale';
$scale = 60;
foreach ($argv as $arg) {
    if (preg_match('/^--scale=(\d+)$/', $arg, $m)) {
        $scale = (int) $m[1];
    }
}

function put(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $content);
}

for ($i = 1; $i <= $scale; $i++) {
    $name = sprintf('m%03d', $i);
    $base = "{$root}/{$name}";

    put("{$base}/module.php", "<?php\n\nreturn [\n    'module_code' => 'scale/{$name}',\n    'author' => 'Razy Framework',\n    'description' => 'Assembly-scale measurement module (benchmark epoch 2026-09).',\n];\n");
    // the URL prefix is the version manifest's 'alias' (gate lesson live)
    put("{$base}/default/package.php", "<?php\n\nreturn [\n    'api_name' => '',\n    'alias' => 'x{$i}',\n    'require' => [],\n];\n");
    put("{$base}/default/controller/{$name}.php", "<?php\n\nuse Razy\\Agent;\nuse Razy\\Controller;\n\n/**\n * {$name} — route registration (assembly-scale site).\n */\n\nreturn new class () extends Controller {\n    public function __onInit(Agent \$agent): bool\n    {\n        \$agent->addLazyRoute('ping', 'ping');\n        return true;\n    }\n};\n");
    put("{$base}/default/controller/{$name}.ping.php", "<?php\n\nreturn function () {\n    echo 'ok';\n};\n");
}

echo "scale scaffolded: {$scale} modules under {$root}\n";
