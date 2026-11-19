import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router'
import { AuthProvider, useAuth } from './auth/AuthContext'
import Layout from './components/Layout'
import { AbrirChamadoCentralAuto } from './components/CentralSuporte'
import { Carregando, NotificacoesProvider, Pagina } from './components/ui'
import AuditoriaPage from './pages/AuditoriaPage'
import DocumentoEnviarPage from './pages/DocumentoEnviarPage'
import DocumentoPage from './pages/DocumentoPage'
import DocumentosPage from './pages/DocumentosPage'
import InicioPage from './pages/InicioPage'
import LoginPage from './pages/LoginPage'
import MateriaisPage from './pages/MateriaisPage'
import MilitarFormPage from './pages/MilitarFormPage'
import MilitaresPage from './pages/MilitaresPage'
import MovimentacaoPage from './pages/MovimentacaoPage'
import MovimentacoesPage from './pages/MovimentacoesPage'
import NovaEntradaPage from './pages/NovaEntradaPage'
import AcautelamentoPage from './pages/acautelamento/AcautelamentoPage'
import AcautelamentosPage from './pages/acautelamento/AcautelamentosPage'
import CarteiraPage from './pages/acautelamento/CarteiraPage'
import NovoAcautelamentoPage from './pages/acautelamento/NovoAcautelamentoPage'
import VerificarCarteiraPage from './pages/acautelamento/VerificarCarteiraPage'
import { AlertasPage, RelatoriosPage } from './pages/AlertasRelatoriosPage'
import ImportacaoPage from './pages/ImportacaoPage'
import InventarioPage from './pages/inventario/InventarioPage'
import InventariosPage from './pages/inventario/InventariosPage'
import PerfilEfetivoPage from './pages/PerfilEfetivoPage'
import NovaOperacaoPage from './pages/NovaOperacaoPage'
import NovaSaidaPage from './pages/NovaSaidaPage'
import OperacoesPage from './pages/OperacoesPage'
import OrganizacaoPage from './pages/OrganizacaoPage'
import TrocarSenhaPage from './pages/TrocarSenhaPage'
import PermissoesPage from './pages/PermissoesPage'
import ConfiguracoesPage from './pages/ConfiguracoesPage'
import ReferenciasPage from './pages/ReferenciasPage'
import ItensPage from './pages/ItensPage'
import SaldosPage from './pages/SaldosPage'
import MaterialEstoquePage from './pages/MaterialEstoquePage'
import EntregasPage from './pages/EntregasPage'
import DevolucoesPage from './pages/DevolucoesPage'
import NovaEntregaPage from './pages/NovaEntregaPage'
import SessoesPage from './pages/SessoesPage'
import SegurancaPage from './pages/SegurancaPage'
import MeuPerfilPage from './pages/MeuPerfilPage'
import SobrePage, { SobrePublica } from './pages/SobrePage'
import UsuariosPage from './pages/UsuariosPage'
import { MaterialComigoPage, MeusPedidosPage, NovoPedidoPage, PedidoEfetivoPage, PortalInicioPage } from './pages/portal/PortalEfetivo'
import { PedidoPage, PedidosPage } from './pages/pedidos/PedidosMaterial'
import { soPortal } from './api/tipos'
import AtendimentoPage from './pages/suporte/AtendimentoPage'
import ChamadoPage from './pages/suporte/ChamadoPage'
import NovoChamadoPage from './pages/suporte/NovoChamadoPage'
import SuportePage from './pages/suporte/SuportePage'

function Protegida({ children }: { children: ReactNode }) {
  const { perfil, carregando } = useAuth()
  if (carregando) return <Carregando rotulo="Verificando sessão…" />
  if (perfil?.usuario.senha_provisoria) return <TrocarSenhaPage obrigatoria />
  if (perfil?.usuario.senha_expirada) return <TrocarSenhaPage obrigatoria motivo="expirada" />
  if (perfil?.usuario.mfa_cadastro_pendente) return <SegurancaPage obrigatoria />
  return perfil ? <>{children}</> : <Navigate to="/login" replace />
}

