{{--
    La recherche pendant la frappe des listes (bons de vente / d'achat, clients, fournisseurs), comme celle du stock :
    le formulaire form.search[data-live="<id>"] remplit l'element <id> avec la meme adresse + ?partial=1.
    Pause de 220 ms apres la derniere touche ; la requete precedente est annulee, et une reponse arrivee en retard
    (seq) n'ecrase jamais une plus recente. L'adresse garde la recherche (actualiser, revenir), Echap l'efface.
    Apres chaque mise a jour : l'evenement "results:updated" sur l'element. Sans JavaScript, le formulaire marche
    comme avant. A inclure dans la section "content" (il pousse son style et son script).
--}}
@push('head')
<style>
    .search .q-wrap { position: relative; flex: 1; display: flex; }
    /* a la fin du champ, avant la croix d'effacement du navigateur */
    .search .live-spin { display: none; position: absolute; inset-inline-end: 38px; top: 50%; width: 16px; height: 16px; margin-top: -8px;
        border: 2px solid var(--muted); border-top-color: transparent; border-radius: 50%; animation: live-spin .8s linear infinite; }
    .search.busy .live-spin { display: block; }
    .search.offline input { border-color: var(--warn); }
    [data-live-results] { transition: opacity .15s; }
    [data-live-results][aria-busy="true"] { opacity: .6; }
    /* pas en jaune : le jaune veut dire "stock negatif" (couleurs de Gescomm) */
    [data-live-results] mark { background: var(--accent-soft); color: inherit; border-radius: 3px; padding: 0 1px; box-shadow: inset 0 -2px 0 var(--accent); }
    @keyframes live-spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .search .live-spin { animation: none; } }
</style>
@endpush

@push('scripts')
<script>
(() => {
    const form = document.querySelector('form.search[data-live]');
    const results = form && document.getElementById(form.dataset.live);
    if (!results) return;
    const input = form.querySelector('input[name=q]');
    const WEAK = @json(__('stock.weak_connection'));
    const baseTitle = form.dataset.title || document.title;
    let timer = null, inflight = null, seq = 0, shown = input.value.trim();

    async function search(q, force = false) {
        if (q === shown && !force) return;
        const my = ++seq;
        if (inflight) inflight.abort();
        inflight = new AbortController();

        // les autres reglages du formulaire (periode, filtre) restent ; une nouvelle recherche repart de la page 1
        const url = new URL(form.action, location.href);
        new FormData(form).forEach((value, key) => { if (key !== 'q' && value !== '') url.searchParams.set(key, value); });
        if (q) url.searchParams.set('q', q);
        history.replaceState(null, '', url);
        document.title = (q ? q + ' — ' : '') + baseTitle;
        url.searchParams.set('partial', '1');

        form.classList.add('busy');
        results.setAttribute('aria-busy', 'true');
        try {
            const res = await fetchRetry(url, {headers: {Accept: 'text/html'}, signal: inflight.signal}, 2, 8000);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const html = await res.text();
            if (my !== seq) return;
            results.innerHTML = html;
            shown = q;
            form.classList.remove('offline');
            form.title = '';
            results.dispatchEvent(new CustomEvent('results:updated'));
        } catch (e) {
            if (my !== seq || e.name === 'AbortError') return;
            // les resultats precedents restent ; la prochaine touche (ou Entree) reessaie
            form.classList.add('offline');
            form.title = WEAK;
        } finally {
            if (my === seq) {
                form.classList.remove('busy');
                results.removeAttribute('aria-busy');
            }
        }
    }

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => search(input.value.trim()), 220);
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape' && input.value) {
            e.preventDefault();
            input.value = '';
            clearTimeout(timer);
            search('');
        }
    });
    form.addEventListener('submit', e => {
        e.preventDefault();
        clearTimeout(timer);
        search(input.value.trim(), true);
    });
})();
</script>
@endpush
