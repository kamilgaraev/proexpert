<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class ContractWordDrawingShapes
{
    private const NS = [
        'w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'wps' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape',
    ];

    public function replaceTextBoxes(DOMDocument $document): void
    {
        $query = new DOMXPath($document);
        $query->registerNamespace('v', 'urn:schemas-microsoft-com:vml');
        $query->registerNamespace('w', self::NS['w']);
        foreach (iterator_to_array($query->query('//v:shape[v:textbox]')) as $index => $shape) {
            $geometry = [];
            preg_match_all('/(?:^|;)\s*(width|height|margin-left|margin-top)\s*:\s*([0-9.]+)pt\s*(?=;|$)/', $shape->getAttribute('style'), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) $geometry[$match[1]] = (int) round((float) $match[2] * 12700);
            if (count($geometry) !== 4) throw new ContractBuilderException('contracts.builder_export_failed', 503);
            $width = $geometry['width']; $height = $geometry['height'];
            $drawing = $this->element($document, 'w:drawing');
            $anchor = $this->append($drawing, 'wp:anchor', ['distT' => 0, 'distB' => 0, 'distL' => 0, 'distR' => 0,
                'simplePos' => 0, 'relativeHeight' => $index + 1, 'behindDoc' => 0, 'locked' => 0, 'layoutInCell' => 1, 'allowOverlap' => 1]);
            $this->append($anchor, 'wp:simplePos', ['x' => 0, 'y' => 0]);
            foreach (['H' => 'margin-left', 'V' => 'margin-top'] as $axis => $key) {
                $position = $this->append($anchor, 'wp:position'.$axis, ['relativeFrom' => 'page']);
                $this->append($position, 'wp:posOffset')->appendChild($document->createTextNode((string) $geometry[$key]));
            }
            $this->append($anchor, 'wp:extent', ['cx' => $width, 'cy' => $height]);
            $this->append($anchor, 'wp:effectExtent', ['l' => 0, 't' => 0, 'r' => 0, 'b' => 0]);
            $this->append($anchor, 'wp:wrapNone');
            $this->append($anchor, 'wp:docPr', ['id' => $index + 1, 'name' => 'ContractShape'.($index + 1)]);
            $this->append($anchor, 'wp:cNvGraphicFramePr');
            $graphic = $this->append($anchor, 'a:graphic');
            $data = $this->append($graphic, 'a:graphicData', ['uri' => self::NS['wps']]);
            $wordShape = $this->append($data, 'wps:wsp');
            $this->append($wordShape, 'wps:cNvSpPr', ['txBox' => 1]);
            $properties = $this->append($wordShape, 'wps:spPr');
            $transform = $this->append($properties, 'a:xfrm');
            $this->append($transform, 'a:off', ['x' => 0, 'y' => 0]);
            $this->append($transform, 'a:ext', ['cx' => $width, 'cy' => $height]);
            $this->append($this->append($properties, 'a:prstGeom', ['prst' => 'rect']), 'a:avLst');
            $this->append($properties, 'a:noFill');
            $line = $this->append($properties, 'a:ln', ['w' => 6350]);
            if ($shape->getAttribute('stroked') === 'f') {
                $this->append($line, 'a:noFill');
            } else {
                $this->append($this->append($line, 'a:solidFill'), 'a:srgbClr', ['val' => 'AAB3BF']);
            }
            $content = $query->query('v:textbox/w:txbxContent', $shape)->item(0);
            if (! $content instanceof DOMElement) throw new ContractBuilderException('contracts.builder_export_failed', 503);
            $box = $this->append($wordShape, 'wps:txbx');
            $box->appendChild($content);
            if (! $content->hasChildNodes()) $this->append($content, 'w:p');
            $body = $this->append($wordShape, 'wps:bodyPr', ['rot' => 0, 'vert' => 'horz', 'wrap' => 'none',
                'lIns' => 0, 'tIns' => 0, 'rIns' => 0, 'bIns' => 0, 'anchor' => 't', 'anchorCtr' => 0]);
            $this->append($body, 'a:noAutofit');
            $shape->parentNode->parentNode->replaceChild($drawing, $shape->parentNode);
        }
    }

    private function element(DOMDocument $document, string $name, array $attributes = []): DOMElement
    {
        $element = $document->createElementNS(self::NS[explode(':', $name)[0]], $name);
        foreach ($attributes as $key => $value) $element->setAttribute($key, (string) $value);

        return $element;
    }

    private function append(DOMNode $parent, string $name, array $attributes = []): DOMElement
    {
        $element = $this->element($parent->ownerDocument, $name, $attributes);
        $parent->appendChild($element);

        return $element;
    }
}
