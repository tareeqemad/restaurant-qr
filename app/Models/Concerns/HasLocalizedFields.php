<?php

namespace App\Models\Concerns;

trait HasLocalizedFields
{
    public function localizedName(): string
    {
        return (string) ($this->getAttribute('name') ?? '');
    }

    public function localizedDescription(): ?string
    {
        return $this->getAttribute('description');
    }
}
