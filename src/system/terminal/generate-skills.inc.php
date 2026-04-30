<?php

/**
 * CLI Command: generate-skills.
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
 * @license MIT
 */

namespace Razy;

use Razy\Tool\SkillsGenerator;
use Throwable;

return function (string ...$args) use (&$parameters) {
    // Check if --root-only flag was passed
    $rootOnly = \in_array('--root-only', $args, true);

    try {
        $this->writeLineLogging('{@s:bu}Skills Documentation Generator', true);
        $this->writeLineLogging('Generating skills documentation...', true);
        $this->writeLineLogging('', true);

        // Instantiate the generator and produce all documentation artefacts
        $generator = new SkillsGenerator();
        $results = $generator->generate();

        // Display per-section generation results
        $this->writeLineLogging('{@c:cyan}── Root Documentation{@reset}', true);
        if ($results['root']) {
            $this->writeLineLogging('  [{@c:green}✓{@reset}] Generated: skills.md', true);
        } else {
            $this->writeLineLogging('  [{@c:red}✗{@reset}] Failed to generate root documentation', true);
        }

        $distCount = \count($results['distributions'] ?? []);
        if ($distCount > 0) {
            $this->writeLineLogging('', true);
            $this->writeLineLogging("{@c:cyan}── Distribution Contexts ({$distCount} distributions){@reset}", true);
            foreach ($results['distributions'] as $distCode => $status) {
                if ($status === 'generated') {
                    $this->writeLineLogging("  [{@c:green}✓{@reset}] skills/{$distCode}.md", true);
                } else {
                    $this->writeLineLogging("  [{@c:red}✗{@reset}] {$distCode}: {$status}", true);
                }
            }
        }

        $modCount = \count($results['modules'] ?? []);
        if ($modCount > 0) {
            $this->writeLineLogging('', true);
            $this->writeLineLogging("{@c:cyan}── Module Contexts ({$modCount} modules){@reset}", true);
            foreach ($results['modules'] as $moduleKey => $status) {
                if ($status === 'generated') {
                    $this->writeLineLogging("  [{@c:green}✓{@reset}] {$moduleKey}", true);
                } else {
                    $this->writeLineLogging("  [{@c:red}✗{@reset}] {$moduleKey}: {$status}", true);
                }
            }
        }

        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:green}Documentation generation complete!{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Next steps:', true);
        $this->writeLineLogging('  1. Review generated files in skills/ folder', true);
        $this->writeLineLogging('  2. Share skills.md with your LLM assistant', true);
        $this->writeLineLogging('  3. For each distribution, share skills/{dist_code}.md', true);
        $this->writeLineLogging('  4. For specific work, share skills/{dist_code}/{module}.md', true);

        if (isset($results['error'])) {
            $this->writeLineLogging('', true);
            $this->writeLineLogging("{@c:yellow}[WARN]{@reset} {$results['error']}", true);

            return false;
        }
    } catch (Throwable $e) {
        $this->writeLineLogging("{@c:red}[ERROR]{@reset} {$e->getMessage()}", true);
        \error_log('[generate-skills] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        return false;
    }

    return true;
};
