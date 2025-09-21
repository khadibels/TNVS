<?php
$dir = __DIR__ . '/uploads';
$deleted = 0;
if (is_dir($dir)) {
  foreach (glob($dir.'/*') as $f) {
    if (is_file($f)) { @unlink($f); $deleted++; }
  }
}
echo "Deleted {$deleted} files\n";
