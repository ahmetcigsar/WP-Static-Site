const form = document.querySelector('#wext-static-search-form');
const input = document.querySelector('#wext-static-search-input');
const status = document.querySelector('#wext-static-search-status');
const results = document.querySelector('#wext-static-search-results');
let translations = {};

const formatMessage = (message, value) => String(message).replace('%d', String(value));
const normalise = (value) => String(value)
    .replace(/[ÇĞİIÖŞÜçğıöşü]/g, (letter) => ({
        Ç: 'C', Ğ: 'G', İ: 'I', I: 'I', Ö: 'O', Ş: 'S', Ü: 'U',
        ç: 'c', ğ: 'g', ı: 'i', ö: 'o', ş: 's', ü: 'u',
    })[letter])
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();

const editDistance = (left, right, maximum) => {
    if (Math.abs(left.length - right.length) > maximum) return maximum + 1;
    let previous = Array.from({ length: right.length + 1 }, (_, index) => index);
    for (let row = 1; row <= left.length; row += 1) {
        const current = [row];
        let smallest = row;
        for (let column = 1; column <= right.length; column += 1) {
            current[column] = Math.min(
                previous[column] + 1,
                current[column - 1] + 1,
                previous[column - 1] + (left[row - 1] === right[column - 1] ? 0 : 1),
            );
            smallest = Math.min(smallest, current[column]);
        }
        if (smallest > maximum) return maximum + 1;
        previous = current;
    }
    return previous[right.length];
};

const fieldScore = (field, token, tolerance) => {
    if (field.text.includes(token)) return 1;
    if (tolerance === 0) return 0;
    let best = 0;
    for (const word of field.words) {
        if (Math.abs(word.length - token.length) > tolerance) continue;
        const distance = editDistance(token, word, tolerance);
        if (distance <= tolerance) best = Math.max(best, 1 - distance / Math.max(token.length, word.length));
    }
    return best;
};

const searchDocuments = (documents, query, config) => {
    const tokens = [...new Set(normalise(query).match(/[\p{L}\p{N}]+/gu) || [])].slice(0, 12);
    if (!tokens.length) return [];
    const tolerance = Math.max(0, Math.min(0.8, Number(config.threshold) || 0));
    const requireAll = config.tokenMatch !== 'any';
    const scored = [];
    for (const document of documents) {
        let total = 0;
        let matches = 0;
        for (const token of tokens) {
            const allowedEdits = Math.floor(token.length * tolerance);
            let strongest = 0;
            for (const field of document.fields) {
                strongest = Math.max(strongest, fieldScore(field, token, allowedEdits) * field.weight);
            }
            if (strongest > 0) matches += 1;
            total += strongest;
        }
        if (matches > 0 && (!requireAll || matches === tokens.length)) {
            scored.push({ item: document.item, score: total / tokens.length });
        }
    }
    scored.sort((a, b) => b.score - a.score || a.item.title.localeCompare(b.item.title));
    return scored.slice(0, Math.max(1, Number(config.resultLimit) || 10)).map(({ item }) => item);
};

const render = (items) => {
    results.replaceChildren();
    if (!items.length) {
        status.textContent = translations.noResults || 'No results matched your search.';
        return;
    }
    status.textContent = formatMessage(translations.resultsShown || '%d results shown.', items.length);
    const fragment = document.createDocumentFragment();
    items.forEach((item) => {
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

const loadJson = (path) => fetch(path).then((response) => {
    if (!response.ok) throw new Error(`Could not load ${path}`);
    return response.json();
});

Promise.all([
    loadJson('/wext-static-search-index.json'),
    loadJson('/wext-static-search-config.json'),
]).then(([items, config]) => {
    if (!Array.isArray(items) || !Array.isArray(config.keys)) throw new Error('Invalid search data');
    translations = config.i18n || {};
    const documents = items.map((item) => ({
        item,
        fields: config.keys.map(({ name, weight }) => {
            const text = normalise(Array.isArray(item[name]) ? item[name].join(' ') : (item[name] || ''));
            // Content is still searched for exact matches; fuzzy matching its
            // entire vocabulary would consume too much memory on large sites.
            const words = name === 'content' ? [] : [...new Set(text.match(/[\p{L}\p{N}]+/gu) || [])];
            return { text, words, weight: Number(weight) || 0 };
        }),
    }));

    const search = () => {
        const query = input.value.trim().slice(0, 120);
        const url = new URL(location.href);
        if (query) url.searchParams.set('q', query);
        else url.searchParams.delete('q');
        history.replaceState({}, '', url);
        if (query.length < config.minChars) {
            results.replaceChildren();
            status.textContent = formatMessage(translations.minimumCharacters || 'Enter at least %d characters to search.', config.minChars);
            return;
        }
        render(searchDocuments(documents, query, config));
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
    input.value = new URLSearchParams(location.search).get('q') || '';
    search();
}).catch(() => {
    status.textContent = translations.loadError || 'The search index could not be loaded. Please try again later.';
});
