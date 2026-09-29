<?php

declare(strict_types=1);

namespace Wext\StaticPublisher;

use Generator;
use RuntimeException;

/** ZIP STORE streaming: no temporary files, executable files, or archives on disk. */
final class Export_Zip
{
    /** @param array<string,string> $objects Archive names mapped to database object identifiers. */
    public static function pieces(array $objects): Generator
    {
        if (count($objects) > 65535) {
            throw new RuntimeException(esc_html__('This export exceeds the ZIP entry limit.', 'wext-static-publisher'));
        }
        $central = '';
        $offset = 0;
        foreach ($objects as $name => $object) {
            if (! Path_Mapper::is_safe_static_path($name) || strlen($name) > 65535) {
                throw new RuntimeException(esc_html__('Invalid ZIP entry name.', 'wext-static-publisher'));
            }
            $meta = Export_Storage::metadata($object);
            if ($meta === null || $meta['size'] >= 0xffffffff || $offset + 30 + strlen($name) + $meta['size'] >= 0xffffffff) {
                throw new RuntimeException(esc_html__('This export exceeds the ZIP size limit or contains missing data.', 'wext-static-publisher'));
            }
            $size = (int) $meta['size'];
            $crc = (int) hexdec($meta['crc32']);
            $date = getdate(max(315532800, (int) $meta['modified']));
            $dos_time = ($date['hours'] << 11) | ($date['minutes'] << 5) | ($date['seconds'] >> 1);
            $dos_date = (($date['year'] - 1980) << 9) | ($date['mon'] << 5) | $date['mday'];
            $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x800, 0, $dos_time, $dos_date, $crc, $size, $size, strlen($name), 0) . $name;
            yield $header;
            yield from Export_Storage::chunks($object);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x800, 0, $dos_time, $dos_date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $offset += strlen($header) + $size;
        }
        if ($offset + strlen($central) + 22 >= 0xffffffff) {
            throw new RuntimeException(esc_html__('This export exceeds the ZIP size limit.', 'wext-static-publisher'));
        }
        yield $central;
        yield pack('VvvvvVVv', 0x06054b50, 0, 0, count($objects), count($objects), strlen($central), $offset, 0);
    }
}
