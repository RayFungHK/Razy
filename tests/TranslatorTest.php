<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Translation\Pluralizer;
use Razy\Translation\Translator;

/**
 * i18n backbone tests (Lane B4): Traditional Chinese first-class, en fallback.
 */
#[CoversClass(Translator::class)]
#[CoversClass(Pluralizer::class)]
class TranslatorTest extends TestCase
{
    private string $langDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->langDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_lang_test_' . \uniqid();

        // zh-Hant (primary)
        \mkdir($this->langDir . '/zh-Hant', 0o777, true);
        $this->writeGroup('zh-Hant', 'messages', [
            'welcome' => '歡迎, :name！',
            'cart' => [
                'empty' => '購物車是空的',
                'items' => '有 :count 件商品|有 :count 件商品',
                'explicit' => '{0} 沒有商品|{1} 只剩一件|[2,*] 共 :count 件',
            ],
            'deep' => ['nested' => ['value' => '深層值']],
        ]);

        // en (fallback + switch target)
        \mkdir($this->langDir . '/en', 0o777, true);
        $this->writeGroup('en', 'messages', [
            'welcome' => 'Hello, :name!',
            'only_en' => 'english only',
            'cart' => [
                'items' => 'There :count item|There are :count items',
                'explicit' => '{0} no items|{1} exactly one|[2,*] :count items',
            ],
        ]);

        // namespaced module dictionary (RZ-001 style ownership)
        $moduleLang = $this->langDir . '/shop_ns';
        \mkdir($moduleLang . '/zh-Hant', 0o777, true);
        \file_put_contents(
            $moduleLang . '/zh-Hant/errors.php',
            '<?php return ' . \var_export(['not_found' => '找不到 :thing'], true) . ';',
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->langDir);
        parent::tearDown();
    }

    // ── basics ────────────────────────────────────────────────

    public function testDefaultLocaleIsTraditionalChinese(): void
    {
        $this->assertSame('zh-Hant', (new Translator($this->langDir))->getLocale());
    }

    public function testTranslatesPrimaryLocaleWithPlaceholder(): void
    {
        $this->assertSame('歡迎, 阿明！', $this->translator()->get('messages.welcome', ['name' => '阿明']));
    }

    public function testNestedAndDeepKeys(): void
    {
        $t = $this->translator();
        $this->assertSame('購物車是空的', $t->get('messages.cart.empty'));
        $this->assertSame('深層值', $t->get('messages.deep.nested.value'));
    }

    public function testMissingKeyReturnsKeyAndHasDetects(): void
    {
        $t = $this->translator();
        $this->assertSame('messages.nope', $t->get('messages.nope'));
        $this->assertTrue($t->has('messages.welcome'));
        $this->assertFalse($t->has('messages.cart')); // arrays are not translatable leaves
        $this->assertFalse($t->has('nonexistent.key'));
    }

    public function testFallbackResolvesPartialCoverage(): void
    {
        $this->assertSame('english only', $this->translator()->get('messages.only_en'), 'absent in zh-Hant → en fallback');
    }

    public function testLocaleSwitchChangesResolution(): void
    {
        $t = $this->translator();
        $t->setLocale('en');
        $this->assertSame('Hello, sam!', $t->get('messages.welcome', ['name' => 'sam']));
        $t->setLocale('zh-Hant');
        $this->assertSame('歡迎, sam！', $t->get('messages.welcome', ['name' => 'sam']));
    }

    public function testSmartCaseVariants(): void
    {
        \file_put_contents($this->langDir . '/en/case.php', '<?php return ["three" => "a :who b :Who c :WHO"];');
        $t = new Translator($this->langDir, 'en');

        $this->assertSame('a sam b Sam c SAM', $t->get('case.three', ['who' => 'sam']));
    }

    public function testNamespacedKeys(): void
    {
        $t = $this->translator();
        $this->assertSame('找不到 商品', $t->get('core/shop::errors.not_found', ['thing' => '商品']));
        $this->assertSame('core/shop::errors.missing', $t->get('core/shop::errors.missing'));
    }

    public function testUnregisteredNamespaceFallsBackToKey(): void
    {
        $this->assertSame('ghost/mod::g.k', $this->translator()->get('ghost/mod::g.k'));
    }

    // ── plurals ───────────────────────────────────────────────

    public function testEnglishPositionalPlural(): void
    {
        $t = $this->translator('en');
        $this->assertSame('There 1 item', $t->choice('messages.cart.items', 1));
        $this->assertSame('There are 7 items', $t->choice('messages.cart.items', 7));
    }

    public function testCjkSingleCategoryPicksLastSegment(): void
    {
        $t = $this->translator('zh-Hant');
        $this->assertSame('有 3 件商品', $t->choice('messages.cart.items', 3));
        $this->assertSame('有 1 件商品', $t->choice('messages.cart.items', 1), 'zh has no singular form: one segment wins');
    }

    public function testExplicitSelectorsBothLocales(): void
    {
        $t = $this->translator('zh-Hant');
        $this->assertSame('沒有商品', $t->choice('messages.cart.explicit', 0));
        $this->assertSame('只剩一件', $t->choice('messages.cart.explicit', 1));
        $this->assertSame('共 9 件', $t->choice('messages.cart.explicit', 9));

        $e = $this->translator('en');
        $this->assertSame('no items', $e->choice('messages.cart.explicit', 0));
        $this->assertSame('4 items', $e->choice('messages.cart.explicit', 4));
    }

    public function testNumericFormattingSkippedForCjk(): void
    {
        \file_put_contents($this->langDir . '/en/num.php', '<?php return ["n" => ":count total"];');
        $this->assertSame('1,234 total', (new Translator($this->langDir, 'en'))->get('num.n', ['count' => 1234]));

        \file_put_contents($this->langDir . '/zh-Hant/num.php', '<?php return ["n" => ":count 個"];');
        $this->assertSame('1234 個', (new Translator($this->langDir, 'zh-Hant'))->get('num.n', ['count' => 1234]));
    }

    public function testPluralizerEscapedPipeAndRanges(): void
    {
        $this->assertSame('a|b', Pluralizer::line('a\|b', 5, 'en'), 'escaped pipe is literal');
        $this->assertSame('low', Pluralizer::line('[2,5] low|[6,*] high', 4, 'en'));
        $this->assertSame('high', Pluralizer::line('[2,5] low|[6,*] high', 44, 'en'));
    }

    public function testCustomLocaleRule(): void
    {
        Pluralizer::registerRule('xx', static fn (int|float $n): int => 2);

        $this->assertSame('third', Pluralizer::line('first|second|third', 9, 'xx-YY'));
        $this->assertSame('other', Pluralizer::line('one|other', 1, 'xx-YY'), 'xx rule says index 2 clamped to last');
    }

    private function writeGroup(string $locale, string $group, array $data): void
    {
        \file_put_contents(
            $this->langDir . '/' . $locale . '/' . $group . '.php',
            '<?php return ' . \var_export($data, true) . ';',
        );
    }

    private function translator(string $locale = 'zh-Hant'): Translator
    {
        return (new Translator($this->langDir, $locale))
            ->addNamespace('core/shop', $this->langDir . '/shop_ns');
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            \is_dir($p) ? $this->removeDir($p) : @\unlink($p);
        }
        @\rmdir($dir);
    }
}
