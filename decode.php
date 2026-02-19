<?php
$json = json_decode(file_get_contents('.gigaide/3.txt'), true);
file_put_contents('.gigaide/3_decoded.json', json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Done";
