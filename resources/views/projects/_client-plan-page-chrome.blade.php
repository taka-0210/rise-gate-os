@if ($showHeader ?? true)
    <header class="print-page-header" aria-hidden="true">
        <span>プロジェクト実施計画書</span>
        <strong>{{ $project->name }}</strong>
    </header>
@endif
<footer class="print-page-footer" aria-hidden="true">
    <span>{{ $issuerName }} / Confidential</span>
    <span>Ver. {{ $documentOptions['version'] ?: '1.0' }}　{{ \Carbon\Carbon::parse($documentOptions['prepared_on'])->format('Y/m/d') }}</span>
    <span>{{ $page }} / {{ $totalPages }}</span>
</footer>
