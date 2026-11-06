/**
 * CSP de PRODUÇÃO da SPA (ADR-012). Sem 'unsafe-inline' e sem 'unsafe-eval'.
 * Estilos dinâmicos do React são aplicados via CSSOM (não bloqueado por style-src).
 * Em produção atrás de HTTPS, acrescentar `upgrade-insecure-requests` e HSTS no servidor web.
 * O servidor de desenvolvimento do Vite NÃO usa esta política (HMR exige scripts inline).
 */
export const CSP_PRODUCAO = [
  "default-src 'self'",
  "script-src 'self'",
  "style-src 'self'",
  "img-src 'self' data:",
  "font-src 'self'",
  "connect-src 'self'",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join('; ')

export const CABECALHOS_SEGURANCA = {
  'Content-Security-Policy': CSP_PRODUCAO,
  'X-Content-Type-Options': 'nosniff',
  'Referrer-Policy': 'same-origin',
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), payment=()',
  'Cross-Origin-Opener-Policy': 'same-origin',
}
