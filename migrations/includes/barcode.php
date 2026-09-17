<?php
// ============================================================
//  FILE: includes/barcode.php  (FIXED — Scanner-Safe Code 128B)
//
//  FIXES:
//   1. Module width raised from 2px → 3px minimum
//      USB desktop scanners reading from phone screens need wider bars.
//      2px was borderline; 3px is reliable on all common phone screens.
//
//   2. Height increased from 80px → 100px default
//      More height = larger scan target = more forgiving angle tolerance
//
//   3. Cache key includes module width to avoid stale 2px cached images
//      Call Barcode128::clearCache() once after upgrading
//
//   4. image-rendering CSS cleaned up: only crisp-edges (no pixelated)
//
//  USAGE (unchanged):
//    echo Barcode128::img($token);           // <img> tag (full-width)
//    echo Barcode128::dataUri($token, 100);  // data:image/png;base64,...
// ============================================================

class Barcode128
{
    // Code 128 Set B symbol patterns (alternating bar/space widths, 6 elements = 11 modules)
    private const DATA_PATTERNS = [
         0=>"212222",  1=>"222122",  2=>"222221",  3=>"121223",
         4=>"121322",  5=>"131222",  6=>"122213",  7=>"122312",
         8=>"132212",  9=>"221213", 10=>"221312", 11=>"231212",
        12=>"112232", 13=>"122132", 14=>"122231", 15=>"113222",
        16=>"123122", 17=>"123221", 18=>"223211", 19=>"221132",
        20=>"221231", 21=>"213212", 22=>"223112", 23=>"312131",
        24=>"311222", 25=>"321122", 26=>"321221", 27=>"312212",
        28=>"322112", 29=>"322211", 30=>"212123", 31=>"212321",
        32=>"232121", 33=>"111323", 34=>"131123", 35=>"131321",
        36=>"112313", 37=>"132113", 38=>"132311", 39=>"211313",
        40=>"231113", 41=>"231311", 42=>"112133", 43=>"112331",
        44=>"132131", 45=>"113123", 46=>"113321", 47=>"133121",
        48=>"313121", 49=>"211331", 50=>"231131", 51=>"213113",
        52=>"213311", 53=>"213131", 54=>"311123", 55=>"311321",
        56=>"331121", 57=>"312113", 58=>"312311", 59=>"332111",
        60=>"314111", 61=>"221411", 62=>"431111", 63=>"111224",
        64=>"111422", 65=>"121124", 66=>"121421", 67=>"141122",
        68=>"141221", 69=>"112214", 70=>"112412", 71=>"122114",
        72=>"122411", 73=>"142211", 74=>"214111", 75=>"241111",
        76=>"134111", 77=>"111242", 78=>"121142", 79=>"121241",
        80=>"114212", 81=>"124112", 82=>"124211", 83=>"411212",
        84=>"421112", 85=>"421211", 86=>"212141", 87=>"214121",
        88=>"412121", 89=>"111143", 90=>"111341", 91=>"131141",
        92=>"114113", 93=>"114311", 94=>"411113", 95=>"411311",
        96=>"113141", 97=>"114131", 98=>"311141", 99=>"411131",
       100=>"211412",101=>"211214",102=>"211232",
    ];

    private const START_B_PATTERN = "211214";
    private const STOP_PATTERN    = "2331112";
    private const START_B_VALUE   = 104;

    // ── Public API ────────────────────────────────────────────

    /**
     * Returns an <img> tag. Width fills parent container (CSS width:100%).
     * Use crisp-edges rendering — prevents browser anti-aliasing on bars.
     *
     * @param string $data    The token to encode
     * @param int    $height  Bar height in pixels (default 100)
     */
    public static function img(
        string $data,
        int    $height = 100,
        string $class  = '',
        string $style  = ''
    ): string {
        $uri = self::dataUri($data, $height);
        if (!$uri) {
            return '<div style="color:red;font-size:12px;padding:8px;">Barcode generation failed (GD not available)</div>';
        }
        $cls = $class ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
        // crisp-edges prevents blurring when browser stretches to CSS width
        $sty = 'width:100%;height:auto;display:block;'
             . 'image-rendering:crisp-edges;image-rendering:-moz-crisp-edges;'
             . htmlspecialchars($style, ENT_QUOTES);
        return '<img src="' . $uri . '" alt="Barcode"' . $cls . ' style="' . $sty . '"/>';
    }

