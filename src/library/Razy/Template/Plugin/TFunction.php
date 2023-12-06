<?php

namespace Razy\Template\Plugin;

use Razy\Template\Entity;
use Throwable;

class TFunction
{
    protected bool $encloseContent = false;

    protected bool $extendedParameter = false;

    protected array $allowedParameters = [];
    private string $name;

    protected mixed $caller = null;

    /**
     * @param string $entity
     * @return $this
     */
    final public function bind(mixed $entity): static
    {
        $this->caller = $entity;

        return $this;
    }

    /**
     * @param Entity $entity
     * @param array $parameters
     * @param array $arguments
     * @param string $wrappedText
     * @return string|null
     */
    protected function processor(Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): ?string
    {
        return '';
    }

    /**
     * Start parse the function tag.
     *
     * @param Entity $entity The Entity instance
     * @param string $syntax The well-formatted function's parameter string
     * @param string $wrappedText The wrapped content if the plugin is an enclosure tag
     *
     * @return string|null
     * @throws Throwable
     */
    final public function parse(Entity $entity, string $syntax = '', string $wrappedText = ''): ?string
    {
        $parameters = [];
        $arguments = [];

        $text = trim($syntax);
        if (0 === strlen($text)) {
            $parameters = $this->allowedParameters;
        } else {
            $syntax = ' ' . $syntax;
            if (preg_match('/^\s((?::\w+)*)((?:\s+\w+=(?:(?<value>\$\w+(?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>)))*|-?\d+(?:\.\d+)?|(?P>rq))|true|false))+)|((?:\s+(?P>value))+)$/', $syntax, $matches)) {
                $parameters = $this->allowedParameters;
                $arguments = explode(':', ltrim(trim($matches[1]), ':'));
                $syntax = trim($matches[2]);
                $clips = preg_split('/(?:(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>|\\.)(*SKIP)(*FAIL)|\s+/', $syntax);

                if (isset($matches[6])) {
                    foreach ($parameters as &$value) {
                        if (count($clips) > 0) {
                            $clip = array_shift($clips);
                            $value = $entity->parseValue($clip);
                        }

                        if (empty($clips)) {
                            break;
                        }
                    }
                } else {
                    preg_match_all('/\s+(\w+)=(?:(\$\w+(?:\.(?:\w+|(?P>rq)))*)|true|false|(-?\d+(?:\.\d+)?)|(?<rq>(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)*)\k<q>))/', $syntax, $matches, PREG_SET_ORDER);
                    foreach ($clips as $param) {
                        [$parameter, $value] = explode('=', $param);
                        if ($this->extendedParameter || array_key_exists($parameter, $this->allowedParameters)) {
                            $parameters[$parameter] = $entity->parseValue($value);
                        }
                    }
                }
            }
        }

        return call_user_func($this->processor($entity, $parameters, $arguments, $wrappedText));
    }

    /**
     * @return bool
     */
    final public function isEncloseContent(): bool
    {
        return $this->encloseContent;
    }

    /**
     * @return array
     */
    final public function getAllowedParameters(): array
    {
        return $this->allowedParameters;
    }

    /**
     * @return bool
     */
    final public function isExtendedParameter(): bool
    {
        return $this->extendedParameter;
    }

    /**
     * @return string
     */
    final public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return TFunction
     */
    final public function setName(string $name): TFunction
    {
        $this->name = $name;

        return $this;
    }
}