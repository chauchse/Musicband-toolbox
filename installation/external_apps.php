<?php
/**
 * Musicband EXTERNAL APPS : utilitaire d'ouverture de liens externes dans leurs applications natives associées.
 *
 * Responsabilités :
 * - Validation stricte des URL externes (protocoles autorisés http/https uniquement, rejet des protocoles dangereux).
 * - Détection et classification extensible des fournisseurs de liens profonds (Ultimate Guitar, extensible à YouTube, Spotify, etc.).
 * - Génération d'ancres HTML opt-in enrichies (attribut data-external-app, target="_blank" initial pour fallback desktop).
 * - Fourniture du script client d'adaptation dynamique de navigation (openExternalApp).
 *
 * Dépendances internes :
 * - auth.php (fonction MusicbandEscape si disponible)
 * - external_apps.js (implémentation JavaScript côté client)
 *
 * Dépendances externes : aucune (fonctions natives PHP).
 */
declare(strict_types=1);

if (!function_exists('MusicbandEscape')) {
    function MusicbandEscape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Valide et décompose une URL externe autorisée.
 * Rejette strictement les protocoles non autorisés (javascript:, data:, vbscript:, etc.).
 */
function MusicbandExternalAppValidateUrl(string $url): ?array {
    $trimmed = trim($url);
    if ($trimmed === '' || !filter_var($trimmed, FILTER_VALIDATE_URL)) {
        return null;
    }
    $parts = parse_url($trimmed);
    if (!$parts || !isset($parts['scheme']) || !isset($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['https', 'http'], true)) {
        return null;
    }
    return [
        'url' => $trimmed,
        'scheme' => $scheme,
        'host' => strtolower((string)$parts['host']),
        'path' => (string)($parts['path'] ?? '/'),
        'query' => (string)($parts['query'] ?? ''),
        'fragment' => (string)($parts['fragment'] ?? ''),
    ];
}

/**
 * Identifie le fournisseur associé à une URL externe selon un registre extensible.
 */
function MusicbandExternalAppDetectProvider(string $url): string {
    $parsed = MusicbandExternalAppValidateUrl($url);
    if (!$parsed) {
        return 'invalid';
    }
    $host = $parsed['host'];
    // Ultimate Guitar (tabs.ultimate-guitar.com, www.ultimate-guitar.com, ultimate-guitar.com)
    if ($host === 'tabs.ultimate-guitar.com' || $host === 'www.ultimate-guitar.com' || $host === 'ultimate-guitar.com' || str_ends_with($host, '.ultimate-guitar.com')) {
        return 'ultimate-guitar';
    }
    // Google Drive (drive.google.com)
    if ($host === 'drive.google.com' || str_ends_with($host, '.drive.google.com')) {
        return 'google-drive';
    }
    // Architecture extensible pour futurs fournisseurs (ex: YouTube, Spotify, Instagram, Facebook)
    return 'generic';
}

/**
 * Génère le code HTML d'un lien opt-in vers une application externe avec repli navigateur transparent.
 *
 * Sur desktop ou sans JavaScript : s'ouvre dans un nouvel onglet avec protection noopener noreferrer.
 * Sur mobile (iOS / Android) : le script client external_apps.js adapte le lien pour permettre
 * le déclenchement natif des Universal Links (iOS) ou Android App Links via un geste utilisateur direct.
 */
function MusicbandExternalAppLink(string $url, string $provider = 'auto', array $options = []): string {
    $parsed = MusicbandExternalAppValidateUrl($url);
    if (!$parsed) {
        // En cas d'URL invalide ou dangereuse, neutraliser sous forme de texte brut échappé
        return MusicbandEscape($url);
    }

    $detectedProvider = ($provider === 'auto' || $provider === '') ? MusicbandExternalAppDetectProvider($url) : $provider;
    $label = isset($options['label']) && is_string($options['label']) && $options['label'] !== '' ? $options['label'] : $url;
    $extraClass = isset($options['class']) && is_string($options['class']) && $options['class'] !== '' ? ' ' . $options['class'] : '';
    $title = isset($options['title']) && is_string($options['title']) && $options['title'] !== '' ? ' title="' . MusicbandEscape($options['title']) . '"' : '';

    return '<a href="' . MusicbandEscape($url) . '" class="Musicband-external-app' . MusicbandEscape($extraClass) . '" data-external-app="' . MusicbandEscape($detectedProvider) . '" target="_blank" rel="noopener noreferrer"' . $title . '>' . MusicbandEscape($label) . '</a>';
}

/**
 * Retourne le contenu du script JavaScript external_apps.js pour injection inline sécurisée.
 */
function MusicbandExternalAppScript(): string {
    static $cachedScript = null;
    if ($cachedScript !== null) {
        return $cachedScript;
    }
    $b64 = 'LyoqCiAqIE11c2ljYmFuZCBFeHRlcm5hbCBBcHAgRGVlcCBMaW5rIFV0aWxpdHkKICoKICogVXRpbGl0YWlyZSBtb2R1bGFpcmUgZXQgb3B0LWluIHBlcm1ldHRhbnQgbCdvdXZlcnR1cmUgZGVzIGxpZW5zIEhUVFBTIGV4dGVybmVzCiAqIGRhbnMgbGV1cnMgYXBwbGljYXRpb25zIG1vYmlsZXMgbmF0aXZlcyByZXNwZWN0aXZlcyAoaU9TIFVuaXZlcnNhbCBMaW5rcywKICogQW5kcm9pZCBBcHAgTGlua3MpLCBhdmVjIHJlcGxpIHRyYW5zcGFyZW50IHN1ciBsZSBuYXZpZ2F0ZXVyIChmYWxsYmFjayBIVFRQUykuCiAqCiAqIEludmFyaWFudHMgOgogKiAtIEF1Y3VuIHNjaMOpbWEgZCdVUkwgcGVyc29ubmFsaXPDqSBub24gdsOpcmlmacOpIG91IGludmVudMOpLgogKiAtIEF1Y3VuIHRpbWVyIGRlIGTDqXRlY3Rpb24gYXJiaXRyYWlyZSBvdSBub24gZmlhYmxlLgogKiAtIFByw6lzZXJ2YXRpb24gaW50w6lncmFsZSBkZSBsJ1VSTCBvcmlnaW5hbGUgKHJlcXXDqnRlIGV0IGZyYWdtZW50cyBpbmNsdXMpLgogKiAtIEF1Y3VuIGTDqXRvdXJuZW1lbnQgZ2xvYmFsIDogcG9ydMOpZSBzdHJpY3RlbWVudCByZXN0cmVpbnRlIGF1eCBsaWVucyBvcHQtaW4gKGRhdGEtZXh0ZXJuYWwtYXBwKS4KICogLSBNYWludGllbiBkdSBjb21wb3J0ZW1lbnQgc3RhbmRhcmQgZGUgbmF2aWdhdGlvbiBIVFRQUyBzdXIgZGVza3RvcCAob3V2ZXJ0dXJlIG5vdXZlbCBvbmdsZXQpLgogKi8KKCgpID0+IHsKICAndXNlIHN0cmljdCc7CgogIC8qKgogICAqIFJlZ2lzdHJlIGV4dGVuc2libGUgZGVzIGZvdXJuaXNzZXVycyBkJ2FwcGxpY2F0aW9ucyBleHRlcm5lcyB2w6lyaWZpw6lzLgogICAqIENvbsOndSBwb3VyIGFjY3VlaWxsaXIgZGUgZnV0dXJzIGZvdXJuaXNzZXVycyAoWW91VHViZSwgU3BvdGlmeSwgZXRjLikgc2FucyByZWZvbnRlIGQnYXJjaGl0ZWN0dXJlLgogICAqLwogIGNvbnN0IEVYVEVSTkFMX0FQUF9QUk9WSURFUlMgPSB7CiAgICAndWx0aW1hdGUtZ3VpdGFyJzogewogICAgICBuYW1lOiAnVWx0aW1hdGUgR3VpdGFyJywKICAgICAgbWF0Y2hlczogZnVuY3Rpb24odXJsT2JqKSB7CiAgICAgICAgY29uc3QgaG9zdCA9IHVybE9iai5ob3N0bmFtZS50b0xvd2VyQ2FzZSgpOwogICAgICAgIHJldHVybiBob3N0ID09PSAndGFicy51bHRpbWF0ZS1ndWl0YXIuY29tJyB8fCBob3N0ID09PSAnd3d3LnVsdGltYXRlLWd1aXRhci5jb20nIHx8IGhvc3QgPT09ICd1bHRpbWF0ZS1ndWl0YXIuY29tJyB8fCBob3N0LmVuZHNXaXRoKCcudWx0aW1hdGUtZ3VpdGFyLmNvbScpOwogICAgICB9LAogICAgICBpc1N1cHBvcnRlZFBhdGg6IGZ1bmN0aW9uKHVybE9iaikgewogICAgICAgIGNvbnN0IHAgPSB1cmxPYmoucGF0aG5hbWU7CiAgICAgICAgcmV0dXJuIHAuc3RhcnRzV2l0aCgnL3RhYi8nKSB8fCBwLmluY2x1ZGVzKCdfY3JkLmh0bScpIHx8IHAuaW5jbHVkZXMoJ190YWIuaHRtJykgfHwgcC5pbmNsdWRlcygnX2J0YWInKSB8fCBwLmluY2x1ZGVzKCdfZ3VpdGFyX3Byby5odG0nKTsKICAgICAgfQogICAgfSwKICAgICdnb29nbGUtZHJpdmUnOiB7CiAgICAgIG5hbWU6ICdHb29nbGUgRHJpdmUnLAogICAgICBtYXRjaGVzOiBmdW5jdGlvbih1cmxPYmopIHsKICAgICAgICBjb25zdCBob3N0ID0gdXJsT2JqLmhvc3RuYW1lLnRvTG93ZXJDYXNlKCk7CiAgICAgICAgcmV0dXJuIGhvc3QgPT09ICdkcml2ZS5nb29nbGUuY29tJyB8fCBob3N0LmVuZHNXaXRoKCcuZHJpdmUuZ29vZ2xlLmNvbScpOwogICAgICB9LAogICAgICBpc1N1cHBvcnRlZFBhdGg6IGZ1bmN0aW9uKHVybE9iaikgewogICAgICAgIGNvbnN0IHAgPSB1cmxPYmoucGF0aG5hbWU7CiAgICAgICAgcmV0dXJuIHAuc3RhcnRzV2l0aCgnL2RyaXZlLycpIHx8IHAuc3RhcnRzV2l0aCgnL2ZpbGUvJykgfHwgcC5zdGFydHNXaXRoKCcvZm9sZGVydmlldycpOwogICAgICB9CiAgICB9CiAgfTsKCiAgLyoqCiAgICogRMOpdGVjdGlvbiBkZXMgcGxhdGVmb3JtZXMgZXQgY29udGV4dGVzIGQnZXjDqWN1dGlvbi4KICAgKi8KICBmdW5jdGlvbiBpc0lPUygpIHsKICAgIHJldHVybiAvaVBhZHxpUGhvbmV8aVBvZC8udGVzdChuYXZpZ2F0b3IudXNlckFnZW50KSB8fCAobmF2aWdhdG9yLnBsYXRmb3JtID09PSAnTWFjSW50ZWwnICYmIG5hdmlnYXRvci5tYXhUb3VjaFBvaW50cyA+IDEpOwogIH0KCiAgZnVuY3Rpb24gaXNBbmRyb2lkKCkgewogICAgcmV0dXJuIC9BbmRyb2lkL2kudGVzdChuYXZpZ2F0b3IudXNlckFnZW50KTsKICB9CgogIGZ1bmN0aW9uIGlzU3RhbmRhbG9uZSgpIHsKICAgIHJldHVybiB3aW5kb3cubmF2aWdhdG9yLnN0YW5kYWxvbmUgPT09IHRydWUgfHwgKHdpbmRvdy5tYXRjaE1lZGlhICYmIHdpbmRvdy5tYXRjaE1lZGlhKCcoZGlzcGxheS1tb2RlOiBzdGFuZGFsb25lKScpLm1hdGNoZXMpOwogIH0KCiAgLyoqCiAgICogVmFsaWRlIHVuZSBVUkwgZXh0ZXJuZSBldCB2w6lyaWZpZSBsYSBjb25mb3JtaXTDqSBkdSBwcm90b2NvbGUgZGUgc8OpY3VyaXTDqS4KICAgKi8KICBmdW5jdGlvbiBwYXJzZVZhbGlkVXJsKHVybCkgewogICAgaWYgKHR5cGVvZiB1cmwgIT09ICdzdHJpbmcnIHx8ICF1cmwudHJpbSgpKSByZXR1cm4gbnVsbDsKICAgIHRyeSB7CiAgICAgIGNvbnN0IHBhcnNlZCA9IG5ldyBVUkwodXJsLnRyaW0oKSwgd2luZG93LmxvY2F0aW9uLmhyZWYpOwogICAgICBpZiAocGFyc2VkLnByb3RvY29sICE9PSAnaHR0cHM6JyAmJiBwYXJzZWQucHJvdG9jb2wgIT09ICdodHRwOicpIHsKICAgICAgICByZXR1cm4gbnVsbDsKICAgICAgfQogICAgICByZXR1cm4gcGFyc2VkOwogICAgfSBjYXRjaCAoXykgewogICAgICByZXR1cm4gbnVsbDsKICAgIH0KICB9CgogIC8qKgogICAqIE91dnJlIHVuZSBVUkwgZXh0ZXJuZSBkYW5zIGwnYXBwbGljYXRpb24gbmF0aXZlIGFzc29jacOpZSBzaSBzdXBwb3J0w6ksCiAgICogb3UgZGFucyBsZSBuYXZpZ2F0ZXVyIGF2ZWMgcmVwbGkgdHJhbnNwYXJlbnQuCiAgICoKICAgKiBAcGFyYW0ge3N0cmluZ30gdXJsIC0gVVJMIEhUVFBTIGNvbXBsw6h0ZS4KICAgKiBAcGFyYW0ge09iamVjdH0gW29wdGlvbnNdIC0gT3B0aW9ucyBmYWN1bHRhdGl2ZXMgKGVsZW1lbnQsIGV2ZW50LCBwcm92aWRlcikuCiAgICogQHJldHVybnMge2Jvb2xlYW59IFN1Y2PDqHMgZGUgbGEgcHJpc2UgZW4gY2hhcmdlIGRlIGxhIG5hdmlnYXRpb24uCiAgICovCiAgZnVuY3Rpb24gb3BlbkV4dGVybmFsQXBwKHVybCwgb3B0aW9ucyA9IHt9KSB7CiAgICBjb25zdCBwYXJzZWQgPSBwYXJzZVZhbGlkVXJsKHVybCk7CiAgICBpZiAoIXBhcnNlZCkgcmV0dXJuIGZhbHNlOwoKICAgIGNvbnN0IGVsZW1lbnQgPSBvcHRpb25zLmVsZW1lbnQgfHwgbnVsbDsKICAgIGNvbnN0IGV2ZW50ID0gb3B0aW9ucy5ldmVudCB8fCBudWxsOwogICAgY29uc3QgbW9iaWxlID0gaXNJT1MoKSB8fCBpc0FuZHJvaWQoKTsKCiAgICAvLyAxLiBTdXIgRGVza3RvcCA6IG5hdmlnYXRpb24gSFRUUFMgc3RhbmRhcmQgZGFucyB1biBub3V2ZWwgb25nbGV0CiAgICBpZiAoIW1vYmlsZSkgewogICAgICBpZiAoZWxlbWVudCkgewogICAgICAgIGVsZW1lbnQudGFyZ2V0ID0gJ19ibGFuayc7CiAgICAgICAgZWxlbWVudC5yZWwgPSAnbm9vcGVuZXIgbm9yZWZlcnJlcic7CiAgICAgICAgcmV0dXJuIHRydWU7CiAgICAgIH0KICAgICAgaWYgKCFldmVudCkgewogICAgICAgIHdpbmRvdy5vcGVuKHBhcnNlZC5ocmVmLCAnX2JsYW5rJywgJ25vb3BlbmVyLG5vcmVmZXJyZXInKTsKICAgICAgfQogICAgICByZXR1cm4gdHJ1ZTsKICAgIH0KCiAgICAvLyAyLiBTdXIgTW9iaWxlIChpT1MgZXQgQW5kcm9pZCkgOgogICAgLy8gTGVzIFVuaXZlcnNhbCBMaW5rcyAoaU9TKSBldCBBcHAgTGlua3MgKEFuZHJvaWQpIHJlcG9zZW50IHN1ciB1biBnZXN0ZSB1dGlsaXNhdGV1ciBkaXJlY3QKICAgIC8vIGRhbnMgbGUgY29udGV4dGUgZGUgbmF2aWdhdGlvbiBjb3VyYW50IChfc2VsZikuCiAgICAvLyBTdXIgdW5lIFBXQSBpT1Mgc3RhbmRhbG9uZSwgdGFyZ2V0PSJfYmxhbmsiIG9ibGlnZSBXZWJLaXQgw6Agb3V2cmlyIFNhZmFyaSAvIFdlYlNoZWV0CiAgICAvLyBldCBuZXV0cmFsaXNlIGwnw6l2YWx1YXRpb24gZGVzIFVuaXZlcnNhbCBMaW5rcyBzeXN0w6htZS4KICAgIC8vIEwnYWp1c3RlbWVudCBkdSB0YXJnZXQgw6AgX3NlbGYgcGVybWV0IGF1IHN5c3TDqG1lIGlPUyAvIEFuZHJvaWQgZCfDqXZhbHVlciBsZSBkb21haW5lCiAgICAvLyBhc3NvY2nDqSAoQUFTQSAvIGFzc2V0bGlua3MpIGV0IGRlIGJhc2N1bGVyIGltbcOpZGlhdGVtZW50IHZlcnMgbCdhcHBsaWNhdGlvbiBuYXRpdmUuCiAgICAvLyBTaSBsJ2FwcGxpY2F0aW9uIG5hdGl2ZSBuJ2VzdCBwYXMgaW5zdGFsbMOpZSwgaU9TL0FuZHJvaWQgYmFzY3VsZSBhdXRvbWF0aXF1ZW1lbnQKICAgIC8vIHN1ciBsZSBuYXZpZ2F0ZXVyIHdlYiBzYW5zIGJsb3F1ZXIgbGEgUFdBLgogICAgaWYgKGVsZW1lbnQpIHsKICAgICAgZWxlbWVudC50YXJnZXQgPSAnX3NlbGYnOwogICAgICByZXR1cm4gdHJ1ZTsKICAgIH0KCiAgICAvLyBEw6ljbGVuY2hlbWVudCBwcm9ncmFtbWF0aXF1ZSBzYW5zIMOpbMOpbWVudCBkJ2FuY3JhZ2UgY2xpcXXDqQogICAgd2luZG93LmxvY2F0aW9uLmFzc2lnbihwYXJzZWQuaHJlZik7CiAgICByZXR1cm4gdHJ1ZTsKICB9CgogIC8qKgogICAqIEluaXRpYWxpc2UgbGVzIGxpZW5zIG1hcnF1w6lzIGNvbW1lIG9wdC1pbiAoZGF0YS1leHRlcm5hbC1hcHApLgogICAqIEFkYXB0ZSBkeW5hbWlxdWVtZW50IGxhIGNpYmxlIGR1IGxpZW4gZMOocyBsZSBjaGFyZ2VtZW50IGRlIGxhIHBhZ2Ugc2Vsb24gbGEgcGxhdGVmb3JtZS4KICAgKi8KICBmdW5jdGlvbiBpbml0RXh0ZXJuYWxBcHBMaW5rcyhyb290KSB7CiAgICBjb25zdCBjb250YWluZXIgPSByb290IHx8IGRvY3VtZW50OwogICAgY29uc3QgbW9iaWxlID0gaXNJT1MoKSB8fCBpc0FuZHJvaWQoKTsKICAgIGNvbnN0IGxpbmtzID0gY29udGFpbmVyLnF1ZXJ5U2VsZWN0b3JBbGwoJ2FbZGF0YS1leHRlcm5hbC1hcHBdJyk7CgogICAgbGlua3MuZm9yRWFjaChsaW5rID0+IHsKICAgICAgLy8gQ29uZmlndXJhdGlvbiBpbW3DqWRpYXRlIGRlIGxhIGNpYmxlIHNlbG9uIGxhIHBsYXRlZm9ybWUKICAgICAgaWYgKG1vYmlsZSkgewogICAgICAgIGxpbmsudGFyZ2V0ID0gJ19zZWxmJzsKICAgICAgfSBlbHNlIHsKICAgICAgICBsaW5rLnRhcmdldCA9ICdfYmxhbmsnOwogICAgICAgIGxpbmsucmVsID0gJ25vb3BlbmVyIG5vcmVmZXJyZXInOwogICAgICB9CgogICAgICBsaW5rLmFkZEV2ZW50TGlzdGVuZXIoJ2NsaWNrJywgZXZlbnQgPT4gewogICAgICAgIC8vIFByw6lzZXJ2ZXIgbGVzIGNvbXBvcnRlbWVudHMgbmF0aWZzIGRlIGwndXRpbGlzYXRldXIgKGNsaWMgbW9sZXR0ZSwgdG91Y2hlcyBkZSBtb2RpZmljYXRpb24pCiAgICAgICAgaWYgKGV2ZW50LmRlZmF1bHRQcmV2ZW50ZWQgfHwgZXZlbnQuYnV0dG9uICE9PSAwIHx8IGV2ZW50LmN0cmxLZXkgfHwgZXZlbnQubWV0YUtleSB8fCBldmVudC5zaGlmdEtleSB8fCBldmVudC5hbHRLZXkpIHsKICAgICAgICAgIHJldHVybjsKICAgICAgICB9CgogICAgICAgIGNvbnN0IHByb3ZpZGVyID0gbGluay5nZXRBdHRyaWJ1dGUoJ2RhdGEtZXh0ZXJuYWwtYXBwJykgfHwgJ2F1dG8nOwogICAgICAgIG9wZW5FeHRlcm5hbEFwcChsaW5rLmhyZWYsIHsKICAgICAgICAgIGVsZW1lbnQ6IGxpbmssCiAgICAgICAgICBldmVudDogZXZlbnQsCiAgICAgICAgICBwcm92aWRlcjogcHJvdmlkZXIKICAgICAgICB9KTsKICAgICAgfSk7CiAgICB9KTsKICB9CgogIC8vIEV4cG9zaXRpb24gcHVibGlxdWUgcG91ciByw6l1dGlsaXNhdGlvbiBtb2R1bGFpcmUKICB3aW5kb3cub3BlbkV4dGVybmFsQXBwID0gb3BlbkV4dGVybmFsQXBwOwogIHdpbmRvdy5pbml0RXh0ZXJuYWxBcHBMaW5rcyA9IGluaXRFeHRlcm5hbEFwcExpbmtzOwoKICBpZiAoZG9jdW1lbnQucmVhZHlTdGF0ZSA9PT0gJ2xvYWRpbmcnKSB7CiAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCdET01Db250ZW50TG9hZGVkJywgKCkgPT4gaW5pdEV4dGVybmFsQXBwTGlua3MoKSwgeyBvbmNlOiB0cnVlIH0pOwogIH0gZWxzZSB7CiAgICBpbml0RXh0ZXJuYWxBcHBMaW5rcygpOwogIH0KfSkoKTsK';
    $decoded = base64_decode($b64, true);
    if ($decoded !== false && strlen($decoded) > 20) {
        return $cachedScript = $decoded;
    }
    $localFile = __DIR__ . '/external_apps.js';
    if (is_file($localFile)) {
        return $cachedScript = (string)file_get_contents($localFile);
    }
    return $cachedScript = '';
}