function SessaoOuPublica({ children, publica }: { children: ReactNode; publica: ReactNode }) {
  const { perfil, carregando } = useAuth()
  if (carregando) return <Carregando rotulo="Verificando sessão…" />
  const liberada = perfil && !perfil.usuario.senha_provisoria && !perfil.usuario.senha_expirada && !perfil.usuario.mfa_cadastro_pendente
  return liberada ? <>{children}</> : <>{publica}</>
}

/** Início: quem só tem o portal do efetivo vê o resumo do portal; os demais, o painel. */
function Inicio() {
  const { perfil } = useAuth()
  return soPortal(perfil?.permissoes) ? <PortalInicioPage /> : <InicioPage />
}

/** Redireciona mantendo os filtros da URL (links antigos de "Material com efetivos"). */
function RedirecionaComBusca({ para }: { para: string }) {
  const { search } = useLocation()
  return <Navigate to={para + search} replace />
}

/** Oculta a tela sem a permissão; a garantia real é sempre o servidor (RNF-03). */
function Exige({ perm, children }: { perm: string; children: ReactNode }) {
  const { pode } = useAuth()
  return pode(perm) ? <>{children}</> : <Pagina titulo="Acesso negado"><p>Seu perfil não tem permissão para esta área.</p></Pagina>
}

