import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { AuthProvider, useAuth } from './auth/AuthContext'
import Layout from './components/Layout'
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
import OperacoesPage from './pages/OperacoesPage'
import OrganizacaoPage from './pages/OrganizacaoPage'
import ConfiguracoesPage from './pages/ConfiguracoesPage'
import ReferenciasPage from './pages/ReferenciasPage'
import ItensPage from './pages/ItensPage'
import SaldosPage from './pages/SaldosPage'
import VinculadosPage from './pages/VinculadosPage'
import SessoesPage from './pages/SessoesPage'
import UsuariosPage from './pages/UsuariosPage'
import AtendimentoPage from './pages/suporte/AtendimentoPage'
import ChamadoPage from './pages/suporte/ChamadoPage'
import NovoChamadoPage from './pages/suporte/NovoChamadoPage'
import SuportePage from './pages/suporte/SuportePage'

function Protegida({ children }: { children: ReactNode }) {
  const { perfil, carregando } = useAuth()
  if (carregando) return <Carregando rotulo="Verificando sessão…" />
  return perfil ? <>{children}</> : <Navigate to="/login" replace />
}

/** Oculta a tela sem a permissão; a garantia real é sempre o servidor (RNF-03). */
function Exige({ perm, children }: { perm: string; children: ReactNode }) {
  const { pode } = useAuth()
  return pode(perm) ? <>{children}</> : <Pagina titulo="Acesso negado"><p>Seu perfil não tem permissão para esta área.</p></Pagina>
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <NotificacoesProvider>
          <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route element={<Protegida><Layout /></Protegida>}>
              <Route index element={<InicioPage />} />
              <Route path="estoque" element={<Exige perm="estoque.ver"><SaldosPage /></Exige>} />
              <Route path="estoque/itens" element={<Exige perm="estoque.ver"><ItensPage /></Exige>} />
              <Route path="estoque/vinculados" element={<Exige perm="estoque.ver"><VinculadosPage /></Exige>} />
              <Route path="movimentacoes" element={<Exige perm="estoque.ver"><MovimentacoesPage /></Exige>} />
              <Route path="movimentacoes/nova-entrada" element={<Exige perm="estoque.entrada"><NovaEntradaPage /></Exige>} />
              <Route path="movimentacoes/:id" element={<Exige perm="estoque.ver"><MovimentacaoPage /></Exige>} />
              <Route path="operacoes" element={<OperacoesPage />} />
              <Route path="acautelamentos" element={<Exige perm="estoque.ver"><AcautelamentosPage /></Exige>} />
              <Route path="acautelamentos/novo" element={<Exige perm="acautelamento.solicitar"><NovoAcautelamentoPage /></Exige>} />
              <Route path="acautelamentos/:codigo" element={<Exige perm="estoque.ver"><AcautelamentoPage /></Exige>} />
              <Route path="acautelamentos/:codigo/carteira" element={<Exige perm="acautelamento.operar"><CarteiraPage /></Exige>} />
              <Route path="carteiras/:token" element={<Exige perm="estoque.ver"><VerificarCarteiraPage /></Exige>} />
              <Route path="inventarios" element={<Exige perm="estoque.ver"><InventariosPage /></Exige>} />
              <Route path="inventarios/:codigo" element={<Exige perm="estoque.ver"><InventarioPage /></Exige>} />
              <Route path="alertas" element={<AlertasPage />} />
              <Route path="relatorios" element={<Exige perm="relatorios.exportar"><RelatoriosPage /></Exige>} />
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
              <Route path="configuracoes" element={<Exige perm="sistema.configurar"><ConfiguracoesPage /></Exige>} />
              <Route path="referencias" element={<Exige perm="referencias.gerir"><ReferenciasPage /></Exige>} />
              <Route path="auditoria" element={<Exige perm="auditoria.ver"><AuditoriaPage /></Exige>} />
              <Route path="suporte" element={<Exige perm="suporte.abrir"><SuportePage /></Exige>} />
              <Route path="suporte/novo" element={<Exige perm="suporte.abrir"><NovoChamadoPage /></Exige>} />
              <Route path="suporte/chamados/:codigo" element={<Exige perm="suporte.abrir"><ChamadoPage /></Exige>} />
              <Route path="suporte/atendimento" element={<Exige perm="suporte.atender"><AtendimentoPage /></Exige>} />
              <Route path="sessoes" element={<SessoesPage />} />
              <Route path="*" element={<Pagina titulo="Página não encontrada"><p>O endereço não existe.</p></Pagina>} />
            </Route>
          </Routes>
        </NotificacoesProvider>
      </AuthProvider>
    </BrowserRouter>
  )
}