    /**
     * Returns data:image/png;base64,...
     * The PNG is generated at 3px per module × height px.
     */
    public static function dataUri(string $data, int $height = 100): string
    {
        $cacheKey = $data . '_h' . $height . '_m3'; // include scale in key
        $cached   = self::getCached($cacheKey);
        if ($cached) return $cached;

        $png = self::generatePNG($data, $height, 3); // 3px per module
        if ($png) {
            self::setCached($cacheKey, $png);
            return 'data:image/png;base64,' . base64_encode($png);
        }
        return '';
    }

    public static function clearCache(): void
    {
        $files = glob(sys_get_temp_dir() . '/pbc_*.dat');
        if ($files) foreach ($files as $f) @unlink($f);
    }

    // ── PNG Generator ─────────────────────────────────────────

    private static function generatePNG(string $data, int $height, int $scale): ?string
    {
        if (!extension_loaded('gd')) {
            error_log('[Barcode128] GD not loaded — install php_gd2');
            return null;
        }

        $bars = self::buildBars($data);
        if (!$bars) return null;

        $totalModules = array_sum($bars);
        $quietModules = 10; // ISO 15417 requires ≥10-module quiet zone each side
        $imgW         = ($totalModules + $quietModules * 2) * $scale;
        $imgH         = max(60, $height);

        $im = imagecreatetruecolor($imgW, $imgH);
        if (!$im) return null;

        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im,   0,   0,   0);
        imagefill($im, 0, 0, $white);

        // Draw bars (even index = bar, odd index = space)
        $x = $quietModules * $scale;
        foreach ($bars as $idx => $width) {
            if ($idx % 2 === 0) {
                imagefilledrectangle($im, $x, 0, $x + $width * $scale - 1, $imgH - 1, $black);
            }
            $x += $width * $scale;
        }

        ob_start();
        imagepng($im, null, 0); // compression=0 → sharpest
        $png = ob_get_clean();
        imagedestroy($im);

        return $png ?: null;
    }

    // ── Code 128B Encoder ─────────────────────────────────────

    private static function buildBars(string $data): ?array
    {
        // Validate: Code 128B supports ASCII 32–126 only
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $o = ord($data[$i]);
            if ($o < 32 || $o > 126) {
                error_log("[Barcode128] Char at pos {$i} (ASCII {$o}) out of Code128B range 32-126");
                return null;
            }
        }

        // Checksum: START_B_VALUE + Σ (position+1) × (ASCII - 32), mod 103
        $len   = strlen($data);
        $check = self::START_B_VALUE;
        for ($i = 0; $i < $len; $i++) {
            $check += ($i + 1) * (ord($data[$i]) - 32);
        }
        $check %= 103;

        $bars = [];
        self::push($bars, self::START_B_PATTERN);
        for ($i = 0; $i < $len; $i++) {
            self::push($bars, self::DATA_PATTERNS[ord($data[$i]) - 32]);
        }
        self::push($bars, self::DATA_PATTERNS[$check]);
        self::push($bars, self::STOP_PATTERN);

        return $bars;
    }

    private static function push(array &$bars, string $pattern): void
    {
        for ($i = 0, $l = strlen($pattern); $i < $l; $i++) {
            $bars[] = (int)$pattern[$i];
        }
    }

    // ── Cache (24-hour TTL) ───────────────────────────────────

    private static function getCached(string $key): ?string
    {
        $file = sys_get_temp_dir() . '/pbc_' . md5($key) . '.dat';
        if (file_exists($file) && (time() - filemtime($file) < 86400)) {
            $raw = @file_get_contents($file);
            if ($raw) return 'data:image/png;base64,' . base64_encode($raw);
        }
        return null;
    }

    private static function setCached(string $key, string $png): void
    {
        $file = sys_get_temp_dir() . '/pbc_' . md5($key) . '.dat';
        @file_put_contents($file, $png);
    }
}