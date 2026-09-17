<?php
// ============================================================
//  FILE: includes/qr.php  (FINAL — Python temp-file approach)
//
//  WHY STDOUT FAILED ON WINDOWS:
//  PHP's shell_exec() on Windows opens the stdout pipe in TEXT
//  mode. This corrupts binary PNG data in two ways:
//   1. \n (0x0A) gets expanded to \r\n (0x0D 0x0A)
//   2. 0x1A (Ctrl+Z) is treated as EOF, truncating output
//  msvcrt.setmode() in Python does NOT fix this because the
//  corruption happens on PHP's side of the pipe, not Python's.
//
//  THE FIX:
//  gen_qr.py writes PNG to a temp file and prints the file path.
//  PHP reads the temp file directly (file I/O is always binary).
//  Temp file is deleted immediately after reading.
//
//  FALLBACK CHAIN:
//   1. Python → temp file → PHP reads file  (primary)
//   2. qrserver.com remote API              (if Python fails)
//
//  PUBLIC API:
//   echo PlayerQR::img($token);
//   echo PlayerQR::dataUri($token);
//   PlayerQR::clearCache();
// ============================================================

define('PYTHON_EXE', 'C:\\Users\\rjocu\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe');

class PlayerQR
{
    private static function pyScript(): string
    {
        return __DIR__ . '/gen_qr.py';
    }

    // ── Public API ────────────────────────────────────────────

    public static function img(
        string $data,
        int    $displaySize = 300,
        string $class       = '',
        string $style       = ''
    ): string {
        $uri = self::dataUri($data);
        $cls = $class ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
        $sty = "width:{$displaySize}px;height:{$displaySize}px;display:block;"
             . htmlspecialchars(
                   str_replace(
                       ['image-rendering:pixelated', 'image-rendering: pixelated'],
                       '',
                       $style
                   ),
                   ENT_QUOTES
               );
        return '<img src="' . $uri . '" width="' . $displaySize . '" height="' . $displaySize
             . '" alt="QR Code"' . $cls . ' style="' . $sty . '"/>';
    }

    public static function dataUri(string $data): string
    {
        $cacheKey = 'py2_' . $data;
        $cached   = self::getCached($cacheKey);
        if ($cached) return $cached;

        $png = self::generateViaPython($data);
        if ($png) {
            self::setCached($cacheKey, $png);
            return 'data:image/png;base64,' . base64_encode($png);
        }

        error_log('[PlayerQR] Python failed, using qrserver.com fallback');
        return self::fallbackUrl($data);
    }

    public static function url(string $data, int $size = 300): string
    {
        return self::dataUri($data);
    }

    public static function clearCache(): void
    {
        $files = glob(sys_get_temp_dir() . '/pqr_*.dat');
        if ($files) {
            foreach ($files as $f) @unlink($f);
        }
    }

    // ── Python generation via temp file ───────────────────────

    private static function generateViaPython(string $data): ?string
    {
        $script = self::pyScript();
        if (!file_exists($script)) {
            error_log('[PlayerQR] gen_qr.py not found at: ' . $script);
            return null;
        }

        $pythonExe = PYTHON_EXE;
        $tmpErr    = tempnam(sys_get_temp_dir(), 'qrerr_');

        // Python prints the temp PNG file path to stdout (plain text — safe on Windows)
        $cmd = '"' . $pythonExe . '" '
             . escapeshellarg($script)
             . ' ' . escapeshellarg($data)
             . ' 2>' . escapeshellarg($tmpErr);

        $tmpPngPath = trim((string)shell_exec($cmd));

        // Log stderr if anything went wrong
        if (file_exists($tmpErr)) {
            $errOut = @file_get_contents($tmpErr);
            @unlink($tmpErr);
            if ($errOut && strlen(trim($errOut)) > 0) {
                error_log('[PlayerQR] Python stderr: ' . trim($errOut));
            }
        }

        if (!$tmpPngPath) {
            error_log('[PlayerQR] Python returned no file path');
            return null;
        }

        if (!file_exists($tmpPngPath)) {
            error_log('[PlayerQR] Temp PNG file not found: ' . $tmpPngPath);
            return null;
        }

        $png = @file_get_contents($tmpPngPath);
        @unlink($tmpPngPath); // clean up temp file immediately

        if (!$png || strlen($png) < 100) {
            error_log('[PlayerQR] Temp PNG file too small: ' . strlen((string)$png) . ' bytes');
            return null;
        }

        if (substr($png, 0, 4) !== "\x89PNG") {
            error_log('[PlayerQR] Temp PNG has invalid header: ' . bin2hex(substr($png, 0, 8)));
            return null;
        }

        return $png;
    }

    // ── Remote fallback ───────────────────────────────────────

    private static function fallbackUrl(string $data): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/'
             . '?size=500x500&data=' . urlencode($data)
             . '&ecc=L&margin=4&format=png';
    }

    // ── 24-hour file cache ────────────────────────────────────

    private static function getCached(string $key): ?string
    {
        $file = sys_get_temp_dir() . '/pqr_' . md5($key) . '.dat';
        if (file_exists($file) && (time() - filemtime($file) < 86400)) {
            $raw = @file_get_contents($file);
            if ($raw && substr($raw, 0, 4) === "\x89PNG") {
                return 'data:image/png;base64,' . base64_encode($raw);
            }
        }
        return null;
    }

    private static function setCached(string $key, string $png): void
    {
        $file = sys_get_temp_dir() . '/pqr_' . md5($key) . '.dat';
        @file_put_contents($file, $png);
    }
}