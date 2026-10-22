<?php
/* Musicband BUSINESS LIST: tableaux et recherche authentifiée en lecture seule. */
/* Listes des modules métier : filtres, pagination, colonnes et dates importées.
 * Appelé par business.php ; réutilise les droits et réglages de champs, ainsi que contacts_followup_service.php.
 * Retourne des données paginées ou du HTML ; les actions métier restent dans les contrôleurs.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/contacts_followup_service.php';
function businessListSettings(): array {
    return json_decode(base64_decode('eyJ0aW1lem9uZSI6IkV1cm9wZS9QYXJpcyIsInBhZ2Vfc2l6ZSI6MzAsInNlYXJjaF9kZWxheV9tcyI6MjUwLCJkYXRlX2Zvcm1hdHMiOlsiZC9tL1kiLCJZLW0tZCJdfQ==', true), true, 16, JSON_THROW_ON_ERROR);
}
function businessColumns(string $module,bool $layout=true,bool $includeHidden=false): array {
    if(in_array($module,['expenses','accounting'],true)) {
        $fields=$module==='accounting'?['entry_date'=>'Date','description'=>'Description','category'=>'Source','credit'=>'Crédit','debit'=>'Débit','running'=>'Solde chronologique']:businessConfig($module)['fields'];
        if(!$includeHidden)$fields=array_filter($fields,fn($key)=>fieldVisibilityVisible($module,$key),ARRAY_FILTER_USE_KEY);
        return $layout?fieldLayoutOrder($module,'list',$fields):$fields;
    }
    if($module==='songs'){$fields=businessConfig('songs')['fields'];foreach(fieldVisibilityRegistry(MusicbandRbacDb(),'songs') as $key=>$label)if(str_starts_with($key,'source:'))$fields[$key]=$label;return $layout?fieldLayoutOrder('songs','list',$fields):$fields;}
    $columns = $module === 'contacts'
        ? (['nom'=>'Nom', 'adresse'=>'Lieu / adresse', 'contact'=>'Interlocuteur', 'mail'=>'E-mail', 'tel'=>'Téléphone', 'statut'=>'Statut'] + (contactsFollowupFields() ? ['dernier_contact'=>'Dernier contact'] : []) + ['commentaires'=>'Commentaires'])
        : ['evenement'=>'Nom', 'date_concert'=>'Date', 'adresse'=>'Lieu', 'statut'=>'Statut', 'client'=>'Client', 'contact'=>'Interlocuteur', 'heure_arrivee'=>'Arrivée', 'heure_balances'=>'Balances', 'heure_prestation'=>'Prestation', 'duree'=>'Durée (min.)', 'commentaires'=>'Commentaires'];
    if ($module==='concerts' && documentsWorkflowConcertFields()) $columns += ['montant'=>'Montant cachet','reglement'=>'Mode Reglement'];
    if ($module==='devis_factures') { $fields=businessConfig($module)['fields']; $columns=['numero'=>$fields['numero'],'type_document'=>$fields['type_document']]+$fields; }
    if (!$includeHidden) $columns=array_filter($columns, fn($key)=>fieldVisibilityVisible($module,$key), ARRAY_FILTER_USE_KEY);
    if ($layout) foreach ($columns as $key=>$label) $columns[$key]=fieldDisplayLabel($module,$key,$label);
    return $layout?fieldLayoutOrder($module,'list',$columns):$columns;
}
function businessListPage(string $name): int {
    $page = $_GET[$name] ?? '1';
    if (!is_string($page) || !ctype_digit($page) || strlen($page) > 6 || (int)$page < 1) throw new RuntimeException('Page invalide.');
    return (int)$page;
}
function businessDateImported(array $row): bool {
    if (($row['source_table']??'manual')==='manual') return false;
    $original=$row['imported_date']??null;
    if ($original===null && isset($row['source_json'])) {
        $source=json_decode($row['source_json'],true,32,JSON_THROW_ON_ERROR);
        $original=$source['Date']??null;
    }
    return is_string($original) && trim($original)===trim((string)($row['date_concert']??''));
}
function businessConcertDate(?string $value, array $settings, bool $imported = false): ?string {
    foreach (array_unique($imported?['Y-m-d','m/d/Y','n/j/Y']:['Y-m-d',...$settings['date_formats']]) as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, trim($value ?? ''), new DateTimeZone($settings['timezone']));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && $date->format('Y') !== '0000' && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format($format) === trim($value ?? '')) return $date->format('Y-m-d');
    }
    return null;
}
// Même date métier et même fuseau que le classement « Concerts passés » ; aujourd’hui reste à venir.
function businessConcertIsPast(array $row,?array $settings=null): bool {
    $settings=$settings??businessListSettings();
    $date=businessConcertDate($row['date_concert']??null,$settings,businessDateImported($row));
    return $date!==null&&$date<(new DateTimeImmutable('today',new DateTimeZone($settings['timezone'])))->format('Y-m-d');
}
function businessTable(array $rows, int $total, int $page, string $pageKey, string $title, string $module, string $search, array $settings): string {
    $columns = businessColumns($module); $primary = businessConfig($module)['required']; $route = MusicbandBase() . $module . '.php';
    $canEdit = MusicbandCan(MusicbandBusinessPermission($module));
    $isAdmin=(bool)(MusicbandUser()['admin']??false);
    $canPublish=$module==='concerts' && MusicbandCan('concerts.publish.wordpress');
    $html = '<section class="business-table-section"><h3>' . MusicbandEscape($title) . ' <span class="business-count">' . $total . '</span></h3><div class="business-table-scroll" tabindex="0" role="region" aria-label="' . MusicbandEscape($title) . '"><table role="table"><caption class="business-sr">' . MusicbandEscape($title) . '</caption><thead role="rowgroup"><tr role="row">';
    $layout=fieldLayoutEntries($module,'list',$columns); $groupStarts=[]; $previous=null;
    foreach ($layout as $key=>$entry) { $groupStarts[$key]=$previous!==null && $previous!==$entry['group']; $previous=$entry['group']; }
    foreach ($columns as $key => $label) $html .= '<th scope="col"'.($groupStarts[$key]?' class="field-group-start"':'').' data-column="' . $key . '">' . ($layout[$key]['title']!==''?'<small class="field-column-group">'.MusicbandEscape($layout[$key]['title']).'</small>':'') . MusicbandEscape($label) . '</th>';
    $html .= '<th scope="col">Actions</th></tr></thead><tbody role="rowgroup">';
    foreach ($rows as $row) {
        $past=$module==='concerts'&&businessConcertIsPast($row,$settings);
        $html .= '<tr role="row">';
        foreach ($columns as $key => $label) {
            $value = businessDisplayValue($module,$key,$row[$key]??'',$row);
            preg_match('/\A.{0,180}/us', $value, $match); $short = $match[0] ?? ''; if (strlen($short) < strlen($value)) $short .= '…';
            $text = MusicbandEscape($short === '' ? '—' : $short);
            if ($key === $primary) $text = '<a href="' . MusicbandEscape($route) . '?id=' . (int)$row['id'] . '">' . ($short === '' ? 'Fiche #' . (int)$row['id'] : $text) . '</a>';
            $html .= '<td role="cell"'.($groupStarts[$key]?' class="field-group-start"':'').' data-column="' . $key . '"><span class="Musicband-table-label" aria-hidden="true">'.MusicbandEscape(($layout[$key]['title']!==''?$layout[$key]['title'].' · ':'').$label).'</span><span class="Musicband-cell-value">' . $text . '</span></td>';
        }
        $recordURL = MusicbandEscape($route) . '?id=' . (int)$row['id'];
        $label = MusicbandEscape((string)($row[$primary] ?? ('Fiche #' . $row['id'])));
        $html .= '<td role="cell"><span class="Musicband-table-label" aria-hidden="true">Actions</span><div class="business-row-actions"><a class="business-action" href="' . $recordURL . '" aria-label="Consulter : ' . $label . '">Voir</a>';
        if ($canEdit && (!$past||$isAdmin) && ($module!=='devis_factures' || in_array(documentsWorkflowState($row),['Préparation',''],true))) $html .= '<a class="business-action" href="' . $recordURL . '&amp;edit=1" aria-label="Modifier : ' . $label . '">Modifier</a>';
        if (MusicbandCan('calendar.send') && $module==='concerts') {
            if (!empty($row['calendar_status'])) {
                $labels=['accepted'=>'Invitation envoyée aux troubadours','failed'=>'Invitation refusée · envoi bloqué','reserved'=>'Invitation en cours / non confirmée','uncertain'=>'Invitation incertaine · envoi bloqué'];
                $html .= '<span>'.($labels[$row['calendar_status']]??'Invitation déjà déclenchée').'</span>';
            } elseif (concertsCalendarEligible($row)) $html .= '<a class="business-action" href="'.$recordURL.'&amp;calendar=1">Inviter au calendrier…</a>';
        }
        if ($canPublish&&!$past) $html.='<a class="business-action" href="'.$recordURL.'&amp;publication=1">Publication WordPress…</a>';
        $html .= '</div></td></tr>';
    }
    $html .= '</tbody></table></div>';
    if (!$total) $html .= '<p>Aucun résultat.</p>';
    elseif (!$rows) $html .= '<p>Aucun résultat à cette page. Revenir à la première page.</p>';
    $pages = max(1, (int)ceil($total / $settings['page_size']));
    $html .= '<nav class="business-pagination" aria-label="Pages · ' . MusicbandEscape($title) . '"><span>Page ' . $page . ' / ' . $pages . '</span>';
    foreach ([1=>'Première page', $page - 1=>'Précédente', $page + 1=>'Suivante'] as $target => $label) {
        if ($target < 1 || $target > $pages || $target === $page) continue;
        $params = ['q'=>$search];
        if($module==='devis_factures'&&($_GET['deleted']??'')==='1'&&MusicbandCan('documents.delete'))$params['deleted']='1';
        foreach (['page','upcoming_page','past_page','unknown_page'] as $key) $params[$key] = (string)businessListPage($key);
        $params[$pageKey] = (string)$target;
        $html .= '<a data-list-page href="' . MusicbandEscape($route . '?' . http_build_query($params)) . '">' . $label . '</a>';
    }
    return $html . '</nav></section>';
}
function businessListResults(PDO $db, string $module, array $config, array $settings): string {
    $calendarColumn='';
    if ($module==='concerts' && MusicbandCan('calendar.send')) {
        try { if (concertsCalendarReady($db)) $calendarColumn=',(SELECT status FROM concerts_calendar_invitations WHERE concert_id=concerts_evenements.id) AS calendar_status'; }
        catch (Throwable $e) { /* Une migration partielle ne bloque pas la consultation. */ }
    }
    $search = $_GET['q'] ?? ''; if (!is_string($search) || strlen($search) > 800 || str_contains($search, "\0")) throw new RuntimeException('Recherche invalide.');
    $fields = array_keys(businessColumns($module));
    $where = '1=1'; $parameters = [];
    if ($search !== '') {
        $where = implode(' OR ', array_map(fn($key) => $key . " LIKE ? ESCAPE '!'", $fields));
        $parameters = array_fill(0, count($fields), '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $search) . '%');
    }
    $deleted=$module==='devis_factures'&&($_GET['deleted']??'')==='1'&&MusicbandCan('documents.delete');
    if($module==='devis_factures')$where='('.$where.') AND '.($deleted?"statut='Supprimé'":"(statut IS NULL OR statut<>'Supprimé')");
    $size = $settings['page_size']; $select = 'SELECT id,' . implode(',', $fields) . ' FROM ' . $config['table'] . ' WHERE ' . $where;
    if ($module !== 'concerts') {
        $page = businessListPage('page');
        $count = $db->prepare('SELECT COUNT(*) FROM ' . $config['table'] . ' WHERE ' . $where); $count->execute($parameters); $total = (int)$count->fetchColumn();
        $query = $db->prepare($select . ' ORDER BY '.$config['required'].',id LIMIT ' . $size . ' OFFSET ' . (($page-1)*$size)); $query->execute($parameters);
        $trash=$module==='devis_factures'&&MusicbandCan('documents.delete')?'<p><a href="'.MusicbandEscape(MusicbandBase().'devis_factures.php'.($deleted?'':'?deleted=1')).'">'.($deleted?'Revenir aux documents courants':'Documents supprimés · restaurer').'</a></p>':'';
        return $trash.businessTable($query->fetchAll(), $total, $page, 'page', $config['title'], $module, $search, $settings);
    }
    $today = (new DateTimeImmutable('today', new DateTimeZone($settings['timezone'])))->format('Y-m-d');
    // Ne conserver que les identifiants et dates pendant le classement : aucune
    // conversion SQL permissive, aucune date impossible rangée dans le calendrier.
    $query = $db->prepare("SELECT id,date_concert,source_table,JSON_UNQUOTE(JSON_EXTRACT(source_json, '$.Date')) AS imported_date FROM " . $config['table'] . ' WHERE ' . $where); $query->execute($parameters);
    $groups = [];
    foreach (['upcoming'=>'À venir · aujourd’hui inclus', 'past'=>'Concerts passés', 'unknown'=>'Dates à préciser'] as $key=>$title) $groups[$key] = ['title'=>$title,'page'=>businessListPage($key . '_page'),'items'=>[]];
    while ($row = $query->fetch()) {
        $date = businessConcertDate($row['date_concert'], $settings, businessDateImported($row));
        $key = $date === null ? 'unknown' : ($date < $today ? 'past' : 'upcoming');
        $groups[$key]['items'][] = ['id'=>(int)$row['id'], 'date'=>$date];
    }
    foreach ($groups as $key=>&$group) {
        usort($group['items'], function($a,$b) use ($key) {
            $dateOrder = ($a['date'] ?? '') <=> ($b['date'] ?? '');
            return ($key === 'upcoming' ? $dateOrder : -$dateOrder) ?: ($b['id'] <=> $a['id']);
        });
        $group['count'] = count($group['items']);
        $ids = array_column(array_slice($group['items'], ($group['page']-1)*$size, $size), 'id');
        $group['rows'] = [];
        if ($ids) {
            $recordFields=array_unique([...$fields,'date_concert','adresse','heure_arrivee','heure_prestation','duree','source_table']);
            $records = $db->prepare('SELECT id,' . implode(',', $recordFields) . ",JSON_UNQUOTE(JSON_EXTRACT(source_json, '$.Date')) AS imported_date" . $calendarColumn . ' FROM ' . $config['table'] . ' WHERE id IN (' . implode(',', array_fill(0,count($ids),'?')) . ')'); $records->execute($ids);
            $byId = []; foreach ($records as $row) $byId[(int)$row['id']] = $row;
            foreach ($ids as $id) if (isset($byId[$id])) $group['rows'][] = $byId[$id];
        }
        unset($group['items']);
    }
    unset($group);
    $html = '<p class="business-date-note">Classement au ' . MusicbandEscape(MusicbandDisplayDate($today)) . '. Les dates d’origine sont conservées.</p>';
    foreach ($groups as $key=>$group) if ($key !== 'unknown' || $group['count']) $html .= businessTable($group['rows'], $group['count'], $group['page'], $key . '_page', $group['title'], $module, $search, $settings);
    return $html;
}
function businessListControls(string $module): string {
    $columns = businessColumns($module); $defaults = $module === 'contacts' ? ['nom','adresse'] : ($module==='concerts' ? ['evenement','date_concert','adresse'] : ['numero','type_document','statut','client','montant']);
    $search = is_string($_GET['q'] ?? '') ? ($_GET['q'] ?? '') : '';
    $hasCriteria = $search !== '';
    $summaryText = $hasCriteria ? 'Filtre actif : Recherche « ' . MusicbandEscape($search) . ' »' : 'Aucun filtre actif';
    $chevronSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
    $quickReset = '<button type="button" class="Musicband-search-quick-reset" title="Effacer la recherche"' . ($hasCriteria ? '' : ' hidden') . '>Effacer</button>';
    $html = '<div id="business-list" data-module="' . $module . '">';
    $html .= '<details class="Musicband-search-collapsible" id="business-search-panel">';
    $html .= '<summary class="Musicband-search-header"><div class="Musicband-search-header-main">';
    $html .= '<span class="Musicband-search-chevron">' . $chevronSvg . '</span>';
    $html .= '<span class="Musicband-search-title">Recherche et colonnes</span>';
    $html .= '<span class="Musicband-search-summary-badge' . ($hasCriteria ? ' is-active' : '') . '" id="business-search-badge" role="status">' . $summaryText . '</span>';
    $html .= '</div>' . $quickReset . '</summary>';
    $html .= '<div class="Musicband-search-body"><div class="business-toolbar">';
    $html .= '<form id="business-search" method="get" role="search"><label for="business-query">Rechercher</label><input id="business-query" type="search" name="q" maxlength="200" autocomplete="off" placeholder="Nom, lieu…" value="' . MusicbandEscape($search) . '"><button type="submit" class="button-primary business-search-submit">Rechercher</button></form>';
    $html .= '<details class="business-columns"><summary>Colonnes affichées</summary><div>';
    foreach ($columns as $key=>$label) $html .= '<label><input type="checkbox" data-toggle-column="' . $key . '"' . (in_array($key,$defaults,true) ? ' checked' : '') . ($key === businessConfig($module)['required'] ? ' disabled' : '') . '> ' . MusicbandEscape($label) . '</label>';
    if($module==='devis_factures'&&($_GET['deleted']??'')==='1'&&MusicbandCan('documents.delete'))$html=str_replace('</form>','<input type="hidden" name="deleted" value="1"></form>',$html);
    return $html . '</div></details></div></div></details><p id="business-search-status" role="status" aria-live="polite"></p><div id="business-results">';
}
