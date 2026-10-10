# vendor-sync.ps1
# Rebuilds every third-party skill of the plugin from its pinned upstream commit
# plus the house overlays declared under vendor/. Vendored skill folders are build
# output: never edit them by hand, change a manifest or an overlay file, then rerun.
#
# Usage:
#   .\vendor-sync.ps1                       rebuild and install every vendored skill
#   .\vendor-sync.ps1 -Skill animate,impeccable
#   .\vendor-sync.ps1 -Check                rebuild in temp, report drift, exit 1 if any
#   .\vendor-sync.ps1 -Skill impeccable -Commit <sha> -Out <dir>
#                                           try another upstream commit: build into
#                                           <dir> for audit, install nothing
#
# Manifest (vendor/<source>/vendor.json), one per upstream repository:
#   source      { git, commit (full sha) }
#   skills      [ { skill (folder under skills/), path (folder in the repo), transforms } ]
#   transforms  applied to every skill of the manifest
# vendor/_shared/transforms.json holds the transforms applied to every vendored skill.
# Order per skill: its own transforms, then the manifest's, then the shared ones.
# Transform ops, each one must change something or the build fails:
#   replace           { files, pattern (.NET regex, {skill} token), replacement, min }
#   prepend           { files, when (regex the file must match), textFile }
#   afterFrontmatter  { files, textFile }
#   frontmatterAppend { files, field, text }
#   patch             { file }  unified diff relative to the skill folder
# files / textFile accept a string or an array; files are -like globs relative to the
# skill folder, textFile and patch paths are relative to vendor/.
# Requires git on PATH. Network: one shallow fetch of the pinned commit per manifest.

[CmdletBinding()]
param(
  [string[]]$Skill,
  [switch]$Check,
  [string]$Commit,
  [string]$Out
)

$ErrorActionPreference = 'Stop'
$PluginRoot = Split-Path -Parent $PSScriptRoot
$VendorRoot = Join-Path $PluginRoot 'vendor'
$SkillsRoot = Join-Path $PluginRoot 'skills'
$Utf8 = New-Object System.Text.UTF8Encoding $false
# powershell -File passes "a,b" as one string: accept both forms.
$Skill = @($Skill | ForEach-Object { $_ -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ })

if ($Commit -and (-not $Out -or $Skill.Count -ne 1)) {
  throw '-Commit needs exactly one -Skill and an -Out directory.'
}

function Invoke-Git {
  param([string]$Dir, [string[]]$GitArgs)
  & git -C $Dir @GitArgs
  if ($LASTEXITCODE -ne 0) { throw "git $($GitArgs -join ' ') failed in $Dir" }
}

$Utf8Strict = New-Object System.Text.UTF8Encoding $false, $true

function Read-Text([string]$Path) { [IO.File]::ReadAllText($Path, $Utf8) }
function Write-Text([string]$Path, [string]$Text) { [IO.File]::WriteAllText($Path, $Text, $Utf8) }

# $null for binary or non-UTF-8 content: such files are never re-encoded.
function Get-Text([byte[]]$Bytes) {
  if ([Array]::IndexOf($Bytes, [byte]0) -ge 0) { return $null }
  try { $Utf8Strict.GetString($Bytes) } catch { $null }
}

# Inputs under vendor/ are read with LF endings whatever the checkout of this repo did to them.
function Read-VendorText([string]$Rel) {
  [IO.File]::ReadAllText((Join-Path $VendorRoot $Rel), $Utf8Strict).Replace("`r`n", "`n")
}

function Get-OverlayText($TextFile) {
  $parts = foreach ($rel in @($TextFile)) { (Read-VendorText $rel).Trim() }
  $parts -join "`n`n"
}

