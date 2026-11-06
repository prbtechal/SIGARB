<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/** Arquivos SINTÉTICOS para testes de upload. */
final class Arquivos
{
    public static function pdf(string $extra = ''): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n{$extra}\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    public static function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    public static function arquivo(string $nome, string $conteudo): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, $conteudo);
    }
}
