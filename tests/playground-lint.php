<?php
$count = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/workspace/wext-static-publisher')) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
        $count++;
    }
}
echo "PHP syntax passed for $count files.\n";
