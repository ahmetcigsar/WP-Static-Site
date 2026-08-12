import assert from 'node:assert/strict';
import test from 'node:test';

import worker, {preferredLanguage, readCookie} from '../src/worker.mjs';

const config = {
  enabled: true,
  supported_languages: ['tr', 'en', 'de'],
  default_language: 'tr',
  cookie_name: 'ragnus_language'
};

function environment(overrides = {}) {
  return {
    ASSETS: {
      async fetch(request) {
        const path = new URL(request.url).pathname;
        if (path === '/ragnus-language-config.json') {
          return Response.json({...config, ...overrides});
        }
        return new Response(`asset:${path}`, {status: 200});
      }
    }
  };
}

test('Accept-Language kalite sırasına ve temel dile göre eşleşir', () => {
  assert.equal(preferredLanguage('fr-CH, de-DE;q=0.9, en;q=0.8', ['tr', 'en', 'de']), 'de');
  assert.equal(preferredLanguage('en-US,en;q=0.9', ['tr', 'en']), 'en');
});

test('kayıtlı dil tercihi güvenli şekilde okunur', () => {
  const request = new Request('https://example.com/', {headers: {Cookie: 'x=1; ragnus_language=en'}});
  assert.equal(readCookie(request, 'ragnus_language'), 'en');
});

test('kök adres tarayıcı diline yönlendirilir ve sorgu korunur', async () => {
  const request = new Request('https://example.com/?utm_source=test', {
    headers: {'Accept-Language': 'de-DE,de;q=0.9,en;q=0.8'}
  });
  const response = await worker.fetch(request, environment());
  assert.equal(response.status, 302);
  assert.equal(response.headers.get('Location'), 'https://example.com/de/?utm_source=test');
  assert.equal(response.headers.get('Cache-Control'), 'private, no-store');
});

test('çerez tarayıcı dilinden üstündür', async () => {
  const request = new Request('https://example.com/', {
    headers: {Cookie: 'ragnus_language=en', 'Accept-Language': 'de'}
  });
  const response = await worker.fetch(request, environment());
  assert.equal(response.headers.get('Location'), 'https://example.com/en/');
});

test('özellik kapalıysa kök statik dosya sunulur', async () => {
  const response = await worker.fetch(new Request('https://example.com/'), environment({enabled: false}));
  assert.equal(await response.text(), 'asset:/');
});

test('dil içeren doğrudan bağlantı yönlendirilmez', async () => {
  const response = await worker.fetch(new Request('https://example.com/en/about/'), environment());
  assert.equal(await response.text(), 'asset:/en/about/');
});
