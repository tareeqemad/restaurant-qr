<?php

namespace Tests\Unit;

use App\Support\MarketHtmlTranslator;
use App\Support\MarketSpreadsheetLocalizer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class MarketHtmlTranslatorTest extends TestCase
{
    public function test_us_market_translates_legacy_arabic_html_labels(): void
    {
        config(['market.profile' => 'us']);

        $html = '<h1>تفاصيل الصندوق</h1><button>إصدار الفاتورة</button><span>٧ أيام</span>';
        $translated = MarketHtmlTranslator::translateForCurrentMarket($html);

        $this->assertStringContainsString('Income statement', $translated);
        $this->assertStringContainsString('Issue invoice', $translated);
        $this->assertStringContainsString('7 days', $translated);
        $this->assertStringNotContainsString('تفاصيل الصندوق', $translated);
    }

    public function test_non_us_market_keeps_arabic_html_unchanged(): void
    {
        config(['market.profile' => 'palestine']);

        $html = '<h1>تفاصيل الصندوق</h1>';

        $this->assertSame($html, MarketHtmlTranslator::translateForCurrentMarket($html));
    }

    public function test_us_market_translates_spreadsheet_cells_and_direction(): void
    {
        config(['market.profile' => 'us']);

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('ملخص');
        $sheet->setRightToLeft(true);
        $sheet->setCellValue('A1', 'تاريخ التقرير');

        MarketSpreadsheetLocalizer::apply($book);

        $this->assertSame('Summary', $sheet->getTitle());
        $this->assertFalse($sheet->getRightToLeft());
        $this->assertSame('Generated at', $sheet->getCell('A1')->getValue());
    }
}
