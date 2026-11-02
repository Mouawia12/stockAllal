{{-- Bouton qui alterne : تلقائي -> فاتح -> داكن. Le choix reste dans ce navigateur (localStorage). --}}
<button type="button" class="link theme-toggle" id="theme-toggle" aria-label="{{ __('common.theme.change') }}"></button>
<script>
    (() => {
        const btn = document.getElementById('theme-toggle');
        const order = ['auto', 'light', 'dark'];
        const labels = {auto: ['◐', @json(__('common.theme.auto'))], light: ['☀', @json(__('common.theme.light'))], dark: ['☾', @json(__('common.theme.dark'))]};
        const TITLE = @json(__('common.theme.title'));
        const current = () => { try { return localStorage.getItem('theme') || 'auto'; } catch (e) { return 'auto'; } };
        const apply = (t) => {
            if (t === 'auto') document.documentElement.removeAttribute('data-theme');
            else document.documentElement.setAttribute('data-theme', t);
            // le mot est masquable sur petit ecran (.theme-label), le symbole reste
            btn.innerHTML = labels[t][0] + '<span class="theme-label"> ' + labels[t][1] + '</span>';
            btn.title = TITLE.replace(':theme', labels[t][1]);
        };
        apply(current());
        btn.addEventListener('click', () => {
            const next = order[(order.indexOf(current()) + 1) % order.length];
            try { next === 'auto' ? localStorage.removeItem('theme') : localStorage.setItem('theme', next); } catch (e) {}
            apply(next);
        });
    })();
</script>
