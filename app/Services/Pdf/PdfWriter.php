<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * A MINIMAL PDF WRITER, BUILT HERE RATHER THAN INSTALLED.
 *
 * WHY NOT DOMPDF OR TCPDF. Two hard facts on this machine decided it, and
 * neither is a preference:
 *
 *  1. **GD and Imagick are both absent** — verified on the CLI *and* under
 *     XAMPP. Every mainstream PHP PDF library resamples and recompresses
 *     images through one of them. A dependency that cannot render a photograph
 *     is a dependency that ships a blank rectangle where the picture should be,
 *     and does it silently.
 *  2. **The deployment target disables `exec()` and `symlink()`** (Hostinger
 *     shared hosting). A library with a native or external-code path is a
 *     deploy-time gamble, and this app has already lost a deployment to a
 *     missing binary.
 *
 * What is written here is deliberately small and deliberately dumb: rectangles,
 * lines, bezier paths and text in the fourteen standard PDF fonts. No font
 * parsing, no image pipeline, no layout engine. That is enough for a designed
 * document, and it has no way to fail at runtime on the host.
 *
 * **THE FOURTEEN STANDARD FONTS ARE THE POINT, NOT A LIMITATION.** They are
 * guaranteed present in every conforming reader, so nothing is embedded and
 * nothing can be missing on the server. The trade is WinAnsiEncoding, and that
 * encoding has **no peso sign** — 0xA5 is the yen. A peso written as `\x{20B1}`
 * renders as a yen sign on the traveller's page, so money is written "PHP 450"
 * throughout. See `ItineraryPdf::money()`.
 *
 * Coordinates are PDF-native: origin bottom-left, y increasing upward. Every
 * public drawing method takes PDF coordinates, because a PDF writer that lies
 * about its coordinate system is a bug factory. `ItineraryPdf` flips to
 * top-down at its own boundary and nowhere else.
 */
class PdfWriter
{
    public const A4_WIDTH = 595.28;

    public const A4_HEIGHT = 841.89;

    /** The standard fonts, and the letter this file refers to each by. */
    private const FONTS = [
        'F1' => 'Helvetica',
        'F2' => 'Helvetica-Bold',
        'F3' => 'Helvetica-Oblique',
        'F4' => 'Times-Roman',
        'F5' => 'Times-Bold',
        'F6' => 'Times-Italic',
        'F7' => 'Courier',
    ];

    private float $width;

    private float $height;

    /** @var array<int, string> finished content streams, one per page */
    private array $pages = [];

    /** The page currently being drawn. */
    private string $buffer = '';

    /**
     * Graphics-state stack depth, so `restoreState()` can refuse to underflow.
     *
     * Unbalanced save/restore makes the PDF unreadable past that point and no
     * parser complains, so it is tracked rather than trusted.
     */
    private int $depth = 0;

    private int $startedAt;

    public function __construct(float $width = self::A4_WIDTH, float $height = self::A4_HEIGHT)
    {
        $this->width = $width;
        $this->height = $height;
        $this->startedAt = 0;
    }

    public function width(): float
    {
        return $this->width;
    }

    public function height(): float
    {
        return $this->height;
    }

    // -- pages ------------------------------------------------------------

    /** Begin a new page. The first call starts the document's only page. */
    public function addPage(): void
    {
        if ($this->pages !== [] || $this->buffer !== '') {
            $this->pages[] = $this->buffer;
            $this->buffer = '';
            $this->depth = 0;
        }
    }

    private function page(): void
    {
        // Nothing to prepare. Kept as an explicit hook because the one real
        // invariant of this writer — that a page must exist before anything is
        // drawn on it — is enforced by `output()` rather than here, and a reader
        // looking for where that happens deserves to be pointed somewhere.
    }

    // -- graphics state ---------------------------------------------------

    public function saveState(): void
    {
        $this->write('q');
        $this->depth++;
    }

    public function restoreState(): void
    {
        if ($this->depth === 0) {
            throw new RuntimeException('restoreState() with nothing saved — the PDF would be unreadable past this point.');
        }

        $this->write('Q');
        $this->depth--;
    }

    // -- colour -----------------------------------------------------------

    /** Fill colour from a `#rrggbb` string. */
    public function fillColor(string $hex): self
    {
        [$r, $g, $b] = self::rgb($hex);

        return $this->rawFillColor($r, $g, $b);
    }

    /** Stroke colour from a `#rrggbb` string. */
    public function strokeColor(string $hex): self
    {
        [$r, $g, $b] = self::rgb($hex);

        return $this->rawStrokeColor($r, $g, $b);
    }

    private function rawFillColor(float $r, float $g, float $b): self
    {
        return $this->write(sprintf('%.3F %.3F %.3F rg', $r, $g, $b));
    }

