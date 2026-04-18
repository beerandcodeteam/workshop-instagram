<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class VideoPostForm extends Form
{
    public $video = null;

    public ?string $caption = null;

    public function rules(): array
    {
        return [
            'video' => ['required', 'file', 'mimes:mp4,mov,webm', 'max:102400'],
            'caption' => ['nullable', 'string', 'max:2200'],
        ];
    }

    public function messages(): array
    {
        return [
            'video.required' => 'Selecione um arquivo de vídeo.',
            'video.file' => 'Selecione um arquivo válido.',
            'video.mimes' => 'Apenas arquivos mp4, mov ou webm são permitidos.',
            'video.max' => 'O vídeo pode ter no máximo 100 MB.',
            'caption.max' => 'A legenda pode ter no máximo 2200 caracteres.',
        ];
    }
}
