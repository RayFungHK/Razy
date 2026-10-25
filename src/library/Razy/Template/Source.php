<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the Source class for the Razy Template Engine. A Source represents
 * a loaded template file, managing the root block/entity hierarchy, source-level
 * parameters, and template rendering output.
 *
 *
 * @license MIT
 */

namespace Razy\Template;

use Razy\Exception\TemplateException;
use Razy\FileReader;
use Razy\ModuleInfo;
use Razy\Template;
use Razy\Template\Plugin\TFunction;
use Razy\Template\Plugin\TFunctionCustom;
use Razy\Template\Plugin\TModifier;
use Throwable;

/**
 * Represents a loaded template file source within the Template Engine.
 *
 * A Source wraps a single template file, creating a root Block from parsing
 * and a root Entity for rendering. It manages source-level parameters and
 * delegates plugin loading and template lookups to the parent Template instance.
 *
 * @class Source
 */
class Source
{
    use ParameterBagTrait;

    /** Most INCLUDEs one template file may pull in, so an include cycle fails instead of looping forever. */
    public const MAX_INCLUDES = 256;

    /** @var string Absolute directory path of the loaded template file */
    private string $fileDirectory = '';

    /** @var array<string, mixed> Source-level template parameters */
    private array $parameters = [];

    /** @var Block|null Root block parsed from the template file */
    private ?Block $rootBlock = null;

    /** @var Entity|null Root entity for rendering the template */
    private ?Entity $rootEntity = null;

    /** @var int INCLUDEs resolved while parsing this source */
    private int $includes = 0;

    /**
     * Template Source constructor.
     *
     * @param string $tplPath The path of template file
     * @param Template $template The Template object
     *
     * @throws Throwable
     */
    public function __construct(string $tplPath, private Template $template, private readonly ?ModuleInfo $module = null)
    {
        if (!\is_file($tplPath)) {
            throw new TemplateException('Template file ' . $tplPath . ' is not exists.');
        }

        $this->fileDirectory = \dirname(\realpath($tplPath));
        $this->rootEntity = new Entity(($this->rootBlock = new Block($this, '_ROOT', new FileReader($tplPath))));
    }

    /**
     * Get the module which was loaded the template file.
     *
     * @return ModuleInfo|null
     */
    public function getModule(): ?ModuleInfo
    {
        return $this->module;
    }

    /**
     * Get the template file located directory.
     *
     * @return string The directory of the template file
     */
    public function getFileDirectory(): string
    {
        return $this->fileDirectory;
    }

    /**
     * Resolve an INCLUDE path for the parser (RZ-11): `@layout/…` from the dist's
     * shared layout folder, anything else relative to this template's directory.
     *
     * @param string $spec The path written in the INCLUDE tag
     *
     * @return string|null The file to read, or null when skipped (non-strict mode)
     *
     * @throws TemplateException When the path does not resolve, or too many includes (a cycle)
     */
    public function resolveInclude(string $spec): ?string
    {
        if (++$this->includes > self::MAX_INCLUDES) {
            throw new TemplateException('More than ' . self::MAX_INCLUDES . ' INCLUDE BLOCKs while parsing a template; is a file including itself?');
        }

        return $this->template->getIncludeResolver()->resolve($spec, $this->fileDirectory);
    }

    /**
     * Get the instance hash ID.
     *
     * @return string
     */
    public function getID(): string
    {
        return \spl_object_hash($this);
    }

    /**
     * Get the root entity.
     *
     * @return Entity|null The root entity object
     */
    public function getRoot(): ?Entity
    {
        return $this->rootEntity;
    }

    /**
     * Get the root entity.
     *
     * @return Block|null The root block object
     */
    public function getRootBlock(): ?Block
    {
        return $this->rootBlock;
    }

    /**
     * Get the template block.
     *
     * @param string $name
     *
     * @return Block|null
     */
    public function getTemplate(string $name): ?Block
    {
        return $this->template->getTemplate($name);
    }

    /**
     * Return a parameter value from the source scope.
     *
     * When $recursion is true and the parameter is not assigned at this
     * scope, resolution continues upward to the Template (manager) scope.
     *
     * @param string $parameter The parameter name
     * @param bool $recursion If true, walk up to Template scope when not found here
     *
     * @return mixed The parameter value, or null if not found at any resolved scope
     */
    public function getValue(string $parameter, bool $recursion = false): mixed
    {
        if ($recursion) {
            if (!$this->parameterAssigned($parameter)) {
                return $this->template->getValue($parameter);
            }
        }

        return $this->parameters[$parameter] ?? null;
    }

    /**
     * Determine the parameter has been assigned.
     *
     * @param string $parameter The parameter name
     *
     * @return bool Return true if the parameter is existing
     */
    public function parameterAssigned(string $parameter): bool
    {
        return \array_key_exists($parameter, $this->parameters);
    }

    /**
     * Link to a specified template engine entity.
     *
     * @param Template $template
     *
     * @return $this
     */
    public function link(Template $template): self
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Load the plugin from the plugin function by given name.
     *
     * @param string $type
     * @param string $name
     *
     * @return TModifier|TFunction|TFunctionCustom|null
     *
     * @throws Error
     * @throws Throwable
     */
    public function loadPlugin(string $type, string $name): null|TModifier|TFunction|TFunctionCustom
    {
        return $this->template->loadPlugin($type, $name);
    }

    /**
     * Whether the owning Template HTML-escapes `{$tag}` output (RZ-03).
     *
     * @return bool
     */
    public function isAutoEscape(): bool
    {
        return $this->template->isAutoEscape();
    }

    /**
     * Return the entity content.
     *
     * @return string The entity content
     *
     * @throws Throwable
     */
    public function output(): string
    {
        // RZ-27: the outermost render replaces anchor markers ({@anchor} / {@push})
        $this->template->beginRender();
        try {
            $html = $this->rootEntity->process();
        } catch (Throwable $e) {
            $this->template->endRender('');
            throw $e;
        }

        return $this->template->endRender($html);
    }

    /**
     * RZ-27: the Template manager this source belongs to.
     */
    public function getManager(): Template
    {
        return $this->template;
    }

    /**
     * Add current template source into queue list.
     *
     * @param string $name The section name
     *
     * @return Source
     */
    public function queue(string $name = ''): self
    {
        $this->template->addQueue($this, $name);

        return $this;
    }
}
