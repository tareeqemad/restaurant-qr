<?php

namespace App\Models\Concerns;

trait HasLocalizedFields
{
    public function localizedName(): string
    {
        return (string) ($this->localizedValue('name') ?? '');
    }

    public function localizedDescription(): ?string
    {
        return $this->localizedValue('description');
    }

    private function localizedValue(string $field): mixed
    {
        $englishField = $field.'_en';
        $englishValue = $this->getAttribute($englishField);

        if (app()->getLocale() === 'en' && is_string($englishValue) && trim($englishValue) !== '') {
            return $englishValue;
        }

        return $this->getAttribute($field);
    }
}
