<?php

namespace App\Domain\Reports;

use App\Models\Store;
use Illuminate\Support\Carbon;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Turns a report result (see ReportRunner) into a CSV, Excel or PDF file.
 * Amounts leave the system here as rupees with two decimals; nothing is calculated from them again.
 */
final class ReportExporter
{
    public const FORMATS = ['csv' => 'text/csv; charset=UTF-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'];

    /**
     * Writes the file and returns its path. The caller sends it and deletes it.
     *
     * @param  array<string, mixed>  $report
     */
    public function write(array $report, string $format, Store $store, ReportLabels $labels): string
    {
        $directory = storage_path('app/exports');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.bin2hex(random_bytes(12)).'.'.$format;

        $header = array_map(fn (array $c) => $labels->get('reports.col.'.$c['key']), $report['columns']);
        $numeric = $format === 'xlsx';
        $rows = array_map(fn (array $row) => $this->cells($report['columns'], $row, $labels, $numeric), $report['rows']);
        $totals = $report['totals'] === [] ? null : $this->totalCells($report, $labels, $numeric);

        match ($format) {
            'csv' => $this->csv($path, $header, $rows, $totals),
            'xlsx' => $this->xlsx($path, $this->headingLines($report, $store, $labels), $header, $rows, $totals),
            'pdf' => $this->pdf($path, $report, $store, $labels, $header, $rows, $totals),
        };

        return $path;
    }

    public function fileName(array $report, string $format): string
    {
        $period = isset($report['filters']['from']) ? $report['filters']['from'].'_'.$report['filters']['to'] : now(config('shop.timezone'))->format('Y-m-d');

        return str_replace('_', '-', $report['key']).'-'.$period.'.'.$format;
    }

    /** @return list<string> title, shop, period */
    private function headingLines(array $report, Store $store, ReportLabels $labels): array
    {
        $lines = [$labels->get('reports.name.'.$report['key']), $store->name.($store->village ? ', '.$store->village : '')];
        $f = $report['filters'];
        if (isset($f['from'])) {
            $lines[] = $labels->get('reports.period', ['from' => self::date($f['from']), 'to' => self::date($f['to'])]);
        }
        if (isset($f['days'])) {
            $lines[] = $labels->get('reports.days_n', ['count' => $f['days']]);
        }
        if (! empty($f['type'])) {
            $lines[] = $labels->get('products.type.'.$f['type']);
        }
        $lines[] = $labels->get('reports.made_on', ['when' => now(config('shop.timezone'))->format('d-m-Y H:i')]);

        return $lines;
    }

    private static function date(?string $value): string
    {
        return $value ? Carbon::parse($value)->format('d-m-Y') : '';
    }

    /** "5.00" -> "5%", "0" -> "0%", "40" -> "40%", "2.50" -> "2.5%". */
    public static function percent(string $rate): string
    {
        return (str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate).'%';
    }

    /**
     * A cell beginning with = + - @ (or a tab / line break before them) would be run as a formula by Excel.
     * Plain numbers are left alone; anything else gets a leading quote so it is read as text.
     */
    public static function csvSafe(mixed $cell): mixed
    {
        if (! is_string($cell) || $cell === '' || is_numeric($cell)) {
            return $cell;
        }

        return preg_match('/^[\s]*[=+\-@]|^[\t\r\n]/', $cell) ? "'".$cell : $cell;
    }

    private static function rupees(int $paise): string
    {
        return number_format($paise / 100, 2, '.', '');
    }

    /** @return list<string|int|float> */
    private function cells(array $columns, array $row, ReportLabels $labels, bool $numeric): array
    {
        $cells = [];
        foreach ($columns as $column) {
            $value = $row[$column['key']] ?? null;
            $cells[] = match (true) {
                $value === null || $value === '' => '',
                $column['type'] === 'money' => $numeric ? round($value / 100, 2) : self::rupees((int) $value),
                $column['type'] === 'int' => (int) $value,
                $column['type'] === 'percent' => self::percent((string) $value),
                $column['type'] === 'date' => self::date($value),
                $column['type'] === 'datetime' => Carbon::parse($value)->timezone(config('shop.timezone'))->format('d-m-Y H:i'),
                $column['type'] === 'label' => $labels->get($column['prefix'].'.'.$value),
                default => (string) $value,
            };
        }

        return $cells;
    }

    private function totalCells(array $report, ReportLabels $labels, bool $numeric): array
    {
        $cells = [];
        foreach ($report['columns'] as $index => $column) {
            $total = $report['totals'][$column['key']] ?? null;
            $cells[] = match (true) {
                $total === null => $index === 0 ? $labels->get('reports.total') : '',
                $column['type'] === 'money' => $numeric ? round($total / 100, 2) : self::rupees($total),
                default => $total,
            };
        }

        return $cells;
    }

