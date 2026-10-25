<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\Compile\VocabularyCheck;
use Razy\Rzx\CompileCache;
use Razy\Rzx\CompileError;
use Razy\Rzx\DirectoryResolver;
use Razy\Rzx\Profile;
use Razy\Rzx\Runtime\Runtime;
use Razy\Rzx\Vocabulary;

/**
 * RZ-28c: vocabularies and profiles are data a package registers; the
 * compiler only applies what is registered. With nothing registered, no
 * vocabulary check runs.
 */
#[CoversClass(Vocabulary::class)]
#[CoversClass(Profile::class)]
#[CoversClass(VocabularyCheck::class)]
class VocabularyTest extends TestCase
{
    use RzxHarness;

    private const KIT = [
        'prefixes' => ['x-'],
        'attributePrefixes' => ['x-', 'tone', 'busy'],
        'elements' => [
            'x-button' => ['attributes' => ['label' => 'string', 'tone' => ['plain', 'danger'], 'busy' => 'boolean', 'x-size' => ['sm', 'md']]],
            'x-bar' => ['attributes' => []],
        ],
        'forbiddenAttributes' => ['data-x-part'],
        'hooks' => ['--x-gap'],
        'slotMarker' => 'data-x-slot',
        'mappings' => ['motion' => ['attribute' => 'data-x-motion', 'values' => ['fade', 'rise']]],
        'profiles' => [
            'screen' => ['elements' => ['p', 'a', 'b'], 'customElements' => true, 'forbiddenAttributes' => ['class'], 'hooks' => false, 'mappings' => false],
            'composition' => [],
        ],
    ];

    /**
     * @return iterable<string, array{string, ?string, string, string}>
     */
    public static function rejected(): iterable
    {
        yield 'unknown element' => ['<x-nope />', 'composition', 'RZX151', 'Unknown element <x-nope>'];
        yield 'unknown governed attribute' => ['<x-button x-color="red" />', 'composition', 'RZX154', 'Unknown attribute x-color on <x-button>'];
        yield 'enum value' => ['<x-button tone="loud" />', 'composition', 'RZX155', 'tone="loud" on <x-button> is not one of plain, danger'];
        yield 'enum literal expression' => ["<x-button x-size={'xl'} />", 'composition', 'RZX155', 'x-size="xl"'];
        yield 'boolean given a string' => ['<x-button busy="yes" />', 'composition', 'RZX155', 'busy on <x-button> is a boolean'];
        yield 'forbidden attribute' => ['<div data-x-part="p"></div>', 'composition', 'RZX152', 'internal to the UI kit'];
        yield 'unknown hook' => ["<div hooks={{ '--x-pad': '1px' }}></div>", 'composition', 'RZX157', 'Unknown hook --x-pad'];
        yield 'mapping value' => ['<div motion="spin"></div>', 'composition', 'RZX155', 'motion="spin" is not one of fade, rise'];
        yield 'mapping expression' => ["<div motion={'rise'}></div>", 'composition', 'RZX156', 'takes a literal value'];
        yield 'screen element' => ['<p><div></div></p>', 'screen', 'RZX150', '<div> is not allowed in a screen file'];
        yield 'screen class' => ['<p class="x"></p>', 'screen', 'RZX153', 'class is not allowed in a screen file'];
        yield 'screen hooks' => ["<p hooks={{ '--x-gap': '1px' }}></p>", 'screen', 'RZX153', 'hooks is not allowed'];
        yield 'screen motion' => ['<p motion="fade"></p>', 'screen', 'RZX153', 'motion is not allowed'];
    }

    public function testNothingRegisteredMeansNoVocabularyChecks(): void
    {
        $html = $this->renderWith(Vocabulary::none(), null, '<x-anything any-attr="1" motion="spin" data-x-part="p"></x-anything>');
        $this->assertSame('<x-anything any-attr="1" motion="spin" data-x-part="p"></x-anything>', $html);
    }

    public function testKnownElementsAttributesAndMappings(): void
    {
        $html = $this->renderWith($this->kit(), 'composition', '<div class="c" motion="rise"><x-button label="Save" tone="danger" busy={true} id="b" aria-label="s" data-k="1" /></div>');
        $this->assertSame('<div class="c" data-x-motion="rise"><x-button label="Save" tone="danger" busy id="b" aria-label="s" data-k="1"></x-button></div>', $html);
    }

