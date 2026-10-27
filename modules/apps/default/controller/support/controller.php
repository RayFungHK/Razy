<?php

/**
 * razymod/apps — named controller class.
 *
 * Every public command is a pure function of its arguments and the module
 * config: no IO, no database, no state kept between calls (safe under
 * FrankenPHP worker mode). Inputs are bounded by the Caps the validator
 * enforces before any other work.
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
use razymod\apps\Weight;

class AppsController extends Controller
{
    /**
     * Commands any module of the distributor may call. All are read-only
     * computations over the definition passed in; the allow-list exists so
     * a command added later is closed until it is listed here.
     */
    public const API_ALLOW = [
        'validate' => true,
        'compile' => true,
        'hash' => true,
        'assign-ids' => true,
        'weight' => true,
    ];

    public function __onInit(Agent $agent): bool
    {
        // Paths relative to controller/ (ClosureLoader). Registration only (RZ-009).
        $agent->addAPICommand('validate', 'api/validate');
        $agent->addAPICommand('compile', 'api/compile');
        $agent->addAPICommand('hash', 'api/hash');
        $agent->addAPICommand('assign-ids', 'api/assign_ids');
        $agent->addAPICommand('weight', 'api/weight');

        return true;
    }

    /**
     * Gate for api('razymod/apps')->… (RZ-010). The framework default allows
     * every command; this denies anything not on API_ALLOW.
     */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        return isset(self::API_ALLOW[$method]);
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
        $result = (new Validator($this->caps()))->validate($definition, self::options($options));

        return ['ok' => $result->ok(), 'issues' => $result->issuesArray()];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<mixed> $options
     *
     * @return array{ok: bool, plan: ?array<string, mixed>, issues: list<array{severity: string, path: string, code: string, message: string}>}
     */
    public function compileDefinition(array $definition, array $options = []): array
    {
        return (new Compiler($this->caps()))->compile($definition, self::options($options));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array{ok: bool, hash: ?string, bytes: int, error?: string}
     */
    public function hashDefinition(array $definition): array
    {
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
     * @return array<string, mixed>
     */
    public function assignIds(array $definition, ?array $previous = null): array
    {
        return ElementIds::assign($definition, $previous);
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function weightOf(array $definition): array
    {
        $c = $this->coefficients();

        return Weight::of($definition, $c['coefficients']) + ['coefficients_source' => $c['source']];
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