export default function App() {
  return (
    <BrowserRouter basename={import.meta.env.BASE_URL.replace(/\/$/, "") || undefined}>
      <AuthProvider>
        <NotificacoesProvider>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            {/* Sobre o sistema é público: com sessão válida abre no layout do sistema; sem sessão, na moldura de acesso. */}
            <Route element={<SessaoOuPublica publica={<SobrePublica />}><Layout /></SessaoOuPublica>}>
              <Route path="sobre" element={<SobrePage />} />
            </Route>
            <Route element={<Protegida><Layout /></Protegida>}>
              <Route index element={<Inicio />} />
              {/* Portal do efetivo (decisão do responsável, 2026-11-18). */}
              <Route path="portal" element={<Exige perm="portal.efetivo"><PortalInicioPage /></Exige>} />
              <Route path="portal/novo" element={<Exige perm="portal.efetivo"><NovoPedidoPage /></Exige>} />
              <Route path="portal/pedidos" element={<Exige perm="portal.efetivo"><MeusPedidosPage /></Exige>} />
              <Route path="portal/pedidos/:codigo" element={<Exige perm="portal.efetivo"><PedidoEfetivoPage /></Exige>} />
              <Route path="portal/material" element={<Exige perm="portal.efetivo"><MaterialComigoPage /></Exige>} />
              <Route path="pedidos" element={<Exige perm="pedidos.gerir"><PedidosPage /></Exige>} />
              <Route path="pedidos/:codigo" element={<Exige perm="pedidos.gerir"><PedidoPage /></Exige>} />
              <Route path="estoque" element={<Exige perm="estoque.ver"><SaldosPage /></Exige>} />
              <Route path="saldos" element={<Navigate to="/estoque" replace />} />
              <Route path="estoque/materiais/:id" element={<Exige perm="estoque.ver"><MaterialEstoquePage /></Exige>} />
              <Route path="estoque/saida" element={<Exige perm="estoque.saida|estoque.baixar"><NovaSaidaPage /></Exige>} />
              <Route path="estoque/itens" element={<Exige perm="estoque.itens"><ItensPage /></Exige>} />
              {/* Decisão 2026-11-18: "Material com efetivos" virou "Entrega de material"; endereços antigos redirecionam. */}
              <Route path="entregas" element={<Exige perm="estoque.ver"><EntregasPage /></Exige>} />
              <Route path="entregas/nova" element={<Exige perm="estoque.entregar"><NovaEntregaPage /></Exige>} />
              <Route path="devolucoes" element={<Exige perm="estoque.entregar"><DevolucoesPage /></Exige>} />
              <Route path="estoque/vinculados" element={<RedirecionaComBusca para="/entregas" />} />
              <Route path="estoque/vinculados/nova-entrega" element={<Navigate to="/entregas/nova" replace />} />
              <Route path="movimentacoes" element={<Exige perm="movimentacoes.ver"><MovimentacoesPage /></Exige>} />
              <Route path="movimentacoes/nova-entrada" element={<Exige perm="estoque.entrada"><NovaEntradaPage /></Exige>} />
              <Route path="movimentacoes/:id" element={<Exige perm="estoque.ver"><MovimentacaoPage /></Exige>} />
              <Route path="operacoes" element={<Exige perm="movimentacoes.ver"><OperacoesPage /></Exige>} />
              <Route path="acautelamentos" element={<Exige perm="estoque.ver"><AcautelamentosPage /></Exige>} />
              <Route path="acautelamentos/novo" element={<Exige perm="acautelamento.solicitar"><NovoAcautelamentoPage /></Exige>} />
              <Route path="acautelamentos/:codigo" element={<Exige perm="estoque.ver"><AcautelamentoPage /></Exige>} />
              <Route path="acautelamentos/:codigo/carteira" element={<Exige perm="acautelamento.operar"><CarteiraPage /></Exige>} />
              <Route path="carteiras/:token" element={<Exige perm="estoque.ver"><VerificarCarteiraPage /></Exige>} />
              <Route path="inventarios" element={<Exige perm="inventario.ver"><InventariosPage /></Exige>} />
              <Route path="inventarios/:codigo" element={<Exige perm="inventario.ver|inventario.contar|inventario.aprovar"><InventarioPage /></Exige>} />
              <Route path="alertas" element={<AlertasPage />} />
              <Route path="relatorios" element={<Exige perm="relatorios.exportar|relatorios.entregas"><RelatoriosPage /></Exige>} />
              <Route path="dados" element={<Exige perm="dados.importar|relatorios.exportar"><ImportacaoPage /></Exige>} />
              <Route path="operacoes/nova" element={<Exige perm="estoque.ver"><NovaOperacaoPage /></Exige>} />
              <Route path="documentos" element={<Exige perm="documentos.ver"><DocumentosPage /></Exige>} />
              <Route path="documentos/enviar" element={<Exige perm="documentos.enviar"><DocumentoEnviarPage /></Exige>} />
              <Route path="documentos/:codigo" element={<Exige perm="documentos.ver"><DocumentoPage /></Exige>} />
              <Route path="militares" element={<Exige perm="militares.ver"><MilitaresPage /></Exige>} />
              <Route path="militares/novo" element={<Exige perm="militares.gerir"><MilitarFormPage /></Exige>} />
              <Route path="militares/:id" element={<Exige perm="militares.ver"><PerfilEfetivoPage /></Exige>} />
              <Route path="militares/:id/editar" element={<Exige perm="militares.gerir"><MilitarFormPage /></Exige>} />
              <Route path="materiais" element={<Exige perm="catalogo.ver"><MateriaisPage /></Exige>} />
              <Route path="organizacao" element={<Exige perm="organizacao.ver"><OrganizacaoPage /></Exige>} />
              <Route path="usuarios" element={<Exige perm="usuarios.ver"><UsuariosPage /></Exige>} />
              <Route path="permissoes" element={<Exige perm="sistema.configurar"><PermissoesPage /></Exige>} />
              <Route path="configuracoes" element={<Exige perm="sistema.configurar"><ConfiguracoesPage /></Exige>} />
              <Route path="referencias" element={<Exige perm="referencias.gerir"><ReferenciasPage /></Exige>} />
              <Route path="auditoria" element={<Exige perm="auditoria.ver"><AuditoriaPage /></Exige>} />
              <Route path="suporte" element={<Exige perm="suporte.abrir"><SuportePage /></Exige>} />
              <Route path="suporte/novo" element={<Exige perm="suporte.abrir"><NovoChamadoPage /></Exige>} />
              <Route path="suporte/chamados/:codigo" element={<Exige perm="suporte.abrir"><ChamadoPage /></Exige>} />
              <Route path="suporte/atendimento" element={<Exige perm="suporte.atender"><AtendimentoPage /></Exige>} />
              <Route path="sessoes" element={<SessoesPage />} />
              <Route path="seguranca" element={<SegurancaPage />} />
              <Route path="perfil" element={<MeuPerfilPage />} />
              <Route path="*" element={<Pagina titulo="Página não encontrada"><p>O endereço não existe. Se chegou aqui por um link do sistema, avise o suporte.</p><AbrirChamadoCentralAuto /></Pagina>} />
            </Route>
          </Routes>
        </NotificacoesProvider>
      </AuthProvider>
    </BrowserRouter>
  )
}
