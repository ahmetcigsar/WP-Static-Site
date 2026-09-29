<?php
/** Test-only adapter: validate generated database ZIPs with the independent ZipArchive reader. */
function wext_test_zip_path(string $object): string
{
    $path = tempnam(sys_get_temp_dir(), 'wext-test-');
    file_put_contents($path, Wext\StaticPublisher\Export_Storage::read($object));
    register_shutdown_function(static function () use ($path): void { @unlink($path); });
    return $path;
}
