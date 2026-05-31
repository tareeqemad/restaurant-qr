<?php

namespace App\Support;

class MarketHtmlTranslator
{
    public static function translateForCurrentMarket(string $html): string
    {
        if (! MarketProfile::isUs()) {
            return $html;
        }

        return static::translate($html);
    }

    public static function translate(string $html): string
    {
        $phrases = trans('market_html.phrases', [], MarketProfile::lang());

        if (! is_array($phrases) || $phrases === []) {
            return $html;
        }

        uksort($phrases, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($phrases as $source => $target) {
            if (! is_string($source) || ! is_string($target) || $source === '' || $source === $target) {
                continue;
            }

            $pattern = '/(?<!\p{Arabic})'.preg_quote($source, '/').'(?!\p{Arabic})/u';
            $translated = preg_replace($pattern, $target, $html);
            if (is_string($translated)) {
                $html = $translated;
            }
        }

        return strtr($html, static::digitMap());
    }

    private static function digitMap(): array
    {
        return [
            "\u{0660}" => '0',
            "\u{0661}" => '1',
            "\u{0662}" => '2',
            "\u{0663}" => '3',
            "\u{0664}" => '4',
            "\u{0665}" => '5',
            "\u{0666}" => '6',
            "\u{0667}" => '7',
            "\u{0668}" => '8',
            "\u{0669}" => '9',
            "\u{06F0}" => '0',
            "\u{06F1}" => '1',
            "\u{06F2}" => '2',
            "\u{06F3}" => '3',
            "\u{06F4}" => '4',
            "\u{06F5}" => '5',
            "\u{06F6}" => '6',
            "\u{06F7}" => '7',
            "\u{06F8}" => '8',
            "\u{06F9}" => '9',
        ];
    }
}
