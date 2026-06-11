<?php
namespace VonNeumannGame\FrontRoute;

use VonNeumannGame\I18n\Translator;
use VonNeumannGame\View\TplBlock;

class FrontRouteChangelog extends FrontRoute{
    public function getContent(string $method, string $routePath, ?string $bearer, string $language): string
    {
        $projectRoot = dirname(__DIR__, 2);
        $path = $projectRoot . '/CHANGELOG.md';
        $translator = new Translator(Translator::normalize($language));
        $tpl = new TplBlock();
        $tpl->addPrefixedVars('t', $translator->allEscaped());
        $tpl->addVars([
            'changelogHtml' => is_file($path) ? $this->renderMarkdownHtml((string) file_get_contents($path)) : '',
        ]);

        return $tpl->applyTplFile($projectRoot . '/templates/changelog.html');
    }

    public function getPageTitle(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return 'Von Neumann Game - ' . $translator->get('changelogFooterLink');
    }

    public function getMetaDescription(?string $bearer, string $language): string
    {
        $translator = new Translator(Translator::normalize($language));

        return self::e($translator->get('changelogMetaDescription'));
    }

    private function renderMarkdownHtml(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $html = [];
        $paragraph = [];
        $listOpen = false;

        $flushParagraph = function () use (&$html, &$paragraph): void {
            if ($paragraph === []) {
                return;
            }

            $html[] = '<p>' . $this->renderMarkdownInline(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        };
        $closeList = static function () use (&$html, &$listOpen): void {
            if (!$listOpen) {
                return;
            }

            $html[] = '</ul>';
            $listOpen = false;
        };

        foreach ($lines as $line) {
            $line = rtrim($line);
            if (trim($line) === '') {
                $flushParagraph();
                $closeList();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                $closeList();
                $level = strlen($matches[1]);
                $html[] = '<h' . $level . '>' . $this->renderMarkdownInline(trim($matches[2])) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^-\s+(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                if (!$listOpen) {
                    $html[] = '<ul>';
                    $listOpen = true;
                }
                $html[] = '<li>' . $this->renderMarkdownInline(trim($matches[1])) . '</li>';
                continue;
            }

            $paragraph[] = trim($line);
        }

        $flushParagraph();
        $closeList();

        return implode("\n", $html);
    }

    private function renderMarkdownInline(string $text): string
    {
        $segments = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $html = '';
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '`') && str_ends_with($segment, '`') && strlen($segment) >= 2) {
                $html .= '<code>' . self::e(substr($segment, 1, -1)) . '</code>';
                continue;
            }

            $html .= $this->renderMarkdownLinks($segment);
        }

        return $html;
    }

    private function renderMarkdownLinks(string $text): string
    {
        if (preg_match_all('/\[([^\]]+)\]\(([^)\s]+)\)/', $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return self::e($text);
        }

        $html = '';
        $offset = 0;
        foreach ($matches[0] as $index => $match) {
            [$whole, $position] = $match;
            $html .= self::e(substr($text, $offset, $position - $offset));
            $label = $matches[1][$index][0];
            $url = $matches[2][$index][0];
            $html .= preg_match('#^(https?://|/)#', $url) === 1
                ? '<a href="' . self::e($url) . '">' . self::e($label) . '</a>'
                : self::e($whole);
            $offset = $position + strlen($whole);
        }

        return $html . self::e(substr($text, $offset));
    }
}
