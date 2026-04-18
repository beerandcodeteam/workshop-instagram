<?php

namespace App\Livewire\Forms;

use Livewire\Attributes\Validate;
use Livewire\Form;

class CaptionPostForm extends Form
{
    #[Validate('required|string|max:2200')]
    public string $body = '';
}
