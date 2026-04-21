# Passo a passo — Embeddings de Post

Walkthrough da construção do fluxo de embeddings (migration + model + service + observer + job), na ordem natural de dependência, com pontos a ajustar em cada camada.

---

## 1. Migration + Model de `post_embeddings`

**Migration** (`2026_04_21_005536_create_post_embeddings_table.php`)

```php
Schema::ensureVectorExtensionExists();              // habilita pgvector

Schema::create('post_embeddings', function (Blueprint $table) {
    $table->id();
    $table->foreignId('post_id')->constrained();
    $table->vector('embedding', 1536);              // dimensão fixa = Gemini output
    $table->timestamps();
});

DB::statement('CREATE INDEX embedding_hnsw_idx ON post_embeddings USING hnsw (embedding vector_cosine_ops)');
```

**Pontos:**
- `vector(1536)` tem que bater com `GEMINI_EMBEDDING_DIMENSIONS`. Se um dia mudar a dimensão → quebra. Considere `->vector('embedding', (int) config('services.gemini.embedding.dimensions'))` para não divergir.
- `foreignId('post_id')` — falta `->cascadeOnDelete()`. Se deletar o post, os embeddings ficam órfãos.
- Índice HNSW com `vector_cosine_ops` assume que você vai buscar por **similaridade de cosseno** (`<=>`). Se for usar L2 (`<->`), troque para `vector_l2_ops`.

**Model** (`PostEmbedding.php`) — está ok. O cast `'array'` funciona com pgvector porque Laravel serializa como JSON ao ler. Relacionamento inverso `post()` definido. Relacionamento direto `postEmbeddings()` já está no `Post`.

---

## 2. Service `GeminiEmbeddingService`

```php
public function embed(array $parts, $task_type = 'RETRIEVAL_DOCUMENT')
{
    return Http::withHeaders([
            'x-goog-api-key' => config('services.gemini.key'),
            'Content-Type' => 'application/json',
        ])->post(config('services.gemini.embedding.endpoint').'/gemini-embedding-2-preview:embedContent', [
            'content' => ['parts' => $parts],
            'task_type' => $task_type,
            'output_dimensionality' => config('services.gemini.embedding.dimensions'),
        ])->throw()->json('embedding.values');
}
```

**Pontos a corrigir:**
1. **Modelo hardcoded na URL.** Você tem `config('services.gemini.embedding.model')` mas está ignorando. Troque para:
   ```php
   ->post(sprintf(
       '%s/%s:embedContent',
       config('services.gemini.embedding.endpoint'),
       config('services.gemini.embedding.model'),
   ), [...])
   ```
2. **Tipagem.** Adicione return type `array` e tipe `$task_type` como `string`.
3. **Timeout.** Use `->timeout(config('services.gemini.embedding.timeout'))` — o config já tem 30s definido.
4. **Service está desacoplado do `request()`/`auth()`** ✅ — bom, fica testável.

---

## 3. Observer `PostObserver`

```php
class PostObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Post $post): void
    {
        dispatch_sync(new GeneratePostEmbeddingJob($post));
    }
}
```

**Pontos:**
- `ShouldHandleEventsAfterCommit` ✅ — garante que só dispara depois do commit da transação do `CreateModal` (que já tem `DB::transaction`).
- **Inconsistência:** o Job implementa `ShouldQueue`, mas você usa `dispatch_sync` — isso ignora a fila e roda inline no request. Se é intencional (dev sem worker), ok; para produção troque para `GeneratePostEmbeddingJob::dispatch($post)` ou só `dispatch(new ...)`.
- Remova os métodos vazios (`updated`, `deleted`, `restored`, `forceDeleted`) — Laravel só chama o que existe.
- **Passe o ID, não o model.** Jobs enfileirados serializam o model e podem pegar estado defasado. Padrão recomendado:
  ```php
  public function created(Post $post): void
  {
      GeneratePostEmbeddingJob::dispatch($post->id);
  }
  ```

---

## 4. Job `GeneratePostEmbeddingJob`

```php
public function handle(GeminiEmbeddingService $gemini): void
{
    if (trim($this->post->body) !== '') {
        $this->parts[] = ['text' => $this->post->body];
    }

    $finfo = new \finfo(FILEINFO_MIME_TYPE);

    if ($this->post->has('media')) {                          // ❌ bug
        foreach ($this->post->media as $mediaItem) {
            $bytes = Storage::get($mediaItem->file_path);
            $this->parts[] = [
                'inline_data' => [
                    'mime_type' => $finfo->buffer($bytes),
                    'data' => base64_encode($bytes),
                ],
            ];
        }
    }

    $embedding = $gemini->embed($this->parts);

    $this->post->postEmbeddings()->create(['embedding' => $embedding]);
}
```

**Pontos a corrigir:**
1. **`$this->post->has('media')` é bug.** `has()` é método do **Builder** (para `whereHas`-like), não do Model. Sempre retorna truthy de forma inesperada. Use:
   ```php
   if ($this->post->media->isNotEmpty()) { ... }
   ```
   Ou simplesmente remova o `if` — o `foreach` sobre coleção vazia é no-op.
2. **Propriedade `$parts` privada.** Como o Job é serializado na fila, `$parts = []` inicializado na classe vira estado persistido. Prefira variável local dentro do `handle()`:
   ```php
   public function handle(GeminiEmbeddingService $gemini): void
   {
       $parts = [];
       // ... popula $parts
       $embedding = $gemini->embed($parts);
   }
   ```
3. **Se passar `post_id` no construtor** (recomendado no passo 3), comece o handle com `$post = Post::with('media')->findOrFail($this->postId);`.
4. **`trim($this->post->body)`** — se `body` for `null`, `trim(null)` dá deprecation warning no PHP 8.5. Use `filled($this->post->body)`.
5. **Falhas e retries.** Adicione `public int $tries = 3;` e `public int $backoff = 10;` — chamada HTTP pra Gemini pode falhar por rate limit/rede.
6. **`$post->postEmbeddings()->create([...])`** ✅ — passa `post_id` automaticamente via relationship.

---

## Fluxo completo (mental model)

```
Livewire CreateModal.save()
  └─ DB::transaction:
       ├─ Post::create()                       ← dispara "creating"/"created" (pending commit)
       ├─ MediaUploadService::storeImage()     ← S3/MinIO upload
       └─ PostMedia::create() (xN)
  [commit]
  ↓ (ShouldHandleEventsAfterCommit dispara aqui)
PostObserver::created($post)
  └─ GeneratePostEmbeddingJob::dispatch($post->id)   ← vai pra fila
       ↓ (worker)
     Job::handle()
       ├─ Monta parts (texto + cada mídia como inline_data)
       ├─ GeminiEmbeddingService::embed($parts)       ← POST HTTP
       └─ PostEmbedding::create(['embedding' => [...]])
```

---

## Ordem sugerida de ajustes

1. Trocar `dispatch_sync` por `dispatch` (ou manter sync se dev sem worker).
2. Trocar `$post` por `$post->id` no Job constructor + carregar com `with('media')` no `handle`.
3. Corrigir `$this->post->has('media')` → remover ou `->media->isNotEmpty()`.
4. Mover `$parts` para variável local.
5. Dinamizar o modelo na URL do Service.
6. Adicionar `cascadeOnDelete` na migration (rodar em nova migration se já subiu em prod).
7. Adicionar `$tries` + `$backoff` no Job.
