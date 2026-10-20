<?php

/**
 * RZ-09: content-hashed asset URLs, the ES module importmap (+ its CSP hash)
 * and the /webassets/{alias}/{version}/{file} server, from dist.php 'assets'.
 *
 * @license MIT
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Asset\AssetManifest;
use Razy\Asset\Assets;
use Razy\Exception\ConfigurationException;
use Razy\Http\SecurityHeaders;
use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

#[CoversClass(Assets::class)]
#[CoversClass(AssetManifest::class)]
class AssetsTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = \sys_get_temp_dir() . '/razy_assets_' . \bin2hex(\random_bytes(6));
        $files = [
            'core/default/webassets/js/app.js' => "import './dom.js';",
            'core/default/webassets/js/dom.js' => 'export const dom = 1;',
            'core/default/webassets/css/site.css' => 'body{}',
            'core/default/webassets/js/my file.js' => 'export {};',
            'ui/1.0.0/webassets/lib/button.mjs' => 'export {};',
            'core/default/package.php' => '<?php return [];',
        ];
        foreach ($files as $path => $content) {
            @\mkdir(\dirname($this->base . '/' . $path), 0o777, true);
            \file_put_contents($this->base . '/' . $path, $content);
        }
    }

    protected function tearDown(): void
    {
        Assets::setCurrent(null);
        SecurityHeaders::setCurrent(null);
        $remove = static function (string $dir) use (&$remove): void {
            foreach (\scandir($dir) ?: [] as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $path = $dir . '/' . $entry;
                \is_dir($path) ? $remove($path) : \unlink($path);
            }
            \rmdir($dir);
        };
        $remove($this->base);
    }

    public static function unservable(): array
    {
        return [
            'wrong version' => ['/webassets/core/1.0.0/js/app.js'],
            'unknown alias' => ['/webassets/nope/default/js/app.js'],
            'traversal' => ['/webassets/core/default/../package.php'],
            'encoded traversal' => ['/webassets/core/default/%2e%2e/package.php'],
            'no file' => ['/webassets/core/default/'],
            'not webassets' => ['/core/default/js/app.js'],
        ];
    }

    public static function invalidConfigs(): array
    {
        return [
            'string' => ['on'],
            'unknown key' => [['serv' => true]],
            'serve not bool' => [['serve' => 'yes']],
            'negative max_age' => [['max_age' => -1]],
            'immutable_max_age not int' => [['immutable_max_age' => '1y']],
            'base_url with newline' => [['base_url' => "https://cdn\n"]],
            'imports not array' => [['imports' => 'x']],
            'import target without alias' => [['imports' => ['x' => 'app.js']]],
            'import target traversal' => [['imports' => ['x' => 'core/../secret.js']]],
        ];
    }

    public function testUrlCarriesTheContentHash(): void
    {
        $url = $this->assets()->url('core', 'js/app.js');

        $this->assertMatchesRegularExpression('~^/app/webassets/core/default/js/app\.js\?v=[0-9a-f]{16}$~', $url);
        $this->assertSame($url, $this->assets()->url('core', '/js/app.js'), 'one URL per file');
    }

    public function testHashChangesWithTheContent(): void
    {
        $before = $this->assets()->url('core', 'js/dom.js');
        \file_put_contents($this->base . '/core/default/webassets/js/dom.js', 'export const dom = 2;');
        \touch($this->base . '/core/default/webassets/js/dom.js', \time() + 5);
        \clearstatcache();

        $this->assertNotSame($before, $this->assets()->url('core', 'js/dom.js'));
    }

    public function testMissingFileFallsBackToThePlainUrl(): void
    {
        $this->assertSame('/app/webassets/core/default/js/nope.js', $this->assets()->url('core', 'js/nope.js'));
    }

    public function testSegmentsAreUrlEncoded(): void
    {
        $this->assertStringStartsWith('/app/webassets/core/default/js/my%20file.js?v=', $this->assets()->url('core', 'js/my file.js'));
    }

    public function testBaseUrlPrefixesEveryUrl(): void
    {
        $url = $this->assets(['assets' => ['base_url' => 'https://cdn.example.test/static']])->url('ui', 'lib/button.mjs');
        $this->assertStringStartsWith('https://cdn.example.test/static/webassets/ui/1.0.0/lib/button.mjs?v=', $url);
    }

    public function testUnknownModuleIsALoudError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->assets()->url('nope', 'js/app.js');
    }

    public function testImportMapMapsEveryModuleScriptToItsHashedUrl(): void
    {
        $assets = $this->assets(['assets' => ['imports' => ['ui/button' => 'ui/lib/button.mjs']]]);
        $map = $assets->importMap();

        $this->assertSame([
            '/app/webassets/core/default/js/app.js' => $assets->url('core', 'js/app.js'),
            '/app/webassets/core/default/js/dom.js' => $assets->url('core', 'js/dom.js'),
            '/app/webassets/core/default/js/my%20file.js' => $assets->url('core', 'js/my file.js'),
            '/app/webassets/ui/1.0.0/lib/button.mjs' => $assets->url('ui', 'lib/button.mjs'),
            'ui/button' => $assets->url('ui', 'lib/button.mjs'),
        ], $map['imports']);
    }

    public function testImportMapTagCarriesTheNonceAndItsCspHashMatches(): void
    {
        $assets = $this->assets();
        $tag = $assets->importMapTag('n0nce');

        $this->assertMatchesRegularExpression('~^<script type="importmap" nonce="n0nce">(.+)</script>$~s', $tag);
        \preg_match('~>(.+)</script>$~s', $tag, $m);
        $this->assertSame($assets->importMapJson(), $m[1]);
        $this->assertSame("'sha256-" . \base64_encode(\hash('sha256', $m[1], true)) . "'", $assets->importMapHash());
        $this->assertStringNotContainsString('nonce=', $assets->importMapTag(null));
    }

    public function testImportMapJsonCannotCloseTheScriptElement(): void
    {
        $json = $this->assets(['assets' => ['imports' => ['</script><b>' => 'core/js/app.js']]])->importMapJson();
        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringNotContainsString('<b>', $json);
    }

    public function testServeMatchesTheAliasVersionLayout(): void
    {
        $assets = $this->assets(['assets' => ['serve' => true]]);

        $hit = $assets->match('/webassets/core/default/js/app.js/');
        $this->assertNotNull($hit);
        $this->assertSame(\realpath($this->base . '/core/default/webassets/js/app.js'), $hit['path']);
        $this->assertSame($this->hash('core/default/webassets', 'js/app.js'), $hit['hash']);

        $this->assertNotNull($assets->match('/webassets/ui/1.0.0/lib/button.mjs'));
    }

    #[DataProvider('unservable')]
    public function testServeRejects(string $path): void
    {
        $this->assertNull($this->assets(['assets' => ['serve' => true]])->match($path));
    }

    public function testServingIsOffUnlessConfigured(): void
    {
        // PHPUnit runs under the CLI SAPI, so the dev-server default (on) does not apply.
        $this->assertFalse($this->assets()->isServing());
        $this->assertNull($this->assets()->match('/webassets/core/default/js/app.js'));
        $this->assertFalse($this->assets()->serve('/webassets/core/default/js/app.js'));
        $this->assertFalse($this->assets(['assets' => ['serve' => false]])->isServing());
    }

    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigDiesAtBoot(mixed $config): void
    {
        $this->expectException(ConfigurationException::class);
        Assets::fromConfig(['assets' => $config], 'site');
    }

    public function testAbsentConfigIsAccepted(): void
    {
        $this->assertInstanceOf(Assets::class, Assets::fromConfig([], 'site'));
        $this->assertInstanceOf(Assets::class, Assets::current());
    }

    public function testAssetTemplateFunctionPrintsTheEscapedHashedUrl(): void
    {
        $assets = $this->assets(['assets' => ['base_url' => '/s?a&b/']]);
        Assets::setCurrent($assets);

        $out = $this->plugin('asset')->parse($this->entity(), " file='js/app.js' module='core'");
        $this->assertSame(\htmlspecialchars($assets->url('core', 'js/app.js'), ENT_QUOTES), $out);
        $this->assertStringContainsString('&amp;', (string) $out);
    }

    public function testImportmapTemplateFunctionUsesTheRequestNonce(): void
    {
        Assets::setCurrent($this->assets());
        $headers = SecurityHeaders::fromConfig([], 'site');
        SecurityHeaders::setCurrent($headers);

        $out = $this->plugin('importmap')->parse($this->entity(), '');
        $this->assertSame(Assets::current()->importMapTag($headers->nonce()), $out);
    }

    public function testDistributorWiresAssetsIntoEveryResponse(): void
    {
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        $this->assertStringContainsString('Assets::fromConfig($config, $distCode)', $src);
        $this->assertStringContainsString('Assets::setCurrent($this->assets);', $src);
        $this->assertSame(3, \substr_count($src, '$this->assets->serve($this->urlQuery)'), 'matchRoute() (before the session; after the lifecycle in a worker) and dispatch()');
        $this->assertLessThan(\strpos($src, '$this->setSession();', \strpos($src, 'public function matchRoute')), \strpos($src, '!$worker && $this->assets->serve($this->urlQuery)'));

        $main = (string) \file_get_contents(SYSTEM_ROOT . '/src/main.php');
        $this->assertStringContainsString('Asset\Assets::setCurrent(null);', $main, 'worker mode resets per request');
    }

    public function testDevRouterNoLongerGuessesTheWrongLayout(): void
    {
        $router = (string) \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/serve.php');
        $this->assertStringNotContainsString('function resolveWebassetPath', $router);
        $this->assertStringContainsString('realpath', $router);
        // php -S sets SCRIPT_NAME to an extension-bearing request path, which the
        // bootstrap read as a subdirectory install (URL_QUERY became '/a.js').
        $this->assertStringContainsString("\$_SERVER['SCRIPT_NAME']     = '/index.php';", $router);
    }

    public function testNewDistSkeletonDocumentsTheKey(): void
    {
        $skeleton = (string) \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/dist.php.tpl');
        $this->assertStringContainsString("'assets'", $skeleton);
    }

    private function assets(array $config = [], string $siteUrl = 'http://localhost/app'): Assets
    {
        return Assets::fromConfig($config, 'site')->bind(
            fn (): array => [
                'core' => ['version' => 'default', 'root' => $this->base . '/core/default/webassets'],
                'ui' => ['version' => '1.0.0', 'root' => $this->base . '/ui/1.0.0/webassets'],
            ],
            static fn (): string => $siteUrl,
        );
    }

    private function hash(string $root, string $file): string
    {
        return (string) (new AssetManifest($this->base . '/' . $root))->hash($file);
    }

    private function plugin(string $name): TFunction
    {
        $factory = require SYSTEM_ROOT . '/src/plugins/Template/function.' . $name . '.php';
        $plugin = $factory();
        $plugin->setName($name);

        return $plugin;
    }

    private function entity(): Entity
    {
        return new class() extends Entity {
            public function __construct()
            {
            }

            public function parseValue(string $content): mixed
            {
                return \trim(\trim($content), "'\"");
            }

            public function getModule(): ?Razy\ModuleInfo
            {
                return null;
            }
        };
    }
}
