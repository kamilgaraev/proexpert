<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;

class BlogInlineBlockEditor extends Field
{
    protected string $view = 'filament.forms.components.blog-inline-block-editor';

    /**
     * @var array<int, array<string, mixed>>|Closure
     */
    protected array|Closure $blockDefinitions = [];

    /**
     * @var array<string, string>|Closure
     */
    protected array|Closure $mediaOptions = [];

    /**
     * @var array<int, string>|Closure
     */
    protected array|Closure $acceptedImageTypes = [];

    /**
     * @param array<int, array<string, mixed>>|Closure $blockDefinitions
     */
    public function blockDefinitions(array|Closure $blockDefinitions): static
    {
        $this->blockDefinitions = $blockDefinitions;

        return $this;
    }

    /**
     * @param array<string, string>|Closure $mediaOptions
     */
    public function mediaOptions(array|Closure $mediaOptions): static
    {
        $this->mediaOptions = $mediaOptions;

        return $this;
    }

    /**
     * @param array<int, string>|Closure $acceptedImageTypes
     */
    public function acceptedImageTypes(array|Closure $acceptedImageTypes): static
    {
        $this->acceptedImageTypes = $acceptedImageTypes;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBlockDefinitions(): array
    {
        return $this->evaluate($this->blockDefinitions);
    }

    /**
     * @return array<string, string>
     */
    public function getMediaOptions(): array
    {
        return $this->evaluate($this->mediaOptions);
    }

    /**
     * @return array<int, string>
     */
    public function getAcceptedImageTypes(): array
    {
        return $this->evaluate($this->acceptedImageTypes);
    }
}
