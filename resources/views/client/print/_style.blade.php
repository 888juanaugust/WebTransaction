{{-- Print styles shared by Central's templates: black on white, Geist, the base's paper rules. --}}
@php($landscape = ($layout['orientation'] ?? 'portrait') === 'landscape')
@php($paper = $layout['paper'] ?? 'A4')
<style>
    @page { size: {{ $paper }} {{ $landscape ? 'landscape' : 'portrait' }}; margin: 10mm 12mm; }
    * { box-sizing: border-box; }
    body { font-family: "Geist Variable", Geist, "Helvetica Neue", Arial, sans-serif; font-size: 10.5pt; color: #111; margin: 0; background: #fff; }
    .sheet { max-width: {{ $landscape ? '273mm' : '186mm' }}; margin: 0 auto; padding: 4mm 0; }
    .copy { page-break-after: always; }
    .copy:last-child { page-break-after: auto; }
    .copy-label { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.08em; color: #555; text-align: right; margin-bottom: 2mm; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8mm; }
    .company { flex: 1; }
    .company h1 { font-size: 15pt; margin: 0 0 1mm; letter-spacing: -0.01em; }
    .company p { margin: 0; font-size: 9pt; color: #333; }
    .mark { height: 11mm; width: auto; display: block; margin-bottom: 1.5mm; }
    .kepada { margin-top: 3mm; font-size: 10pt; }
    .kepada .label { font-weight: 600; }
    .box { border: 1px solid #111; min-width: 72mm; }
    .box h2 { font-size: 14pt; margin: 0; padding: 2mm 3mm; border-bottom: 1px solid #111; font-weight: 600; }
    .box .cells { display: flex; }
    .box .cells > div { flex: 1; padding: 1.5mm 3mm; border-right: 1px solid #111; }
    .box .cells > div:last-child { border-right: 0; }
    .box .note { padding: 1.5mm 3mm; border-top: 1px dashed #111; min-height: 9mm; }
    .box small { display: block; font-size: 8pt; color: #555; }
    .box strong { font-size: 10.5pt; white-space: nowrap; }
    table.lines { width: 100%; border-collapse: collapse; margin-top: 4mm; }
    table.lines th, table.lines td { border: 1px solid #111; padding: 1.2mm 2mm; font-size: 10pt; vertical-align: top; }
    table.lines th { font-weight: 600; text-align: center; background: #f3f3f3; }
    table.lines td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    table.lines td.mono { font-family: "Geist Mono Variable", ui-monospace, monospace; font-size: 9.5pt; white-space: nowrap; }
    .signs { display: flex; gap: 6mm; margin-top: 14mm; }
    .signs > div { flex: 1; text-align: center; font-size: 9.5pt; }
    .signs .line { border-top: 1px solid #111; margin-top: 16mm; padding-top: 1mm; text-align: left; font-size: 9pt; }
    .printed { margin-top: 4mm; font-size: 8.5pt; color: #555; text-align: right; }
    .pengantar { display: flex; gap: 6mm; }
    .pengantar .left { width: 46%; }
    .pengantar .right { flex: 1; }
    .pengantar .brand { font-size: 17pt; font-weight: 800; letter-spacing: 0.04em; margin: 0; }
    .pengantar .tagline { font-size: 8pt; letter-spacing: 0.12em; text-transform: uppercase; margin: 0 0 1mm; color: #333; }
    .pengantar .field { display: flex; gap: 2mm; align-items: baseline; margin: 1mm 0; font-size: 9.5pt; }
    .pengantar .field .value { flex: 1; border-bottom: 1px dotted #111; min-height: 5mm; }
    .pengantar .intro { font-size: 9pt; margin: 2mm 0; }
    .pengantar table.pack { width: 100%; border-collapse: collapse; margin-top: 2mm; }
    .pengantar table.pack th, .pengantar table.pack td { border: 1px solid #111; padding: 1mm 2mm; font-size: 9.5pt; vertical-align: top; }
    .pengantar table.pack th { font-weight: 600; text-align: center; letter-spacing: 0.08em; }
    .pengantar .count { display: flex; align-items: center; gap: 2mm; margin: 1mm 0; }
    .pengantar .count .cell { width: 11mm; height: 6mm; border: 1px solid #111; text-align: center; font-weight: 600; }
    .pengantar .goods { min-height: 24mm; }
    .pengantar .goods div { border-bottom: 1px dotted #111; min-height: 6mm; padding: 0.5mm 1mm; font-size: 10pt; }
    .toolbar { position: fixed; top: 10px; right: 10px; }
    .toolbar button { font: inherit; padding: 8px 14px; border-radius: 10px; border: 0; background: #2f5bea; color: #fff; cursor: pointer; }
    @media print { .toolbar { display: none; } }
</style>
