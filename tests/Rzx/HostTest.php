<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Asset\Assets;
use Razy\Controller;
use Razy\Exception\ConfigurationException;
use Razy\Exception\ModuleConfigException;
use Razy\Http\SecurityHeaders;
use Razy\Module;
use Razy\ModuleInfo;
use Razy\Rzx\CompileError;
use Razy\Rzx\Host;
use Razy\Rzx\RazyFunctions;
use Razy\Rzx\Runtime\RenderError;
use Razy\Rzx\TemplateAnchors;
use Razy\Template;
use Razy\Template\IncludeResolver;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * RZ-28c: `.rzx` inside Razy: package.php namespaces and vocabularies, screens
 * in the site layout with the shared anchors, `{@rzx}` from a `.tpl`, Razy's
 * functions, the dist config, the deploy build and Controller::render().
 */
#[CoversClass(Host::class)]
#[CoversClass(RazyFunctions::class)]
#[CoversClass(TemplateAnchors::class)]
#[CoversClass(ModuleInfo::class)]
class HostTest extends TestCase
{
    private string $dir;

    private string $module;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy_rzx_host_' . \bin2hex(\random_bytes(4));
        $this->module = $this->dir . '/kit/default';
        foreach (['rzx', 'view/admin', 'webassets/js', '../../layout'] as $sub) {
            @\mkdir($this->module . '/' . $sub, 0o777, true);
        }
        \file_put_contents($this->module . '/package.php', "<?php\nreturn ['alias' => 'kit', 'rzx' => ['namespace' => 'Kit', 'vocabulary' => 'vocabulary.json']];\n");
        \file_put_contents($this->module . '/vocabulary.json', \json_encode([
            'prefixes' => ['x-'], 'elements' => ['x-card' => ['attributes' => []]],
            'profiles' => ['screen' => ['elements' => ['p', 'main', 'h1', 'b', 'br'], 'forbiddenAttributes' => ['class']]],
        ]));
        \file_put_contents($this->module . '/webassets/js/app.js', 'export {};');
        \file_put_contents($this->module . '/rzx/Card.rzx', "<props>\nreturn ['title' => Prop::string()->required()];\n</props>\n<x-card><h1>{title}</h1><Slot /></x-card>\n<push to=\"body.scripts\"><script type=\"module\" src={asset('js/app.js')} /></push>");
        Template::addPluginFolder(SYSTEM_ROOT . '/src/plugins/Template');
        SecurityHeaders::setCurrent(SecurityHeaders::fromConfig([], 'site'));
        Assets::setCurrent(Assets::fromConfig([], 'site')->bind(
            fn (): array => ['kit' => ['version' => 'default', 'root' => $this->module . '/webassets']],
            static fn (): string => 'https://site.test',
        ));
    }

    protected function tearDown(): void
    {
        SecurityHeaders::setCurrent(null);
        Assets::setCurrent(null);
        Host::setCurrent(null);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? \rmdir((string) $file) : \unlink((string) $file);
        }
        \rmdir($this->dir);
    }

    public function testPackagePhpDeclaresRzx(): void
    {
        $this->assertSame(['namespace' => 'Kit', 'vocabulary' => 'vocabulary.json'], $this->info()->getRzx());

        foreach ([
            "'rzx' => 'Kit'" => "'rzx' must be an array",
            "'rzx' => ['namespace' => 'kit']" => 'must be PascalCase',
            "'rzx' => ['path' => '../other']" => 'inside the module',
            "'rzx' => ['vocabulary' => '/etc/v.json']" => 'inside the module',
            "'rzx' => ['profile' => '']" => 'non-empty string',
        ] as $declaration => $message) {
            \file_put_contents($this->module . '/package.php', "<?php\nreturn [{$declaration}];\n");
            try {
                $this->info();
                $this->fail('accepted ' . $declaration);
            } catch (ModuleConfigException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testScreenRendersIntoATplLayoutWithSharedAnchors(): void
    {
        \file_put_contents($this->dir . '/layout/site.tpl', "<html><title>{\$title}</title><body>{@anchor name=\"content\"}{@anchor name=\"body.scripts\"}</body></html>\n");
        $screen = $this->screen('admin/Home', "<props>\nreturn ['name' => Prop::string()->required()];\n</props>\n<main><Kit.Card title={'Hi ' ~ name}><p>{name}</p></Kit.Card></main>");
        $template = $this->template()->setLayout('@layout/site.tpl');

        $html = $this->host()->renderPage($template, $this->info(), $screen, ['name' => '<Ann>'], ['title' => 'Home']);

        $nonce = \htmlspecialchars(SecurityHeaders::current()->nonce(), ENT_QUOTES);
        $this->assertMatchesRegularExpression('#^<html><title>Home</title><body><main><x-card><h1>Hi &lt;Ann&gt;</h1><p>&lt;Ann&gt;</p></x-card></main><script nonce="' . \preg_quote($nonce, '#') . '" type="module" src="/webassets/kit/default/js/app\.js\?v=[0-9a-f]+"></script></body></html>\n$#', $html);
        $this->assertSame([], $template->anchors()->unresolved());
    }

    public function testRzxLayoutGetsThePageAsItsDefaultSlot(): void
    {
        \file_put_contents($this->dir . '/layout/site.rzx', "<props>\nreturn ['title' => Prop::string()->required()];\n</props>\n<html><head><title>{title}</title>{html(importmap())}{html(importmap())}</head><body><Slot /><Anchor name=\"body.scripts\" /></body></html>");
        $screen = $this->screen('admin/Home', '<main><Kit.Card title="x" /></main>');
        $template = $this->template()->setLayout('@layout/site.rzx');

        $html = $this->host()->renderPage($template, $this->info(), $screen, [], ['title' => 'T']);

        $this->assertStringStartsWith('<!DOCTYPE html><html><head><title>T</title><script type="importmap" nonce="', $html);
        $this->assertSame(1, \substr_count($html, 'type="importmap"'), 'the importmap prints once per response');
        $this->assertStringContainsString('<body><main><x-card><h1>x</h1></x-card></main><script nonce=', $html);
    }

    public function testScreensUseTheScreenProfileFromTheVocabulary(): void
    {
        $screen = $this->screen('admin/Bad', '<main><div class="x"></div></main>');
        $this->expectException(CompileError::class);
        $this->expectExceptionMessage('<div> is not allowed in a screen file');
        $this->host()->renderPage($this->template(), $this->info(), $screen);
    }

    public function testATplPageEmbedsAComponentWithTheBridge(): void
    {
        $host = $this->host();
        Host::setCurrent($host);
        $template = $this->template();
        $page = $template->load($this->put('view/page.tpl', "<div>{@rzx name=\"Kit.Card\" props=\$card}</div>{@anchor name=\"body.scripts\"}\n"), $this->info());
        $page->assign('card', ['title' => 'From tpl']);

        $html = $template->renderPage($page);

        $this->assertStringStartsWith('<div><x-card><h1>From tpl</h1></x-card></div><script nonce=', $html);
    }

    public function testRazyFunctions(): void
    {
        $screen = $this->screen('admin/F', "<p>{upper('ab')}|{truncate('abcdefgh', 5)}|{date(0, 'Y')}|{join(['a', 'b'], '+')}|{capitalize('word')}|{url('/a/b')}|{asset('kit:js/app.js')}|{nonce() == '' ? 'none' : 'nonce'}|{html(nl2br('<b>\\n'))}</p>");

        $html = $this->host()->renderPage($this->template(), $this->info(), $screen);

        $this->assertMatchesRegularExpression('#^<p>AB\|ab\.\.\.\|1970\|a\+b\|Word\|https://site\.test/a/b\|/webassets/kit/default/js/app\.js\?v=[0-9a-f]+\|nonce\|&lt;b&gt;<br />\n</p>$#', $html);
    }

    public function testAppFunctionsAreRegisteredOnTheHost(): void
    {
        $host = $this->host();
        $host->functions()->register('t', static fn (string $key): string => 'T:' . $key);
        $screen = $this->screen('admin/T', "<p>{t('a.b')}</p>");
        $this->assertSame('<p>T:a.b</p>', $host->renderPage($this->template(), $this->info(), $screen));
    }

    public function testConfig(): void
    {
        $host = Host::fromConfig(['rzx' => ['mode' => 'compiled', 'check_props' => 'dev', 'screen_profile' => null]], 'site', $this->dir . '/data', static fn (): array => []);
        $this->assertInstanceOf(Host::class, $host);
        $screen = $this->screen('admin/C', '<p>x</p>');
        try {
            $host->renderPage($this->template(), $this->info(), $screen);
            $this->fail('compiled mode compiled at request time');
        } catch (RenderError $e) {
            $this->assertStringContainsString('not compiled; run php Razy.phar compile --rzx', $e->getMessage());
        }

        foreach ([['rzx' => 'x'], ['rzx' => ['mode' => 'fast']], ['rzx' => ['check_props' => 'yes']], ['rzx' => ['layout_profile' => '']]] as $bad) {
            try {
                Host::fromConfig($bad, 'site', $this->dir, static fn (): array => []);
                $this->fail('accepted ' . \json_encode($bad));
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBuildCompilesEveryComponentAndScreenThenCompiledModeServesThem(): void
    {
        $this->screen('admin/Home', '<main><Kit.Card title="x" /></main>');
        $info = $this->info();
        $data = $this->dir . '/data';

        $built = Host::fromConfig(['rzx' => ['mode' => 'compiled']], 'site', $data, static fn (): array => [$info])->build();
        $this->assertArrayHasKey('Kit.Card', $built);
        $this->assertArrayHasKey(\str_replace('\\', '/', $this->module) . '/view/admin/Home.rzx', $built);
        $this->assertFileExists($data . '/compiled/rzx/site/index.php');

        $compiled = Host::fromConfig(['rzx' => ['mode' => 'compiled']], 'site', $data, static fn (): array => [$info]);
        $html = $compiled->renderPage($this->template(), $info, $this->module . '/view/admin/Home.rzx');
        $this->assertSame('<main><x-card><h1>x</h1></x-card></main>', $html);

        $this->screen('admin/Broken', '<main>{missing}</main>');
        $this->expectException(CompileError::class);
        Host::fromConfig([], 'site', $data, static fn (): array => [$info])->build();
    }

    public function testResetMakesSourceEditsVisibleToALongLivedHost(): void
    {
        $host = $this->host();
        $screen = $this->screen('admin/W', '<p>one</p>');
        $this->assertSame('<p>one</p>', $host->renderPage($this->template(), $this->info(), $screen));
        $this->screen('admin/W', '<p>two!</p>');
        $host->reset();
        $this->assertSame('<p>two!</p>', $host->renderPage($this->template(), $this->info(), $screen));
    }

    public function testControllerRenderPicksTheRzxView(): void
    {
        $this->screen('admin/Members', "<props>\nreturn ['n' => Prop::int()->required()];\n</props>\n<main><b>{n}</b></main>");
        $this->put('view/admin/Legacy.tpl', '<p>{$n}</p>');
        $controller = $this->controller();

        \ob_start();
        $controller->render('admin/Members', ['n' => 3]);
        $this->assertSame('<main><b>3</b></main>', \ob_get_clean());

        \ob_start();
        $controller->render('admin/Legacy', ['n' => 4]);
        $this->assertSame('<p>4</p>', \ob_get_clean(), 'a .tpl view renders as before');

        $this->put('view/admin/Members.tpl', '<p>dup</p>');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exists as both .tpl and .rzx');
        $controller->render('admin/Members', ['n' => 3]);
    }

    private function host(): Host
    {
        $info = $this->info();

        return Host::fromConfig([], 'site', $this->dir . '/data', static fn (): array => [$info], static fn (): string => 'https://site.test');
    }

    private function info(): ModuleInfo
    {
        return new ModuleInfo($this->dir . '/kit', ['module_code' => 'test/kit', 'author' => 'x', 'description' => 'x'], 'default');
    }

    private function template(): Template
    {
        return (new Template())->setIncludeResolver(IncludeResolver::fromConfig([], 'site', $this->dir));
    }

    private function screen(string $view, string $source): string
    {
        return $this->put('view/' . $view . '.rzx', $source);
    }

    private function put(string $relative, string $content): string
    {
        $path = $this->module . '/' . $relative;
        @\mkdir(\dirname($path), 0o777, true);
        \file_put_contents($path, $content);

        return $path;
    }

    private function controller(): Controller
    {
        $info = $this->info();
        $template = $this->template();
        $module = $this->createMock(Module::class);
        $module->method('getModuleInfo')->willReturn($info);
        $module->method('getGlobalTemplateEntity')->willReturn($template);
        $module->method('getRzxHost')->willReturn($this->host());

        return (new ReflectionClass(Controller::class))->newInstance($module);
    }
}
