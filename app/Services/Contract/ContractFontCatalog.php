<?php

declare(strict_types=1);

namespace App\Services\Contract;

final class ContractFontCatalog
{
    public const FAMILIES = ['DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono', 'Liberation Sans', 'Liberation Serif', 'Liberation Mono', 'Carlito', 'Lato', 'PT Sans', 'PT Serif', 'Roboto', 'Open Sans', 'Montserrat', 'Noto Sans', 'Noto Serif', 'Fira Sans', 'Rubik', 'MOST Source Sans 3', 'Source Serif 4', 'MOST Lora', 'MOST Playfair Display'];

    public const FILES = [
        'Liberation Sans' => ['regular' => 'LiberationSans-Regular.ttf', 'bold' => 'LiberationSans-Bold.ttf', 'italic' => 'LiberationSans-Italic.ttf', 'bolditalic' => 'LiberationSans-BoldItalic.ttf'],
        'Liberation Serif' => ['regular' => 'LiberationSerif-Regular.ttf', 'bold' => 'LiberationSerif-Bold.ttf', 'italic' => 'LiberationSerif-Italic.ttf', 'bolditalic' => 'LiberationSerif-BoldItalic.ttf'],
        'Liberation Mono' => ['regular' => 'LiberationMono-Regular.ttf', 'bold' => 'LiberationMono-Bold.ttf', 'italic' => 'LiberationMono-Italic.ttf', 'bolditalic' => 'LiberationMono-BoldItalic.ttf'],
        'Carlito' => ['regular' => 'Carlito-Regular.ttf', 'bold' => 'Carlito-Bold.ttf', 'italic' => 'Carlito-Italic.ttf', 'bolditalic' => 'Carlito-BoldItalic.ttf'],
        'Lato' => ['regular' => 'Lato-Regular.ttf', 'bold' => 'Lato-Bold.ttf', 'italic' => 'Lato-Italic.ttf', 'bolditalic' => 'Lato-BoldItalic.ttf'],
        'PT Sans' => ['regular' => 'PTSans-Regular.ttf', 'bold' => 'PTSans-Bold.ttf', 'italic' => 'PTSans-Italic.ttf', 'bolditalic' => 'PTSans-BoldItalic.ttf'],
        'PT Serif' => ['regular' => 'PTSerif-Regular.ttf', 'bold' => 'PTSerif-Bold.ttf', 'italic' => 'PTSerif-Italic.ttf', 'bolditalic' => 'PTSerif-BoldItalic.ttf'],
        'Roboto' => ['regular' => 'Roboto-Regular.ttf', 'bold' => 'Roboto-Bold.ttf', 'italic' => 'Roboto-Italic.ttf', 'bolditalic' => 'Roboto-BoldItalic.ttf'],
        'Open Sans' => ['regular' => 'OpenSans-Regular.ttf', 'bold' => 'OpenSans-Bold.ttf', 'italic' => 'OpenSans-Italic.ttf', 'bolditalic' => 'OpenSans-BoldItalic.ttf'],
        'Montserrat' => ['regular' => 'Montserrat-Regular.ttf', 'bold' => 'Montserrat-Bold.ttf', 'italic' => 'Montserrat-Italic.ttf', 'bolditalic' => 'Montserrat-BoldItalic.ttf'],
        'Noto Sans' => ['regular' => 'NotoSans-Regular.ttf', 'bold' => 'NotoSans-Bold.ttf', 'italic' => 'NotoSans-Italic.ttf', 'bolditalic' => 'NotoSans-BoldItalic.ttf'],
        'Noto Serif' => ['regular' => 'NotoSerif-Regular.ttf', 'bold' => 'NotoSerif-Bold.ttf', 'italic' => 'NotoSerif-Italic.ttf', 'bolditalic' => 'NotoSerif-BoldItalic.ttf'],
        'Fira Sans' => ['regular' => 'FiraSans-Regular.ttf', 'bold' => 'FiraSans-Bold.ttf', 'italic' => 'FiraSans-Italic.ttf', 'bolditalic' => 'FiraSans-BoldItalic.ttf'],
        'Rubik' => ['regular' => 'Rubik-Regular.ttf', 'bold' => 'Rubik-Bold.ttf', 'italic' => 'Rubik-Italic.ttf', 'bolditalic' => 'Rubik-BoldItalic.ttf'],
        'MOST Source Sans 3' => ['regular' => 'MOSTSourceSans3-Regular.ttf', 'bold' => 'MOSTSourceSans3-Bold.ttf', 'italic' => 'MOSTSourceSans3-Italic.ttf', 'bolditalic' => 'MOSTSourceSans3-BoldItalic.ttf'],
        'Source Serif 4' => ['regular' => 'SourceSerif4-Regular.ttf', 'bold' => 'SourceSerif4-Bold.ttf', 'italic' => 'SourceSerif4-Italic.ttf', 'bolditalic' => 'SourceSerif4-BoldItalic.ttf'],
        'MOST Lora' => ['regular' => 'MOSTLora-Regular.ttf', 'bold' => 'MOSTLora-Bold.ttf', 'italic' => 'MOSTLora-Italic.ttf', 'bolditalic' => 'MOSTLora-BoldItalic.ttf'],
        'MOST Playfair Display' => ['regular' => 'MOSTPlayfairDisplay-Regular.ttf', 'bold' => 'MOSTPlayfairDisplay-Bold.ttf', 'italic' => 'MOSTPlayfairDisplay-Italic.ttf', 'bolditalic' => 'MOSTPlayfairDisplay-BoldItalic.ttf'],
    ];

    public static function family(string $family): string
    {
        return $family === 'Most Contract' ? 'DejaVu Sans' : $family;
    }

    public static function css(string $family): string
    {
        $family = self::family($family);
        return "'".$family."'".($family === 'DejaVu Sans' ? '' : ", 'DejaVu Sans'");
    }
}
