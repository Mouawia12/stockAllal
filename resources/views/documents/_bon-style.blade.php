{{-- Les styles d'un bon (documents/_bon) : sur sa page et dans l'apercu de la liste (F2). --}}
<style>
    .bon-head { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 16px; margin-bottom: 16px; }
    .bon-id { display: flex; flex-direction: column; gap: 6px; }
    .bon-kind { margin: 0; color: var(--muted); font-weight: 600; }
    /* ltr pour le numero, mais place au debut de la ligne de la page (a droite en arabe) */
    .bon-number { margin: 0; font: 700 clamp(40px, 6vw, 56px)/1 var(--font-code); letter-spacing: .01em; direction: ltr; unicode-bidi: isolate; align-self: flex-start; }
    .bon-when { margin: 4px 0 0; font-size: 15px; }
    .bon-when .num { color: var(--muted); }
    .bon-branch { display: inline-flex; align-items: center; gap: 8px; margin: 0; font-weight: 600; }
    .bon-flags { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
    .facts { margin: 0; display: grid; gap: 8px; }
    .facts div { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); padding-bottom: 7px; }
    .facts div:last-child { border-bottom: 0; padding-bottom: 0; }
    .facts dt { color: var(--muted); }
    .facts dd { margin: 0; font-weight: 600; text-align: end; }
    .lines th.n, .lines td.n { text-align: end; }
    .lines tr.back td { color: var(--muted); }
    .lines a.code { color: var(--text); }
    .facts a.who { color: var(--text); text-decoration: underline dotted var(--muted); text-underline-offset: 3px; }
    .facts a.who:hover { color: var(--accent); }
    .lines a.code:hover { color: var(--accent); text-decoration: underline; text-underline-offset: 3px; }
    /* les totaux sous la colonne des montants, comme le pied d'un bon */
    .totals { display: grid; grid-template-columns: auto auto; justify-content: end; gap: 6px 28px; margin: 14px 0 0; padding-top: 12px; border-top: 2px solid var(--border); }
    .totals dt { color: var(--muted); }
    .totals dd { margin: 0; text-align: end; font-variant-numeric: tabular-nums; }
    .totals .grand { font-size: 18px; font-weight: 700; color: var(--text); }
    @media (max-width: 700px) { .bon-head { grid-template-columns: 1fr; } }
</style>
