<?php

declare(strict_types=1);

namespace App\Services\Contract;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Paragraph;

final class ContractWordParagraphStyles
{
    public function apply(PhpWord $word): void
    {
        foreach (Style::getStyles() as $style) {
            $this->normalize($style instanceof Font ? $style->getParagraph() : $style);
        }

        foreach ($word->getSections() as $section) {
            $this->container($section);
            foreach ([...$section->getHeaders(), ...$section->getFooters()] as $container) {
                $this->container($container);
            }
        }
    }

    private function normalize(mixed $style): void
    {
        if ($style instanceof Paragraph && $style->getNumStyle() === null) {
            $style->setNumStyle('');
        }
    }

    private function container(AbstractContainer $container): void
    {
        foreach ($container->getElements() as $element) {
            if (method_exists($element, 'getParagraphStyle')) {
                $this->normalize($element->getParagraphStyle());
            }

            if ($element instanceof AbstractContainer) {
                $this->container($element);
            } elseif ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->container($cell);
                    }
                }
            }
        }
    }
}
