{{--
    Clavier facon Gescomm + Odoo, sur toutes les pages (docs/roadmap.md, section 5).
    - Gescomm : F3 chercher dans l'ecran, Entree ouvrir, Echap revenir, Inser nouveau. Odoo : Alt + lettre, et Alt seul
      montre la touche de chaque bouton au-dessus de lui.
    - Une page declare ses touches sur ses elements : data-key="Alt+R" (plusieurs separees par un espace). La touche fait
      le geste de l'element : un champ prend le curseur, un lien ou un bouton est clique. data-key-fallback : seulement si
      la page n'a pas d'element pour cette touche (F3 ouvre la recherche globale la ou il n'y a pas de champ de recherche).
    - a[data-back] : si on vient de cette page, revenir dans l'historique (la recherche garde ses mots et son filtre).
    - Le bandeau du bas liste les touches de la page (@push('keys') + partials/key) ; masquable, jamais sur telephone.
    Inser / F2 / F5 / F6 pour creer ou modifier des documents : avec le mode "معتمد" (phase 5), jamais dans une branche synchronisee.
--}}
@php
    $keyNames = ['Escape' => __('common.keys.esc'), 'Enter' => __('common.keys.enter'), 'Insert' => __('common.keys.insert')];
@endphp
<footer class="keys-bar" id="keys-bar" aria-label="{{ __('common.keys.bar') }}">
    <div class="in">
        @stack('keys')
        <span class="keys-end">
            @include('partials.key', ['keys' => ['Ctrl', 'K'], 'run' => 'Ctrl+K', 'label' => __('common.palette.title'), 'global' => true])
            @include('partials.key', ['keys' => ['Alt'], 'run' => 'hints', 'label' => __('common.keys.show'), 'global' => true])
            <button type="button" class="keys-hide" data-keys-toggle title="{{ __('common.keys.hide') }}" aria-label="{{ __('common.keys.hide') }}">✕</button>
        </span>
    </div>
</footer>
<style>
    .keys-bar { position: fixed; inset-inline: 0; bottom: 0; z-index: 4; background: var(--card); border-top: 1px solid var(--border); }
    /* trop de touches pour une ligne : une deuxieme ligne, jamais une touche cachee */
    .keys-bar .in { max-width: 1100px; margin: 0 auto; padding: 4px 12px; display: flex; flex-wrap: wrap; align-items: center; gap: 2px 8px; }
    .keys-bar .k { display: inline-flex; align-items: center; gap: 6px; padding: 3px 7px; border: 0; border-radius: 6px; background: none; color: var(--muted); font-size: 13px; line-height: 1.5; white-space: nowrap; }
    .keys-bar button.k:hover { background: var(--row-hover); color: var(--text); }
    /* des touches de clavier, dans la police des codes d'articles */
    .keys-bar .k kbd { font: 600 13px/1.45 var(--font-code); letter-spacing: .04em; color: var(--text); background: var(--bg); min-width: 24px; text-align: center; }
    .keys-bar .k kbd + kbd { margin-inline-start: -3px; }
    .keys-end { margin-inline-start: auto; display: flex; align-items: center; gap: 2px 8px; }
    .keys-hide { border: 0; background: none; color: var(--muted); padding: 2px 8px; font-size: 13px; }
    /* ecran moyen : les touches de partout sans leur texte (il reste en info-bulle), la place va a celles de l'ecran */
    @media (max-width: 1100px) { .keys-end .k span { display: none; } }
    html[data-keys="off"] .keys-bar { display: none; }
    @media (max-width: 700px), (hover: none) { .keys-bar { display: none; } }
    @media print { .keys-bar { display: none; } }
    /* Alt maintenu : la touche au-dessus de chaque bouton ; coordonnees de l'ecran, donc un calque de gauche a droite */
    .key-hints { position: fixed; inset: 0; z-index: 50; pointer-events: none; direction: ltr; }
    .key-hints kbd { position: absolute; top: 0; inset-inline-start: 0; background: var(--text); color: var(--card); border-color: var(--text);
        font: 700 12.5px/1.55 var(--font-code); letter-spacing: .05em; padding: 0 7px; box-shadow: 0 4px 14px rgba(0, 0, 0, .28); }
</style>
<script>
(() => {
    const NAMES = @json($keyNames);
    const root = document.documentElement;
    const bar = document.getElementById('keys-bar');
    const pal = document.getElementById('pal');
    let dirty = false, hints = null;

    const parse = spec => { const p = spec.split('+'); const key = p.pop(); return {alt: p.includes('Alt'), ctrl: p.includes('Ctrl'), key}; };
    const label = spec => spec.split('+').map(k => NAMES[k] || k).join(' ');
    function hits(e, k) {
        if (e.altKey !== k.alt || (e.ctrlKey || e.metaKey) !== k.ctrl || e.shiftKey) return false;
        // touche physique : Alt+R marche aussi quand le clavier est en arabe, Alt+1 en AZERTY (1 = & sans Maj)
        if (/^[A-Z]$/.test(k.key)) return e.code === 'Key' + k.key;
        if (/^[0-9]$/.test(k.key)) return e.code === 'Digit' + k.key || e.code === 'Numpad' + k.key;
        return e.key === k.key;
    }
    const shown = el => !el.disabled && el.getClientRects().length > 0;
    // une fenetre ouverte (apercu d'un article) : seules ses touches comptent
    const layer = () => document.querySelector('.modal-bg.open') || document;
    const specs = el => el.dataset.key.split(' ');

    function find(spec) {
        let fallback = null;
        for (const el of layer().querySelectorAll('[data-key]')) {
            if (!specs(el).includes(spec) || !shown(el)) continue;
            if (!('keyFallback' in el.dataset)) return el;
            fallback ??= el;
        }
        return fallback;
    }
    function act(el) {
        if (el.matches('input, textarea, select')) { el.focus(); el.select?.(); }
        else el.click();
    }

    function showHints() {
        if (hints) return;
        hints = document.createElement('div');
        hints.className = 'key-hints';
        hints.setAttribute('aria-hidden', 'true');
        for (const el of layer().querySelectorAll('[data-key]')) {
            if (!shown(el)) continue;
            const r = el.getBoundingClientRect();
            if (r.bottom < 0 || r.top > innerHeight) continue;
            const k = document.createElement('kbd');
            k.textContent = label(specs(el)[0]);
            k.style.transform = 'translate(' + Math.round(r.left + r.width / 2) + 'px, ' + Math.round(r.top) + 'px) translate(-50%, -55%)';
            hints.appendChild(k);
        }
        document.body.appendChild(hints);
    }
    function hideHints() { hints?.remove(); hints = null; }

    document.addEventListener('keydown', e => {
        // Alt seul : montrer les touches (et pas le menu du navigateur sous Windows)
        if (e.key === 'Alt') { if (!e.ctrlKey && !e.metaKey && !e.shiftKey && !e.repeat) { e.preventDefault(); showHints(); } return; }
        hideHints();
        // deja traitee par la page (Echap qui ferme l'apercu...), ou la barre Ctrl+K ouverte
        if (e.defaultPrevented || e.repeat || e.isComposing || !pal.hidden) return;
        // AltGr + touche = un caractere (@ # { en AZERTY), jamais un raccourci
        if (e.getModifierState?.('AltGraph')) return;
        const typing = e.target.closest?.('input, textarea, select, [contenteditable]');
        let fallback = null;
        for (const el of layer().querySelectorAll('[data-key]')) {
            for (const spec of specs(el)) {
                const k = parse(spec);
                if (!hits(e, k) || !shown(el)) continue;
                // en saisie, une touche qui ecrit reste au champ, et Echap aussi (il ferme les suggestions du navigateur) ;
                // un formulaire modifie ne se quitte pas par Echap
                if (k.key === 'Escape' && (typing || dirty)) continue;
                if (typing && !k.alt && !k.ctrl && k.key.length === 1) continue;
                if ('keyFallback' in el.dataset) { fallback ??= el; continue; }
                e.preventDefault();
                act(el);
                return;
            }
        }
        if (fallback) { e.preventDefault(); act(fallback); }
    });
    document.addEventListener('keyup', e => { if (e.key === 'Alt') { e.preventDefault(); hideHints(); } });
    addEventListener('blur', hideHints);
    addEventListener('scroll', hideHints, {passive: true});
    document.addEventListener('input', e => { if (e.target.form?.method === 'post') dirty = true; }, true);

    document.addEventListener('click', e => {
        const a = e.target.closest('a[data-back]');
        if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button) return;
        try {
            const from = new URL(document.referrer);
            if (from.origin === location.origin && from.pathname === new URL(a.href).pathname && history.length > 1) {
                e.preventDefault();
                history.back();
            }
        } catch (_) { /* pas de page precedente : le lien normal */ }
    });

    window.toggleKeysBar = () => {
        const off = root.dataset.keys !== 'off';
        if (off) root.dataset.keys = 'off'; else delete root.dataset.keys;
        try { off ? localStorage.setItem('keys-bar', 'off') : localStorage.removeItem('keys-bar'); } catch (_) {}
    };
    bar.addEventListener('click', e => {
        if (e.target.closest('[data-keys-toggle]')) { window.toggleKeysBar(); return; }
        const b = e.target.closest('[data-run]');
        if (!b) return;
        if (b.dataset.run === 'hints') { showHints(); setTimeout(hideHints, 2500); return; }
        const el = find(b.dataset.run);
        if (el) act(el);
    });
})();
</script>
