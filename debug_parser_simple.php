<?php

$filePath = 'C:\\Users\\kamilgaraev\\Downloads\\2dssData.xml';
$content = file_get_contents($filePath);

// Convert from CP1251 to UTF-8
if (!mb_check_encoding($content, 'UTF-8')) {
    $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
    $content = str_replace('encoding="windows-1251"', 'encoding="utf-8"', $content);
}

$content = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $content);

try {
    $xml = new SimpleXMLElement($content);
} catch (Exception $e) {
    die("XML Error: " . $e->getMessage());
}

$items = [];

function parseNode($node, &$items) {
    foreach ($node->children() as $child) {
        $name = $child->getName();
        if ($name == 'Position') {
            $num = (string)$child['Number'];
            $code = (string)$child['Code'];
            $caption = (string)$child['Caption'];
            
            $items[] = "Number: $num | Code: $code | Caption: " . mb_substr($caption, 0, 30);
        }
        // Recurse ONLY if not Position (assuming Positions are leafs or don't contain other Positions we care about here)
        if ($name != 'Position') {
             parseNode($child, $items);
        }
    }
}

parseNode($xml, $items);

foreach ($items as $line) {
    if (preg_match('/Number: (14[0-9])/', $line)) {
        echo $line . "\n";
    }
}
