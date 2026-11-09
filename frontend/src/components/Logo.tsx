/** Marca do ecossistema (Programa Ronda no Bairro), a mesma do Visita-Segura e do RondaSocial. Decorativa: o nome do sistema vem ao lado. */
export default function Logo({ grande = false }: { grande?: boolean }) {
  return (
    <span className={`logo${grande ? ' logo-grande' : ''}`} aria-hidden="true">
      <img src={`${import.meta.env.BASE_URL}logo-ronda-do-bairro.png`} alt="" width="48" height="48" />
    </span>
  )
}
