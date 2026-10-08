<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

/** Removes a test's own temporary directory tree. */
trait RemovesTempDirs
{
    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->remove($path . '/' . $name);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
