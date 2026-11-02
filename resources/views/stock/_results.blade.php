{{-- Bloc des resultats : rendu dans la page, et seul (?partial=1) pour la recherche pendant la frappe. --}}
@use('App\Support\Highlight')
@if ($q === '')
    <div class="empty">
        @if ($merge)
            ابحث بجزء مشترك من الكود (مثلاً <span class="ltr">820</span>) لترى كل كتاباته في الفروع.
        @else
            اكتب كود منتج أو جزءاً من تسميته. النتيجة: سطر واحد لكل منتج، ومجموع كمياته في كل الفروع.
        @endif
    </div>
@elseif ($results->isEmpty())
    <div class="empty">لا يوجد منتج يطابق «{{ $q }}» في أي فرع.</div>
@else
    @if ($merge)
        <div class="merge-bar">
            <button class="primary" type="submit" form="merge-form" id="merge-btn" disabled>دمج المحدد</button>
            <span class="muted small" id="merge-count">حدّد صنفين أو أكثر هما نفس المنتج.</span>
        </div>
    @endif
    <table>
        <thead>
        <tr>
            @if ($merge)<th></th>@endif
            <th>الكود</th>
            <th>التسمية</th>
            <th>المجموع</th>
            @if ($mainBranch)
                {{-- prix de la branche principale, comme dans la liste des articles de Gescomm --}}
                <th title="Pu Detail — سعر الفرع الرئيسي «{{ $mainBranch->name }}»">التجزئة</th>
                <th class="hide-sm" title="Prix Vente Gros — سعر الفرع الرئيسي «{{ $mainBranch->name }}»">الجملة</th>
                @if ($showBuy)
                    <th class="hide-sm" title="Pu Achat — يظهر للمدير فقط">الشراء</th>
                @endif
            @endif
            <th class="hide-sm">الفروع</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($results as $r)
            <tr>
                @if ($merge)
                    <td class="pick"><input type="checkbox" name="codes[]" value="{{ $r->code }}" form="merge-form" aria-label="تحديد {{ $r->code }}"></td>
                @endif
                <td>
                    <span class="codes">
                        <span class="code ltr">{{ Highlight::html($r->code, $terms) }}</span>
                        @if ($merge && $r->merged)
                            <button type="submit" class="link" form="unmerge-form" name="code" value="{{ $r->code }}" title="إخراج {{ $r->code }} من الدمج">✕</button>
                        @endif
                        @foreach ($r->merged as $other)
                            <span class="pill ltr" title="مدمج مع {{ $r->code }}">+ {{ Highlight::html($other, $terms) }}@if ($merge)<button type="submit" class="link" form="unmerge-form" name="code" value="{{ $other }}" title="إخراج {{ $other }} من الدمج">✕</button>@endif</span>
                        @endforeach
                    </span>
                </td>
                <td><bdi>{{ Highlight::html($r->designation, $terms) }}</bdi></td>
                <td><span class="num total {{ $r->total < 0 ? 'neg' : '' }}">{{ number_format($r->total, 2, ',', ' ') }}</span></td>
                @if ($mainBranch)
                    <td>
                        <span class="num">{{ $r->price === null ? '—' : number_format($r->price, 2, ',', ' ') }}</span>
                        @if ($r->price_differs)
                            <span class="pill warn" title="فرع واحد على الأقل يبيع بسعر آخر (تجزئة أو جملة) — التفاصيل في «معاينة»">سعر مختلف</span>
                        @endif
                    </td>
                    <td class="hide-sm"><span class="num">{{ $r->wholesale === null ? '—' : number_format($r->wholesale, 2, ',', ' ') }}</span></td>
                    @if ($showBuy)
                        <td class="hide-sm"><span class="num muted">{{ $r->buy === null ? '—' : number_format($r->buy, 2, ',', ' ') }}</span></td>
                    @endif
                @endif
                <td class="hide-sm"><span class="pill">{{ $r->branches }} / {{ $branchesTotal }}</span></td>
                <td><button type="button" data-code="{{ $r->code }}" data-designation="{{ $r->designation }}">معاينة</button></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @if ($results->count() >= 100)
        <p class="muted small">تُعرض أول 100 نتيجة فقط — دقّق البحث.</p>
    @endif
    @if (! $mainBranch && auth()->user()->is_admin)
        <p class="muted small">لعرض الأسعار في البحث: حدّد «الفرع الرئيسي» في صفحة <a href="{{ route('branches.index') }}">الفروع</a>.</p>
    @endif
@endif
