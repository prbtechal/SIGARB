<?php
$t=['materiais','itens_individuais','militares','lotes','movimentacoes','eventos_auditoria','categorias','papeis','permissoes'];
$o=[];foreach($t as $x){$o[$x]=[DB::table($x)->count(),DB::table($x)->max('id')];}
$o['ids']=md5(json_encode(array_map(fn($x)=>DB::table($x)->orderBy('id')->pluck('id'),['materiais','itens_individuais','militares','movimentacoes','eventos_auditoria'])));
$o['integra']=app(App\Services\AuditoriaService::class)->verificar()===null;
$o['cp12']=Schema::hasTable('tipos_material'); $o['niveis']=Schema::hasTable('niveis_protecao')?DB::table('niveis_protecao')->count():null;
$o['parcial']=Schema::hasColumn('movimentacoes','vinculo_parcial_id');
$o['armeiro']=DB::table('papeis')->where('codigo','ARMEIRO')->exists();
$o['importar']=DB::table('papel_permissao')->join('permissoes','permissoes.id','=','papel_permissao.permissao_id')->where('permissoes.codigo','dados.importar')->count();
echo json_encode($o),"\n";
