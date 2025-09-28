<?php
if (!defined('BASE_URL')) {
    $cfg = __DIR__ . '/config.php';
    if (is_file($cfg)) require_once $cfg;
}

function vendor_upload_rel_base(): string {
    return 'vendor_portal/vendor/uploads';
}

function vendor_upload_abs_base(): string {
    $root = realpath(__DIR__ . '/..');
    if (!$root) $root = dirname(__DIR__);
    return $root . '/' . vendor_upload_rel_base();
}

function vendor_photo_url(?string $filename): string {
    if ($filename) {
        return rtrim(BASE_URL, '/') . '/' . vendor_upload_rel_base() . '/' . ltrim($filename, '/');
    }
    return rtrim(BASE_URL, '/') . '/img/default_vendor.png';
}

function vendor_photo_abs(?string $filename): ?string {
    if (!$filename) return null;
    return vendor_upload_abs_base() . '/' . ltrim($filename, '/');
}

function vendor_photo_delete(?string $filename): void {
    $abs = vendor_photo_abs($filename);
    if ($abs && is_file($abs)) @unlink($abs);
}
