param(
    [Parameter(Mandatory = $true)]
    [string]$InputPath,
    [Parameter(Mandatory = $true)]
    [string]$OutputPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$nsUri = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"

function Normalize-TokenPart([string]$text) {
    if ([string]::IsNullOrWhiteSpace($text)) { return "" }
    $v = $text.ToLowerInvariant()
    $v = [Regex]::Replace($v, "[^a-z0-9]+", "_")
    $v = $v.Trim("_")
    if ($v.Length -gt 60) { $v = $v.Substring(0, 60) }
    return $v
}

function Get-CellText($cellNode, $nsMgr) {
    $nodes = $cellNode.SelectNodes(".//w:t", $nsMgr)
    if ($null -eq $nodes -or $nodes.Count -eq 0) { return "" }
    $parts = @()
    foreach ($n in $nodes) { $parts += [string]$n.InnerText }
    $txt = ($parts -join " ").Trim()
    return [Regex]::Replace($txt, "\s+", " ")
}

function Set-CellPlaceholder($xmlDoc, $cellNode, $nsMgr, [string]$placeholderText) {
    $paragraph = $cellNode.SelectSingleNode("./w:p", $nsMgr)
    if ($null -eq $paragraph) {
        $paragraph = $xmlDoc.CreateElement("w", "p", $nsUri)
        [void]$cellNode.AppendChild($paragraph)
    }

    $run = $xmlDoc.CreateElement("w", "r", $nsUri)
    $text = $xmlDoc.CreateElement("w", "t", $nsUri)
    $text.InnerText = $placeholderText
    [void]$run.AppendChild($text)
    [void]$paragraph.AppendChild($run)
}

$inputFull = (Resolve-Path -LiteralPath $InputPath).Path
$outputFull = [System.IO.Path]::GetFullPath($OutputPath)

$outputDir = [System.IO.Path]::GetDirectoryName($outputFull)
if (-not [string]::IsNullOrWhiteSpace($outputDir) -and -not (Test-Path -LiteralPath $outputDir)) {
    [void](New-Item -ItemType Directory -Path $outputDir -Force)
}

if (Test-Path -LiteralPath $outputFull) {
    Remove-Item -LiteralPath $outputFull -Force
}

$placeholders = New-Object System.Collections.Generic.List[string]
$used = @{}
$counter = 1

$inZip = [System.IO.Compression.ZipFile]::OpenRead($inputFull)
try {
    $outZip = [System.IO.Compression.ZipFile]::Open($outputFull, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($entry in $inZip.Entries) {
            $outEntry = $outZip.CreateEntry($entry.FullName, [System.IO.Compression.CompressionLevel]::Optimal)

            $isWordXml = $entry.FullName -eq "word/document.xml" -or $entry.FullName -like "word/header*.xml" -or $entry.FullName -like "word/footer*.xml"
            if (-not $isWordXml) {
                $inStream = $entry.Open()
                $outStream = $outEntry.Open()
                try {
                    $inStream.CopyTo($outStream)
                } finally {
                    $outStream.Dispose()
                    $inStream.Dispose()
                }
                continue
            }

            $reader = New-Object System.IO.StreamReader($entry.Open())
            try {
                $xmlText = $reader.ReadToEnd()
            } finally {
                $reader.Dispose()
            }

            $xml = New-Object System.Xml.XmlDocument
            $xml.PreserveWhitespace = $true
            $xml.LoadXml($xmlText)

            $nsMgr = New-Object System.Xml.XmlNamespaceManager($xml.NameTable)
            [void]$nsMgr.AddNamespace("w", $nsUri)

            $tables = $xml.SelectNodes("//w:tbl", $nsMgr)
            $tableIndex = 0
            foreach ($table in $tables) {
                $tableIndex++
                $headers = @{}
                $rows = $table.SelectNodes("./w:tr", $nsMgr)
                $rowIndex = 0
                foreach ($row in $rows) {
                    $rowIndex++
                    $cells = $row.SelectNodes("./w:tc", $nsMgr)
                    $rowLabel = ""
                    foreach ($probe in $cells) {
                        $probeText = Get-CellText $probe $nsMgr
                        if (-not [string]::IsNullOrWhiteSpace($probeText)) {
                            $rowLabel = $probeText
                            break
                        }
                    }

                    $colIndex = 0
                    foreach ($cell in $cells) {
                        $colIndex++
                        $cellText = Get-CellText $cell $nsMgr

                        if ($rowIndex -eq 1 -and -not [string]::IsNullOrWhiteSpace($cellText)) {
                            $headers[$colIndex] = $cellText
                        }

                        if (-not [string]::IsNullOrWhiteSpace($cellText)) {
                            continue
                        }

                        $rowToken = Normalize-TokenPart $rowLabel
                        $colToken = Normalize-TokenPart ([string]($headers[$colIndex]))
                        $base = ""
                        if ($rowToken -and $colToken) {
                            $base = "${rowToken}_${colToken}"
                        } elseif ($rowToken) {
                            $base = $rowToken
                        } elseif ($colToken) {
                            $base = $colToken
                        } else {
                            $base = "table_${tableIndex}_row_${rowIndex}_col_${colIndex}"
                        }

                        if ($used.ContainsKey($base)) {
                            $used[$base] = [int]$used[$base] + 1
                            $token = "${base}_$($used[$base])"
                        } else {
                            $used[$base] = 1
                            $token = $base
                        }

                        $placeholders.Add($token)
                        Set-CellPlaceholder $xml $cell $nsMgr ('${' + $token + '}')
                        $counter++
                    }
                }
            }

            $writer = New-Object System.IO.StreamWriter($outEntry.Open())
            try {
                $writer.Write($xml.OuterXml)
            } finally {
                $writer.Dispose()
            }
        }
    } finally {
        $outZip.Dispose()
    }
} finally {
    $inZip.Dispose()
}

$placeholderListPath = [System.IO.Path]::ChangeExtension($outputFull, ".placeholders.txt")
[System.IO.File]::WriteAllLines($placeholderListPath, $placeholders)

Write-Output ("Template created: " + $outputFull)
Write-Output ("Placeholders count: " + $placeholders.Count)
Write-Output ("Placeholder list: " + $placeholderListPath)
