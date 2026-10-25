<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\CompileCache;
use Razy\Rzx\DirectoryResolver;
use Razy\Rzx\FunctionRegistry;
use Razy\Rzx\Runtime\Runtime;
use Razy\Rzx\SafeHtml;

/**
 * RZ-28b: the RZX design's samples compile against each other and render:
 * a screen using two compositions, fills through `<if>`, pushes, and a layout.
 */
#[CoversNothing]
class SamplesRenderTest extends TestCase
{
    use RzxHarness;

    public function testDesignSamplesCompileAndRender(): void
    {
        \mkdir($this->root . '/screens');
        foreach (['ManagementList', 'FormDialog', 'SplitDialog'] as $name) {
            \copy(__DIR__ . '/fixtures/' . $name . '.rzx', $this->root . '/kit/' . $name . '.rzx');
        }
        \copy(__DIR__ . '/fixtures/Members.rzx', $this->root . '/screens/Members.rzx');
        \copy(__DIR__ . '/fixtures/site.rzx', $this->root . '/screens/Site.rzx');
        \file_put_contents($this->root . '/kit/FieldGroup.rzx', "<props>\nreturn ['label' => Prop::string()->required()];\n</props>\n<ui-card ui-variant=\"field-group\" label={label}><Slot /></ui-card>");
        \file_put_contents($this->root . '/app/SettingsPage.rzx', "<props>\nreturn ['title' => Prop::string()->required(), 'description' => Prop::string()];\n</props>\n<section><h1>{title}</h1><p>{description}</p><Slot /></section>");

        $functions = (new FunctionRegistry())
            ->register('t', static fn (string $key): mixed => $key === 'list.labels' ? ['search' => 'Search'] : '[' . $key . ']')
            ->register('join', static fn (array $list, string $sep): string => \implode($sep, $list))
            ->register('asset', static fn (string $path): string => '/assets/' . $path);
        $resolver = new DirectoryResolver(['' => $this->root . '/screens', 'Kit' => $this->root . '/kit', 'App' => $this->root . '/app']);
        $cache = new CompileCache($resolver, $this->root . '/cache', $functions);
        $this->assertCount(7, $cache->build(['Members', 'Site', 'Kit.SplitDialog', 'Kit.FormDialog', 'Kit.ManagementList', 'Kit.FieldGroup', 'App.SettingsPage']));

        $runtime = new Runtime($cache, $functions, $this->sink);
        $page = $runtime->render('Members', ['members' => [['name' => 'Ann']], 'counts' => ['all' => 1, 'admins' => 0, 'inactive' => 0], 'canEdit' => true]);

        $this->assertStringStartsWith('<section><h1>[members.title]</h1><p>[members.description]</p><ui-data-table aria-label="[members.title]" ui-frame="card"', $page);
        $this->assertStringContainsString('ui-features="selection,sort,pagination,filter" ui-filter-key="name,login,email"', $page);
        $this->assertStringContainsString('ui-page-sizes="10,20,50,100"', $page, 'defaults from the composition schema');
        $this->assertStringContainsString('ui-rows="[{&quot;name&quot;:&quot;Ann&quot;}]"', $page);
        $this->assertStringContainsString('ui-labels="{&quot;search&quot;:&quot;Search&quot;}"', $page);
        $this->assertStringNotContainsString('ui-loading', $page, 'false boolean attribute is omitted');
        $this->assertStringContainsString('<ui-button id="app-members-enable" ui-style="outline" label="[members.enable]"></ui-button>', $page, 'the toolbar fill');
        $this->assertStringEndsWith('</ui-data-table></section>', $page);

        [$overlay, $script] = $this->sink->pushes;
        $this->assertSame(['body.overlays', 'app-members-add'], [$overlay[0], $overlay[2]]);
        $this->assertStringContainsString('<ui-dialog id="app-ws-invite-dialog" ui-variant="form" ui-size="md" ui-icon="user-plus"', $overlay[1]);
        $this->assertStringContainsString('<ui-card ui-variant="field-group" label="[members.add.login]"><ui-input name="login"', $overlay[1]);
        $this->assertSame(['body.scripts', '<script type="module" src="/assets/js/settings-page.js"></script>', 'app-members', 0], $script);

        $this->sink->pushes = [];
        $runtime->render('Members', ['members' => [], 'counts' => ['all' => 0, 'admins' => 0, 'inactive' => 0]]);
        $this->assertCount(1, $this->sink->pushes, 'canEdit=false: no dialog pushed');

        $split = $runtime->render('Kit.SplitDialog', [
            'id' => 'prefs', 'title' => 'Prefs', 'navLabel' => 'Sections', 'section' => 'a',
            'sections' => [['id' => 'a', 'label' => 'A', 'group' => 'G'], ['id' => 'b', 'label' => 'B']],
        ], ['section:b' => new SafeHtml('<p>B body</p>')]);
        $this->assertStringContainsString('<div data-ui-nav-group>G</div><button data-ui-section="a">A</button><button data-ui-section="b">B</button>', $split);
        $this->assertStringContainsString('<div data-ui-section="a"></div><div data-ui-section="b"><p>B body</p></div>', $split);

        $layout = $runtime->render('Site', ['title' => 'Members'], ['' => new SafeHtml('<main></main>')]);
        $this->assertStringStartsWith('<!DOCTYPE html><html lang="en" data-theme="light"><head><meta charset="utf-8"><title>Members</title><!--anchor:head.meta-->', $layout);
        $this->assertStringEndsWith('<body><main></main><!--anchor:body.overlays--><!--anchor:body.scripts--></body></html>', $layout);
    }
}