    #[DataProvider('rejected')]
    public function testRejected(string $markup, ?string $profile, string $code, string $message): void
    {
        try {
            $this->renderWith($this->kit(), $profile, $markup);
            $this->fail('accepted: ' . $markup);
        } catch (CompileError $e) {
            $this->assertSame($code, $e->rzxCode, $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testAScreenMayUseAllowedTagsAndCustomElements(): void
    {
        $this->assertSame('<p><a href="/x">x</a><x-bar></x-bar></p>', $this->renderWith($this->kit(), 'screen', '<p><a href="/x">x</a><x-bar /></p>'));
    }

    public function testNamedFillChildrenGetTheSlotMarker(): void
    {
        $this->component('Kit.Bar', "<props>\nreturn ['ids' => Prop::list()];\n</props>\n<x-bar><Slot name=\"end\" /><Slot /><for each={ids} as=\"i\"><Slot name={'tab:' ~ i} /></for></x-bar>");
        $this->component('T', <<<'RZX'
            <Kit.Bar ids={['a']}>
              <Fill slot="end"><x-button label="A" /><if test={true}><x-button label="B" data-x-slot="custom" /></if>text</Fill>
              <Fill slot={'tab:' ~ 'a'}><b>tab</b></Fill>
              <p>default</p>
            </Kit.Bar>
            RZX);
        $html = $this->runtimeWith($this->kit())->render('T');
        $this->assertSame('<x-bar><x-button label="A" data-x-slot="end"></x-button><x-button label="B" data-x-slot="custom"></x-button>text<p>default</p><b data-x-slot="tab:a">tab</b></x-bar>', $html);
    }

    public function testChangingTheVocabularyRecompiles(): void
    {
        $this->component('T', '<x-button tone="plain" />');
        $this->runtimeWith($this->kit())->render('T');
        $hash = (include $this->root . '/cache/index.php')['components']['T']['hash'];

        $data = self::KIT;
        $data['elements']['x-button']['attributes']['tone'] = ['danger'];
        $this->expectException(CompileError::class);
        $this->expectExceptionMessage('is not one of danger');
        try {
            $this->runtimeWith(Vocabulary::fromArray($data))->render('T');
        } finally {
            $this->assertSame($hash, (include $this->root . '/cache/index.php')['components']['T']['hash'], 'the failed compile left the old entry');
        }
    }

    public function testAVocabularyCannotWeakenBuiltInChecks(): void
    {
        $lenient = Vocabulary::fromArray([
            'prefixes' => ['x-'],
            'attributePrefixes' => ['on', 'style', 'src'],
            'elements' => ['x-box' => ['attributes' => ['onclick' => 'string', 'style' => 'string', 'srcdoc' => 'string', 'href' => 'string']]],
            'profiles' => ['composition' => ['hooks' => true, 'mappings' => true]],
        ]);
        foreach ([
            '<x-box onclick="alert(1)"></x-box>' => 'RZX132',
            '<x-box style="color:red"></x-box>' => 'RZX131',
            '<x-box srcdoc="x"></x-box>' => 'RZX135',
            '<x-box href="javascript:alert(1)"></x-box>' => 'RZX134',
            '<div><script>x</script></div>' => 'RZX133',
        ] as $markup => $code) {
            try {
                $this->renderWith($lenient, 'composition', $markup);
                $this->fail('compiled: ' . $markup);
            } catch (CompileError $e) {
                $this->assertSame($code, $e->rzxCode, $markup);
            }
        }
    }

    public function testMergeJoinsListsAndLaterDefinitionsWin(): void
    {
        $a = Vocabulary::fromArray(['prefixes' => ['a-'], 'hooks' => ['--a'], 'slotMarker' => 'data-a', 'elements' => ['a-x' => ['attributes' => ['k' => 'string']]]]);
        $b = Vocabulary::fromArray(['prefixes' => ['b-'], 'hooks' => ['--b'], 'elements' => ['a-x' => ['attributes' => []]]]);
        $merged = Vocabulary::merge($a, $b);
        $this->assertSame(['a-', 'b-'], $merged->prefixes);
        $this->assertSame(['--a', '--b'], $merged->hooks);
        $this->assertSame('data-a', $merged->slotMarker);
        $this->assertSame([], $merged->elements['a-x']);
        $this->assertTrue(Vocabulary::merge()->isEmpty());
        $this->assertNull(Vocabulary::merge($b)->slotMarker);
        $this->assertNull(Vocabulary::none()->hooks, 'no hook list = any hook');
    }

    public function testInvalidVocabularies(): void
    {
        foreach ([
            ['prefixes' => 'x-'], ['elements' => ['Bad' => []]], ['mappings' => ['m' => ['values' => []]]], ['hooks' => [1]],
            // a vocabulary can never make the compiler emit a handler, a style, a URL or broken markup
            ['mappings' => ['motion' => ['attribute' => 'onclick']]],
            ['mappings' => ['motion' => ['attribute' => 'href']]],
            ['mappings' => ['motion' => ['attribute' => 'style']]],
            ['mappings' => ['motion' => ['attribute' => 'data-x" onload="alert(1)']]],
            ['slotMarker' => 'onclick'],
            ['slotMarker' => 'src'],
            ['slotMarker' => 'data-'],
            ['slotMarker' => 'data-X-slot'],
            ['slotMarker' => ['data-x']],
        ] as $bad) {
            try {
                Vocabulary::fromArray($bad, 'kit.json');
                $this->fail('accepted ' . \json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith('kit.json: ', $e->getMessage());
            }
        }
        $file = $this->root . '/v.json';
        \file_put_contents($file, '{not json');
        $this->expectException(InvalidArgumentException::class);
        Vocabulary::fromFile($file);
    }

    private function kit(): Vocabulary
    {
        $file = $this->root . '/kit.json';
        \file_put_contents($file, \json_encode(self::KIT));

        return Vocabulary::fromFile($file);
    }

    private function renderWith(Vocabulary $vocabulary, ?string $profile, string $markup): string
    {
        $path = $this->component('T', $markup);
        $cache = new CompileCache(new DirectoryResolver(['' => $this->root . '/app']), $this->root . '/cache', $this->functions(), 'dev', $vocabulary);

        return (new Runtime($cache, $this->functions()))->renderFile($path, [], [], $profile);
    }

    private function runtimeWith(Vocabulary $vocabulary): Runtime
    {
        $resolver = new DirectoryResolver();
        $resolver->add('', $this->root . '/app', 'composition')->add('Kit', $this->root . '/kit', 'composition');

        return new Runtime(new CompileCache($resolver, $this->root . '/cache', $this->functions(), 'dev', $vocabulary), $this->functions());
    }
}
