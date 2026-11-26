<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportExporter;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Tests\TestCase;

/**
 * The desktop package ships only the font files that match ^(FreeSans|FreeSerif|DejaVuSans) (see
 * build/windows/make-desktop.ps1). These tests render with exactly that set of files, so a report cell in any
 * script never makes mPDF look for a font file that is not there.
 */
class ReportPdfFontsTest extends TestCase
{
    private string $fontDir;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fontDir = storage_path('app/test-fonts-'.uniqid());
        $this->tempDir = storage_path('app/test-mpdf-'.uniqid());
        File::ensureDirectoryExists($this->fontDir);
        File::ensureDirectoryExists($this->tempDir);
        foreach (File::files(base_path('vendor/mpdf/mpdf/ttfonts')) as $file) {
            if (preg_match('/^(FreeSans|FreeSerif|DejaVuSans)/', $file->getFilename())) {
                File::copy($file->getPathname(), $this->fontDir.DIRECTORY_SEPARATOR.$file->getFilename());
            }
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fontDir);
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_every_font_the_config_names_is_a_shipped_file(): void
    {
        $fontdata = ReportExporter::pdfConfig()['fontdata'];
        $this->assertEqualsCanonicalizing(ReportExporter::PDF_FONTS, array_keys($fontdata));
        foreach ($fontdata as $family => $styles) {
            foreach (['R', 'B', 'I', 'BI'] as $style) {
                if (isset($styles[$style])) {
                    $this->assertFileExists($this->fontDir.DIRECTORY_SEPARATOR.$styles[$style], "$family $style");
                }
            }
        }
        foreach (['sans_fonts', 'serif_fonts', 'mono_fonts', 'backupSubsFont'] as $list) {
            foreach (ReportExporter::pdfConfig()[$list] as $font) {
                $this->assertTrue(
                    in_array($font, ReportExporter::PDF_FONTS, true) || in_array($font, ['sans', 'sans-serif', 'serif', 'mono', 'monospace'], true),
                    "$list names $font, which is not shipped"
                );
            }
        }
    }

    public function test_text_in_any_script_renders_with_only_the_shipped_fonts(): void
    {
        $mpdf = new Mpdf(ReportExporter::pdfConfig() + ['tempDir' => $this->tempDir, 'fontDir' => [$this->fontDir]]);
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->WriteHTML('<style>body{font-size:9pt} .s{font-family:serif} .m{font-family:monospace}</style>
            <table>
            <tr><td>Paracetamol 500 mg · ₹ 240.00 — strip</td></tr>
            <tr><td>पॅरासिटामॉल ५०० मि.ग्रॅ. · साखर १ किलो · हिंदी: दूध</td></tr>
            <tr><td>ಕನ್ನಡ ಹೆಸರು</td></tr>
            <tr><td>عربي اردو</td></tr>
            <tr><td>中文名称 日本語</td></tr>
            <tr><td class="s">serif</td><td class="m">mono</td><td>😀 emoji</td></tr>
            </table>');
        $pdf = $mpdf->Output('', Destination::STRING_RETURN);

        $this->assertStringStartsWith('%PDF-', $pdf);
        preg_match_all('/\/BaseFont\s*\/[A-Z]+\+([A-Za-z]+)/', $pdf, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $embedded) {
            $this->assertMatchesRegularExpression('/^(FreeSans|FreeSerif|DejaVuSans)/', $embedded);
        }
    }
}
