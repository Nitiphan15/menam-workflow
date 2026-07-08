<?php

if (($argv[1] ?? '') === '' || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php scripts/inspect-pdf-fonts.php <pdf-path>\n");
    exit(1);
}

$contents = file_get_contents($argv[1]);
preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9\+\-_,\.]+)/', $contents ?: '', $matches);

$fonts = array_values(array_unique(array_filter(array_map(
    fn ($font) => preg_replace('/^[A-Z]{6}\+/', '', (string) $font),
    $matches[1] ?? []
))));

echo "Fonts in {$argv[1]}:\n";
foreach ($fonts as $font) {
    echo "- {$font}\n";
}

if (!$fonts) {
    echo "- No /BaseFont entries found. The PDF may use object streams or embedded resources that need a deeper PDF parser.\n";
}
