(() => {
  if (window.__vk) return;
  window.__vk = true;
  document.querySelectorAll('[data-vk-imprimir]').forEach(b => b.addEventListener('click', () => window.print()));
  const raiz = document.getElementById('vk-raiz');
  if (!raiz) return;
  const base = raiz.dataset.base;
  let csrf = raiz.dataset.csrf || '';
  const iniciar = () => {
  const enviar = async (url, dados) => {
    const fd = new FormData();
    Object.entries(dados).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' } });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(j.erro || 'Não foi possível salvar.');
    return j;
  };
  const aviso = (txt, erro = false) => {
    const t = document.createElement('div');
    t.className = 'vk-toast' + (erro ? ' erro' : ''); t.textContent = txt; t.setAttribute('role', 'status');
    document.body.appendChild(t); setTimeout(() => t.remove(), 4000);
  };

  const botao = document.createElement('button');
  botao.type = 'button'; botao.className = 'vk-flutuante'; botao.setAttribute('aria-label', 'Enviar ponderação');
  botao.innerHTML = '<span aria-hidden="true">✎</span><b>Ponderação</b>';
  document.body.appendChild(botao);
  if (!location.pathname.startsWith(base)) {
    const ajuda = document.createElement('a');
    ajuda.className = 'vk-flutuante vk-ajuda'; ajuda.href = base; ajuda.setAttribute('aria-label', 'Ajuda, manual e roteiro de testes');
    ajuda.innerHTML = '<span aria-hidden="true">?</span><b>Ajuda</b>';
    document.body.appendChild(ajuda);
  }

  const dlg = document.createElement('dialog');
  dlg.className = 'vk-dialogo';
  dlg.innerHTML = `<form method="dialog" class="vk-form">
    <h2>Enviar ponderação</h2>
    <p class="vk-meta">Sobre a tela: <code data-tela></code></p>
    <div class="vk-opcoes"><label><input type="radio" name="tipo" value="erro" required> Erro</label><label><input type="radio" name="tipo" value="melhoria"> Melhoria</label><label><input type="radio" name="tipo" value="duvida"> Dúvida</label><label><input type="radio" name="tipo" value="texto"> Texto</label></div>
    <label>Título<input name="titulo" maxlength="160" required placeholder="Ex.: Botão salvar não responde"></label>
    <label>O que aconteceu / o que sugere<textarea name="texto" rows="5" maxlength="5000" required placeholder="Passo a passo do que fez, o que esperava e o que aconteceu."></textarea></label>
    <label>Print (opcional)<input type="file" name="arquivo" accept="image/png,image/jpeg,image/webp"><small>Também dá para colar (Ctrl+V) uma imagem aqui.</small></label>
    <img data-previa alt="" hidden>
    <div class="vk-acoes"><button class="vk-botao primario" value="enviar">Enviar</button><button class="vk-botao" value="cancelar" formnovalidate>Cancelar</button><a class="vk-botao" href="${base}">Ajuda e manual</a></div>
  </form>`;
  document.body.appendChild(dlg);
  const form = dlg.querySelector('form');
  let print = '';
  const previa = dlg.querySelector('[data-previa]');
  const reduzir = file => new Promise((ok, falha) => {
    if (!/^image\/(png|jpeg|webp)$/.test(file.type)) return falha(new Error('Use uma imagem PNG, JPG ou WebP.'));
    const img = new Image(); const leitor = new FileReader();
    img.onload = () => {
      const esc = Math.min(1, 1600 / Math.max(img.width, img.height));
      const c = document.createElement('canvas'); c.width = Math.round(img.width * esc); c.height = Math.round(img.height * esc);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      ok(c.toDataURL('image/jpeg', 0.85));
    };
    img.onerror = () => falha(new Error('Imagem inválida.'));
    leitor.onload = () => { img.src = leitor.result; }; leitor.onerror = () => falha(new Error('Imagem inválida.')); leitor.readAsDataURL(file);
  });
  const usarArquivo = async f => { try { print = await reduzir(f); previa.src = print; previa.hidden = false; } catch (e) { aviso(e.message, true); } };
  form.arquivo.addEventListener('change', e => { if (e.target.files[0]) usarArquivo(e.target.files[0]); });
  dlg.addEventListener('paste', e => { const it = [...(e.clipboardData?.items || [])].find(i => i.type.startsWith('image/')); if (it) usarArquivo(it.getAsFile()); });
  botao.addEventListener('click', () => {
    form.reset(); print = ''; previa.hidden = true;
    dlg.querySelector('[data-tela]').textContent = location.pathname + location.search + location.hash;
    dlg.showModal();
  });
  form.addEventListener('submit', async e => {
    if (e.submitter?.value !== 'enviar') return;
    e.preventDefault();
    if (!form.reportValidity()) return;
    const b = e.submitter; b.disabled = true;
    try {
      await enviar(base + '/ponderacao', { tipo: form.tipo.value, titulo: form.titulo.value, texto: form.texto.value, tela: location.pathname + location.search + location.hash, print });
      dlg.close(); aviso('Ponderação enviada. Obrigado! Acompanhe em Ajuda → Minhas ponderações.');
    } catch (er) { aviso(er.message, true); }
    finally { b.disabled = false; }
  });

  document.querySelectorAll('.vk-passo .vk-responder').forEach(f => f.addEventListener('submit', async e => {
    e.preventDefault();
    const art = f.closest('.vk-passo'), msg = f.querySelector('.vk-msg');
    const status = f.querySelector('input[name=status]:checked')?.value;
    if (!status) { msg.textContent = 'Escolha OK, Falhou ou Não se aplica.'; return; }
    msg.textContent = 'Salvando…';
    try {
      await enviar(base + '/roteiro', { passo: art.dataset.passo, status, obs: f.obs.value });
      art.className = 'vk-passo st-' + status;
      msg.textContent = status === 'falhou' ? 'Salvo e enviado como ponderação.' : 'Salvo.';
    } catch (er) { msg.textContent = er.message; }
  }));
  return [botao, document.querySelector('.vk-flutuante.vk-ajuda')].filter(Boolean);
  };
  if (!raiz.dataset.auto) { iniciar(); return; }
  let elementos = null, timer = 0;
  const conferir = async () => {
    clearTimeout(timer);
    let logado = false;
    try {
      const r = await fetch(base + '/estado', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const j = await r.json();
      logado = !!j.logado;
      if (j.csrf) csrf = j.csrf;
    } catch (e) { logado = !!elementos; }
    if (logado && !elementos) elementos = iniciar();
    if (elementos) elementos.forEach(el => { el.style.display = logado ? '' : 'none'; });
    timer = setTimeout(conferir, logado ? 60000 : 8000);
  };
  window.addEventListener('hashchange', () => { if (!elementos) conferir(); });
  window.addEventListener('popstate', () => { if (!elementos) conferir(); });
  conferir();
})();
