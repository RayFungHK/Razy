<?php

namespace Razy\Template\Plugin;

use Razy\Controller;
use Razy\Template\Entity;

class TFunctionCustom
{
    protected bool $encloseContent = false;

    private string $name;

	protected ?Controller $controller = null;

	/**
	 * @param Controller $entity
	 * @return $this
	 */
	final public function bind(Controller $entity): static
	{
		$this->controller = $entity;

		return $this;
	}

    /**
     * @param Entity $entity
     * @param string $syntax
     * @param string $wrappedText
     * @return string|null
     */
    protected function processor(Entity $entity, string $syntax = '', string $wrappedText = ''): ?string {
        return '';
    }

    /**
     * Start parse the function tag.
     *
     * @param Entity $entity The Entity instance
     * @param string $syntax
     * @param string $wrappedText The wrapped content if the plugin is an enclosure tag
     *
     * @return string|null
     */
    final public function parse(Entity $entity, string $syntax = '', string $wrappedText = ''): ?string
    {
        return $this->processor($entity, $syntax, $wrappedText);
    }

    /**
     * @return bool
     */
    final public function isEncloseContent(): bool
    {
        return $this->encloseContent;
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
     * @return TFunctionCustom
     */
    final public function setName(string $name): TFunctionCustom
    {
        $this->name = $name;

        return $this;
    }
}