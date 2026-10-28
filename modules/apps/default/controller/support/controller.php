<?php

/**
 * razymod/apps — named controller class.
 *
 * Every public command is a pure function of its arguments and the module
 * config: no IO, no database, no state kept between calls (safe under
 * FrankenPHP worker mode).
 *
 * Doors (review r3):
 * - __onAPICall admits only the modules named in config 'callers' (default:
 *   none) and only the commands on API_ALLOW;
 * - every command first checks the size of what it was handed (the
 *   definition, 'previous' and each of 'dependencies', at most
 *   dependencies_per_app of them) with InputGuard, before any other work;
 * - answers carry codes and plain messages, never file paths.
 */

namespace Razy\Module\apps;

use InvalidArgumentException;
use Razy\Agent;
use Razy\Controller;
use Razy\ModuleInfo;
use razymod\apps\Caps;
use razymod\apps\Compiler;
use razymod\apps\Definition\Canonical;
use razymod\apps\Definition\ElementIds;
use razymod\apps\Definition\Validator;
use razymod\apps\InputGuard;
use razymod\apps\Weight;

class AppsController extends Controller
{
    /**
     * The commands of the module. All are read-only computations over the
     * definition passed in; a command added later stays closed until it is
     * listed here.
     */
    public const API_ALLOW = [
        'validate' => true,
        'compile' => true,
        'hash' => true,
        'assignIds' => true,
        'weight' => true,
    ];

    public function __onInit(Agent $agent): bool
    {
        // Paths relative to controller/ (ClosureLoader). Registration only (RZ-009).
        $agent->addAPICommand('validate', 'api/validate');
        $agent->addAPICommand('compile', 'api/compile');
        $agent->addAPICommand('hash', 'api/hash');
        $agent->addAPICommand('assignIds', 'api/assign_ids');
        $agent->addAPICommand('weight', 'api/weight');

        return true;
    }

    /**
     * Gate for api('razymod/apps')->… (RZ-010). The framework default allows
     * every caller and command; this admits a command on API_ALLOW from a
     * module listed in config 'callers', and nothing else.
     */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        if (!isset(self::API_ALLOW[$method])) {
            return false;
        }
        $callers = $this->config()['callers'] ?? [];
        $code = $module->getCode();

