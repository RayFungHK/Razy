<div style="padding:10px;border-radius:6px;margin-bottom:8px;{@if $type="success"}background:#d4edda;border:1px solid #28a745;color:#155724{@else}{@if $type="warning"}background:#fff3cd;border:1px solid #ffc107;color:#856404{@else}background:#f8f9fa;border:1px solid #dee2e6;color:#333{/if}{/if}">
    {@if $type="success"}✅{@else}{@if $type="warning"}⚠️{@else}ℹ️{/if}{/if} {$message}
</div>