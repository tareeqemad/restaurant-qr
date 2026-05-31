<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

class MarketSpreadsheetLocalizer
{
    public static function apply(Spreadsheet $book): void
    {
        if (! MarketProfile::isUs()) {
            return;
        }

        static::translateProperties($book);

        $usedTitles = [];
        foreach ($book->getAllSheets() as $sheet) {
            $sheet->setRightToLeft(false);

            $title = static::uniqueTitle(
                static::safeTitle(MarketHtmlTranslator::translate($sheet->getTitle())),
                $usedTitles
            );
            $sheet->setTitle($title);
            $usedTitles[] = $title;

            foreach ($sheet->getCoordinates(false) as $coordinate) {
                $cell = $sheet->getCell($coordinate);
                $value = $cell->getValue();

                if (is_string($value) && preg_match('/\p{Arabic}/u', $value) === 1) {
                    $cell->setValue(MarketHtmlTranslator::translate($value));
                }
            }

            foreach ($sheet->getComments() as $comment) {
                foreach ($comment->getText()->getRichTextElements() as $element) {
                    $text = $element->getText();
                    if (preg_match('/\p{Arabic}/u', $text) === 1) {
                        $element->setText(MarketHtmlTranslator::translate($text));
                    }
                }
            }
        }
    }

    private static function translateProperties(Spreadsheet $book): void
    {
        $properties = $book->getProperties();

        foreach (['Title', 'Subject', 'Description', 'Keywords', 'Category'] as $name) {
            $getter = 'get'.$name;
            $setter = 'set'.$name;

            if (! method_exists($properties, $getter) || ! method_exists($properties, $setter)) {
                continue;
            }

            $value = $properties->{$getter}();
            if (is_string($value) && preg_match('/\p{Arabic}/u', $value) === 1) {
                $properties->{$setter}(MarketHtmlTranslator::translate($value));
            }
        }
    }

    private static function safeTitle(string $title): string
    {
        $title = trim(preg_replace('/[\\\\\\/?*:\\[\\]]+/', ' ', $title) ?? $title);
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = mb_substr($title, 0, 31);

        return $title !== '' ? $title : 'Sheet';
    }

    private static function uniqueTitle(string $title, array $usedTitles): string
    {
        $used = array_map('mb_strtolower', $usedTitles);

        if (! in_array(mb_strtolower($title), $used, true)) {
            return $title;
        }

        $base = mb_substr($title, 0, 28);
        $index = 2;

        do {
            $candidate = mb_substr($base, 0, 31 - strlen(" {$index}"))." {$index}";
            $index++;
        } while (in_array(mb_strtolower($candidate), $used, true));

        return $candidate;
    }
}
