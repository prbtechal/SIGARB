<?php

namespace App\Services;

use App\Models\Documento;
use App\Models\Usuario;
use App\Support\SigarbException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * RF-014 / RN-021 — validação de conteúdo e armazenamento privado (ADR-011).
 * O arquivo é gravado com nome aleatório fora da raiz pública; o nome original serve só para exibição.
 */
class DocumentoService
{
    public function __construct(private AuditoriaService $auditoria) {}

    public function disco(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('documentos');
    }

    /** @return array{ext: string, mime: string} */
    public function inspecionar(UploadedFile $f): array
    {
        if (! $f->isValid()) {
            throw new SigarbException('ARQUIVO_INVALIDO', 'O envio do arquivo falhou ou foi interrompido.', 422);
        }
        $tam = (int) $f->getSize();
        if ($tam <= 0) {
            throw new SigarbException('ARQUIVO_VAZIO', 'O arquivo está vazio.', 422);
        }
        $max = config('sigarb.documentos.max_bytes');
        if ($tam > $max) {
            throw new SigarbException('ARQUIVO_GRANDE_DEMAIS', 'O arquivo excede o limite de '.intdiv($max, 1048576).' MB.', 422);
        }
        $ext = strtolower($f->getClientOriginalExtension());
        $tipos = config('sigarb.documentos.tipos');
        if (! isset($tipos[$ext])) {
            throw new SigarbException('TIPO_NAO_PERMITIDO', 'Formato não permitido. Aceitos: PDF, PNG, JPG.', 422);
        }
        $esperado = $tipos[$ext];
        $cabeca = (string) file_get_contents($f->getRealPath(), false, null, 0, 16);
        if (! str_starts_with($cabeca, $esperado['assinatura'])) {
            throw new SigarbException('CONTEUDO_INCOMPATIVEL', 'O conteúdo do arquivo não corresponde à extensão informada.', 422);
        }
        $detectado = (new \finfo(FILEINFO_MIME_TYPE))->file($f->getRealPath());
        if ($detectado !== $esperado['mime']) {
            throw new SigarbException('CONTEUDO_INCOMPATIVEL', 'O conteúdo do arquivo não corresponde à extensão informada.', 422);
        }
        if ($esperado['mime'] === 'application/pdf') {
            $this->validarPdf($f->getRealPath());
        }

        return ['ext' => $ext === 'jpeg' ? 'jpg' : $ext, 'mime' => $esperado['mime']];
    }

    private function validarPdf(string $caminho): void
    {
        $conteudo = (string) file_get_contents($caminho);
        if (! str_contains(substr($conteudo, -2048), '%%EOF')) {
            throw new SigarbException('CONTEUDO_MALFORMADO', 'PDF malformado ou truncado.', 422);
        }
        // Formas que impedem inspeção confiável sem um parser completo são recusadas (falha fechada):
        // nomes com escape hexadecimal (/J#61vaScript ≡ /JavaScript) e object streams comprimidos (/ObjStm).
        if ($this->contem('#/[^\s/<>\[\]()]*\#[0-9A-Fa-f]{2}#', $conteudo) || $this->contem('#/ObjStm(?![A-Za-z0-9])#', $conteudo)) {
            throw new SigarbException('CONTEUDO_NAO_VERIFICAVEL', 'PDF com estrutura comprimida ou nomes codificados não pode ser verificado. Salve-o novamente como PDF simples (ex.: "Imprimir como PDF").', 422);
        }
        foreach (config('sigarb.documentos.pdf_marcadores_proibidos') as $m) {
            // Nome PDF seguido de delimitador (evita casar /JSON com /JS, por exemplo).
            if ($this->contem('#'.preg_quote($m, '#').'(?![A-Za-z0-9])#', $conteudo)) {
                throw new SigarbException('CONTEUDO_ATIVO', 'PDF com conteúdo ativo (scripts, ações ou anexos) não é aceito.', 422);
            }
        }
    }

    /** preg_match estrito: erro do PCRE (ex.: limite de backtracking) = arquivo recusado, nunca "não encontrado". */
    private function contem(string $regex, string $conteudo): bool
    {
        $r = preg_match($regex, $conteudo);
        if ($r === false || preg_last_error() !== PREG_NO_ERROR) {
            throw new SigarbException('CONTEUDO_NAO_VERIFICAVEL', 'Não foi possível verificar o conteúdo do PDF.', 422);
        }

        return $r === 1;
    }