    private function csv(string $path, array $header, array $rows, ?array $totals): void
    {
        $out = fopen($path, 'w');
        fwrite($out, "\xEF\xBB\xBF"); // lets Excel read Devanagari
        fputcsv($out, $header);
        foreach ($rows as $row) {
            // A cell starting with = + - @ would be run as a formula by a spreadsheet; a leading quote stops that.
            fputcsv($out, array_map(self::csvSafe(...), $row));
        }
        if ($totals) {
            fputcsv($out, $totals);
        }
        fclose($out);
    }

    private function xlsx(string $path, array $heading, array $header, array $rows, ?array $totals): void
    {
        $bold = new Style(fontBold: true);
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        foreach ($heading as $index => $line) {
            $writer->addRow($index === 0 ? Row::fromValuesWithStyle([$line], new Style(fontBold: true, fontSize: 14)) : Row::fromValues([$line]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle($header, $bold));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        if ($totals) {
            $writer->addRow(Row::fromValuesWithStyle($totals, $bold));
        }
        $writer->close();
    }

    /** The font families the PDF export may use; the desktop package ships only these files (build/windows/make-desktop.ps1). */
    public const PDF_FONTS = ['freesans', 'freeserif', 'dejavusans', 'dejavusanscondensed', 'dejavusansmono'];

    /**
     * mPDF font settings. Marathi and Hindi need their letters joined properly: autoScriptToLang/autoLangToFont
     * switch to FreeSerif for Devanagari. mPDF is told that only PDF_FONTS exist, so text in a script with no
     * shipped font (Kannada, Arabic, Chinese…) prints as boxes instead of crashing on a missing font file.
     *
     * @return array<string, mixed>
     */
    public static function pdfConfig(): array
    {
        $defaults = (new FontVariables)->getDefaults();

        return [
            'default_font' => 'freesans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'fontdata' => array_intersect_key($defaults['fontdata'], array_flip(self::PDF_FONTS)),
            'sans_fonts' => ['freesans', 'dejavusanscondensed', 'dejavusans', 'sans', 'sans-serif'],
            'serif_fonts' => ['freeserif', 'serif'],
            'mono_fonts' => ['dejavusansmono', 'mono', 'monospace'],
            'backupSubsFont' => ['dejavusanscondensed', 'freesans', 'freeserif'],
            'backupSIPFont' => null,
        ];
    }

    private function pdf(string $path, array $report, Store $store, ReportLabels $labels, array $header, array $rows, ?array $totals): void
    {
        $e = fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        $heading = $this->headingLines($report, $store, $labels);
        $right = array_map(fn (array $c) => in_array($c['type'], ['money', 'int', 'percent'], true), $report['columns']);

        $html = '<h1>'.$e($heading[0]).'</h1><p class="meta">'.implode(' &nbsp;·&nbsp; ', array_map($e, array_slice($heading, 1))).'</p>';
        $html .= '<table><thead><tr>';
        foreach ($header as $i => $text) {
            $html .= '<th'.($right[$i] ? ' class="r"' : '').'>'.$e($text).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $i => $cell) {
                $html .= '<td'.($right[$i] ? ' class="r"' : '').'>'.$e($cell).'</td>';
            }
            $html .= '</tr>';
        }
        if ($rows === []) {
            $html .= '<tr><td colspan="'.count($header).'">'.$e($labels->get('reports.empty')).'</td></tr>';
        }
        $html .= '</tbody>';
        if ($totals) {
            $html .= '<tfoot><tr>';
            foreach ($totals as $i => $cell) {
                $html .= '<td'.($right[$i] ? ' class="r"' : '').'>'.$e($cell).'</td>';
            }
            $html .= '</tr></tfoot>';
        }
        $html .= '</table>';

        $temp = storage_path('app/mpdf');
        if (! is_dir($temp)) {
            mkdir($temp, 0775, true);
        }
        $mpdf = new Mpdf(self::pdfConfig() + [
            'tempDir' => $temp,
            'format' => 'A4',
            'orientation' => count($header) > 7 ? 'L' : 'P',
            'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 14,
        ]);
        $mpdf->SetTitle($heading[0]);
        $mpdf->SetCreator(config('app.name'));
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML('
            body { font-size: 9pt; color: #1c2434; }
            h1 { font-size: 15pt; margin: 0 0 2pt 0; }
            p.meta { color: #5b6577; margin: 0 0 8pt 0; }
            table { width: 100%; border-collapse: collapse; }
            th { background: #eef1f6; text-align: left; border-bottom: 1px solid #c9cfda; padding: 4pt 5pt; }
            td { border-bottom: 1px solid #e4e7ee; padding: 3.5pt 5pt; }
            tfoot td { font-weight: bold; border-top: 1px solid #1c2434; border-bottom: none; }
            .r { text-align: right; }
        ', HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);
        $mpdf->Output($path, Destination::FILE);
    }
}
