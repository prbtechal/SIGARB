"""PE-06 — compara: (A) heurística atual em bytes crus, (B) qpdf --qdf + mesma varredura, (C) reescrita Ghostscript.
Fixtures SINTÉTICAS e inofensivas (o 'JavaScript' é texto inerte; nenhum leitor é executado)."""
import os, re, subprocess, time, json, shutil

MARC = ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/OpenAction', '/AA', '/RichMedia', '/XFA']
def contem(b):
    t = b.decode('latin-1')
    ativos = [m for m in MARC if re.search(re.escape(m) + r'(?![A-Za-z0-9])', t)]
    nao_verif = bool(re.search(r'/[^\s/<>\[\]()]*#[0-9A-Fa-f]{2}', t) or re.search(r'/ObjStm(?![A-Za-z0-9])', t))
    return ativos, nao_verif

def pdf(objs, root=1):
    out, offs = b'%PDF-1.7\n', []
    for i, o in enumerate(objs, 1):
        offs.append(len(out)); out += f'{i} 0 obj\n{o}\nendobj\n'.encode()
    x = len(out)
    out += f'xref\n0 {len(objs)+1}\n0000000000 65535 f \n'.encode() + b''.join(f'{o:010d} 00000 n \n'.encode() for o in offs)
    out += f'trailer<</Size {len(objs)+1}/Root {root} 0 R>>\nstartxref\n{x}\n%%EOF\n'.encode()
    return out

pagina = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>'
F = {
  'limpo': pdf(['<</Type/Catalog/Pages 2 0 R>>', '<</Type/Pages/Kids[3 0 R]/Count 1>>', pagina]),
  'js_explicito': pdf(['<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>', '<</Type/Pages/Kids[3 0 R]/Count 1>>', pagina, '<</S/JavaScript/JS(app.alert\\(1\\))>>']),
  'js_nome_codificado': pdf(['<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>', '<</Type/Pages/Kids[3 0 R]/Count 1>>', pagina, '<</S/J#61vaScript/J#53(x)>>']),
}
os.makedirs('fx', exist_ok=True)
for n, b in F.items(): open(f'fx/{n}.pdf', 'wb').write(b)
# JS escondido em object stream comprimido (gerado pelo próprio qpdf)
subprocess.run(['qpdf', '--object-streams=generate', 'fx/js_explicito.pdf', 'fx/js_em_objstm.pdf'], check=True)

res = []
for n in ['limpo', 'js_explicito', 'js_nome_codificado', 'js_em_objstm']:
    cru = open(f'fx/{n}.pdf', 'rb').read()
    a = contem(cru)
    t0 = time.time(); subprocess.run(['qpdf', '--qdf', '--object-streams=disable', '--decode-level=all', f'fx/{n}.pdf', f'fx/{n}.qdf.pdf'], check=False)
    tq = time.time() - t0; b = contem(open(f'fx/{n}.qdf.pdf', 'rb').read())
    t0 = time.time(); r = subprocess.run(['gs', '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=pdfwrite', f'-sOutputFile=fx/{n}.gs.pdf', f'fx/{n}.pdf'], capture_output=True)
    tg = time.time() - t0
    c = contem(open(f'fx/{n}.gs.pdf', 'rb').read()) if os.path.exists(f'fx/{n}.gs.pdf') else (['falhou'], False)
    res.append({'fixture': n,
      'A_heuristica_atual': 'recusa: ativo '+','.join(a[0]) if a[0] else ('recusa: não verificável' if a[1] else 'aceita'),
      'B_qpdf_normalizado': ('ativo: '+','.join(b[0])) if b[0] else ('não verificável' if b[1] else 'limpo'), 'B_ms': round(tq*1000),
      'C_ghostscript_reescrito': ('restou ativo: '+','.join(c[0])) if c[0] else 'sem conteúdo ativo', 'C_ms': round(tg*1000)})
print(json.dumps(res, ensure_ascii=False, indent=1))
