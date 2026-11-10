# prune-session-temp.ps1 — Supprime les dossiers temporaires de session restés inactifs (hook SessionStart).
# Claude Code crée <temp>\claude\<projet>\<session-uuid>\ à chaque session et ne le supprime jamais.
# Seuls les dossiers nommés par un UUID au second niveau partent ; tout le reste (tasks, bundled-skills,
# fichiers isolés) est d'un autre propriétaire. Silencieux (la sortie d'un SessionStart entre dans le
# contexte), borné dans le temps : un arriéré se résorbe sur plusieurs démarrages. N'échoue jamais.
param(
    [int]$Days = 7,
    [int]$BudgetSeconds = 3,
    [string]$Root = (Join-Path ([IO.Path]::GetTempPath()) 'claude')
)

$ErrorActionPreference = 'SilentlyContinue'
try {
    $current = $null
    if ([Console]::IsInputRedirected) {
        $raw = [Console]::In.ReadToEnd()
        if ($raw) { $current = ($raw | ConvertFrom-Json).session_id }
    }
    if (-not (Test-Path -LiteralPath $Root)) { exit 0 }

    $uuid    = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
    $cutoff  = (Get-Date).AddDays(-$Days)
    $clock   = [Diagnostics.Stopwatch]::StartNew()

    $sessions = Get-ChildItem -LiteralPath $Root -Directory -Force |
        ForEach-Object { Get-ChildItem -LiteralPath $_.FullName -Directory -Force } |
        Where-Object { $_.Name -match $uuid -and $_.Name -ne $current -and $_.LastWriteTime -lt $cutoff } |
        Sort-Object LastWriteTime

    foreach ($s in $sessions) {
        if ($clock.Elapsed.TotalSeconds -ge $BudgetSeconds) { break }
        # Préfixe \\?\ : une copie de site dépasse vite 260 caractères, où les cmdlets de PowerShell 5.1
        # échouent à mi-chemin. L'activité se lit sur les FICHIERS : une session longue écrit dans ses
        # sous-dossiers sans dater le sien, et une suppression interrompue rajeunit les dossiers.
        $long = '\\?\' + $s.FullName
        try {
            $recent = $false
            foreach ($f in ([IO.DirectoryInfo]$long).EnumerateFiles('*', [IO.SearchOption]::AllDirectories)) {
                if ($f.LastWriteTime -ge $cutoff) { $recent = $true; break }
            }
            if (-not $recent) { [IO.Directory]::Delete($long, $true) }
        } catch { }
    }
} catch { }
exit 0
