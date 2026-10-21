export const normalizeTaskSearch = (value) => String(value || '').normalize('NFKD')
    .replace(/[\u064b-\u065f\u0670\u0640]/g, '').replace(/[أإآٱ]/g, 'ا')
    .replace(/ى/g, 'ي').replace(/ة/g, 'ه').toLocaleLowerCase('ar').trim();

/** Only search destinations provided by the server; never manufacture URLs. */
export function searchTasks(nav, tasks, query) {
    const terms = normalizeTaskSearch(query).split(/\s+/).filter(Boolean);
    if (!terms.length) return nav;
    const flatten = (nodes, context = '') => nodes.flatMap((node) => {
        if (node.section) return [];
        const keywords = `${context} ${node.label} ${node.keywords || ''}`;
        return node.children?.length ? flatten(node.children, keywords) : node.href ? [{ ...node, keywords }] : [];
    });
    const seen = new Set();
    return flatten([...tasks, ...nav]).filter((item) => {
        const matches = terms.every((term) => normalizeTaskSearch(item.keywords).includes(term));
        if (!matches || seen.has(item.href)) return false;
        seen.add(item.href);
        return true;
    });
}
