<?php

/**
 * razymod/permissions — Template function plugin: {can} (S4).
 *
 * Enclosure form (function tags carry the `@` prefix — manual/05 §3.4):
 *
 *     {@can 'demo.publish'}<a href="/compose">Compose</a>{/can}
 *     {@can 'demo.publish' 'demo.publish.all'} ...or either...{/can}
 *     {@can $abilityVar}resolved from template data{/can}
 *
 * Grammar is the module's own (guest > super > db, api can() identical);
 * the decision NEVER throws — a dead DB hides the block, it does not
 * break the render (Service.php hot-path contract).
 *
 * RZ-004 note: on ALLOW the enclosed markup is emitted as the engine
 * already rendered it — this plugin adds no escaping and no new variable
 * interpolation; escaping remains the template author's duty at the HTML
 * boundary exactly as everywhere else. On DENY the enclosed content is
 * DROPPED, not merely hidden — server-side, the inner markup never ships.
 *
 * Shape precedent: razit-multilang plugins/Template/function.ml.php
 * (production-proven cross-module template function bound to its owning
 * controller); base class contract Template/Plugin/TFunctionCustom.php.
 */

namespace plugins\Template;

use Razy\Module\permissions\PermissionController;
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunctionCustom;

// the engine instantiates the closure with NO arguments and injects the
// owning controller via bind() (Template.php:346,:352-353) — so unlike the
// razit-multilang shape this file mirrors, no `...$arguments` forward is
// needed (phpstan: TFunctionCustom has no constructor).
return function () {
    return new class() extends TFunctionCustom {
        /** @var bool enclosure tag: {can …} … {/can} */
        protected bool $encloseContent = true;

        public function processor(Entity $entity, string $syntax = '', string $wrappedText = ''): string
        {
            // tokens: 'quoted' | "quoted" | $dotted.var (template data) | bare.code
            \preg_match_all('/\'([^\']*)\'|"([^"]*)"|\$([\w.]+)|([\w.\-]+)/', $syntax, $tokens, \PREG_SET_ORDER);

            $abilities = [];

            foreach ($tokens as $token) {
                if (($token[1] ?? '') !== '') {
                    $abilities[] = $token[1];
                } elseif (($token[2] ?? '') !== '') {
                    $abilities[] = $token[2];
                } elseif (($token[3] ?? '') !== '') {
                    $value = $entity->getValue($token[3]);

                    if (\is_string($value) && $value !== '') {
                        $abilities[] = $value;
                    }
                } elseif (($token[4] ?? '') !== '') {
                    $abilities[] = $token[4];
                }
            }

            // wrong binder or no abilities: deny, never leak, never throw
            if ($abilities === [] || !$this->controller instanceof PermissionController) {
                return '';
            }

            return $this->controller->canAbilities($abilities) ? $wrappedText : '';
        }
    };
};
