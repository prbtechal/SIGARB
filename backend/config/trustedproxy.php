<?php

/*
 * Proxies confiáveis (RNF-01). SEMPRE um array explícito:
 * - vazio  = nenhum proxy confiável; X-Forwarded-* são ignorados;
 * - lista  = somente esses IPs/CIDRs podem informar IP e esquema do cliente.
 * Um array (mesmo vazio) desativa o atalho do framework que confia em qualquer origem quando o Host
 * termina em .on-forge.com/.on-vapor.com (Host é controlado pelo cliente → bypass de limite por IP).
 * '*' e '**' são recusados de propósito.
 */
$lista = array_values(array_filter(
    array_map('trim', explode(',', (string) env('SIGARB_TRUSTED_PROXIES', ''))),
    fn (string $p) => $p !== '' && ! in_array($p, ['*', '**'], true)
));

return ['proxies' => $lista];