        return \is_array($callers) && $code !== '' && \in_array($code, $callers, true);
    }

    /**
     * Keep only the documented options, with the right types.
     *
     * @param array<mixed> $options
     *
     * @return array{dependencies?: array<string, array<string, mixed>>, previous?: array<string, mixed>, system?: bool}
     */
    public static function options(array $options): array
    {
        $out = [];
        if (\is_array($options['dependencies'] ?? null)) {
            $deps = [];
            foreach ($options['dependencies'] as $code => $def) {
                if (\is_string($code) && \is_array($def)) {
                    $deps[$code] = $def;
                }
            }
            $out['dependencies'] = $deps;
        }
        if (\is_array($options['previous'] ?? null)) {
            $out['previous'] = $options['previous'];
        }
        if (\is_bool($options['system'] ?? null)) {
            $out['system'] = $options['system'];
        }

        return $out;
    }

    public function caps(): Caps
    {
        $caps = $this->config()['caps'] ?? [];

        return new Caps(\is_array($caps) ? $caps : []);
    }

    /**
     * @return array{coefficients: array<string, int|float>, source: string}
     */
    public function coefficients(): array
    {
        $configured = $this->config()['weight'] ?? null;
        if (\is_array($configured)) {
            Weight::checkCoefficients($configured);

            return ['coefficients' => $configured, 'source' => 'config'];
        }

        return ['coefficients' => Weight::REFERENCE, 'source' => 'reference'];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<mixed> $options
     *
     * @return array{ok: bool, issues: list<array{severity: string, path: string, code: string, message: string}>}
     */
    public function validateDefinition(array $definition, array $options = []): array
    {
        $refused = $this->refuse($definition, $options);
        if ($refused !== null) {
            return $refused;
        }
        $result = (new Validator($this->caps()))->validate($definition, self::options($options));

        return ['ok' => $result->ok(), 'issues' => $result->issuesArray()];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<mixed> $options
     *
     * @return array{ok: bool, plan?: ?array<string, mixed>, issues: list<array{severity: string, path: string, code: string, message: string}>}
     */
    public function compileDefinition(array $definition, array $options = []): array
    {
        $refused = $this->refuse($definition, $options);
        if ($refused !== null) {
            return $refused + ['plan' => null];
        }

        return (new Compiler($this->caps()))->compile($definition, self::options($options));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array{ok: bool, hash: ?string, bytes: int, error?: string}
     */
    public function hashDefinition(array $definition): array
    {
        if ($this->refuse($definition, []) !== null) {
            return ['ok' => false, 'hash' => null, 'bytes' => 0, 'error' => 'Definition is over the definition_bytes cap'];
        }
        try {
            $json = Canonical::json($definition);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'hash' => null, 'bytes' => 0, 'error' => $e->getMessage()];
        }
        $bytes = \strlen($json);
        if ($bytes > $this->caps()->get('definition_bytes')) {
            return ['ok' => false, 'hash' => null, 'bytes' => $bytes, 'error' => 'Definition is over the definition_bytes cap'];
        }

        return ['ok' => true, 'hash' => 'sha256:' . \hash('sha256', $json), 'bytes' => $bytes];
    }

    /**
     * @param array<string, mixed> $definition
     * @param ?array<string, mixed> $previous
     *
     * @return array{ok: bool, definition?: array<string, mixed>, issues: list<array{severity: string, path: string, code: string, message: string}>}
     */
    public function assignIds(array $definition, ?array $previous = null): array
    {
        $refused = $this->refuse($definition, $previous !== null ? ['previous' => $previous] : []);
        if ($refused !== null) {
            return $refused;
        }

        return ['ok' => true, 'definition' => ElementIds::assign($definition, $previous), 'issues' => []];
    }

    /**
     * Weight of a valid definition only: an invalid shape is never weighed.
     *
     * @param array<string, mixed> $definition
     * @param array<mixed> $options
     *
     * @return array<string, mixed>
     */
    public function weightOf(array $definition, array $options = []): array
    {
        $check = $this->validateDefinition($definition, $options);
        if (!$check['ok']) {
            return $check;
        }
        $c = $this->coefficients();

        return ['ok' => true, 'issues' => $check['issues']] + Weight::of($definition, $c['coefficients']) + ['coefficients_source' => $c['source']];
    }

    /**
     * The size door every command passes first.
     *
     * @param array<mixed> $definition
     * @param array<mixed> $options
     *
     * @return ?array{ok: false, issues: list<array{severity: string, path: string, code: string, message: string}>}
     */
    private function refuse(array $definition, array $options): ?array
    {
        $caps = $this->caps();
        $bytes = $caps->get('definition_bytes');
        $problem = null;
        if (!InputGuard::within($definition, $bytes)) {
            $problem = ['/', 'cap.definition_bytes', "Definition is over the definition_bytes cap ({$bytes} bytes) or nested too deep"];
        } elseif (isset($options['previous']) && !InputGuard::within($options['previous'], $bytes)) {
            $problem = ['/', 'cap.definition_bytes', 'The previous version is over the definition_bytes cap'];
        } elseif (isset($options['dependencies'])) {
            $deps = $options['dependencies'];
            if (!\is_array($deps) || \count($deps) > $caps->get('dependencies_per_app')) {
                $problem = ['/depends_on', 'cap.dependencies_per_app', 'At most ' . $caps->get('dependencies_per_app') . ' dependency definitions'];
            } else {
                foreach ($deps as $dep) {
                    if (!InputGuard::within($dep, $bytes)) {
                        $problem = ['/depends_on', 'cap.definition_bytes', 'A dependency definition is over the definition_bytes cap'];
                        break;
                    }
                }
            }
        }
        if ($problem === null) {
            return null;
        }

        return ['ok' => false, 'issues' => [['severity' => 'error', 'path' => $problem[0], 'code' => $problem[1], 'message' => $problem[2]]]];
    }

    /**
     * @return array<mixed>
     */
    private function config(): array
    {
        $config = $this->getModuleConfig();

        return $config->getArrayCopy();
    }
}