function Get-TargetFiles([string]$Stage, $Globs) {
  $all = Get-ChildItem -LiteralPath $Stage -Recurse -File -Force | ForEach-Object {
    [pscustomobject]@{ Full = $_.FullName; Rel = $_.FullName.Substring($Stage.Length + 1).Replace('\', '/') }
  }
  $hits = @($all | Where-Object { $r = $_.Rel; @($Globs | Where-Object { $r -like $_ }).Count -gt 0 })
  if ($hits.Count -eq 0) { throw "no file matches $($Globs -join ', ')" }
  $hits
}

function Invoke-Transform([string]$Stage, $T, [string]$Name) {
  switch ($T.op) {
    'replace' {
      $pattern = $T.pattern.Replace('{skill}', [regex]::Escape($Name))
      $count = 0
      foreach ($f in Get-TargetFiles $Stage $T.files) {
        $text = Read-Text $f.Full
        $n = ([regex]::Matches($text, $pattern)).Count
        if ($n) { Write-Text $f.Full ([regex]::Replace($text, $pattern, $T.replacement)); $count += $n }
      }
      $min = if ($T.min) { [int]$T.min } else { 1 }
      if ($count -lt $min) { throw "replace '$($T.pattern)': $count match(es), expected at least $min (upstream changed?)" }
      "replace: $count"
    }
    'prepend' {
      $overlay = Get-OverlayText $T.textFile
      $count = 0
      foreach ($f in Get-TargetFiles $Stage $T.files) {
        $text = Read-Text $f.Full
        if ($T.when -and $text -notmatch $T.when) { continue }
        Write-Text $f.Full ($overlay + "`n`n" + $text); $count++
      }
      if ($count -eq 0) { throw "prepend $($T.textFile -join ', '): no file qualified" }
      "prepend: $count file(s)"
    }
    'afterFrontmatter' {
      $overlay = Get-OverlayText $T.textFile
      foreach ($f in Get-TargetFiles $Stage $T.files) {
        $m = [regex]::Match((Read-Text $f.Full), '\A(---\r?\n.*?\r?\n---\r?\n)(.*)\z', 'Singleline')
        if (-not $m.Success) { throw "afterFrontmatter: no frontmatter in $($f.Rel)" }
        Write-Text $f.Full ($m.Groups[1].Value + "`n" + $overlay + "`n" + $m.Groups[2].Value)
      }
      'afterFrontmatter: ok'
    }
    'frontmatterAppend' {
      foreach ($f in Get-TargetFiles $Stage $T.files) {
        $text = Read-Text $f.Full
        $fm = [regex]::Match($text, '\A---\r?\n.*?\r?\n---\r?\n', 'Singleline')
        if (-not $fm.Success) { throw "frontmatterAppend: no frontmatter in $($f.Rel)" }
        $line = '(?m)^(' + [regex]::Escape($T.field) + ':[ \t]*)(["'']?)(.*?)\2[ \t]*\r?$'
        $m = [regex]::Match($fm.Value, $line)
        if (-not $m.Success) { throw "frontmatterAppend: field '$($T.field)' missing in $($f.Rel)" }
        if ($m.Groups[3].Value -match '^[>|]') { throw "frontmatterAppend: block scalar '$($T.field)' in $($f.Rel) is not supported" }
        $q = $m.Groups[2].Value
        $value = $m.Groups[1].Value + $q + $m.Groups[3].Value + $T.text + $q
        Write-Text $f.Full ($text.Substring(0, $m.Index) + $value + $text.Substring($m.Index + $m.Length))
      }
      "frontmatterAppend: $($T.field)"
    }
    'patch' {
      $patch = Join-Path (Split-Path -Parent $Stage) 'transform.patch'
      Write-Text $patch (Read-VendorText $T.file)
      Invoke-Git $Stage @('apply', '--check', $patch)
      Invoke-Git $Stage @('apply', $patch)
      "patch: $($T.file)"
    }
    default { throw "unknown transform op '$($T.op)'" }
  }
}

# Text is hashed with LF endings: the checkout of this repo may be CRLF (core.autocrlf).
function Get-ContentHash([string]$Path) {
  $bytes = [IO.File]::ReadAllBytes($Path)
  $text = Get-Text $bytes
  if ($null -ne $text) { $bytes = $Utf8.GetBytes($text.Replace("`r`n", "`n")) }
  $sha = [Security.Cryptography.SHA256]::Create()
  try { [BitConverter]::ToString($sha.ComputeHash($bytes)) } finally { $sha.Dispose() }
}

function Get-TreeHashes([string]$Dir) {
  # Case-sensitive keys: a rename that only changes case is a change.
  $map = New-Object 'System.Collections.Generic.Dictionary[string,string]'
  if (Test-Path -LiteralPath $Dir) {
    Get-ChildItem -LiteralPath $Dir -Recurse -File -Force | ForEach-Object {
      $map[$_.FullName.Substring($Dir.Length + 1)] = Get-ContentHash $_.FullName
    }
  }
  $map
}

function Compare-Trees([string]$Built, [string]$Installed) {
  $a = Get-TreeHashes $Built
  $b = Get-TreeHashes $Installed
  $diff = @()
  foreach ($k in $a.Keys) {
    if (-not $b.ContainsKey($k)) { $diff += "+ $k" } elseif ($a[$k] -ne $b[$k]) { $diff += "~ $k" }
  }
  foreach ($k in $b.Keys) { if (-not $a.ContainsKey($k)) { $diff += "- $k" } }
  $diff | Sort-Object
}

# Match the checkout convention of the repo holding the plugin, so git sees no EOL-only change.
function Convert-ToCrlf([string]$Dir) {
  Get-ChildItem -LiteralPath $Dir -Recurse -File -Force | ForEach-Object {
    $text = Get-Text ([IO.File]::ReadAllBytes($_.FullName))
    if ($null -ne $text) { Write-Text $_.FullName $text.Replace("`r`n", "`n").Replace("`n", "`r`n") }
  }
}

# The new tree is fully written outside skills/ (never seen as a second skill), then
# swapped in by moves on the same volume. Any failure restores the old tree and leaves
# nothing behind: an error never leaves the skill missing or doubled.
function Install-Tree([string]$Stage, [string]$Installed, [string]$Name) {
  $new = Join-Path $PluginRoot ".vendor-new-$Name"
  $old = Join-Path $PluginRoot ".vendor-old-$Name"
  foreach ($p in $new, $old) { if (Test-Path -LiteralPath $p) { Remove-Item -LiteralPath $p -Recurse -Force } }
  try {
    Copy-Item -LiteralPath $Stage -Destination $new -Recurse
    if ($CrlfCheckout) { Convert-ToCrlf $new }
    $hadOld = Test-Path -LiteralPath $Installed
    if ($hadOld) { Move-Item -LiteralPath $Installed -Destination $old }
    try { Move-Item -LiteralPath $new -Destination $Installed }
    catch {
      if ($hadOld) { Move-Item -LiteralPath $old -Destination $Installed }
      throw
    }
  }
  finally {
    if (Test-Path -LiteralPath $new) { Remove-Item -LiteralPath $new -Recurse -Force }
    # Kept when it could not be moved back: it is then the only copy of the skill.
    if ((Test-Path -LiteralPath $old) -and (Test-Path -LiteralPath $Installed)) { Remove-Item -LiteralPath $old -Recurse -Force }
  }
}
$CrlfCheckout = (& git -C $PluginRoot config --get core.autocrlf) -eq 'true'

function Read-Json([string]$Path) { Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json }

$sharedFile = Join-Path $VendorRoot '_shared\transforms.json'
$sharedTransforms = if (Test-Path -LiteralPath $sharedFile) { @((Read-Json $sharedFile).transforms) } else { @() }

$plan = foreach ($dir in Get-ChildItem -LiteralPath $VendorRoot -Directory) {
  $file = Join-Path $dir.FullName 'vendor.json'
  if (-not (Test-Path -LiteralPath $file)) { continue }
  $m = Read-Json $file
  $entries = @($m.skills | Where-Object { -not $Skill -or $Skill -contains $_.skill })
  if ($entries.Count) { [pscustomobject]@{ Manifest = $m; Entries = $entries } }
}
$known = @($plan | ForEach-Object { $_.Entries.skill })
$missing = if ($Skill) { @($Skill | Where-Object { $known -notcontains $_ }) } else { @() }
if (-not $plan -or $missing.Count) { throw "no manifest under $VendorRoot declares '$($missing -join ', ')'" }

$drift = $false
foreach ($p in $plan) {
  $m = $p.Manifest
  $sha = if ($Commit) { $Commit } else { $m.source.commit }
  $work = Join-Path ([IO.Path]::GetTempPath()) ('vendor-sync-' + [guid]::NewGuid().ToString('N'))
  try {
    $src = Join-Path $work 'src'
    New-Item -ItemType Directory -Force -Path $src | Out-Null
    Invoke-Git $src @('init', '-q')
    Invoke-Git $src @('config', 'core.autocrlf', 'false')
    Invoke-Git $src @('fetch', '-q', '--depth', '1', $m.source.git, $sha)
    Invoke-Git $src @('-c', 'advice.detachedHead=false', 'checkout', '-q', 'FETCH_HEAD')

    foreach ($e in $p.Entries) {
      $from = Join-Path $src ($e.path -replace '/', '\')
      if (-not (Test-Path -LiteralPath (Join-Path $from 'SKILL.md'))) { throw "$($e.skill): no SKILL.md at $($e.path) in $sha" }
      $stage = Join-Path $work "stage-$($e.skill)"
      Copy-Item -LiteralPath $from -Destination $stage -Recurse

      Write-Host "[$($e.skill)] $($m.source.git) @ $($sha.Substring(0, 7))"
      foreach ($t in @($e.transforms) + @($m.transforms) + $sharedTransforms) {
        if ($t) { Write-Host ('  ' + (Invoke-Transform $stage $t $e.skill)) }
      }

      if ($Commit) {
        if (Test-Path -LiteralPath $Out) { throw "-Out '$Out' already exists" }
        Copy-Item -LiteralPath $stage -Destination $Out -Recurse
        Write-Host "  built into $Out (nothing installed)"
        continue
      }

      $installed = Join-Path $SkillsRoot $e.skill
      $changes = @(Compare-Trees $stage $installed)
      if ($changes.Count -eq 0) { Write-Host '  up to date'; continue }
      $changes | ForEach-Object { Write-Host "  $_" }
      if ($Check) { $drift = $true; continue }

      Install-Tree $stage $installed $e.skill
      Write-Host "  installed ($($changes.Count) change(s))"
    }
  }
  finally {
    if (Test-Path -LiteralPath $work) { Remove-Item -LiteralPath $work -Recurse -Force }
  }
}

if ($drift) { exit 1 }
