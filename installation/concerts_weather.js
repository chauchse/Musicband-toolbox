/**
 * Musicband CONCERTS WEATHER JS: affichage dynamique et accessible du widget météo.
 *
 * Rôle :
 * Récupération asynchrone des prévisions météo via l'endpoint AJAX concerts.php,
 * rendu accessible de la frise chronologique horaire, des indicateurs météo
 * (icônes SVG intégrées, températures, risque de pluie, vent, rafales) et mise
 * en valeur de la période de prestation du concert.
 *
 * Dépendances internes :
 * - Invoqué sur la page de détail d'un concert (web/business.php)
 * - Styles dans web/css/business.css
 *
 * Effets de bord :
 * - Injection dans les nœuds DOM #weather-summary-bar et #weather-content
 */

(function () {
  'use strict';

  // Bibliothèque d'icônes SVG vectorielles intégrées, adaptées au thème Musicband
  const WEATHER_ICONS = {
    'clear': '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#f6ad55" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>',
    'partly-cloudy': '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#cbd5e0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v2M4.93 4.93l1.41 1.41M2 12h2" stroke="#f6ad55"/><circle cx="12" cy="10" r="3" stroke="#f6ad55"/><path d="M7 19h11a4 4 0 0 0 .5-7.97A5.5 5.5 0 0 0 7.5 13 3.5 3.5 0 0 0 7 19z" fill="#2d3748"/></svg>',
    'cloudy': '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#a0aec0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>',
    'overcast': '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#718096" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h.79a4.5 4.5 0 0 1 1 8.89z" fill="#2d3748"/></svg>',
    'fog': '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#a0aec0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14h16M4 18h16M7 10h10M9 6h6"/></svg>',
    'drizzle': '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#63b3ed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 13a4 4 0 0 0-3.5-3.9 6 6 0 0 0-11 1.9A4 4 0 0 0 5 17h14" stroke="#a0aec0"/><path d="M8 19v2M12 19v2M16 19v2"/></svg>',
    'rain': '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#4299e1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 11a4 4 0 0 0-3.5-3.9 6 6 0 0 0-11 1.9A4 4 0 0 0 5 15h14" stroke="#a0aec0"/><path d="M10 17l-2 4M14 17l-2 4M18 17l-2 4"/></svg>',
    'heavy-rain': '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#3182ce" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 10a4 4 0 0 0-3.5-3.9 6 6 0 0 0-11 1.9A4 4 0 0 0 5 14h14" stroke="#718096" fill="#1a202c"/><path d="M9 16l-2 5M13 16l-2 5M17 16l-2 5"/></svg>',
    'snow': '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#e2e8f0" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 11a4 4 0 0 0-3.5-3.9 6 6 0 0 0-11 1.9A4 4 0 0 0 5 15h14" stroke="#a0aec0"/><path d="M8 18h.01M12 18h.01M16 18h.01M10 21h.01M14 21h.01"/></svg>',
    'thunderstorm': '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#ecc94b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 11a4 4 0 0 0-3.5-3.9 6 6 0 0 0-11 1.9A4 4 0 0 0 5 15h14" stroke="#718096" fill="#1a202c"/><path d="M13 13l-3 5h4l-2 5"/></svg>',
    'droplet': '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" aria-hidden="true"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>',
    'wind': '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg>',
  };

  /**
   * Échappement HTML sécurisé pour les données affichées.
   */
  function escapeHtml(str) {
    if (str == null) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /**
   * Chargement des prévisions météo du concert courant.
   */
  function loadConcertWeather() {
    const mount = document.getElementById('concert-weather');
    if (!mount) return;

    const concertId = mount.getAttribute('data-concert-id');
    if (!concertId) return;

    const summaryBar = document.getElementById('weather-summary-bar');
    const content = document.getElementById('weather-content');
    if (!content) return;

    const url = 'concerts.php?ajax=weather&id=' + encodeURIComponent(concertId);

    fetch(url, {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        return res.json();
      })
      .then(function (data) {
        if (!data || data.status !== 'success') {
          const message = (data && data.message)
            ? data.message
            : 'Météo indisponible : données inaccessibles.';
          if (summaryBar) summaryBar.textContent = '';
          content.innerHTML = '<p class="weather-notice" role="status">' + escapeHtml(message) + '</p>';
          return;
        }

        renderWeather(data, summaryBar, content);
      })
      .catch(function () {
        if (summaryBar) summaryBar.textContent = '';
        content.innerHTML = '<p class="weather-notice" role="status">Données météo temporairement indisponibles.</p>';
      });
  }

  /**
   * Rendu visuel du panneau de prévisions et de la frise horaire.
   */
  function renderWeather(data, summaryBar, content) {
    const sum = data.summary || {};
    const iconSvg = WEATHER_ICONS[sum.icon] || WEATHER_ICONS['cloudy'];

    // Barre récapitulative
    if (summaryBar) {
      const chips = [];

      if (data.location) {
        chips.push('<span class="weather-summary-chip weather-chip-location" title="Localisation retenue">' + escapeHtml(data.location) + '</span>');
      }

      if (sum.temp_min !== undefined && sum.temp_max !== undefined) {
        const tempText = sum.temp_min === sum.temp_max ? sum.temp_min + '°C' : sum.temp_min + ' à ' + sum.temp_max + '°C';
        chips.push('<span class="weather-summary-chip"><strong>' + tempText + '</strong></span>');
      }

      if (sum.rain_prob_max !== undefined) {
        const rainTitle = 'Risque max de pluie : ' + sum.rain_prob_max + '% (' + (sum.rain_sum || 0) + ' mm)';
        chips.push('<span class="weather-summary-chip" title="' + escapeHtml(rainTitle) + '">' + WEATHER_ICONS.droplet + ' ' + sum.rain_prob_max + '%</span>');
      }

      if (sum.wind_max !== undefined) {
        const windTitle = 'Vent max : ' + sum.wind_max + ' km/h' + (sum.wind_gusts_max ? ' (rafales ' + sum.wind_gusts_max + ' km/h)' : '');
        chips.push('<span class="weather-summary-chip" title="' + escapeHtml(windTitle) + '">' + WEATHER_ICONS.wind + ' ' + sum.wind_max + ' km/h</span>');
      }

      summaryBar.innerHTML = chips.join('');
    }

    // Frise horaire défilante
    const hourly = data.hourly || [];
    if (!hourly.length) {
      content.innerHTML = '<p class="weather-notice" role="status">Aucune tranche horaire disponible pour ce concert.</p>';
      return;
    }

    const cardsHtml = [];
    for (let i = 0; i < hourly.length; i++) {
      const h = hourly[i];
      const cardIcon = WEATHER_ICONS[h.icon] || WEATHER_ICONS['cloudy'];
      const perfClass = h.is_performance ? ' is-performance' : '';
      const arrivalClass = h.is_arrival ? ' is-arrival' : '';

      let badgeHtml = '';
      if (h.is_performance) {
        badgeHtml = '<span class="weather-badge">En concert</span>';
      } else if (h.is_arrival) {
        badgeHtml = '<span class="weather-badge-arrival">Arrivée</span>';
      }

      const dateTag = h.date_label ? '<span class="weather-date-tag">' + escapeHtml(h.date_label) + '</span>' : '';
      const rainClass = h.rain_prob >= 20 ? ' has-rain' : '';
      const precipText = (h.precipitation && h.precipitation > 0) ? ' <small>(' + h.precipitation + ' mm)</small>' : '';
      const windGustText = (h.wind_gusts && h.wind_gusts > h.wind_speed + 8) ? ' <small>(' + h.wind_gusts + ')</small>' : '';

      cardsHtml.push(
        '<div class="weather-hour-card' + perfClass + arrivalClass + '" tabindex="0" title="' + escapeHtml(h.tooltip) + '" aria-label="' + escapeHtml(h.tooltip) + '">' +
          badgeHtml +
          '<span class="weather-time">' + escapeHtml(h.time) + '</span>' +
          dateTag +
          '<div class="weather-icon">' + cardIcon + '</div>' +
          '<span class="weather-temp">' + h.temp + '°C</span>' +
          '<span class="weather-rain' + rainClass + '">' + WEATHER_ICONS.droplet + ' ' + h.rain_prob + '%' + precipText + '</span>' +
          '<span class="weather-wind">' + WEATHER_ICONS.wind + ' ' + h.wind_speed + ' km/h' + windGustText + '</span>' +
        '</div>'
      );
    }

    const timelineHtml =
      '<div class="weather-timeline" tabindex="0" role="region" aria-label="Frise chronologique des prévisions météo">' +
        cardsHtml.join('') +
      '</div>' +
      '<div class="weather-attribution">' +
        'Données météo : <a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Open-Meteo.com</a> (CC BY 4.0)' +
      '</div>';

    content.innerHTML = timelineHtml;
  }

  // Initialisation au chargement du DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadConcertWeather);
  } else {
    loadConcertWeather();
  }
})();
