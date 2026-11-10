<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The dashboard runs under a Content Security Policy without
 * 'unsafe-inline'. Vue's compiler can hoist static nodes as HTML strings,
 * so a static `style="…"` attribute in a template would need
 * `style-src-attr 'unsafe-inline'`; a `<style>` element inside a template
 * is an inline stylesheet without the nonce; `v-html` (and its binding
 * form `:innerHTML`) puts HTML that the server sent into the page. Bound
 * styles (`:style`) are set through the CSSOM, which the policy allows, and
 * the top-level `<style scoped>` blocks are compiled into dist/app.css,
 * which Orrery::css() inlines with the nonce. A constant `:style` inside a
 * large static block is the exception: Vue stringifies it into a literal
 * `style="…"`, which bin/check-assets refuses in the built dist/app.js
 * (AssetsCheckTest). The scripts (every `.js` module and the `<script>`
 * blocks) never write HTML (`.innerHTML =`, `insertAdjacentHTML()`, …),
 * never evaluate a string as code and never set a style or event
 * attribute.
 *
 * The test reads files only (group scripts: `composer test:all` runs it).
 */
#[Group('scripts')]
final class TemplateStyleTest extends TestCase
{
    /**
     * Rejects: a static `style=` attribute, a `<style>` element inside a
     * template, a `v-html` directive, an `:innerHTML` binding or a top-level
     * style block that is not scoped in any `.vue` file of resources/js, and
     * a scan that reads no file (the count check).
     * Still passes: a style that a script sets as an attribute string at run
     * time (`el.setAttribute('style', …)`): the scan reads templates only.
     */
    public function test_no_template_has_an_inline_style_or_html(): void
    {
        $files = self::vueFiles();
        self::assertGreaterThanOrEqual(30, count($files), 'The scan found too few .vue files under resources/js.');

        $problems = [];
        foreach ($files as $path => $source) {
            foreach (self::problems($source) as $problem) {
                $problems[] = $path.': '.$problem;
            }
        }

        self::assertSame([], $problems, "A template breaks the Content Security Policy (D8 spec 8.2):\n".implode("\n", $problems));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function brokenComponents(): iterable
    {
        yield 'a static style attribute' => [self::component('<div style="color: red">x</div>'), 'a static style attribute'];
        yield 'a static style attribute with spaces' => [self::component("<span\n    style = 'width: 2px'>x</span>"), 'a static style attribute'];
        yield 'a style element in the template' => [self::component('<div><style>.a{color:red}</style></div>'), 'a <style> element inside the template'];
        yield 'an upper-case style element' => [self::component('<div><STYLE>.a{}</STYLE></div>'), 'a <style> element inside the template'];
        yield 'a script element in the template' => [self::component('<div><script>alert(1)</script></div>'), 'a <script> element inside the template'];
        yield 'v-html' => [self::component('<div v-html="message"></div>'), 'a v-html directive'];
        yield 'an innerHTML binding' => [self::component('<div :innerHTML="message"></div>'), 'an innerHTML binding'];
        yield 'a v-bind innerHTML binding' => [self::component('<div v-bind:inner-html="message"></div>'), 'an innerHTML binding'];
        yield 'a style block that is not scoped' => [self::component('<div>x</div>', '<style>'), 'a top-level <style> block that is not scoped'];
        yield 'an innerHTML prop binding' => [self::component('<div .innerHTML="message"></div>'), 'an HTML prop binding'];
        yield 'an outerHTML binding' => [self::component('<div :outerHTML="message"></div>'), 'an outerHTML binding'];
        yield 'an outerHTML prop binding' => [self::component('<div .outer-html="message"></div>'), 'an HTML prop binding'];
    }

    /**
     * Rejects: a script (a `.js` module or the `<script>` block of a
     * component) that puts HTML into the page or evaluates a string as
     * code: the policy has no 'unsafe-eval', and a server
     * value written as HTML runs as markup.
     * Still passes: a sink reached through a computed property name
     * (`el[key] = html`, `{ [key]: html }`) or a props object built
     * elsewhere and spread into h().
     */
    public function test_no_script_writes_html_or_evaluates_code(): void
    {
        $scripts = self::scripts();
        self::assertGreaterThanOrEqual(60, count($scripts), 'The scan found too few scripts under resources/js.');

        $problems = [];
        foreach ($scripts as $path => $source) {
            foreach (self::scriptProblems($source) as $problem) {
                $problems[] = $path.': '.$problem;
            }
        }

        self::assertSame([], $problems, "A script breaks the Content Security Policy (D8 spec 8.2):\n".implode("\n", $problems));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function brokenScripts(): iterable
    {
        yield 'innerHTML' => ['el.innerHTML = html;', 'an HTML property write'];
        yield 'outerHTML' => ['node.outerHTML += html;', 'an HTML property write'];
        yield 'an innerHTML render prop' => ["return h('div', { class: 'x', innerHTML: html });", 'an HTML prop in an object'];
        yield 'an innerHTML shorthand prop' => ["const innerHTML = html; return h('div', {innerHTML});", 'an HTML prop in an object'];
        yield 'a quoted innerHTML prop' => ["return h('div', { 'innerHTML': html });", 'an HTML prop in an object'];
        yield 'insertAdjacentHTML' => ["el.insertAdjacentHTML('beforeend', html);", 'insertAdjacentHTML()'];
        yield 'document.write' => ['document.write(html);', 'document.write()'];
        yield 'createContextualFragment' => ['range.createContextualFragment(html);', 'createContextualFragment()'];
        yield 'setHTMLUnsafe' => ['el.setHTMLUnsafe(html);', 'setHTMLUnsafe()'];
        yield 'eval' => ['eval(code);', 'eval()'];
        yield 'new Function' => ["const f = new Function('a', code);", 'Function()'];
        yield 'a style attribute' => ["el.setAttribute('style', css);", 'a style or event attribute'];
        yield 'an event attribute' => ['el.setAttribute("onclick", code);', 'a style or event attribute'];
    }

    /**
     * Rejects: a script scan that misses one of the sinks (each case is
     * one line of an otherwise clean module).
     */
    #[DataProvider('brokenScripts')]
    public function test_each_script_sink_is_found(string $line, string $expected): void
    {
        self::assertSame([$expected], self::scriptProblems("export function show(el, html) {\n    {$line}\n}\n"));
    }

    /**
     * Rejects: a script scan that refuses the allowed forms: a comparison
     * with innerHTML, the words in a comment, a URL with "//" in a string,
     * and a textContent write.
     */
    public function test_the_allowed_script_forms_pass(): void
    {
        $source = <<<'JS'
            // Never eval() or set innerHTML = here (the policy).
            /* new Function() is not allowed either. */
            const url = 'https://example.test/a';
            if (el.innerHTML === '') {
                el.textContent = text;
            }
            JS;

        self::assertSame([], self::scriptProblems($source));
    }

    /**
     * The problems of one script, without its comments.
     *
     * @return list<string>
     */
    private static function scriptProblems(string $source): array
    {
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~(^|[^:\\\\])//[^\n]*~'], ['', '$1'], $source);
        $checks = [
            '/\.(?:inner|outer)HTML\s*(?:\+?=(?!=))/' => 'an HTML property write',
            // A render function's props, h('div', { innerHTML: html }) or the shorthand { innerHTML }.
            '/[{,]\s*([\'"]?)(?:inner|outer)HTML\1\s*[:,}]/' => 'an HTML prop in an object',
            '/\binsertAdjacentHTML\s*\(/' => 'insertAdjacentHTML()',
            '/\bdocument\s*\.\s*write(?:ln)?\s*\(/' => 'document.write()',
            '/\bcreateContextualFragment\s*\(/' => 'createContextualFragment()',
            '/\bsetHTMLUnsafe\s*\(/' => 'setHTMLUnsafe()',
            '/(?<![\w.$])eval\s*\(/' => 'eval()',
            '/(?<![\w.$])Function\s*\(/' => 'Function()',
            '/\bsetAttribute\s*\(\s*[\'"](?:style|on\w+)[\'"]/i' => 'a style or event attribute',
        ];
        $problems = [];

        foreach ($checks as $pattern => $problem) {
            if (preg_match($pattern, $code) === 1) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * Every `.js` module under resources/js and the top-level `<script>`
     * blocks of every component.
     *
     * @return array<string, string> relative path => source
     */
    private static function scripts(): array
    {
        $root = dirname(__DIR__, 2);
        $scripts = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $source = (string) file_get_contents($file->getPathname());

            if ($file->getExtension() === 'js') {
                $scripts[$relative] = $source;
            } elseif ($file->getExtension() === 'vue' && preg_match_all('/^<script\b[^>]*>(.*?)^<\/script>/ms', $source, $blocks) > 0) {
                $scripts[$relative] = implode("\n", $blocks[1]);
            }
        }
        ksort($scripts);

        return $scripts;
    }

    /**
     * Rejects: a scan that misses one of the forbidden forms (each case
     * injects exactly one into an otherwise clean component).
     */
    #[DataProvider('brokenComponents')]
    public function test_each_forbidden_form_is_found(string $source, string $expected): void
    {
        self::assertSame([$expected], self::problems($source));
    }

    /**
     * Rejects: a scan that also refuses the allowed forms: a bound style
     * (`:style`, `v-bind:style`), a data attribute or a class whose name
     * ends in "style", the word in the text, and a scoped style block.
     */
    public function test_the_allowed_forms_pass(): void
    {
        $template = <<<'HTML'
            <div :style="{ width: w + 'px' }" v-bind:style="extra" data-style="x" class="o-style">
                Pick a style for the inner text.
                <template v-if="shown"><span :title="html">inner</span></template>
            </div>
            HTML;

        self::assertSame([], self::problems(self::component($template)));
    }

    /**
     * The problems of one single-file component: the template is everything
     * outside its top-level `<script>` and `<style>` blocks (each opens at
     * the start of a line, as in every component of the package).
     *
     * @return list<string>
     */
    private static function problems(string $source): array
    {
        $problems = [];

        preg_match_all('/^<style\b([^>]*)>/m', $source, $blocks);
        foreach ($blocks[1] as $attributes) {
            if (preg_match('/(^|\s)scoped(\s|=|$)/', $attributes) !== 1) {
                $problems[] = 'a top-level <style> block that is not scoped';
            }
        }

        $template = (string) preg_replace(['/^<script\b.*?^<\/script>/ms', '/^<style\b.*?^<\/style>/ms'], '', $source);

        $checks = [
            '/(?<![\w:.\-])style\s*=/i' => 'a static style attribute',
            '/<style\b/i' => 'a <style> element inside the template',
            '/<script\b/i' => 'a <script> element inside the template',
            '/\bv-html\b/i' => 'a v-html directive',
            '/(?:\bv-bind)?:inner-?html\b/i' => 'an innerHTML binding',
            '/(?:\bv-bind)?:outer-?html\b/i' => 'an outerHTML binding',
            '/(?<![\w-])\.(?:inner|outer)-?html\b/i' => 'an HTML prop binding',
        ];
        foreach ($checks as $pattern => $problem) {
            if (preg_match($pattern, $template) === 1) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    private static function component(string $template, string $styleTag = '<style scoped>'): string
    {
        return "<template>\n    {$template}\n</template>\n\n<script>\n    export default { data() { return { style: 'x' }; } };\n</script>\n\n{$styleTag}\n    .a { color: red; }\n</style>\n";
    }

    /**
     * @return array<string, string> relative path => source
     */
    private static function vueFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources/js', RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
