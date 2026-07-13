<?php
/**
 * GET api/ui_images.php -> {hero, logo} base64 data URIs.
 * Ports extractDataUri_(): the HeroJs/LogoJs files define the URI as chunked
 * double-quoted string concatenation, so join every quoted segment.
 */
require __DIR__ . '/_common.php';

function extract_data_uri(string $file): string
{
    if (!is_file($file)) {
        return '';
    }
    $content = (string)file_get_contents($file);
    if (preg_match_all('/"([^"]*)"/', $content, $m)) {
        return implode('', $m[1]);
    }
    return '';
}

$root = dirname(__DIR__);
json_out([
    'hero' => extract_data_uri($root . '/HeroJs.html'),
    'logo' => extract_data_uri($root . '/LogoJs.html'),
]);