    /** Nome apenas para exibição: sem diretórios, controles ou caracteres de cabeçalho. */
    public static function sanearNome(string $nome, string $ext): string
    {
        $nome = str_replace(['\\', '/'], '_', $nome);
        $nome = preg_replace('/[\x00-\x1F\x7F"<>:|?*]/u', '', $nome) ?? '';
        $nome = trim(preg_replace('/\s+/u', ' ', $nome) ?? '', " .\t");
        $nome = ltrim($nome, '.');
        $base = pathinfo($nome, PATHINFO_FILENAME);
        $base = mb_substr($base !== '' ? $base : 'documento', 0, 180);

        return $base.'.'.$ext;
    }

    public function armazenar(Usuario $u, UploadedFile $f, array $d): Documento
    {
        $info = $this->inspecionar($f);
        $sha = hash_file('sha256', $f->getRealPath());
        $disco = $this->disco();

        // Nome aleatório de 256 bits; nunca sobrescreve (verifica e grava sem substituir).
        for ($i = 0; $i < 5; $i++) {
            $id = bin2hex(random_bytes(32));
            $caminho = 'documentos/'.substr($id, 0, 2).'/'.substr($id, 2, 2).'/'.$id;
            if (! $disco->exists($caminho)) {
                break;
            }
        }
        $stream = fopen($f->getRealPath(), 'rb');
        try {
            if (! $disco->writeStream($caminho, $stream)) {
                throw new SigarbException('FALHA_ARMAZENAMENTO', 'Não foi possível armazenar o arquivo.', 500);
            }
        } finally {
            is_resource($stream) && fclose($stream);
        }

        try {
            return DB::transaction(function () use ($u, $f, $d, $info, $sha, $id) {
                $doc = Documento::create([
                    'codigo_publico' => (string) Str::ulid(),
                    'tipo_documento_id' => $d['tipo_documento_id'],
                    'unidade_id' => $d['unidade_id'],
                    'titulo' => $d['titulo'],
                    'descricao' => $d['descricao'] ?? null,
                    'numero_referencia' => $d['numero_referencia'] ?? null,
                    'nome_original' => self::sanearNome($f->getClientOriginalName(), $info['ext']),
                    'armazenamento_id' => $id,
                    'mime' => $info['mime'],
                    'extensao' => $info['ext'],
                    'tamanho_bytes' => $f->getSize(),
                    'sha256' => $sha,
                    'situacao' => 'ATIVO',
                    'enviado_por_id' => $u->id,
                ]);
                if (! empty($d['movimentacao_id'])) {
                    $doc->vinculos()->create(['movimentacao_id' => $d['movimentacao_id'], 'vinculado_por_id' => $u->id]);
                }
                $this->auditoria->registrar('documento.enviado', 'SUCESSO', 'documento', $doc->id, $doc->unidade_id, [
                    'codigo' => $doc->codigo_publico, 'tipo_documento_id' => $doc->tipo_documento_id, 'mime' => $doc->mime,
                    'tamanho_bytes' => $doc->tamanho_bytes, 'sha256' => $sha, 'movimentacao_id' => $d['movimentacao_id'] ?? null,
                ]);

                return $doc;
            });
        } catch (\Throwable $e) {
            $disco->delete($caminho); // sem registro, sem arquivo órfão
            throw $e;
        }
    }

    /** Verifica a integridade antes de servir. Retorna caminho absoluto. */
    public function arquivoVerificado(Documento $doc): string
    {
        $disco = $this->disco();
        if (! $disco->exists($doc->caminho())) {
            $this->auditoria->registrar('documento.integridade', 'FALHA', 'documento', $doc->id, $doc->unidade_id, ['motivo' => 'arquivo_ausente']);
            throw new SigarbException('ARQUIVO_INDISPONIVEL', 'Arquivo indisponível. Acione o suporte.', 409);
        }
        $abs = $disco->path($doc->caminho());
        if (! hash_equals($doc->sha256, (string) hash_file('sha256', $abs))) {
            $this->auditoria->registrar('documento.integridade', 'FALHA', 'documento', $doc->id, $doc->unidade_id, ['motivo' => 'checksum_divergente']);
            throw new SigarbException('INTEGRIDADE_VIOLADA', 'O arquivo não confere com o checksum registrado. Download bloqueado.', 409);
        }

        return $abs;
    }
}
