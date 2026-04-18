<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class ImagePostForm extends Form
{
    public array $images = [];

    public ?string $caption = null;

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp'],
            'caption' => ['nullable', 'string', 'max:2200'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.required' => 'Selecione pelo menos uma imagem.',
            'images.array' => 'Selecione pelo menos uma imagem.',
            'images.min' => 'Selecione pelo menos uma imagem.',
            'images.max' => 'Envie no máximo 10 imagens.',
            'images.*.required' => 'Selecione pelo menos uma imagem.',
            'images.*.image' => 'Apenas arquivos de imagem são permitidos.',
            'images.*.mimes' => 'Apenas arquivos jpg, png ou webp são permitidos.',
            'caption.max' => 'A legenda pode ter no máximo 2200 caracteres.',
        ];
    }
}
