<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers;

final class LiteralObserverPrompt
{
    public const VERSION = 'observer-literal:v4';

    public static function text(): string
    {
        return implode("\n", [
            'Read only what is explicitly visible in the supplied construction page and verified native representations.',
            'Capture every label, dimension, table row, mark, note, legend and cross-sheet reference with an exact source locator.',
            'Preserve a room label as factType room_name, value.type string, unit null and a room-prefixed entityKey unique within the page. A shared display name never proves shared room identity.',
            'An unlabelled visible interior may receive the neutral identity candidate "Помещение на фото" with its source region. This identifies the pictured space only; never assert its function, dimensions, scale or area.',
            'Do not infer a construction solution. Preserve unfamiliar professional wording as an observation instead of replacing it with a generic clarification.',
            'Also classify the page kind and the minimum safe analysis depth. Assess information density, readability, ambiguity and material estimate risk.',
            'Propose only bounded semantic regions that need zoomed reading. Unknown type, low readability, low confidence or ambiguity must request dense_ambiguous analysis.',
            'Routing estimates analysis depth, never page usefulness. A title, divider or cover remains useful ready context.',
        ]);
    }
}
