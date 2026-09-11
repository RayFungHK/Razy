<?php

/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Template Function Plugin: paginate.
 *
 * Renders a windowed pagination nav from plain numbers — presentation only,
 * no framework service required. The controller owns the arithmetic (total
 * pages, current page); this plugin only turns it into escaped markup.
 *
 * Usage in templates:
 *   {@paginate page=$page pages=$total_pages base=$listUrl}
 *   {@paginate page=2 pages=10 base='/shop?page=1' query='page' window=2}
 *
 * Parameters:
 *   page   (int)   current page, 1-based                (default 1)
 *   pages  (int)   total pages; <= 1 renders nothing    (default 1)
 *   base   (str)   URL the links point at; '' renders spans only
 *   query  (str)   query key appended as ?{query}=N     (default 'page')
 *   window (int)   neighbour pages kept around current  (default 2)
 *   label_prev / label_next / label_gap                 text overrides
 *   class  (str)   css class on the <nav> element       (default 'pagination')
 *
 * Every interpolated value is htmlspecialchars-escaped at emit time — base
 * URLs and labels are treated as untrusted.
 *
 * @package Razy
 *
 * @license MIT
 */

use Razy\Template\Entity;
use Razy\Template\Plugin\TFunction;

/**
 * Factory closure that creates and returns the `paginate` function plugin instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TFunction class constructor
 *
 * @return TFunction The pagination nav renderer
 */
return function (...$arguments) {
    return new class(...$arguments) extends TFunction {
        /** @var array Declared parameters and their defaults */
        protected array $allowedParameters = [
            'page' => 1,
            'pages' => 1,
            'base' => '',
            'query' => 'page',
            'window' => 2,
            'label_prev' => '‹',
            'label_next' => '›',
            'label_gap' => '…',
            'class' => 'pagination',
        ];

        /**
         * Build the pagination nav markup.
         *
         * @param Entity $entity The current template entity context (unused; numbers come from parameters)
         * @param array $parameters Parsed parameters (see allowedParameters)
         * @param array $arguments Positional arguments (unused)
         * @param string $wrappedText Enclosed content (unused)
         *
         * @return string The nav markup, or '' when pagination is unnecessary
         */
        protected function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): ?string
        {
            $params = \array_merge($this->allowedParameters, $parameters);

            $page = \max(1, (int) $params['page']);
            $pages = \max(0, (int) $params['pages']);

            if ($pages <= 1) {
                return '';
            }

            $page = \min($page, $pages);
            $window = \max(1, (int) $params['window']);
            $base = (string) $params['base'];
            $query = (string) $params['query'];

            $href = static function (int $target) use ($base, $query): string {
                if ($base === '') {
                    return '';
                }

                if ($query === '') {
                    return $base;
                }

                return $base . (\str_contains($base, '?') ? '&' : '?') . $query . '=' . $target;
            };

            $emit = static function (int|string $label, ?int $target = null) use ($href, $page): string {
                $text = \htmlspecialchars((string) $label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

                $url = $target === null ? '' : $href($target);

                if ($url === '') {
                    // No link target (no base, or active page) → inert span.
                    $aria = \is_int($label) && $label === $page ? ' aria-current="page"' : '';

                    return '<span class="page-item"' . $aria . '>' . $text . '</span>';
                }

                return '<a class="page-item" href="' . \htmlspecialchars($url, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8') . '">' . $text . '</a>';
            };

            // Window: first, gap, current±window, gap, last.
            $visible = [];

            for ($i = 1; $i <= $pages; ++$i) {
                if ($i === 1 || $i === $pages || \abs($i - $page) <= $window) {
                    $visible[] = $i;
                }
            }

            $items = '';

            if ($page > 1) {
                $items .= $emit((string) $params['label_prev'], $page - 1);
            }

            $previous = 0;
            foreach ($visible as $i) {
                if ($previous !== 0 && $i - $previous > 1) {
                    $items .= $emit((string) $params['label_gap']);
                }
                $items .= ($i === $page) ? $emit($i) : $emit($i, $i);
                $previous = $i;
            }

            if ($page < $pages) {
                $items .= $emit((string) $params['label_next'], $page + 1);
            }

            $class = \htmlspecialchars((string) $params['class'], \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

            return '<nav aria-label="pagination" class="' . $class . '">' . $items . '</nav>';
        }
    };
};
