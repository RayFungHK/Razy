<?php
/**
 * CLI Command: generate-skills
 *
 * Generates skills documentation files for the Razy framework, its
 * distributions, and modules. The generated Markdown files provide structured
 * context that LLM assistants can consume to understand the project
 * architecture, APIs, lifecycle events, and file layout.
 *
 * Usage:
 *   php Razy.phar generate-skills
 *   php Razy.phar generate-skills --root-only
 *
 * Options:
 *   --root-only  Generate only the root skills.md, skip distributions/modules
 *
 * Generated files:
 *   skills.md                         Framework overview
 *   skills/{dist_code}.md             Distribution context
 *   skills/{dist_code}/{module}.md    Module context
 *
 * @package Razy
 * @license MIT
 */

use Razy\Tool\SkillsGenerator;

// Check if --root-only flag was passed on the command line
$rootOnly = in_array('--root-only', $argv);

try {
    echo "🚀 Generating skills documentation...\n\n";
    
    // Instantiate the generator and produce all documentation artefacts
    $generator = new SkillsGenerator();
    $results = $generator->generate();

    // Display per-section generation results
    echo "─ Root Documentation\n";
    if ($results['root']) {
        echo "  ✓ Generated: skills.md\n";
    } else {
        echo "  ✗ Failed to generate root documentation\n";
    }

    $distCount = count($results['distributions'] ?? []);
    if ($distCount > 0) {
        echo "\n─ Distribution Contexts ($distCount distributions)\n";
        foreach ($results['distributions'] as $distCode => $status) {
            if ($status === 'generated') {
                echo "  ✓ skills/$distCode.md\n";
            } else {
                echo "  ✗ $distCode: $status\n";
            }
        }
    }

    $modCount = count($results['modules'] ?? []);
    if ($modCount > 0) {
        echo "\n─ Module Contexts ($modCount modules)\n";
        foreach ($results['modules'] as $moduleKey => $status) {
            if ($status === 'generated') {
                echo "  ✓ $moduleKey\n";
            } else {
                echo "  ✗ $moduleKey: $status\n";
            }
        }
    }

    echo "\n✅ Documentation generation complete!\n";
    echo "\nNext steps:\n";
    echo "  1. Review generated files in skills/ folder\n";
    echo "  2. Share skills.md with your LLM assistant\n";
    echo "  3. For each distribution, share skills/{dist_code}.md\n";
    echo "  4. For specific work, share skills/{dist_code}/{module}.md\n";

    if (isset($results['error'])) {
        fwrite(STDERR, "\n⚠️  Error: " . $results['error'] . "\n");
        exit(1);
    }

} catch (Throwable $e) {
    fwrite(STDERR, "❌ Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "   File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}
