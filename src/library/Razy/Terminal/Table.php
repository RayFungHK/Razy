<?php
/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Terminal;

use Razy\Terminal;

class Table
{
    /**
     * @var bool
     */
    private bool $autoWidth = false;

    /**
     * @var Terminal
     */
    private Terminal $terminal;

    /**
     * @var int
     */
    private int $columns;

    /**
     * @var array
     */
    private array $columnName;

    /**
     * @var array
     */
    private array $dataset = [];

    /**
     * @var array
     */
    private array $maxLength = [];

    /**
     * @var int
     */
    private int $maxWidth;

    /**
     * @var int
     */
    private int $padding = 1;

    public function __construct(Terminal $terminal)
    {
        $this->terminal = $terminal;
        $this->maxWidth = $terminal->getScreenWidth();
    }

    /**
     * @param bool $enable
     */
    public function autoWidth(bool $enable)
    {
        $this->autoWidth = $enable;
    }

    /**
     * @param int $padding
     *
     * @return $this
     */
    public function setPadding(int $padding): Table
    {
        $this->padding = max(1, $padding);

        return $this;
    }

    public function setColumns(int $count, array $columnName = []): Table
    {
        if (count($columnName)) {
            $columnName       = array_values($columnName);
            $this->columnName = $columnName;
        }
        $this->columns = $count;

        return $this;
    }

    /**
     * @param array $dataset
     *
     * @return $this
     */
    public function bindData(array $dataset): Table
    {
        $this->dataset = [];
        foreach ($dataset as $data) {
            if (is_array($data)) {
                $this->addRow($data);
            }
        }

        return $this;
    }

    /**
     * @param array $data
     *
     * @return $this
     */
    public function addRow(array $data): Table
    {
        $data = array_values($data);
        foreach ($data as $index => $name) {
            $this->maxLength[$index] = max(strlen($name), $this->maxLength[$index] ?? 0);
            if (!count($this->columnName)) {
                $this->columns = max(count($this->columnName), $this->columns);
            }
        }
        $this->dataset[] = $data;

        return $this;
    }

    public function draw(): Table
    {
        $maxLength = [];
        $adjust    = ($this->padding * 2) + 1;
        $maxWidth  = $this->maxWidth - (1 + ($this->padding * 2 * $this->columns));

        // Compare for the maximum length of column header
        if (count($this->columnName)) {
            foreach ($this->columnName as $index => &$name) {
                $name              = $this->terminal->format($name);
                $maxLength[$index] = max($maxLength[$index] ?? 0, min($maxWidth, $this->terminal->length($name)));
            }
            array_unshift($this->dataset, $this->columnName);
        }

        // Compare for the maximum length of content
        foreach ($this->dataset as $dataset) {
            foreach ($dataset as $index => &$content) {
                $maxLength[$index] = max($maxLength[$index] ?? 0, min($maxWidth, strlen($content)));
            }
        }

        if ($this->autoWidth) {
            $avg = $maxWidth / $this->columns;

            $totalWidth = array_sum($maxLength) + ($adjust * count($maxLength)) + 1;
            if ($totalWidth < $maxWidth) {
                $remainingSpace = $maxWidth - $totalWidth;
                $minDiff        = 0;

                // Calculate the different and find the minimum different for further adjustment
                $adjustmentColumns = 0;
                foreach ($maxLength as $length) {
                    if ($length <= $avg) {
                        $diff    = $avg - $length;
                        $minDiff = (!$minDiff) ? $diff : min($minDiff, $diff);
                        ++$adjustmentColumns;
                    }
                }

                // Try to align all columns' length lower than the average length
                $divided = floor($remainingSpace / $adjustmentColumns);
                if ($minDiff <= $divided) {
                    foreach ($maxLength as &$length) {
                        if ($length < $avg) {
                            $diff = $avg - $length;
                            $length += $diff;
                            $remainingSpace -= $diff;
                        }
                    }
                }

                // Distribute the remaining space to other columns equally
                if ($remainingSpace > 0) {
                    $divided = floor($remainingSpace / $adjustmentColumns);
                    foreach ($maxLength as $index => &$length) {
                        if ($length <= $avg) {
                            if ($index == count($maxLength) - 1) {
                                $length += $remainingSpace;
                            } else {
                                $length += $divided;
                                $remainingSpace -= $divided;
                            }
                        }
                    }
                }
            }
        }

        $columnLengths = [];
        $rowSeparator  = '+';
        foreach ($maxLength as $index => &$length) {
            $rowSeparator .= str_repeat('-', $length + 2) . '+';
            $columnLengths[$index] = $length;
        }

        $this->terminal->writeLine($rowSeparator, true);
        foreach ($this->dataset as $rowCount => $data) {
            $format = '|';
            // Generate the format
            foreach ($data as $index => &$text) {
                $padding = str_repeat(' ', $this->padding);
                $text    = $this->terminal->format($text);

                // Adjust the content length by escaped text
                $textLength = $columnLengths[$index];
                $this->terminal->length($text, $escaped);
                $textLength += $escaped;

                $format .= $padding . '%-' . $textLength . 's' . $padding . '{@reset}|';
            }
            $this->terminal->writeLine(vsprintf($format, $data), true);
            if (0 === $rowCount && count($this->columnName)) {
                $this->terminal->writeLine($rowSeparator, true);
            }
        }
        $this->terminal->writeLine($rowSeparator, true);

        return $this;
    }

    /**
     * @param string $content
     * @param int    $length
     *
     * @return int
     */
    private function wrapContent(string &$content, int $length = 5): int
    {
        $clips = explode("\n", $content);
        if (1 == count($clips)) {
            return strlen($clips);
        }
        $maxLength = 0;
        $wrapped   = [];
        foreach ($clips as $clip) {
            if (strlen($clip) > $length) {
                $maxLength = $length;
                $wrapped   = array_merge($wrapped, str_split($clip, $length));
            } else {
                $maxLength = max($maxLength, $clip);
                $wrapped[] = $clip;
            }
        }
        $content = $wrapped;

        return $maxLength;
    }
}