    private function rawStrokeColor(float $r, float $g, float $b): self
    {
        return $this->write(sprintf('%.3F %.3F %.3F RG', $r, $g, $b));
    }

    // -- shapes -----------------------------------------------------------

    /** A filled rectangle. `(x, y)` is the lower-left corner. */
    public function rect(float $x, float $y, float $w, float $h, ?string $fill = null): self
    {
        if ($fill !== null) {
            $this->fillColor($fill);
        }

        return $this->write(sprintf('%.2F %.2F %.2F %.2F re %s', $x, $y, $w, $h, $fill === null ? 'n' : 'f'));
    }

    /** A stroked rectangle. `(x, y)` is the lower-left corner. */
    public function strokeRect(float $x, float $y, float $w, float $h, string $colour, float $width = 0.6): self
    {
        $this->strokeColor($colour)->lineWidth($width);

        return $this->write(sprintf('%.2F %.2F %.2F %.2F re S', $x, $y, $w, $h));
    }

    /**
     * A filled rectangle with rounded corners, drawn as four bezier arcs.
     *
     * Built from `re` plus manual corner curves rather than a rounded-rectangle
     * operator, because PDF has none — the naive implementations online get the
     * control points wrong and produce a subtly lopsided corner.
     */
    public function roundRect(float $x, float $y, float $w, float $h, float $r, ?string $fill = null, ?string $stroke = null, float $strokeWidth = 0.6): self
    {
        // A radius larger than half the short side self-intersects, which draws
        // a bow-tie. Clamping is what keeps a thin bar looking like a pill.
        $r = max(0.0, min($r, min($w, $h) / 2));

        if ($r === 0.0) {
            return $fill !== null
                ? $this->rect($x, $y, $w, $h, $fill)
                : $this->strokeRect($x, $y, $w, $h, (string) $stroke, $strokeWidth);
        }

        // The bezier approximation of a quarter circle; 0.5523 is the standard
        // control-point offset for 90 degrees.
        $k = $r * 0.5523;

        $this->write(sprintf('%.2F %.2F m', $x + $r, $y));
        $this->write(sprintf('%.2F %.2F l', $x + $w - $r, $y));
        $this->write(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r));
        $this->write(sprintf('%.2F %.2F l', $x + $w, $y + $h - $r));
        $this->write(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h));
        $this->write(sprintf('%.2F %.2F l', $x + $r, $y + $h));
        $this->write(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r));
        $this->write(sprintf('%.2F %.2F l', $x, $y + $r));
        $this->write(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y));
        $this->write('h');

        if ($fill !== null) {
            $this->fillColor($fill);
            $this->write('f');

            if ($stroke !== null) {
                // `f` consumed the path, so the stroke needs the path again.
                return $this->roundRect($x, $y, $w, $h, $r, null, $stroke, $strokeWidth);
            }

            return $this;
        }

        $this->strokeColor((string) $stroke)->lineWidth($strokeWidth);

        return $this->write('S');
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $colour, float $width = 0.6): self
    {
        $this->strokeColor($colour)->lineWidth($width);

        return $this->write(sprintf('%.2F %.2F m %.2F %.2F l S', $x1, $y1, $x2, $y2));
    }

    public function lineWidth(float $width): self
    {
        return $this->write(sprintf('%.2F w', $width));
    }

    // -- text -------------------------------------------------------------

    /**
     * Draw a single line of text with its BASELINE at `y`.
     *
     * Baseline, not top-left: it is what PDF measures from, and a top-left
     * convention would push every line down by an ascender height nobody
     * accounted for.
     */
    public function text(string $font, float $size, float $x, float $y, string $content, ?string $colour = null): self
    {
        if (! isset(self::FONTS[$font])) {
            throw new RuntimeException('Unknown PDF font '.$font.'.');
        }

        if ($content === '') {
            return $this;
        }

        if ($colour !== null) {
            $this->fillColor($colour);
        }

        // BT/ET bracket the object, Tf selects the font, Td sets the position.
        // ET is mandatory: a PDF text object left open corrupts everything after.
        return $this->write(sprintf(
            "BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET",
            $font,
            $size,
            $x,
            $y,
            self::escape($content)
        ));
    }

    public function textRight(string $font, float $size, float $xRight, float $y, string $content, ?string $colour = null): self
    {
        return $this->text($font, $size, $xRight - $this->widthOf($content, $font, $size), $y, $content, $colour);
    }

    public function textCentred(string $font, float $size, float $xCentre, float $y, string $content, ?string $colour = null): self
    {
        return $this->text($font, $size, $xCentre - $this->widthOf($content, $font, $size) / 2, $y, $content, $colour);
    }

    /**
     * Letter-spaced text, for the small uppercase labels.
     *
     * PDF draws each glyph with `Tc` spacing applied between them, so the word
     * is measured and repositioned after spacing rather than guessed at.
     */
    public function tracked(string $font, float $size, float $x, float $y, string $content, float $tracking, ?string $colour = null): self
    {
        if ($content === '' || $tracking === 0.0) {
            return $this->text($font, $size, $x, $y, $content, $colour);
        }

        if ($colour !== null) {
            $this->fillColor($colour);
        }

        $length = $this->widthOf($content, $font, $size) + $tracking * (mb_strlen($content) - 1);

        return $this->write(sprintf(
            'BT /%s %.2F Tf %.2F Tc %.2F %.2F Td (%s) Tj ET',
            $font,
            $size,
            $tracking,
            $x,
            $y,
            self::escape($content)
        ))->write(sprintf('BT /%s %.2F Tf 0 Tc ET', $font, $size));
    }

    /**
     * Advance width of a string, in points.
     *
     * THE STANDARD 14 HAVE FIXED METRICS, so these are the published widths
     * from the Adobe font tables rather than a guess. Helvetica is 556/1000 em
     * for most glyphs and Times is narrower throughout; getting this wrong
     * makes right-aligned and centred text visibly drift.
     */
    public function widthOf(string $text, string $font, float $size): float
    {
        $table = self::FONTS[$font] ?? 'Helvetica';

        $isBold = str_contains($table, 'Bold');
        $isSerif = str_starts_with($table, 'Times');
        $isMono = str_starts_with($table, 'Courier');

        if ($isMono) {
            return mb_strlen($text) * 600 * $size / 1000;
        }

        $total = 0.0;

        foreach (mb_str_split($text) as $char) {
            $total += $this->glyphWidth($char, $isSerif, $isBold);
        }

        return $total * $size / 1000;
    }

    private function glyphWidth(string $char, bool $isSerif, bool $isBold): float
    {
        // Space and the thin punctuation carry the same advance as a narrow
        // glyph in both families; everything else is grouped by how wide a
        // capital of that class actually sets.
        return match (true) {
            $char === ' ' => 278,
            str_contains('.,:;\'!|i', $char) => $isSerif ? 250 : 278,
            str_contains('ftrj', $char) => $isSerif ? 333 : 333,
            str_contains('()[]-', $char) => $isSerif ? 333 : 333,
            str_contains('mwMW', $char) => $isSerif ? ($isBold ? 944 : 889) : ($isBold ? 889 : 833),
            str_contains('ABCDEFGHKNOPQRSTUVXYZ&', $char) => $isSerif ? ($isBold ? 722 : 667) : ($isBold ? 722 : 667),
            str_contains('0123456789', $char) => 500,
            default => $isSerif ? 444 : 556,
        };
    }

    // -- vector paths -----------------------------------------------------

    /**
     * Emit raw SVG path data as PDF path operators.
     *
     * **The caller supplies the flip.** This method translates `M L C H V Z`
     * verbatim and nothing else, because an SVG path can also carry `A` (arcs),
     * `Q` and `S`, which are NOT the same commands in PDF. Silently skipping an
     * unsupported one would draw a *different* shape rather than fail, so
     * `ItineraryPdf` checks the command set before ever getting here.
     */
    public function svgPath(string $d, float $lineWidth = 0.0, ?string $colour = null): self
    {
        $tokens = preg_split('/\s*([MLCHVZmlchvz])\s*/', trim($d), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $out = '';

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (strlen($token) === 1 && str_contains('MLCHVZmlchvz', $token)) {
                $out .= match (strtoupper($token)) {
                    'M' => 'm',
                    'L' => 'l',
                    'C' => 'c',
                    'H' => 'h',
                    'V' => 'v',
                    'Z' => 'h',
                    // The relative forms mean the same thing here because the
                    // CTM carries the only transform, not the coordinates.
                    default => strtolower($token),
                };

                continue;
            }

            $out .= ' '.$token;
        }

        $this->write(rtrim($out));

        if ($lineWidth > 0.0) {
            $this->strokeColor((string) $colour)->lineWidth($lineWidth);

            return $this->write('S');
        }

        if ($colour !== null) {
            $this->fillColor($colour);
        }

        return $this->write('f');
    }

    /** Raw operator, for the transform matrices. */
    public function concatMatrix(float $a, float $b, float $c, float $d, float $e, float $f): self
    {
        return $this->write(sprintf('%.4F %.4F %.4F %.4F %.4F %.4F cm', $a, $b, $c, $d, $e, $f));
    }

    // -- output -----------------------------------------------------------

    public function output(): string
    {
        /*
         * THE PAGE IN PROGRESS IS NOT IN `$pages` YET.
         *
         * `addPage()` pushes the page you are LEAVING, so after drawing the
         * last page the buffer still holds it and `$pages` holds every page but
         * that one. Serialising `$pages` alone silently drops the final page —
         * and on an itinerary that is the whole body, gone, with a valid PDF
         * and no error anywhere. Appending the buffer is not optional tidiness.
         */
        $pages = $this->pages;

        if ($this->buffer !== '' || $pages === []) {
            $pages[] = $this->buffer;
        }

        $objects = [];

        // Object numbers are assigned up front so the catalog and page tree can
        // be written first while still pointing at pages that come later.
        $catalogId = 1;
        $pagesId = 2;
        $fontIds = [];

        $next = 3;

        foreach (array_keys(self::FONTS) as $letter) {
            $fontIds[$letter] = $next++;
        }

        $pageIds = [];

        foreach ($pages as $ignored) {
            $pageIds[] = $next++;
        }

        $contentIds = [];

        foreach ($pages as $ignored) {
            $contentIds[] = $next++;
        }

        $objects[$catalogId] = sprintf(
            '<< /Type /Catalog /Pages %d 0 R >>',
            $pagesId
        );

        $kids = implode(' ', array_map(fn (int $id) => $id.' 0 R', $pageIds));

        $objects[$pagesId] = sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            $kids,
            count($pageIds)
        );

        $fontRefs = [];

        foreach (self::FONTS as $letter => $base) {
            $objects[$fontIds[$letter]] = sprintf(
                '<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>',
                $base
            );
            $fontRefs[] = sprintf('/%s %d 0 R', $letter, $fontIds[$letter]);
        }

        foreach ($pages as $i => $content) {
            $objects[$pageIds[$i]] = sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << %s >> >> /Contents %d 0 R >>',
                $pagesId,
                $this->width,
                $this->height,
                implode(' ', $fontRefs),
                $contentIds[$i]
            );

            $objects[$contentIds[$i]] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($content),
                $content
            );
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }

        $xrefAt = strlen($pdf);
        $count = count($objects) + 1;

        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id < $count; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root %d 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $count,
            $catalogId,
            $xrefAt
        );

        return $pdf;
    }

    // -- internals --------------------------------------------------------

    private function write(string $operators): self
    {
        $this->page();
        $this->buffer .= $operators."\n";

        return $this;
    }

    /**
     * Escape a string for a PDF literal.
     *
     * **CONVERT TO WIN-1252 FIRST, AS A WHOLE STRING.** WinAnsiEncoding *is*
     * Windows-1252 for every character this app emits — en dash at 0x96, middle
     * dot at 0xB7, curly quotes at 0x91–0x94, ellipsis at 0x85, and every Latin
     * accent where a reader expects one.
     *
     * THE BUG THIS REPLACES. The first version iterated with `str_split()`,
     * which is **byte-based**, so an en dash (U+2013, bytes `E2 80 93`) became
     * three "characters" of 226, 128 and 147. Every one of those is under 256,
     * so the `> 255` branch never fired and the three raw bytes were emitted as
     * if each were a glyph. The page then read
     *
     *     08:00 â€“ 17:30          Day window 08:00 â€“ 09:30 Â· 1 planned stop
     *
     * which is exactly what a PDF reader does with UTF-8 bytes it was told were
     * Latin-1. Nothing errored; the file opened, every structural check passed,
     * and the text was wrong on the page. `widthOf()` used `mb_str_split()` and
     * measured the real characters, so the layout was correct and the glyphs were
     * not — which is why this looked like a font problem and was not one.
     *
     * Converting the whole string up front also fixes accented names:
     * `Pintô` and `Cape Engaño` now print as themselves rather than as `?`.
     *
     * **Parentheses and backslashes are structural, not content.** A title
     * carrying an unbalanced `(` truncates the string and spills raw binary into
     * the page, so they are escaped in the second pass — after conversion, since
     * conversion is what decides the bytes.
     */
    private static function escape(string $text): string
    {
        // The peso sign first: it has no WinAnsi representation at all, so it
        // must be replaced before conversion rather than converted to `?`.
        // The replacement carries its own space — "PHP2,500" reads like a
        // model number, and the money elsewhere is written "PHP 1,850.00".
        $text = strtr($text, ['₱' => 'PHP ']);

        // Unmappable characters become `?` here, which is honest. Dropping them
        // silently leaves words with holes in them.
        $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        if ($converted === false || $converted === '') {
            $converted = $text;
        }

        $out = '';

        foreach (str_split($converted) as $char) {
            $code = ord($char);

            if ($char === '(' || $char === ')' || $char === '\\') {
                $out .= '\\'.$char;

                continue;
            }

            // A control character is not drawable and can terminate the string.
            if ($code < 32) {
                continue;
            }

            $out .= chr($code);
        }

        return $out;
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
}