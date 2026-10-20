<?php

// phpcs.ignore

/**
 * RZ-21: a `*.apex` binding in sites.inc.php registers as written.
 *
 * updateSites() used to drop `*.apex` bindings for dists named `default`,
 * `personal` or `localhost`, and for local apexes (`*.localhost`): one
 * application's tenant policy living in the framework. Tenant rules belong in
 * a request resolver (RZ-12), not in domain registration.
 *
 * @license MIT
 */

use PHPUnit\Framework\Attributes\DataProvider;
use Razy\Application;

class WildcardBindingTest extends PHPUnit\Framework\TestCase
{
    /** @var list<string> fixture dists this test created (and so may remove) */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $code) {
            @unlink(SITES_FOLDER . '/' . $code . '/dist.php');
            @rmdir(SITES_FOLDER . '/' . $code);
        }
        $this->created = [];
    }

    public static function bindings(): array
    {
        return [
            'dist named personal' => ['*.example.com', 'personal'],
            'dist named default' => ['*.example.com', 'default'],
            'dist named localhost' => ['*.example.com', 'localhost'],
            'local apex' => ['*.localhost', 'rz21_fixture'],
        ];
    }

    #[DataProvider('bindings')]
    public function testWildcardBindingRegistersAsWritten(string $domain, string $dist): void
    {
        $this->fixtureDist($dist);
        $multisite = $this->multisiteFor([$domain => ['/' => $dist]]);

        $this->assertSame($dist, $multisite[$domain]['/'] ?? null, "{$domain} => {$dist} must register");
    }

    public function testSecondaryWildcardOnSameDistRegisters(): void
    {
        $this->fixtureDist('personal');
        $multisite = $this->multisiteFor([
            'personal.example.com' => ['/' => 'personal'],
            '*.example.com' => ['/' => 'personal'],
        ]);

        $this->assertSame('personal', $multisite['personal.example.com']['/'] ?? null);
        $this->assertSame('personal', $multisite['*.example.com']['/'] ?? null);
    }

    private function fixtureDist(string $code): void
    {
        $path = SITES_FOLDER . '/' . $code;
        if (is_file($path . '/dist.php')) {
            return;
        }
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        file_put_contents($path . '/dist.php', "<?php\n\nreturn ['dist' => '{$code}', 'greedy' => false];\n");
        $this->created[] = $code;
    }

    private function multisiteFor(array $domains): array
    {
        $app = new Application();
        $ref = new ReflectionClass($app);

        $config = $ref->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($app, ['domains' => $domains, 'alias' => []]);

        $app->updateSites();

        $multisite = $ref->getProperty('multisite');
        $multisite->setAccessible(true);

        return $multisite->getValue($app);
    }
}
