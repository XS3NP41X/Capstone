<?php
/** Lightweight, dependency-free PDF renderer for printable EcoTwin exports. */
final class EcoTwinPrintPdf
{
    private array $pages = [];
    private string $content = '';
    private float $y = 0;
    private string $title = '';
    private string $subtitle = '';
    private const W = 842.0;
    private const H = 595.0;
    private const L = 36.0;

    // Handles construct.
    public function __construct(string $title, string $subtitle)
    {
        $this->title = $title;
        $this->subtitle = $subtitle;
        $this->newPage();
    }

    // Handles text.
    private static function text(string $value): string
    {
        $value = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) ?: '';
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $value);
    }

    // Handles clip.
    private static function clip(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, max(0, $limit - 1)) . '…' : $value;
    }

    // Handles rect.
    private function rect(float $x, float $y, float $w, float $h, string $rgb): void
    {
        $this->content .= sprintf("%s rg %.2F %.2F %.2F %.2F re f\n", $rgb, $x, $y, $w, $h);
    }

    // Handles line.
    private function line(float $x1, float $y1, float $x2, float $y2, string $rgb = '0.78 0.86 0.80'): void
    {
        $this->content .= sprintf("%s RG 0.45 w %.2F %.2F m %.2F %.2F l S\n", $rgb, $x1, $y1, $x2, $y2);
    }

    // Handles write.
    private function write(float $x, float $y, string $text, float $size = 9, bool $bold = false, string $rgb = '0.09 0.20 0.13'): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->content .= sprintf("BT /%s %.2F Tf %s rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $font, $size, $rgb, $x, $y, self::text($text));
    }

    // Handles new page.
    private function newPage(): void
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
        }
        $this->content = '';
        $this->rect(0, 539, self::W, 56, '0.12 0.44 0.27');
        $this->write(self::L, 575, 'ECOTWIN  /  RESEARCH OPERATIONS', 8, true, '0.86 0.96 0.89');
        $this->write(self::L, 554, $this->title, 18, true, '1 1 1');
        $this->write(370, 558, self::clip($this->subtitle, 76), 8.5, false, '0.90 0.98 0.92');
        $this->y = 520;
    }

    // Handles room.
    private function room(float $height): void
    {
        if ($this->y - $height < 48) {
            $this->newPage();
        }
    }

    // Handles section.
    public function section(string $title): void
    {
        $this->room(26);
        $this->rect(self::L, $this->y - 18, self::W - (self::L * 2), 18, '0.89 0.96 0.90');
        $this->write(self::L + 8, $this->y - 12, $title, 10, true, '0.10 0.35 0.20');
        $this->y -= 23;
    }

    /** @param array<int, array{0:string,1:string}> $items */
    public function metadata(array $items): void
    {
        foreach ($items as [$label, $value]) {
            $this->room(18);
            $this->rect(self::L, $this->y - 14, 185, 14, '0.95 0.98 0.96');
            $this->write(self::L + 7, $this->y - 10, self::clip($label, 30), 8, true);
            $this->write(self::L + 193, $this->y - 10, self::clip($value, 82), 8, false, '0.08 0.42 0.24');
            $this->line(self::L, $this->y - 14, self::W - self::L, $this->y - 14);
            $this->y -= 14;
        }
        $this->y -= 10;
    }

    /** @param string[] $headers @param array<int, array<int, string>> $rows @param float[]|null $widths */
    public function table(array $headers, array $rows, ?array $widths = null): void
    {
        $total = self::W - (self::L * 2);
        $widths = $widths ?: array_fill(0, count($headers), $total / max(1, count($headers)));
        $drawHeader = function () use ($headers, $widths, $total): void {
            $this->room(22);
            $this->rect(self::L, $this->y - 18, $total, 18, '0.18 0.55 0.34');
            $x = self::L;
            foreach ($headers as $i => $header) {
                $this->write($x + 5, $this->y - 12, self::clip($header, (int) max(7, $widths[$i] / 5.2)), 7.6, true, '1 1 1');
                $x += $widths[$i];
            }
            $this->y -= 18;
        };
        $drawHeader();
        foreach ($rows as $rowIndex => $row) {
            if ($this->y - 15 < 48) {
                $this->newPage();
                $drawHeader();
            }
            if ($rowIndex % 2 === 1) {
                $this->rect(self::L, $this->y - 14, $total, 14, '0.96 0.99 0.97');
            }
            $x = self::L;
            foreach ($headers as $i => $_header) {
                $limit = (int) max(7, $widths[$i] / 5.15);
                $this->write($x + 5, $this->y - 10, self::clip((string) ($row[$i] ?? ''), $limit), 7.4);
                $x += $widths[$i];
            }
            $this->line(self::L, $this->y - 14, self::W - self::L, $this->y - 14, '0.85 0.91 0.87');
            $this->y -= 14;
        }
        $this->y -= 12;
    }

    // Handles output.
    public function output(string $filename): never
    {
        if ($this->content !== '') {
            $this->pages[] = $this->content;
        }
        $count = count($this->pages);
        $objects = ["<< /Type /Catalog /Pages 2 0 R >>", '', "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>", "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>"];
        $pageObjects = [];
        foreach ($this->pages as $i => $page) {
            $footer = sprintf("BT /F1 8 Tf 0.25 0.42 0.29 rg 1 0 0 1 36 24 Tm (EcoTwin | Print-ready research report | Page %d of %d) Tj ET\n", $i + 1, $count);
            $stream = $page . $footer;
            $contentId = count($objects) + 1;
            $pageId = $contentId + 1;
            $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentId} 0 R >>";
            $pageObjects[] = "{$pageId} 0 R";
        }
        $objects[1] = "<< /Type /Pages /Kids [" . implode(' ', $pageObjects) . "] /Count {$count} >>";
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id + 1] = strlen($pdf);
            $pdf .= ($id + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
