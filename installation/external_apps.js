/**
 * Musicband External App Deep Link Utility
 *
 * Utilitaire modulaire et opt-in permettant l'ouverture des liens HTTPS externes
 * dans leurs applications mobiles natives respectives (iOS Universal Links,
 * Android App Links), avec repli transparent sur le navigateur (fallback HTTPS).
 *
 * Invariants :
 * - Aucun schéma d'URL personnalisé non vérifié ou inventé.
 * - Aucun timer de détection arbitraire ou non fiable.
 * - Préservation intégrale de l'URL originale (requête et fragments inclus).
 * - Aucun détournement global : portée strictement restreinte aux liens opt-in (data-external-app).
 * - Maintien du comportement standard de navigation HTTPS sur desktop (ouverture nouvel onglet).
 */
(() => {
  'use strict';

  /**
   * Registre extensible des fournisseurs d'applications externes vérifiés.
   * Conçu pour accueillir de futurs fournisseurs (YouTube, Spotify, etc.) sans refonte d'architecture.
   */
  const EXTERNAL_APP_PROVIDERS = {
    'ultimate-guitar': {
      name: 'Ultimate Guitar',
      matches: function(urlObj) {
        const host = urlObj.hostname.toLowerCase();
        return host === 'tabs.ultimate-guitar.com' || host === 'www.ultimate-guitar.com' || host === 'ultimate-guitar.com' || host.endsWith('.ultimate-guitar.com');
      },
      isSupportedPath: function(urlObj) {
        const p = urlObj.pathname;
        return p.startsWith('/tab/') || p.includes('_crd.htm') || p.includes('_tab.htm') || p.includes('_btab') || p.includes('_guitar_pro.htm');
      }
    },
    'google-drive': {
      name: 'Google Drive',
      matches: function(urlObj) {
        const host = urlObj.hostname.toLowerCase();
        return host === 'drive.google.com' || host.endsWith('.drive.google.com');
      },
      isSupportedPath: function(urlObj) {
        const p = urlObj.pathname;
        return p.startsWith('/drive/') || p.startsWith('/file/') || p.startsWith('/folderview');
      }
    }
  };

  /**
   * Détection des plateformes et contextes d'exécution.
   */
  function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  function isAndroid() {
    return /Android/i.test(navigator.userAgent);
  }

  function isStandalone() {
    return window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
  }

  /**
   * Valide une URL externe et vérifie la conformité du protocole de sécurité.
   */
  function parseValidUrl(url) {
    if (typeof url !== 'string' || !url.trim()) return null;
    try {
      const parsed = new URL(url.trim(), window.location.href);
      if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') {
        return null;
      }
      return parsed;
    } catch (_) {
      return null;
    }
  }

  /**
   * Ouvre une URL externe dans l'application native associée si supporté,
   * ou dans le navigateur avec repli transparent.
   *
   * @param {string} url - URL HTTPS complète.
   * @param {Object} [options] - Options facultatives (element, event, provider).
   * @returns {boolean} Succès de la prise en charge de la navigation.
   */
  function openExternalApp(url, options = {}) {
    const parsed = parseValidUrl(url);
    if (!parsed) return false;

    const element = options.element || null;
    const event = options.event || null;
    const mobile = isIOS() || isAndroid();

    // 1. Sur Desktop : navigation HTTPS standard dans un nouvel onglet
    if (!mobile) {
      if (element) {
        element.target = '_blank';
        element.rel = 'noopener noreferrer';
        return true;
      }
      if (!event) {
        window.open(parsed.href, '_blank', 'noopener,noreferrer');
      }
      return true;
    }

    // 2. Sur Mobile (iOS et Android) :
    // Les Universal Links (iOS) et App Links (Android) reposent sur un geste utilisateur direct
    // dans le contexte de navigation courant (_self).
    // Sur une PWA iOS standalone, target="_blank" oblige WebKit à ouvrir Safari / WebSheet
    // et neutralise l'évaluation des Universal Links système.
    // L'ajustement du target à _self permet au système iOS / Android d'évaluer le domaine
    // associé (AASA / assetlinks) et de basculer immédiatement vers l'application native.
    // Si l'application native n'est pas installée, iOS/Android bascule automatiquement
    // sur le navigateur web sans bloquer la PWA.
    if (element) {
      element.target = '_self';
      return true;
    }

    // Déclenchement programmatique sans élément d'ancrage cliqué
    window.location.assign(parsed.href);
    return true;
  }

  /**
   * Initialise les liens marqués comme opt-in (data-external-app).
   * Adapte dynamiquement la cible du lien dès le chargement de la page selon la plateforme.
   */
  function initExternalAppLinks(root) {
    const container = root || document;
    const mobile = isIOS() || isAndroid();
    const links = container.querySelectorAll('a[data-external-app]');

    links.forEach(link => {
      // Configuration immédiate de la cible selon la plateforme
      if (mobile) {
        link.target = '_self';
      } else {
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
      }

      link.addEventListener('click', event => {
        // Préserver les comportements natifs de l'utilisateur (clic molette, touches de modification)
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
          return;
        }

        const provider = link.getAttribute('data-external-app') || 'auto';
        openExternalApp(link.href, {
          element: link,
          event: event,
          provider: provider
        });
      });
    });
  }

  // Exposition publique pour réutilisation modulaire
  window.openExternalApp = openExternalApp;
  window.initExternalAppLinks = initExternalAppLinks;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initExternalAppLinks(), { once: true });
  } else {
    initExternalAppLinks();
  }
})();
