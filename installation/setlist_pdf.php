<?php
/* Musicband SETLIST PDF: owned publication */
/* Route authentifiée produisant le programme PDF SACEM à partir de la publication choisie.
 * Dépend de auth.php et du stockage Setlist ; génère le contenu PDF dans la réponse HTTP.
 */
require_once __DIR__ . '/auth.php';
MusicbandRequireLogin(true);
if ($_SERVER['REQUEST_METHOD'] === 'GET') { header('Content-Type: application/json'); echo json_encode(['csrf' => $_SESSION['csrf']]); exit; }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2000000) MusicbandFail('Rapport trop volumineux.', 413);
try {
    MusicbandCsrf(); session_write_close();
    $export = $_POST['export'] ?? 'sacem';
    if ($export !== 'sacem') throw new RuntimeException('Export indisponible. Seul le programme SACEM est proposé.');
    $data = json_decode((string)($_POST['report'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
    $location = $data['concert_location'] ?? null;
    if ($location !== null && (!is_string($location) || strlen($location) > 1000)) throw new RuntimeException('Lieu invalide.');
    $songs = $data['songs'] ?? null;
    if (!is_array($songs) || !$songs || count($songs) > 1000) throw new RuntimeException('Liste de morceaux invalide.');
    foreach (['target_seconds','transition_seconds','pause_seconds'] as $field) if (!is_int($data[$field] ?? null) || $data[$field] < 0) throw new RuntimeException('Durée invalide.');
    $music = 0;
    foreach ($songs as $song) {
        if (!is_int($song['duration_seconds'] ?? null) || $song['duration_seconds'] < 0) throw new RuntimeException('Durée de morceau invalide.');
        $music += $song['duration_seconds'];
        if (!is_array($song) || !is_string($song['title'] ?? null) || !trim($song['title']) || !in_array($song['section'] ?? '', ['main', 'encore'], true)) throw new RuntimeException('Morceau invalide.');
        foreach (['title'] as $field) if (isset($song[$field]) && (!is_string($song[$field]) || strlen($song[$field]) > 12000)) throw new RuntimeException('Champ de rapport invalide.');
        if (!is_string($song['artist'] ?? null) || strlen($song['artist']) > 12000) throw new RuntimeException('Artiste invalide.');
    }
    function pdfLiteral(string $value): string {
        $encoded = function_exists('iconv') ? iconv('UTF-8', 'Windows-1252//TRANSLIT', $value) : preg_replace('/[^\x20-\x7e]/', '?', $value);
        return str_replace(['\\','(',')',"\r","\n"], ['\\\\','\\(','\\)',' ',' '], $encoded === false ? '?' : $encoded);
    }
    $brand = json_decode('null', true);
    $pages = []; $commands = []; $y = 0;
    $text = function($x, $baseline, $value, $size=10, $bold=false) use (&$commands) { $font = $bold ? 'F2' : 'F1'; $commands[] = "0 0 0 rg BT /$font $size Tf $x $baseline Td (" . pdfLiteral((string)$value) . ') Tj ET'; };
    $total = $music + $data['transition_seconds'] + $data['pause_seconds'];
    $songCount = count($songs);
    $page = function() use (&$pages, &$commands, &$y, $text, $brand, $location, $songCount) {
        if ($commands) $pages[] = implode("\n", $commands);
        $commands = [];
        if ($brand) { $height = 48 * $brand['height'] / $brand['width']; $commands[] = "q 48 0 0 $height 28 797 cm /Brand Do Q"; }
        $text($brand ? 86 : 28, 808, 'Musicband - Programme SACEM', 14, true);
        $y = 778;
        $value = is_string($location) && trim($location) !== '' ? 'Lieu du concert : ' . trim($location) : 'Lieu non renseigné';
        $value = preg_replace('/\s+/u', ' ', $value);
        preg_match_all('/.{1,55}(?:\s+|$)|.{1,55}/u', $value, $locationLines);
        foreach ($locationLines[0] as $line) { $text(28, $y, trim($line), 10); $y -= 14; }
        $text(28, $y, $songCount . ' titres', 9); $y -= 22;
        foreach ([[28,'N°'],[65,'Titre'],[330,'Artiste']] as [$x,$label]) $text($x,$y,$label,10,true);
        $y -= 10;
    };
    $page();
    foreach ($songs as $i => $song) {
        $values = [sprintf('%02d', $i+1), $song['title'], trim($song['artist']) !== '' ? $song['artist'] : 'Artiste non renseigné'];
        $widths = [4,28,24];
        $columns = []; foreach ($values as $c => $value) $columns[] = explode("\n", wordwrap(function_exists('iconv') ? (iconv('UTF-8','Windows-1252//TRANSLIT',$value) ?: '') : preg_replace('/[^\x20-\x7e]/','?',$value), $widths[$c], "\n", true));
        // Replier avant d’échapper les caractères spéciaux du PDF.
        $lines = max(array_map('count', $columns)); $offset = 0;
        while ($offset < $lines) {
            if ($y - 36 < 40) $page();
            $take = min($lines-$offset, max(1,(int)floor(($y-54)/12))); $height = max(36,$take*12+14);
            $shade = ($i+1)%2===0 ? '0.94 0.94 0.94' : '1 1 1'; $bottom = $y-$height;
            $commands[] = "$shade rg 26 $bottom 543 $height re f";
            foreach ($columns as $c => $column) foreach (array_slice($column,$offset,$take) as $j => $line) {
                $x = [28,65,330][$c]; $baseline = $y-15-$j*12; $font = ($c === 1)?'F2':'F1';
                $line = str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $line);
                $commands[] = "0 0 0 rg BT /$font 10 Tf $x $baseline Td ($line) Tj ET";
            }
            $y -= $height; $offset += $take;
            if ($offset < $lines) $page();
        }
    }
    $pages[] = implode("\n",$commands);
    $objects = ['', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
    if ($brand) { $pixels = hex2bin($brand['pdf_hex']); $objects[] = '<< /Type /XObject /Subtype /Image /Width '.$brand['width'].' /Height '.$brand['height'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length '.strlen($pixels)." >>\nstream\n".$pixels."\nendstream"; }
    $kids = []; foreach ($pages as $content) { $n=count($objects)+1; $kids[]="$n 0 R"; $resource=$brand?'/XObject << /Brand 5 0 R >>':''; $objects[]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> '.$resource.' >> /Contents '.($n+1).' 0 R >>'; $objects[]='<< /Length '.strlen($content)." >>\nstream\n$content\nendstream"; }
    $objects[0]='<< /Type /Catalog /Pages 2 0 R >>'; $objects[1]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($pages).' >>';
    $output="%PDF-1.4\n%\xe2\xe3\xcf\xd3\n"; $offsets=[0]; foreach ($objects as $i=>$object) { $offsets[]=strlen($output); $output.=($i+1)." 0 obj\n$object\nendobj\n"; }
    // Les offsets PDF sont des positions en octets : les calculer après encodage des flux.
    $start=strlen($output); $output.='xref' . "\n0 ".count($offsets)."\n0000000000 65535 f \n"; foreach (array_slice($offsets,1) as $offset) $output.=sprintf('%010d 00000 n ',$offset)."\n";
    $output.="trailer\n<< /Size ".count($offsets)." /Root 1 0 R >>\nstartxref\n$start\n%%EOF\n";
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="setlist-' . $export . '.pdf"'); header('Content-Length: '.strlen($output)); echo $output;
} catch (Throwable $e) { MusicbandFail('Export PDF impossible : vérifier les données et la session.',400); }
