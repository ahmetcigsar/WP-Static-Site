import Fuse from './fuse.min.mjs';

const form = document.querySelector('#ragnus-search-form');
const input = document.querySelector('#ragnus-search-input');
const status = document.querySelector('#ragnus-search-status');
const results = document.querySelector('#ragnus-search-results');
let translations = {};

const formatMessage = (message, value) => String(message).replace('%d', String(value));

const normaliseTurkish = (value) => value
    .replace(/[ÇĞİIÖŞÜçğıöşü]/g, (letter) => ({
        Ç: 'C', Ğ: 'G', İ: 'I', I: 'I', Ö: 'O', Ş: 'S', Ü: 'U',
        ç: 'c', ğ: 'g', ı: 'i', ö: 'o', ş: 's', ü: 'u',
    })[letter])
    .toLowerCase();

const setStatus = (message) => {
    status.textContent = message;
};

const render = (items) => {
    results.replaceChildren();
    if (!items.length) {
        setStatus(translations.noResults || 'No results matched your search.');
        return;
    }
    setStatus(formatMessage(translations.resultsShown || '%d results shown.', items.length));
    const fragment = document.createDocumentFragment();
    items.forEach(({ item }) => {
        const article = document.createElement('article');
        const heading = document.createElement('h2');
        const link = document.createElement('a');
        const excerpt = document.createElement('p');
        const path = document.createElement('span');
        link.href = item.url;
        link.textContent = item.title || item.url;
        heading.append(link);
        excerpt.textContent = item.excerpt || '';
        path.textContent = item.url;
        article.append(heading, excerpt, path);
        fragment.append(article);
    });
    results.append(fragment);
};

Promise.all([
    fetch('/ragnus-search-index.json').then((response) => response.json()),
    fetch('/ragnus-search-config.json').then((response) => response.json()),
]).then(([documents, config]) => {
    translations = config.i18n || {};
    const fuse = new Fuse(documents, {
        useTokenSearch: true,
        tokenMatch: config.tokenMatch,
        threshold: config.threshold,
        ignoreLocation: true,
        ignoreDiacritics: true,
        minMatchCharLength: config.minChars,
        includeScore: true,
        keys: config.keys,
    });

    const search = () => {
        const query = input.value.trim();
        const url = new URL(location.href);
        if (query) url.searchParams.set('q', query);
        else url.searchParams.delete('q');
        history.replaceState({}, '', url);
        if (query.length < config.minChars) {
            results.replaceChildren();
            setStatus(formatMessage(translations.minimumCharacters || 'Enter at least %d characters to search.', config.minChars));
            return;
        }
        const direct = fuse.search(query, { limit: config.resultLimit });
        const normalizedQuery = normaliseTurkish(query);
        const normalized = normalizedQuery === query.toLowerCase()
            ? []
            : fuse.search(normalizedQuery, { limit: config.resultLimit });
        const merged = [...direct, ...normalized].filter((result, index, all) =>
            all.findIndex((candidate) => candidate.item.url === result.item.url) === index
        ).slice(0, config.resultLimit);
        render(merged);
    };

    let timer;
    input.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 150);
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        search();
    });
    const initial = new URLSearchParams(location.search).get('q') || '';
    input.value = initial;
    search();
}).catch(() => {
    setStatus(translations.loadError || 'The search index could not be loaded. Please try again later.');
});
