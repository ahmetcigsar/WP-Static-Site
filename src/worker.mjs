function normaliseLanguage(value) {
  return String(value || '').trim().toLowerCase().replaceAll('_', '-');
}

function readCookie(request, name) {
  const cookies = request.headers.get('Cookie') || '';
  for (const part of cookies.split(';')) {
    const separator = part.indexOf('=');
    if (separator === -1 || part.slice(0, separator).trim() !== name) continue;
    try {
      return decodeURIComponent(part.slice(separator + 1).trim());
    } catch {
      return '';
    }
  }
  return '';
}

function preferredLanguage(header, supportedLanguages) {
  const preferences = String(header || '')
    .split(',')
    .map((part, index) => {
      const [locale, ...parameters] = part.trim().split(';');
      const qualityParameter = parameters.find(parameter => parameter.trim().startsWith('q='));
      const quality = qualityParameter ? Number(qualityParameter.trim().slice(2)) : 1;
      return {locale: normaliseLanguage(locale), quality: Number.isFinite(quality) ? quality : 0, index};
    })
    .filter(preference => preference.locale && preference.locale !== '*' && preference.quality > 0)
    .sort((first, second) => second.quality - first.quality || first.index - second.index);

  for (const preference of preferences) {
    if (supportedLanguages.includes(preference.locale)) return preference.locale;
    const baseLanguage = preference.locale.split('-')[0];
    if (supportedLanguages.includes(baseLanguage)) return baseLanguage;
  }
  return '';
}

async function languageConfig(request, env) {
  try {
    const configUrl = new URL('/ragnus-language-config.json', request.url);
    const response = await env.ASSETS.fetch(new Request(configUrl, {method: 'GET'}));
    if (!response.ok) return null;
    return await response.json();
  } catch {
    return null;
  }
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (url.pathname !== '/' || !['GET', 'HEAD'].includes(request.method)) {
      return env.ASSETS.fetch(request);
    }

    const config = await languageConfig(request, env);
    const supportedLanguages = Array.isArray(config?.supported_languages)
      ? [...new Set(config.supported_languages.map(normaliseLanguage).filter(Boolean))]
      : [];
    if (!config?.enabled || supportedLanguages.length < 2) {
      return env.ASSETS.fetch(request);
    }

    const cookieName = String(config.cookie_name || 'ragnus_language');
    const savedLanguage = normaliseLanguage(readCookie(request, cookieName));
    const browserLanguage = preferredLanguage(request.headers.get('Accept-Language'), supportedLanguages);
    const configuredDefault = normaliseLanguage(config.default_language);
    const defaultLanguage = supportedLanguages.includes(configuredDefault)
      ? configuredDefault
      : supportedLanguages[0];
    const language = supportedLanguages.includes(savedLanguage)
      ? savedLanguage
      : browserLanguage || defaultLanguage;

    return new Response(null, {
      status: 302,
      headers: {
        'Cache-Control': 'private, no-store',
        'Content-Language': language,
        Location: `${url.origin}/${language}/${url.search}`,
        Vary: 'Accept-Language, Cookie'
      }
    });
  }
};

export {normaliseLanguage, preferredLanguage, readCookie};
