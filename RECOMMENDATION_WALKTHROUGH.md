# Passo a passo — Recomendação por centroid de embedding

Objetivo: dar ao usuário um vetor (embedding) que represente o "gosto médio" dele, calculado como a média dos embeddings dos posts que ele curtiu. Toda vez que o usuário der ou tirar um like, o vetor é recalculado.

Pré-requisitos já prontos no projeto:

- Tabela `post_embeddings` com `vector(1536)` por post (gerada via `GeneratePostEmbeddingJob`).
- `PostObserver` que gera o embedding de um post quando ele é criado.

O que vamos construir:

1. Guardar o embedding do usuário direto na tabela `users`.
2. `LikeObserver` que dispara um job toda vez que um like é criado ou removido.
3. `CalculateUserCentroidJob` que calcula a média dos embeddings dos posts curtidos e salva em `users.embedding`.

---

## 1. Migration — coluna `embedding` em `users`

Criar: `./vendor/bin/sail artisan make:migration add_embedding_to_users_table --table=users`

```php
public function up(): void
{
    Schema::dropIfExists('user_embeddings');  // limpa tabela antiga, não usada

    Schema::ensureVectorExtensionExists();    // garante pgvector

    Schema::table('users', function (Blueprint $table) {
        $table->vector('embedding', 1536)->nullable();
    });
}
```

**Pontos a discutir em aula:**

- `vector(1536)` tem que bater com a dimensão do modelo de embedding do Gemini (`GEMINI_EMBEDDING_DIMENSIONS`). Se mudar a dimensão do modelo, quebra.
- `nullable()` porque usuário novo ainda não curtiu nada → não tem centroid.
- Não colocamos índice HNSW aqui (diferente de `post_embeddings`) porque a busca vai ser "dado o centroid do user, encontre os posts mais parecidos" — o índice fica do lado dos posts. Se fôssemos buscar "usuários parecidos", aí sim.

Rodar: `./vendor/bin/sail artisan migrate`

---

## 2. Modelo `User` — fillable + cast

Em `app/Models/User.php`:

```php
#[Fillable(['name', 'email', 'password', 'embedding'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'embedding' => 'array',
        ];
    }
}
```

**Pontos:**

- `cast 'array'` no `vector`: o driver devolve o vetor num formato cru (binário) e o cast do Eloquent converte em `array<float>` na leitura e serializa de volta no save. **Sem o cast, `$user->embedding` vem como string crua e as contas quebram.**

---

## 3. Job `CalculateUserCentroidJob`

Criar: `./vendor/bin/sail artisan make:job CalculateUserCentroidJob`

```php
class CalculateUserCentroidJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(): void
    {
        $user = User::findOrFail($this->userId);

        $likedPostIds = Like::where('user_id', $user->id)->pluck('post_id');

        $embeddings = PostEmbedding::whereIn('post_id', $likedPostIds)
            ->get()
            ->pluck('embedding');

        if ($embeddings->isEmpty()) {
            $user->update(['embedding' => null]);

            return;
        }

        $dimensions = count($embeddings->first());
        $centroid = array_fill(0, $dimensions, 0.0);

        foreach ($embeddings as $embedding) {
            foreach ($embedding as $i => $value) {
                $centroid[$i] += $value;
            }
        }

        $total = $embeddings->count();
        foreach ($centroid as $i => $sum) {
            $centroid[$i] = $sum / $total;
        }

        $user->update(['embedding' => $centroid]);
    }
}
```

**Pontos a discutir em aula:**

- **Recalcula do zero todo like** (simples e didático). Versão incremental: `novo = (antigo * n + novo) / (n+1)` — funciona pro like mas dá dor de cabeça pro unlike.
- **`->get()->pluck('embedding')`, não `->pluck('embedding')`**: `pluck` direto no query builder **pula os casts do Eloquent** — volta o valor cru do pgvector (binário). O `get()` força hidratar o modelo, aí o cast `'array'` roda.
- **Passamos `int $userId`, não o objeto `User`**: job enfileirado serializa o modelo e pode pegar estado defasado quando o worker rodar. ID + `findOrFail` no `handle` é o padrão.
- **Posts sem embedding são ignorados** pelo `whereIn` — embedding do post pode ainda não ter sido gerado.
- Se o user não tiver mais nenhum like (ou os posts dele foram deletados), reseta pra `null`.

---

## 4. Observer `LikeObserver`

Criar: `./vendor/bin/sail artisan make:observer LikeObserver --model=Like`

```php
class LikeObserver
{
    public function created(Like $like): void
    {
        CalculateUserCentroidJob::dispatch($like->user_id);
    }

    public function deleted(Like $like): void
    {
        CalculateUserCentroidJob::dispatch($like->user_id);
    }
}
```

**Pontos:**

