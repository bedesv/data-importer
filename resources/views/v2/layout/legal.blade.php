@php
    $sourceUrl = rtrim((string) config('importer.source_url'), '/');
    $sourceRevision = trim((string) config('importer.source_revision'));
    $deployedSourceUrl = '' === $sourceRevision ? $sourceUrl : sprintf('%s/tree/%s', $sourceUrl, rawurlencode($sourceRevision));
    $licenseUrl = '' === $sourceRevision ? sprintf('%s/blob/dev/LICENSE', $sourceUrl) : sprintf('%s/blob/%s/LICENSE', $sourceUrl, rawurlencode($sourceRevision));
@endphp
<footer class="container py-3 mt-4 border-top text-center">
    <p class="mb-0">
        Copyright belongs to the respective Firefly III Data Importer and fork contributors.
        This is an unofficial modified fork, maintained separately from the Firefly III project; fork-specific modifications began 14 March 2026.
        <strong><a href="{{ $deployedSourceUrl }}" rel="noopener noreferrer">Download the corresponding source code</a></strong>
        under the <a href="{{ $licenseUrl }}" rel="noopener noreferrer">GNU AGPL version 3 or later</a>.
        Distributed without warranty.
    </p>
</footer>
