<?php
/* Musicband SETLIST CALCULATIONS: calculs partagés sans entrée HTTP. */
/* Reconstruction déterministe d’une variante depuis les lignes reçues et les règles de sa publication.
 * Appelé par les services Setlist ; les identifiants appartiennent à l’instantané source.
 * Retourne une variante et ses durées en secondes, ou lève RuntimeException ; aucune persistance ici.
 */
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
if(!function_exists('variantSeconds')){
function variantSeconds($value): int {
    if (!is_int($value) || $value < 0 || $value > 9007199254740991) throw new RuntimeException('Durée ou compteur invalide.');
    return $value;
}
}
if(!function_exists('variantBuild')){
/** Reconstruit et vérifie l’ordre soumis : unicité, disponibilité, rappels et enchaînements. Additionne musique, transitions et pauses une seule fois. */
function variantBuild(array $data, array $source, array $input, string $login): array {
    $base = $source['editorRules'] ?? $data['editor'];
    $r = $base;
    $requested = $input['rules'] ?? null;
    if (!is_array($requested)) throw new RuntimeException('Contraintes manquantes.');
    // Seules les obligations assouplies par un retrait ou un transfert manuel peuvent changer.
    foreach (['required', 'encore_required'] as $field) {
        $values = $requested[$field] ?? null;
        if (!is_array($values) || array_values($values) !== $values) throw new RuntimeException('Contraintes invalides.');
        foreach ($values as $id) if (!is_string($id) || !in_array($id, $base[$field], true)) throw new RuntimeException('Contrainte inconnue.');
        $r[$field] = array_values(array_unique($values));
    }
    foreach (['opening', 'presentation'] as $field) {
        if (!array_key_exists($field, $requested) || ($requested[$field] !== null && $requested[$field] !== $base[$field])) throw new RuntimeException('Contrainte inconnue.');
        $r[$field] = $requested[$field];
    }
    $r['presentation_seconds'] = $r['presentation'] === null ? 0 : $base['presentation_seconds'];
    $r['encore_count'] = variantSeconds($requested['encore_count'] ?? null);
    foreach (['normal_transition_seconds', 'linked_transition_seconds', 'guitar_change_extra_seconds', 'base_pause_seconds', 'presentation_seconds', 'encore_break_seconds'] as $field) variantSeconds($r[$field]);
    $rows = $input['songs'] ?? null;
    if (!is_array($rows) || array_values($rows) !== $rows || !$rows || count($rows) > 1000) throw new RuntimeException('Liste de morceaux invalide.');
    $catalogue = []; $previouslySelected = [];
    foreach ($data['available_songs'] as $song) {
        $id = $song['editor_id'];
        if (isset($catalogue[$id])) throw new RuntimeException('Catalogue ambigu.');
        $catalogue[$id] = $song;
    }
    foreach ($source['songs'] as $song) { $catalogue[$song['editor_id']] = $song; $previouslySelected[$song['editor_id']] = true; }
    $songs = []; $ids = []; $encore = false; $music = 0; $transitions = 0; $changes = 0; $unknown = 0; $main = 0; $encores = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException('Morceau invalide.');
        $id = $row['editor_id'] ?? null; $section = $row['section'] ?? null;
        if (!is_string($id) || !isset($catalogue[$id]) || in_array($id, $ids, true) || !in_array($section, ['main', 'encore'], true)) throw new RuntimeException('Morceau inconnu, dupliqué ou section invalide.');
        $song = $catalogue[$id];
        if (!isset($previouslySelected[$id]) && (!empty($song['unavailable_reason']) || !is_int($song['tempo'] ?? null) || $song['tempo'] <= 0 || !is_string($song['key'] ?? null) || !trim($song['key']))) throw new RuntimeException('Morceau indisponible dans cette publication.');
        $duration = variantSeconds($song['duration_seconds'] ?? null);
        if ($duration === 0) throw new RuntimeException('Durée de morceau inconnue.');
        if ($encore && $section !== 'encore') throw new RuntimeException('Le rappel doit rester après le set principal.');
        $encore = $section === 'encore';
        if ($encore) $encores++; else $main++;
        $song['section'] = $section; $song['position'] = count($songs) + 1; $song['hidden'] = false;
        $previous = $songs ? $songs[count($songs) - 1] : null;
        if ($previous) {
            if (empty($previous['guitar']) || empty($song['guitar'])) $unknown++;
            elseif ($previous['guitar'] !== $song['guitar']) $changes++;
            if ($previous['section'] === $section) $transitions += in_array([$previous['editor_id'], $id], $r['sequences'], true) ? $r['linked_transition_seconds'] : $r['normal_transition_seconds'];
        }
        $music += $duration; $ids[] = $id; $songs[] = $song;
    }
    if (!$main || $encores !== $r['encore_count']) throw new RuntimeException('Conserver un set principal et un nombre de rappels cohérent.');
    if (($r['opening'] !== null && $ids[0] !== $r['opening']) || in_array($ids[0], $r['forbidden_start'], true)) throw new RuntimeException('Ouverture interdite.');
    foreach ($r['required'] as $id) if (!in_array($id, $ids, true)) throw new RuntimeException('Titre obligatoire manquant.');
    foreach ($r['encore_required'] as $id) {
        $position = array_search($id, $ids, true);
        if ($position === false || $songs[$position]['section'] !== 'encore') throw new RuntimeException('Titre imposé en rappel manquant.');
    }
    if ($r['presentation'] !== null) {
        $position = array_search($r['presentation'], $ids, true);
        if ($position === false || $songs[$position]['section'] !== 'main') throw new RuntimeException('Présentation absente du set principal.');
    }
    foreach ($r['sequences'] as [$left, $right]) {
        $a = array_search($left, $ids, true); $b = array_search($right, $ids, true);
        if (($a !== false || $b !== false) && ($a === false || $b !== $a + 1 || $songs[$a]['section'] !== $songs[$b]['section'])) throw new RuntimeException('Enchaînement obligatoire séparé.');
    }
    $transitions += ($changes + $unknown) * $r['guitar_change_extra_seconds'];
    $pause = $r['base_pause_seconds'] + $r['presentation_seconds'] + ($encores ? $r['encore_break_seconds'] : 0);
    $total = $music + $transitions + $pause;
    // Le nom est localisé pour la lecture ; saved_at reste un horodatage technique non ambigu.
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return ['id' => 'custom-' . bin2hex(random_bytes(16)), 'name' => $now->setTimezone(MusicbandDateZone())->format('d/m/Y H:i') . ' — ' . $login,
        'saved_at' => $now->format('Y-m-d\TH:i:s.u\Z'), 'saved_by' => $login, 'source_variant_id' => $source['id'],
        'songs' => $songs, 'editorRules' => $r, 'music_seconds' => $music, 'transition_seconds' => $transitions,
        'pause_seconds' => $pause, 'presentation_seconds' => $r['presentation_seconds'],
        'encore_break_seconds' => $encores ? $r['encore_break_seconds'] : 0, 'total_seconds' => $total,
        'margin_seconds' => variantSeconds($data['target_seconds']) - $total, 'guitar_changes' => $unknown ? null : $changes,
        'transition_estimated' => $unknown > 0, 'edited' => false];
}
}