- **Não implementa `ShouldHandleEventsAfterCommit`**: diferente do `PostObserver`, a criação de um Like é uma operação simples, uma linha — não precisa esperar commit de uma transação grande. E em testes com `RefreshDatabase`, `ShouldHandleEventsAfterCommit` **nunca dispararia** (a transação externa do teste faz rollback).
- `created` **e** `deleted`: se o usuário tirar o like, o centroid precisa ser recalculado sem aquele post.
- Removemos os métodos `updated`/`restored`/`forceDeleted` vazios — Laravel só chama o que existe.

---

## 5. Ligar o observer no modelo `Like`

Em `app/Models/Like.php`:

```php
use App\Observers\LikeObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

#[Fillable(['user_id', 'post_id'])]
#[ObservedBy(LikeObserver::class)]
class Like extends Model { /* ... */ }
```

Convenção do projeto: observers registrados via atributo no modelo, não no `AppServiceProvider`.

---

## 6. Ajuste no `LikeButton` — garantir que o evento `deleted` dispara

A versão original fazia:

```php
Like::where('user_id', auth()->id())
    ->where('post_id', $this->post->id)
    ->delete();
```

Isso é **query-builder delete** → não emite evento `deleted` do modelo → o observer nunca dispara no unlike.

Corrigir para apagar via modelo:

```php
Like::where('user_id', auth()->id())
    ->where('post_id', $this->post->id)
    ->first()
    ?->delete();
```

Uma query a mais pro `first()`, mas o observer dispara certinho. É uma diferença sutil importante — lembrar em aula que **query-builder ignora model events**.

---

## 7. Testes (Pest)

Dois arquivos:

**`tests/Feature/Likes/UserCentroidOnLikeTest.php`** — garante que o observer dispara o job:

```php
test('liking a post dispatches the centroid job for the user', function () {
    Queue::fake();

    $user = User::factory()->create();
    $post = Post::factory()->text()->createQuietly();

    Like::create(['user_id' => $user->id, 'post_id' => $post->id]);

    Queue::assertPushed(
        CalculateUserCentroidJob::class,
        fn ($job) => $job->userId === $user->id,
    );
});
```

**`tests/Feature/Jobs/CalculateUserCentroidJobTest.php`** — testa o cálculo do centroid em si:

```php
test('centroid is the average of liked posts embeddings', function () {
    $user = User::factory()->create();

    $postA = Post::factory()->text()->createQuietly();
    $postB = Post::factory()->text()->createQuietly();

    PostEmbedding::create(['post_id' => $postA->id, 'embedding' => array_fill(0, 1536, 0.2)]);
    PostEmbedding::create(['post_id' => $postB->id, 'embedding' => array_fill(0, 1536, 0.8)]);

    Like::create(['user_id' => $user->id, 'post_id' => $postA->id]);
    Like::create(['user_id' => $user->id, 'post_id' => $postB->id]);

    (new CalculateUserCentroidJob($user->id))->handle();

    expect(round($user->fresh()->embedding[0], 4))->toBe(0.5);
});
```

**Ponto crítico — `createQuietly()`:** o `PostObserver` dispara em cima de `Post::create` e chama o Gemini. Se usar `->create()` normal no teste, ele tenta fazer uma chamada HTTP real. `createQuietly()` pula os events do modelo, então só cria o post sem gerar embedding automático. Dá controle total sobre o que tem na tabela `post_embeddings` no teste.

Rodar:

```bash
./vendor/bin/sail artisan test --compact --filter='UserCentroidOnLike|CalculateUserCentroidJob'
```

---

## Fluxo mental completo

```
User clica no botão de like (Livewire LikeButton)
  └─ Like::create(['user_id' => ..., 'post_id' => ...])
       ↓ (dispara evento "created" no model)
     LikeObserver::created($like)
       └─ CalculateUserCentroidJob::dispatch($like->user_id)
            ↓ (worker)
          Job::handle()
            ├─ busca post_ids dos likes do usuário
            ├─ busca embeddings desses posts
            ├─ calcula média vetorial (centroid)
            └─ salva em users.embedding
```

No unlike, o mesmo fluxo roda a partir de `deleted`, recalculando sem o post que saiu.

---

## Para a próxima aula — usar o centroid

Com `users.embedding` preenchido, dá pra ordenar o feed por proximidade:

```sql
SELECT p.*
FROM posts p
JOIN post_embeddings pe ON pe.post_id = p.id
WHERE (SELECT embedding FROM users WHERE id = ?) IS NOT NULL
ORDER BY pe.embedding <=> (SELECT embedding FROM users WHERE id = ?)  -- cosseno
LIMIT 20;
```

`<=>` é o operador de **distância de cosseno** do pgvector (menor = mais parecido). O índice HNSW que já existe em `post_embeddings` (criado com `vector_cosine_ops`) acelera essa busca.

Ideias pra evoluir depois:

- **Cold start**: user sem likes não tem centroid → cair num feed "popular" ou "recente".
- **Exploration vs exploitation**: misturar alguns posts aleatórios no feed pra descobrir novos gostos.
- **Decay temporal**: like de 6 meses atrás pesa menos que like de ontem.
- **Múltiplos centroids**: um por cluster (k-means dos likes), em vez de um só (útil quando o user tem gostos muito diferentes).
