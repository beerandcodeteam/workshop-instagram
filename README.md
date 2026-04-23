# Workshop Instagram

Projeto base do workshop sobre **sistemas de recomendação com embeddings multimodais** usando o modelo do Google.

O clone simplificado do Instagram (Laravel 13 + Livewire 4) é só o cenário — o foco do workshop é construir, em cima desses dados, um sistema que recomenda posts (imagem + texto) a partir de embeddings gerados pelo modelo multimodal do Google.

## Setup

```bash
# 1. Clone
git clone <repo-url> workshop-instagram
cd workshop-instagram

# 2. Instalar dependências do Composer (antes do Sail existir)
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs

# 3. Configurar .env
cp .env.example .env

# 4. Subir os containers (app + Postgres + MinIO)
./vendor/bin/sail up -d

# 5. App key + dependências de front-end
./vendor/bin/sail artisan key:generate
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

# 6. Rodar as migrations
./vendor/bin/sail artisan migrate
```

## Popular com dados de demonstração

O seed cria 300 usuários que dividem entre si os 1250 conteúdos (600 imagens + 400 vídeos + 250 textos) do Pixabay sem repetição, com legendas contextualizadas em português.

As mídias (7.24GB) não estão no repositório — baixe o `seed-media.zip` do Google Drive do workshop e coloque na **raiz do projeto**.

```bash
# 1 Rodar as migrations
./vendor/bin/sail artisan migrate --seed

# 2. Baixar seed-media.zip do Drive e deixar na raiz do projeto seed-media.zip
https://drive.google.com/file/d/12rrGBwMk97zktH40ouSK7QtCN5ep_hVO/view?usp=sharing

# 3. Extrair e subir as mídias pro MinIO (demora alguns minutos)
./vendor/bin/sail artisan app:install-seed-media

# 4. Popular o banco
./vendor/bin/sail artisan db:seed --class=DemoUserSeeder
```

O comando `app:install-seed-media` aceita:
- `--zip=caminho/outro.zip` — usa outro zip
- `--force` — reescreve objetos que já existem no MinIO

## Como os dados foram gerados

Arquivos fonte (versionados no repo):
- **Manifest** (`database/seeders/data/pixabay-manifest.json`): lista das 1000 mídias do Pixabay com tags, query e legenda (`caption`) pré-gerada em PT-BR
- **Pool de texto** (`database/seeders/data/text_pool.json`): 250 posts de texto puro estilo Instagram

Pipeline usado pra gerar esses arquivos:

```bash
# 1. Baixar as mídias do Pixabay pro MinIO e gerar o manifest inicial
#    (precisa PIXABAY_API_KEY no .env)
./vendor/bin/sail artisan app:seed-pixabay-media

# 2. Gerar captions olhando cada imagem + tags (via Claude Code, ~10 min)
#    Os vídeos tiveram caption gerada só a partir das tags (sem visualização)
#    Resultado salvo direto no manifest, em cada entry como "caption"

# 3. Gerar pool de textos (via Claude Code, ~2 min)
#    Resultado salvo em database/seeders/data/text_pool.json

# 4. Zipar as mídias pra distribuir (pra não versionar 7GB no git)
cd /caminho/pro/minio/data && zip -r -0 seed-media.zip seed/
```

Atenção: `app:seed-pixabay-media` **não regera as legendas** — após rodar, as captions no manifest estarão faltando e o pipeline de geração via Claude Code precisa ser rodado de novo.
