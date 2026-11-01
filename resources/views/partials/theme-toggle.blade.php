{{-- Bouton qui alterne : تلقائي -> فاتح -> داكن. Le choix reste dans ce navigateur (localStorage). --}}
<button type="button" class="link theme-toggle" id="theme-toggle" aria-label="تغيير المظهر"></button>
<script>
    (() => {
        const btn = document.getElementById('theme-toggle');
        const order = ['auto', 'light', 'dark'];
        const labels = {auto: '◐ تلقائي', light: '☀ نهاري', dark: '☾ ليلي'};
        const current = () => { try { return localStorage.getItem('theme') || 'auto'; } catch (e) { return 'auto'; } };
        const apply = (t) => {
            if (t === 'auto') document.documentElement.removeAttribute('data-theme');
            else document.documentElement.setAttribute('data-theme', t);
            btn.textContent = labels[t];
            btn.title = 'المظهر: ' + labels[t] + ' — اضغط للتغيير';
        };
        apply(current());
        btn.addEventListener('click', () => {
            const next = order[(order.indexOf(current()) + 1) % order.length];
            try { next === 'auto' ? localStorage.removeItem('theme') : localStorage.setItem('theme', next); } catch (e) {}
            apply(next);
        });
    })();
</script>
