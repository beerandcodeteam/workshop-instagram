<?php

namespace App\Livewire\Post;

use App\Livewire\Forms\ImagePostForm;
use App\Livewire\Forms\TextPostForm;
use App\Livewire\Forms\VideoPostForm;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostType;
use App\Services\MediaUploadService;
use App\Services\VideoProbeService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class CreateModal extends Component
{
    use WithFileUploads;

    public bool $open = false;

    public string $step = 'type';

    public TextPostForm $textForm;

    public ImagePostForm $imageForm;

    public VideoPostForm $videoForm;

    public function mount(): void
    {
        if (session()->pull('open_create_modal') === true) {
            $this->openModal();
        }
    }

    #[On('open-create-post-modal')]
    public function openModal(): void
    {
        abort_unless(auth()->check(), 403);

        $this->resetState();
        $this->open = true;
    }

    public function closeModal(): void
    {
        $this->resetState();
        $this->open = false;
    }

    public function selectType(string $type): void
    {
        if (! in_array($type, ['text', 'image', 'video'], true)) {
            return;
        }

        $this->step = $type;
    }

    public function backToTypeSelection(): void
    {
        $this->textForm->reset();
        $this->imageForm->reset();
        $this->videoForm->reset();
        $this->resetErrorBag();
        $this->step = 'type';
    }

    public function submitText(): void
    {
        abort_unless(auth()->check(), 403);

        $this->textForm->validate();

        Post::create([
            'user_id' => auth()->id(),
            'post_type_id' => $this->postTypeId('text'),
            'body' => $this->textForm->body,
        ]);

        $this->finishWithSuccess();
    }

    public function submitImages(MediaUploadService $mediaUploadService): void
    {
        abort_unless(auth()->check(), 403);

        $this->imageForm->validate();

        DB::transaction(function () use ($mediaUploadService) {
            $post = Post::create([
                'user_id' => auth()->id(),
                'post_type_id' => $this->postTypeId('image'),
                'body' => $this->imageForm->caption ?: null,
            ]);

            foreach ($this->imageForm->images as $index => $file) {
                $path = $mediaUploadService->storeImage($file, $post->id, $index);

                PostMedia::create([
                    'post_id' => $post->id,
                    'file_path' => $path,
                    'sort_order' => $index,
                ]);
            }
        });

        $this->finishWithSuccess();
    }

    public function submitVideo(
        MediaUploadService $mediaUploadService,
        VideoProbeService $videoProbeService,
    ): void {
        abort_unless(auth()->check(), 403);

        $this->videoForm->validate();

        $duration = $videoProbeService->getDurationSeconds($this->videoForm->video->getRealPath());

        if ($duration !== null && $duration > 60.0) {
            $this->addError('videoForm.video', 'O vídeo deve ter no máximo 60 segundos.');

            return;
        }

        DB::transaction(function () use ($mediaUploadService) {
            $post = Post::create([
                'user_id' => auth()->id(),
                'post_type_id' => $this->postTypeId('video'),
                'body' => $this->videoForm->caption ?: null,
            ]);

            $path = $mediaUploadService->storeVideo($this->videoForm->video, $post->id);

            PostMedia::create([
                'post_id' => $post->id,
                'file_path' => $path,
                'sort_order' => 0,
            ]);
        });

        $this->finishWithSuccess();
    }

    public function render()
    {
        return view('livewire.post.create-modal');
    }

    private function postTypeId(string $slug): int
    {
        return PostType::where('slug', $slug)->value('id')
            ?? throw new \RuntimeException("Post type with slug [{$slug}] not found.");
    }

    private function finishWithSuccess(): void
    {
        $this->dispatch('post.created');
        $this->closeModal();
    }

    private function resetState(): void
    {
        $this->step = 'type';
        $this->textForm->reset();
        $this->imageForm->reset();
        $this->videoForm->reset();
        $this->resetErrorBag();
    }
}
