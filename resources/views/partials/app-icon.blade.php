{{-- Icones des applications (SVG plats, facon Odoo). $name = cle de config/apps.php, "grid" (lanceur) ou "logo". --}}
@switch($name)
    @case('stock')
        <svg viewBox="0 0 48 48" aria-hidden="true">
            <polygon points="24,6 42,15 24,24 6,15" fill="#fbbf24"/>
            <polygon points="6,15 24,24 24,43 6,34" fill="#f59e0b"/>
            <polygon points="24,24 42,15 42,34 24,43" fill="#d97706"/>
            <polygon points="14.1,10.95 15.9,10.05 33.9,19.05 32.1,19.95" fill="#fef3c7"/>
            <polygon points="32.1,19.95 33.9,19.05 33.9,26.1 32.1,27" fill="#fde68a"/>
        </svg>
        @break
    @case('sales')
        <svg viewBox="0 0 48 48" aria-hidden="true">
            <rect x="7" y="27" width="9" height="14" rx="2.5" fill="#fca5a5"/>
            <rect x="19.5" y="19" width="9" height="22" rx="2.5" fill="#f87171"/>
            <rect x="32" y="10" width="9" height="31" rx="2.5" fill="#ef4444"/>
            <polyline points="8,21 20,13 29,15 40,5" fill="none" stroke="#fb923c" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        @break
    @case('purchases')
        <svg viewBox="0 0 48 48" aria-hidden="true">
            <polygon points="13,14 43,14 38.5,31 17.5,31" fill="#2dd4bf"/>
            <rect x="16" y="19" width="24" height="2.6" rx="1.3" fill="#99f6e4"/>
            <rect x="17.3" y="24.5" width="21.4" height="2.6" rx="1.3" fill="#99f6e4"/>
            <polyline points="5,8 10.5,8 17.5,31 39,31" fill="none" stroke="#0f766e" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="20" cy="38" r="3.6" fill="#0f766e"/>
            <circle cx="35" cy="38" r="3.6" fill="#0f766e"/>
        </svg>
        @break
    @case('suppliers')
        <svg viewBox="0 0 48 48" aria-hidden="true">
            <rect x="3" y="11" width="27" height="22" rx="3" fill="#60a5fa"/>
            <path d="M30 17h8.5l6.5 7.5V33H30z" fill="#2563eb"/>
            <path d="M33 20.5h4.4l3.6 4.3H33z" fill="#dbeafe"/>
            <rect x="8" y="16" width="17" height="3" rx="1.5" fill="#bfdbfe"/>
            <circle cx="12.5" cy="35" r="5" fill="#1e3a8a"/>
            <circle cx="12.5" cy="35" r="2" fill="#bfdbfe"/>
            <circle cx="37" cy="35" r="5" fill="#1e3a8a"/>
            <circle cx="37" cy="35" r="2" fill="#bfdbfe"/>
        </svg>
        @break
    @case('customers')
        <svg viewBox="0 0 48 48" aria-hidden="true">
            <circle cx="32" cy="15" r="6.5" fill="#f0abfc"/>
            <path d="M20.5 41c0-7.5 5-13 11.5-13S43.5 33.5 43.5 41z" fill="#e879f9"/>
            <circle cx="18" cy="17.5" r="7.5" fill="#c084fc"/>
            <path d="M4 43c0-8.5 6.2-15 14-15s14 6.5 14 15z" fill="#a855f7"/>
        </svg>
        @break
    @case('settings')
        <svg viewBox="0 0 48 48" aria-hidden="true">
            <circle cx="24" cy="24" r="15" fill="none" stroke="#a5b4fc" stroke-width="7" stroke-dasharray="5.9 5.9"/>
            <circle cx="24" cy="24" r="12.5" fill="#6366f1"/>
            <circle cx="24" cy="24" r="5" fill="#e0e7ff"/>
        </svg>
        @break
    @case('grid')
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor">
            @foreach ([4, 10.5, 17] as $y)
                @foreach ([4, 10.5, 17] as $x)
                    <rect x="{{ $x }}" y="{{ $y }}" width="3.6" height="3.6" rx="1"/>
                @endforeach
            @endforeach
        </svg>
        @break
    {{-- etincelle : ce que l'IA propose (page "دمج الأصناف") ; couleur du texte, pas une couleur de stock --}}
    @case('spark')
        <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor">
            <path d="M12 2.5l1.9 5.6 5.6 1.9-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.9z"/>
            <path d="M18.5 14.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z" opacity=".7"/>
        </svg>
        @break
    @case('logo')
        <svg viewBox="0 0 48 48" aria-hidden="true">
            <rect width="48" height="48" rx="13" fill="#146c84"/>
            <polygon points="24,10 37,16.5 24,23 11,16.5" fill="#fbe3a0"/>
            <polygon points="11,16.5 24,23 24,37.5 11,31" fill="#f4c542"/>
            <polygon points="24,23 37,16.5 37,31 24,37.5" fill="#d9a91c"/>
        </svg>
        @break
@endswitch
